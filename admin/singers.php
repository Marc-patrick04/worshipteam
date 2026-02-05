<?php
require_once '../includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Function to clean data for database insertion (removes problematic UTF-8 characters)
function cleanForDatabase($data) {
    if (empty($data)) return $data;

    // Convert to string and trim
    $data = trim((string)$data);

    // Remove any null bytes
    $data = str_replace("\0", "", $data);

    // Remove control characters except for tab, newline, carriage return
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);

    // Ensure it's valid UTF-8, replace invalid sequences
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    }

    // Remove or replace common problematic characters
    $data = str_replace(['"', "'"], ['"', "'"], $data);

    // Limit length to prevent database issues
    if (strlen($data) > 500) {
        $data = substr($data, 0, 500);
    }

    return $data;
}

// Function to convert technical database errors to user-friendly messages
function getUserFriendlyError($errorMessage) {
    if (strpos($errorMessage, 'SQLSTATE[23000]') !== false && strpos($errorMessage, 'Duplicate entry') !== false) {
        // Extract the duplicate value from the error message
        preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $errorMessage, $matches);
        if (count($matches) >= 3) {
            $duplicateValue = $matches[1];
            $keyName = $matches[2];

            if ($keyName === 'full_name') {
                return "❌ <strong>Cannot Add Singer</strong><br>A singer with the name <strong>'$duplicateValue'</strong> already exists in the database.<br><small>Please use a different name or check if this person is already registered.</small>";
            }
        }
        return "❌ <strong>Duplicate Entry Error</strong><br>This information already exists in the system.<br><small>Please check your input and try again.</small>";
    }

    if (strpos($errorMessage, 'SQLSTATE[42000]') !== false) {
        return "❌ <strong>Database Syntax Error</strong><br>There was a problem with the database query.<br><small>Please contact the administrator.</small>";
    }

    if (strpos($errorMessage, 'SQLSTATE[HY000]') !== false) {
        return "❌ <strong>Database Connection Error</strong><br>Unable to connect to the database.<br><small>Please try again later or contact support.</small>";
    }

    if (strpos($errorMessage, 'foreign key constraint') !== false || strpos($errorMessage, 'foreign key') !== false) {
        return "❌ <strong>Data Relationship Error</strong><br>Cannot perform this action because it would break data relationships.<br><small>This record may be referenced by other data in the system.</small>";
    }

    // Generic fallback
    return "❌ <strong>Database Error</strong><br>An unexpected error occurred while processing your request.<br><small>Please try again or contact the administrator.</small>";
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$voice_category_filter = $_GET['voice_category'] ?? '';
$voice_level_filter = $_GET['voice_level'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_singer'])) {
        $fullName = sanitize($_POST['full_name']);
        $voiceCategory = $_POST['voice_category'];
        $voiceLevel = $_POST['voice_level'];
        $status = $_POST['status'];
        $notes = sanitize($_POST['notes'] ?? '');

        try {
            $stmt = $pdo->prepare("INSERT INTO singers (full_name, voice_category, voice_level, status, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$fullName, $voiceCategory, $voiceLevel, $status, $notes]);
            $message = 'Singer added successfully!';
            logAction('add_singer', "Added singer: $fullName");
        } catch (PDOException $e) {
            $message = getUserFriendlyError($e->getMessage());
        }
    } elseif (isset($_POST['edit_singer'])) {
        $fullName = sanitize($_POST['full_name']);
        $voiceCategory = $_POST['voice_category'];
        $voiceLevel = $_POST['voice_level'];
        $status = $_POST['status'];
        $notes = sanitize($_POST['notes'] ?? '');

        try {
            $stmt = $pdo->prepare("UPDATE singers SET full_name = ?, voice_category = ?, voice_level = ?, status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$fullName, $voiceCategory, $voiceLevel, $status, $notes, $id]);
            $message = 'Singer updated successfully!';
            logAction('edit_singer', "Updated singer: $fullName");
        } catch (PDOException $e) {
            $message = getUserFriendlyError($e->getMessage());
        }
    } elseif (isset($_POST['delete_singer'])) {
        $deleteId = $_POST['id'] ?? null;
        if ($deleteId) {
            try {
                $stmt = $pdo->prepare("SELECT full_name FROM singers WHERE id = ?");
                $stmt->execute([$deleteId]);
                $singer = $stmt->fetch();
                if ($singer) {
                    $stmt = $pdo->prepare("DELETE FROM singers WHERE id = ?");
                    $stmt->execute([$deleteId]);
                    $message = 'Singer deleted successfully!';
                    logAction('delete_singer', "Deleted singer: " . $singer['full_name']);
                } else {
                    $message = 'Singer not found.';
                }
            } catch (PDOException $e) {
                $message = getUserFriendlyError($e->getMessage());
            }
        } else {
            $message = 'Invalid singer ID.';
        }
    } elseif (isset($_POST['import_excel'])) {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please select a valid file to upload.';
        } else {
            $file = $_FILES['excel_file'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];

            // Basic validation
            if ($fileSize > 5 * 1024 * 1024) {
                $message = 'File size must be less than 5MB.';
            } elseif (!in_array(pathinfo($fileName, PATHINFO_EXTENSION), ['csv', 'xlsx', 'xls'])) {
                $message = 'Please upload a CSV or Excel file (.csv, .xlsx, or .xls).';
            } else {
                try {
                    $importedCount = 0;
                    $skippedCount = 0;
                    $errors = [];

                    // Handle different file types
                    $extension = pathinfo($fileName, PATHINFO_EXTENSION);

                    if ($extension === 'csv') {
                        // Simple CSV import
                        $handle = fopen($fileTmpName, 'r');
                        if ($handle) {
                            $rowNumber = 0;
                            $headers = [];

                            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                                $rowNumber++;

                                // Clean the row
                                $row = array_map('trim', $row);

                                // Skip empty rows
                                if (empty(array_filter($row))) continue;

                                if ($rowNumber === 1) {
                                    // Get headers and check basic requirements
                                    $headers = $row;
                                    if (count($headers) < 3) {
                                        $errors[] = 'CSV must have at least 3 columns: Full Name, Voice Category, Voice Level';
                                        break;
                                    }
                                    continue;
                                }

                                // Process data rows
                                if (count($row) >= 3) {
                                    // Clean and sanitize data to prevent UTF-8 issues
                                    $fullName = cleanForDatabase($row[0] ?? '');
                                    $voiceCategory = cleanForDatabase($row[1] ?? '');
                                    $voiceLevel = cleanForDatabase($row[2] ?? '');
                                    $status = cleanForDatabase(isset($row[3]) ? $row[3] : 'Active');
                                    $notes = cleanForDatabase(isset($row[4]) ? $row[4] : '');

                                    // Basic validation
                                    if (empty($fullName)) {
                                        $errors[] = "Row $rowNumber: Full Name is required";
                                        continue;
                                    }

                                    if (!in_array(strtolower($voiceCategory), ['soprano', 'alto', 'tenor', 'bass'])) {
                                        $errors[] = "Row $rowNumber: Voice Category must be Soprano, Alto, Tenor, or Bass (got: $voiceCategory)";
                                        continue;
                                    }

                                    if (!in_array(strtolower($voiceLevel), ['good', 'normal'])) {
                                        $errors[] = "Row $rowNumber: Voice Level must be Good or Normal (got: $voiceLevel)";
                                        continue;
                                    }

                                    // Check for duplicates
                                    $checkStmt = $pdo->prepare("SELECT id FROM singers WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?))");
                                    $checkStmt->execute([$fullName]);
                                    if ($checkStmt->fetch()) {
                                        $skippedCount++;
                                        continue;
                                    }

                                    // Insert the singer
                                    $insertStmt = $pdo->prepare("INSERT INTO singers (full_name, voice_category, voice_level, status, notes) VALUES (?, ?, ?, ?, ?)");
                                    $insertStmt->execute([$fullName, ucfirst(strtolower($voiceCategory)), ucfirst(strtolower($voiceLevel)), $status, $notes]);
                                    $importedCount++;
                                }
                            }
                            fclose($handle);
                        } else {
                            $message = 'Could not open the uploaded file.';
                        }
                    } else {
                        // Excel files - show conversion message
                        $message = '❌ Excel file (.xlsx) detected.<br><br>';
                        $message .= '<strong>To import Excel files, convert to CSV format:</strong><br><br>';
                        $message .= '📋 <strong>Quick Steps:</strong><br>';
                        $message .= '1. Open your Excel file<br>';
                        $message .= '2. File → Save As → CSV (Comma delimited)<br>';
                        $message .= '3. Upload the .csv file here<br><br>';
                        $message .= '<small>💡 <strong>Tip:</strong> Use the downloaded template for best results.</small>';
                    }

                    // Generate result message
                    if (empty($errors)) {
                        if ($importedCount > 0) {
                            $message = "✅ Successfully imported $importedCount singer(s)!";
                            if ($skippedCount > 0) {
                                $message .= " ($skippedCount duplicate(s) skipped)";
                            }
                            logAction('import_csv', "Imported $importedCount singers from CSV file");
                        } elseif ($skippedCount > 0) {
                            $message = "ℹ️ No new singers imported. All $skippedCount singer(s) were duplicates.";
                        } else {
                            $message = 'ℹ️ No singers were imported. Please check your file has data rows.';
                        }
                    } else {
                        $message = '❌ Import failed with errors:<br><ul>';
                        foreach (array_slice($errors, 0, 5) as $error) {
                            $message .= "<li>$error</li>";
                        }
                        if (count($errors) > 5) {
                            $message .= "<li>... and " . (count($errors) - 5) . " more errors</li>";
                        }
                        $message .= '</ul><br><small>Please fix the errors and try again.</small>';
                    }

                } catch (Exception $e) {
                    $message = '❌ Import failed: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get singer data for editing
$singer = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM singers WHERE id = ?");
    $stmt->execute([$id]);
    $singer = $stmt->fetch();
    if (!$singer) {
        $message = 'Singer not found.';
        $action = 'list';
    }
}

// Build the singers query with search and filters
$query = "SELECT * FROM singers WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND LOWER(full_name) LIKE LOWER(?)";
    $params[] = "%$search%";
}

if (!empty($voice_category_filter)) {
    $query .= " AND voice_category = ?";
    $params[] = $voice_category_filter;
}

if (!empty($voice_level_filter)) {
    $query .= " AND voice_level = ?";
    $params[] = $voice_level_filter;
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY voice_category, voice_level DESC, full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$singers = $stmt->fetchAll();

// Get filter counts for display
$totalSingers = $pdo->query("SELECT COUNT(*) FROM singers")->fetchColumn();
$activeSingers = $pdo->query("SELECT COUNT(*) FROM singers WHERE status = 'Active'")->fetchColumn();
$inactiveSingers = $pdo->query("SELECT COUNT(*) FROM singers WHERE status = 'Inactive'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Singers - Reverence Worship Team</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <div class="admin-layout">
        <div class="admin-sidebar">
            <div class="logo-container">
                <img src="../assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="logo">
            </div>
            <div class="sidebar-title">
                <h2>Admin Panel</h2>
            </div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="singers.php" class="active">Manage Singers</a>
                <a href="groups.php">Manage Groups</a>
                <a href="reports.php">Reports</a>
                <a href="images.php">Manage Images</a>
                <a href="settings.php">Settings</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>

        <div class="admin-main">
            <div class="admin-header">
                <h1>Manage Singers</h1>
            </div>

            <!-- Horizontal Navigation -->
            <div class="horizontal-nav">
                <a href="singers.php" class="nav-tab <?php echo $action === 'list' || empty($action) ? 'active' : ''; ?>">
                     All Singers
                </a>
                <a href="singers.php?action=add" class="nav-tab <?php echo $action === 'add' ? 'active' : ''; ?>">
                    ➕ Add Singer
                </a>
                <a href="singers.php?action=import" class="nav-tab <?php echo $action === 'import' ? 'active' : ''; ?>">
                     Import Singers
                </a>
            </div>

            <div class="admin-content">
                <?php if ($message): ?>
                    <div class="message <?php echo strpos($message, 'Error') === 0 ? 'error' : 'success'; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'add' || $action === 'edit'): ?>
                    <div class="form-container">
                        <h3><?php echo $action === 'add' ? 'Add New Singer' : 'Edit Singer'; ?></h3>
                        <form method="POST" data-validate>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="full_name">Full Name:</label>
                                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($singer['full_name'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="voice_category">Voice Category:</label>
                                    <select id="voice_category" name="voice_category" required>
                                        <option value="">Select Category</option>
                                        <option value="Soprano" <?php echo ($singer['voice_category'] ?? '') === 'Soprano' ? 'selected' : ''; ?>>Soprano</option>
                                        <option value="Alto" <?php echo ($singer['voice_category'] ?? '') === 'Alto' ? 'selected' : ''; ?>>Alto</option>
                                        <option value="Tenor" <?php echo ($singer['voice_category'] ?? '') === 'Tenor' ? 'selected' : ''; ?>>Tenor</option>
                                        <option value="Bass" <?php echo ($singer['voice_category'] ?? '') === 'Bass' ? 'selected' : ''; ?>>Bass</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="voice_level">Voice Level:</label>
                                    <select id="voice_level" name="voice_level" required>
                                        <option value="">Select Level</option>
                                        <option value="Good" <?php echo ($singer['voice_level'] ?? '') === 'Good' ? 'selected' : ''; ?>>Good</option>
                                        <option value="Normal" <?php echo ($singer['voice_level'] ?? '') === 'Normal' ? 'selected' : ''; ?>>Normal</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="status">Status:</label>
                                    <select id="status" name="status" required>
                                        <option value="Active" <?php echo ($singer['status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($singer['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="notes">Notes (optional):</label>
                                <textarea id="notes" name="notes" rows="3"><?php echo htmlspecialchars($singer['notes'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="<?php echo $action === 'add' ? 'add_singer' : 'edit_singer'; ?>" class="btn">
                                    <?php echo $action === 'add' ? 'Add Singer' : 'Update Singer'; ?>
                                </button>
                                <a href="singers.php" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php elseif ($action === 'import'): ?>
                    <div class="form-container">
                        <h3>📤 Import Singers from Excel</h3>

                        <!-- Excel Format Instructions -->
                        

                            
                            

                            <div class="template-download">
                                <a href="#" onclick="downloadTemplate()" class="btn btn-primary">
                                    📥 Download Excel Template
                                </a>
                                <small>Create your singer list using this pre-formatted template</small>
                            </div>
                        </div>

                        <!-- Upload Form -->
                        <div class="upload-section">
                            <h4>Upload Your Excel File</h4>
                            <form method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label for="excel_file">Select Excel/CSV File:</label>
                                    <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                                    <small>Supported formats: CSV (.csv), Excel (.xlsx, .xls) - Maximum file size: 5MB</small>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" name="import_excel" class="btn">📤 Import Singers</button>
                                    <a href="singers.php" class="btn">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="margin-bottom: 2rem;">
                        <a href="singers.php?action=add" class="btn">Add New Singer</a>
                        <a href="singers.php?action=import" class="btn">Import from Excel</a>
                    </div>

                    <!-- Search and Filter Controls -->
                    <div class="filters-section">
                        <form method="GET" class="filters-form">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="search"> Search by Name:</label>
                                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Enter singer name...">
                                </div>
                                <div class="filter-group">
                                    <label for="voice_category">Voice Category:</label>
                                    <select id="voice_category" name="voice_category">
                                        <option value="">All Categories</option>
                                        <option value="Soprano" <?php echo $voice_category_filter === 'Soprano' ? 'selected' : ''; ?>>Soprano</option>
                                        <option value="Alto" <?php echo $voice_category_filter === 'Alto' ? 'selected' : ''; ?>>Alto</option>
                                        <option value="Tenor" <?php echo $voice_category_filter === 'Tenor' ? 'selected' : ''; ?>>Tenor</option>
                                        <option value="Bass" <?php echo $voice_category_filter === 'Bass' ? 'selected' : ''; ?>>Bass</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="voice_level"> Voice Level:</label>
                                    <select id="voice_level" name="voice_level">
                                        <option value="">All Levels</option>
                                        <option value="Good" <?php echo $voice_level_filter === 'Good' ? 'selected' : ''; ?>>Good</option>
                                        <option value="Normal" <?php echo $voice_level_filter === 'Normal' ? 'selected' : ''; ?>>Normal</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="status"> Status:</label>
                                    <select id="status" name="status">
                                        <option value="">All Status</option>
                                        <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn">Filter</button>
                                    <a href="singers.php" class="btn secondary">❌ Clear</a>
                                </div>
                            </div>
                        </form>

                        <!-- Results Summary -->
                        <div class="results-summary">
                            <span class="results-count">
                                 Showing <?php echo count($singers); ?> of <?php echo $totalSingers; ?> singers
                                (<?php echo $activeSingers; ?> active, <?php echo $inactiveSingers; ?> inactive)
                            </span>
                            <?php if (!empty($search) || !empty($voice_category_filter) || !empty($voice_level_filter) || !empty($status_filter)): ?>
                                <span class="active-filters">
                                    Active filters:
                                    <?php if (!empty($search)): ?>Name: "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
                                    <?php if (!empty($voice_category_filter)): ?>Category: <?php echo $voice_category_filter; ?><?php endif; ?>
                                    <?php if (!empty($voice_level_filter)): ?>Level: <?php echo $voice_level_filter; ?><?php endif; ?>
                                    <?php if (!empty($status_filter)): ?>Status: <?php echo $status_filter; ?><?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Voice Category</th>
                                    <th>Voice Level</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $singerCounter = 1; ?>
                                <?php foreach ($singers as $singer): ?>
                                    <tr>
                                        <td><?php echo $singerCounter; ?>. <?php echo htmlspecialchars($singer['full_name']); ?></td>
                                    <?php $singerCounter++; ?>
                                        <td><?php echo htmlspecialchars($singer['voice_category']); ?></td>
                                        <td><?php echo htmlspecialchars($singer['voice_level']); ?></td>
                                        <td><?php echo htmlspecialchars($singer['status']); ?></td>
                                        <td><?php echo htmlspecialchars($singer['notes'] ?: 'N/A'); ?></td>
                                        <td>
                                            <a href="singers.php?action=edit&id=<?php echo $singer['id']; ?>" class="btn">Edit</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="id" value="<?php echo $singer['id']; ?>">
                                                <button type="submit" name="delete_singer" class="btn btn-delete" onclick="return confirm('Are you sure?')">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                
                <h3>Reverence WorshipTeam</h3>
               
            </div>

            <div class="footer-section footer-scripture">
                <blockquote>
                    <p><strong>Psalm 96:7-9</strong></p>
                    <p>Give praise to the Lord, you who belong to all peoples, give glory to him and take up his praise.</p>
                </blockquote>
            </div>

            <div class="footer-section">
                <h4>Singer Tools</h4>
                <p><a href="singers.php?action=add">Add New Singer</a></p>
                <p><a href="singers.php?action=import">Import from Excel</a></p>
                <p><a href="groups.php">View Group Assignments</a></p>
                <p><a href="dashboard.php">← Back to Dashboard</a></p>
            </div>

            <div class="footer-section">
                <h4>Voice Categories</h4>
                <p>• Soprano</p>
                <p>• Alto</p>
                <p>• Tenor</p>
                <p>• Bass</p>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="copyright">
                <p>&copy; 2026 Reverence WorshipTeam. All rights reserved.</p>
              
            </div>
        </div>
    </footer>

    <script src="../js/main.js"></script>
    <script>
        function downloadTemplate() {
            // Create a simple Excel template data
            const templateData = [
                ['Full Name', 'Voice Category', 'Voice Level', 'Status', 'Notes'],
                ['John Doe', 'Soprano', 'Good', 'Active', 'Lead vocalist'],
                ['Jane Smith', 'Alto', 'Normal', 'Active', 'Backup singer'],
                ['Michael Johnson', 'Tenor', 'Good', 'Active', ''],
                ['Bob Wilson', 'Bass', 'Normal', 'Inactive', 'On leave']
            ];

            // Convert to CSV format
            const csvContent = templateData.map(row =>
                row.map(cell => `"${cell}"`).join(',')
            ).join('\n');

            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'singers_template.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            alert('Template downloaded! Note: Save the file as .xlsx in Excel for best compatibility.');
        }
    </script>

    <style>
        /* Override width restrictions for the entire import page */
        .admin-content,
        .form-container {
            max-width: none !important;
            width: 100% !important;
        }

        .excel-format-info {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            width: 100%;
            box-sizing: border-box;
        }

        .excel-format-info h4 {
            color: #495057;
            margin-bottom: 1rem;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
        }

        .format-table {
            margin: 1.5rem 0;
            border-radius: 6px;
            overflow-x: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
        }

        .format-table table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }

        .format-table th,
        .format-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            white-space: nowrap;
        }

        .format-table th {
            background: #007bff;
            color: white;
            font-weight: 600;
            min-width: 120px;
        }

        .format-table .header-row {
            background: #28a745;
        }

        .format-table .header-row td {
            font-weight: bold;
            color: white;
            min-width: 120px;
        }

        .format-rules {
            background: white;
            padding: 1.5rem;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            margin: 1.5rem 0;
            width: 100%;
            box-sizing: border-box;
        }

        .format-rules h5 {
            color: #495057;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .format-rules ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .format-rules li {
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .format-rules li strong {
            color: #007bff;
        }

        .template-download {
            text-align: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: #e9ecef;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
        }

        .template-download small {
            display: block;
            margin-top: 0.5rem;
            color: #6c757d;
        }

        .upload-section {
            background: white;
            border: 2px solid #007bff;
            border-radius: 8px;
            padding: 2rem;
            width: 100%;
            box-sizing: border-box;
        }

        .upload-section h4 {
            color: #007bff;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .upload-section .form-group {
            margin-bottom: 1.5rem;
        }

        .upload-section input[type="file"] {
            padding: 0.75rem;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            width: 100%;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .upload-section small {
            color: #6c757d;
            font-style: italic;
        }

        /* Improve table readability on smaller screens */
        @media (max-width: 1024px) {
            .format-table table {
                min-width: 700px;
            }

            .format-table th,
            .format-table td {
                padding: 0.5rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 768px) {
            .excel-format-info {
                padding: 1rem;
            }

            .format-table table {
                min-width: 600px;
            }

            .format-table th,
            .format-table td {
                padding: 0.4rem;
                font-size: 0.8rem;
            }

            .format-rules {
                padding: 1rem;
            }

            .upload-section {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .format-table table {
                min-width: 500px;
            }

            .format-table th,
            .format-table td {
                padding: 0.3rem;
                font-size: 0.75rem;
            }
        }
    </style>
</body>
</html>
