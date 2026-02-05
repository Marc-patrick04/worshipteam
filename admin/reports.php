<?php
require_once '../includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit;
}

// Handle AJAX requests for group movement details
if (isset($_GET['action']) && $_GET['action'] === 'get_group_movements') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $groupDate = $_GET['date'] ?? '';

    if ($groupId && $groupDate) {
        // Get group info
        $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ? AND service_date = ?");
        $stmt->execute([$groupId, $groupDate]);
        $group = $stmt->fetch();

        if ($group) {
            // Get current singers in this group
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.voice_category, s.voice_level
                FROM group_assignments ga
                JOIN singers s ON ga.singer_id = s.id
                WHERE ga.group_id = ?
            ");
            $stmt->execute([$groupId]);
            $currentSingers = $stmt->fetchAll();

            // Get previous group data (from the day before or last group before this date)
            $stmt = $pdo->prepare("
                SELECT g.id, g.name, g.service_date
                FROM groups g
                WHERE g.service_date < ?
                ORDER BY g.service_date DESC
                LIMIT 1
            ");
            $stmt->execute([$groupDate]);
            $previousGroup = $stmt->fetch();

            $addedSingers = [];
            $removedSingers = [];
            $transferredSingers = [];

            if ($previousGroup) {
                // Get singers from previous group
                $stmt = $pdo->prepare("
                    SELECT s.id, s.full_name, s.voice_category, s.voice_level
                    FROM group_assignments ga
                    JOIN singers s ON ga.singer_id = s.id
                    WHERE ga.group_id = ?
                ");
                $stmt->execute([$previousGroup['id']]);
                $previousSingers = $stmt->fetchAll();

                $previousSingerIds = array_column($previousSingers, 'id');
                $currentSingerIds = array_column($currentSingers, 'id');

                // Find added singers (in current but not in previous)
                foreach ($currentSingers as $singer) {
                    if (!in_array($singer['id'], $previousSingerIds)) {
                        $addedSingers[] = $singer;
                    }
                }

                // Find removed singers (in previous but not in current)
                foreach ($previousSingers as $singer) {
                    if (!in_array($singer['id'], $currentSingerIds)) {
                        $removedSingers[] = $singer;
                    }
                }

                // Find transferred singers (in both but changed groups)
                foreach ($currentSingers as $singer) {
                    if (in_array($singer['id'], $previousSingerIds)) {
                        // Check if they were in a different group
                        $stmt = $pdo->prepare("
                            SELECT g.name as group_name
                            FROM group_assignments ga
                            JOIN groups g ON ga.group_id = g.id
                            WHERE ga.singer_id = ? AND g.service_date = ?
                        ");
                        $stmt->execute([$singer['id'], $groupDate]);
                        $currentGroupForSinger = $stmt->fetch();

                        $stmt = $pdo->prepare("
                            SELECT g.name as group_name
                            FROM group_assignments ga
                            JOIN groups g ON ga.group_id = g.id
                            WHERE ga.singer_id = ? AND g.service_date = ?
                        ");
                        $stmt->execute([$singer['id'], $previousGroup['service_date']]);
                        $previousGroupForSinger = $stmt->fetch();

                        if ($currentGroupForSinger && $previousGroupForSinger &&
                            $currentGroupForSinger['group_name'] !== $previousGroupForSinger['group_name']) {
                            $transferredSingers[] = [
                                'singer' => $singer,
                                'from_group' => $previousGroupForSinger['group_name'],
                                'to_group' => $currentGroupForSinger['group_name']
                            ];
                        }
                    }
                }
            } else {
                // This is the first group ever, so all singers are "added"
                $addedSingers = $currentSingers;
            }

            // Output HTML for modal
            ?>
            <div class="group-movement-details">
                <div class="group-info">
                    <h4><?php echo htmlspecialchars($group['name']); ?></h4>
                    <p><strong>Date:</strong> <?php echo date('l, F j, Y', strtotime($group['service_date'])); ?></p>
                    <p><strong>Total Singers:</strong> <?php echo count($currentSingers); ?></p>
                </div>

                <?php if (!empty($addedSingers)): ?>
                <div class="movement-section added-section">
                    <h5 style="color: #28a745;">➕ Added to Group (<?php echo count($addedSingers); ?>)</h5>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Singer Name</th>
                                    <th>Voice Category</th>
                                    <th>Voice Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($addedSingers as $singer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($singer['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($singer['voice_category']); ?></td>
                                    <td><?php echo htmlspecialchars($singer['voice_level']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($removedSingers)): ?>
                <div class="movement-section removed-section">
                    <h5 style="color: #dc3545;">➖ Removed from Group (<?php echo count($removedSingers); ?>)</h5>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Singer Name</th>
                                    <th>Voice Category</th>
                                    <th>Voice Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($removedSingers as $singer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($singer['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($singer['voice_category']); ?></td>
                                    <td><?php echo htmlspecialchars($singer['voice_level']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($transferredSingers)): ?>
                <div class="movement-section transferred-section">
                    <h5 style="color: #ffc107;">↔️ Transferred (<?php echo count($transferredSingers); ?>)</h5>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Singer Name</th>
                                    <th>Voice Category</th>
                                    <th>Voice Level</th>
                                    <th>From → To</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transferredSingers as $transfer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transfer['singer']['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transfer['singer']['voice_category']); ?></td>
                                    <td><?php echo htmlspecialchars($transfer['singer']['voice_level']); ?></td>
                                    <td><?php echo htmlspecialchars($transfer['from_group'] . ' → ' . $transfer['to_group']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($addedSingers) && empty($removedSingers) && empty($transferredSingers)): ?>
                <p>No movements detected for this group. All singers remained stable from the previous group.</p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    exit;
}

$message = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Reverence Worship Team</title>
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
                <a href="singers.php">Manage Singers</a>
                <a href="groups.php">Manage Groups</a>
                <a href="reports.php" class="active">Reports</a>
                <a href="images.php">Manage Images</a>
                <a href="settings.php">Settings</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>

        <div class="admin-main">
            <div class="admin-header">
                <h1>Reports</h1>
            </div>

    <div class="admin-content">
                <h3>Group Reports</h3>

                <!-- Horizontal Navigation -->
                <div class="horizontal-nav">
                    <a href="reports.php" class="nav-tab <?php echo !isset($_GET['report_type']) || $_GET['report_type'] === 'singer' ? 'active' : ''; ?>">
                        🔍 Singer Search
                    </a>
                    <a href="reports.php?report_type=daily" class="nav-tab <?php echo isset($_GET['report_type']) && $_GET['report_type'] === 'daily' ? 'active' : ''; ?>">
                        📅 Daily Reports
                    </a>
                    <a href="reports.php?report_type=monthly" class="nav-tab <?php echo isset($_GET['report_type']) && $_GET['report_type'] === 'monthly' ? 'active' : ''; ?>">
                        📊 Monthly Reports
                    </a>
                    <a href="reports.php?report_type=movements" class="nav-tab <?php echo isset($_GET['report_type']) && $_GET['report_type'] === 'movements' ? 'active' : ''; ?>">
                        📈 Movement History
                    </a>
                    <a href="reports.php?report_type=mixing" class="nav-tab <?php echo isset($_GET['report_type']) && $_GET['report_type'] === 'mixing' ? 'active' : ''; ?>">
                        🔄 Mixing Tracking
                    </a>
                </div>

                <?php $report_type = $_GET['report_type'] ?? 'singer'; ?>

                <?php if ($report_type === 'singer'): ?>
                <!-- Singer Search -->
                <div class="form-container">
                    <h4> Search Singer Assignments</h4>
                    <form method="GET" style="margin-bottom: 2rem;">
                        <input type="hidden" name="report_type" value="singer">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="search_singer">Singer Name:</label>
                                <input type="text" id="search_singer" name="search_singer" placeholder="Enter singer name..." value="<?php echo htmlspecialchars($_GET['search_singer'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn">Search Assignments</button>
                            </div>
                        </div>
                    </form>

                    <?php if (isset($_GET['search_singer']) && !empty($_GET['search_singer'])): ?>
                        <?php
                        $searchName = '%' . $_GET['search_singer'] . '%';
                        $stmt = $pdo->prepare("
                            SELECT s.full_name, s.voice_category, s.voice_level,
                                   g.name as group_name, g.service_date, g.service_order,
                                   g.created_at as assigned_date
                            FROM singers s
                            JOIN group_assignments ga ON s.id = ga.singer_id
                            JOIN groups g ON ga.group_id = g.id
                            WHERE s.full_name ILIKE ?
                            ORDER BY g.service_date DESC, g.service_order
                        ");
                        $stmt->execute([$searchName]);
                        $singerAssignments = $stmt->fetchAll();
                        ?>

                        <h5>Assignments for: "<?php echo htmlspecialchars($_GET['search_singer']); ?>"</h5>
                        <div class="export-actions">
                            <button onclick="previewReport('singer-search-report')" class="btn export-btn preview-btn"> Preview</button>
                            <button onclick="exportToExcel('singer', '<?php echo htmlspecialchars($_GET['search_singer']); ?>')" class="btn export-btn excel-btn">📊 Export Excel</button>
                            <button onclick="downloadReport('singer-search-report', 'Singer_Assignments_<?php echo htmlspecialchars(str_replace(' ', '_', $_GET['search_singer'])); ?>')" class="btn export-btn pdf-btn">📄 Download PDF</button>
                        </div>
                        <?php if (!empty($singerAssignments)): ?>
                            <div id="singer-search-report" class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Singer</th>
                                            <th>Voice</th>
                                            <th>Group</th>
                                            <th>Service Date</th>
                                            <th>Assigned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($singerAssignments as $assignment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($assignment['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($assignment['voice_category'] . ' (' . $assignment['voice_level'] . ')'); ?></td>
                                                <td><?php echo htmlspecialchars($assignment['group_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($assignment['service_date'])); ?></td>
                                                <td><?php echo date('M j, Y H:i', strtotime($assignment['assigned_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>No assignments found for this singer.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php elseif ($report_type === 'daily'): ?>
                <!-- Date Report -->
                <div class="form-container">
                    <h4>📅 Daily Report - Groups for Specific Date</h4>
                    <form method="GET" style="margin-bottom: 2rem;">
                        <input type="hidden" name="report_type" value="daily">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="report_date">Select Date:</label>
                                <input type="date" id="report_date" name="report_date" value="<?php echo htmlspecialchars($_GET['report_date'] ?? date('Y-m-d')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn">Generate Daily Report</button>
                            </div>
                        </div>
                    </form>

                    <?php if (isset($_GET['report_date']) && !empty($_GET['report_date'])): ?>
                        <?php
                        $reportDate = $_GET['report_date'];
                        $stmt = $pdo->prepare("
                            SELECT g.name as group_name, g.service_order,
                                   s.full_name, s.voice_category, s.voice_level
                            FROM groups g
                            JOIN group_assignments ga ON g.id = ga.group_id
                            JOIN singers s ON ga.singer_id = s.id
                            WHERE DATE(g.service_date) = ?
                            ORDER BY g.service_order, g.name, s.voice_category, s.voice_level DESC
                        ");
                        $stmt->execute([$reportDate]);
                        $dateReport = $stmt->fetchAll();

                        // Group by service order
                        $services = [];
                        foreach ($dateReport as $row) {
                            $services[$row['service_order']][] = $row;
                        }
                        ?>

                        <h5>Daily Report for: <?php echo date('l, F j, Y', strtotime($reportDate)); ?></h5>
                        <div class="export-actions">
                            <button onclick="previewReport('daily-report')" class="btn export-btn preview-btn"> Preview</button>
                            <button onclick="exportToExcel('daily', '<?php echo $reportDate; ?>')" class="btn export-btn excel-btn">📊 Export Excel</button>
                            <button onclick="downloadReport('daily-report', 'Daily_Report_<?php echo date('Y-m-d', strtotime($reportDate)); ?>')" class="btn export-btn pdf-btn">📄 Download PDF</button>
                        </div>
                        <?php if (!empty($dateReport)): ?>
                            <div id="daily-report" class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Group</th>
                                            <th>Singer</th>
                                            <th>Voice</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $currentGroup = '';
                                        foreach ($dateReport as $assignment):
                                            $showGroup = $assignment['group_name'] !== $currentGroup;
                                            $currentGroup = $assignment['group_name'];
                                        ?>
                                            <tr>
                                                <td><?php echo $showGroup ? htmlspecialchars($assignment['group_name']) : ''; ?></td>
                                                <td><?php echo htmlspecialchars($assignment['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($assignment['voice_category'] . ' (' . $assignment['voice_level'] . ')'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>No groups found for this date.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php elseif ($report_type === 'monthly'): ?>
                <!-- Monthly Report -->
                <div class="form-container">
                    <h4>📊 Monthly Report - Groups in Selected Month</h4>
                    <form method="GET" style="margin-bottom: 2rem;">
                        <input type="hidden" name="report_type" value="monthly">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="report_month">Select Month:</label>
                                <input type="month" id="report_month" name="report_month" value="<?php echo htmlspecialchars($_GET['report_month'] ?? date('Y-m')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn">Generate Monthly Report</button>
                            </div>
                        </div>
                    </form>

                    <?php if (isset($_GET['report_month']) && !empty($_GET['report_month'])): ?>
                        <?php
                        $reportMonth = $_GET['report_month'];
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT DATE(g.service_date) as service_date,
                                   COUNT(DISTINCT g.id) as group_count,
                                   COUNT(DISTINCT ga.singer_id) as singer_count
                            FROM groups g
                            LEFT JOIN group_assignments ga ON g.id = ga.group_id
                            WHERE TO_CHAR(g.service_date, 'YYYY-MM') = ?
                            GROUP BY DATE(g.service_date)
                            ORDER BY service_date
                        ");
                        $stmt->execute([$reportMonth]);
                        $monthlyStats = $stmt->fetchAll();

                        // Get detailed groups for the month
                        $stmt = $pdo->prepare("
                            SELECT g.name as group_name, DATE(g.service_date) as service_date,
                                   g.service_order, COUNT(ga.singer_id) as singer_count
                            FROM groups g
                            LEFT JOIN group_assignments ga ON g.id = ga.group_id
                            WHERE TO_CHAR(g.service_date, 'YYYY-MM') = ?
                            GROUP BY g.id
                            ORDER BY g.service_date, g.service_order
                        ");
                        $stmt->execute([$reportMonth]);
                        $monthlyGroups = $stmt->fetchAll();
                        ?>

                        <h5>Monthly Report for: <?php echo date('F Y', strtotime($reportMonth . '-01')); ?></h5>

                        <div class="export-actions">
                            <button onclick="previewReport('monthly-report')" class="btn export-btn preview-btn"> Preview</button>
                            <button onclick="exportToExcel('monthly', '<?php echo $reportMonth; ?>')" class="btn export-btn excel-btn">📊 Export Excel</button>
                            <button onclick="downloadReport('monthly-report', 'Monthly_Report_<?php echo date('Y-m', strtotime($reportMonth . '-01')); ?>')" class="btn export-btn pdf-btn">📄 Download PDF</button>
                        </div>

            

                        <!-- Detailed Daily Breakdown -->
                        <?php if (!empty($monthlyStats)): ?>
                            <h6>Detailed Daily Breakdown</h6>
                            <?php
                            // Get detailed daily information
                            $stmt = $pdo->prepare("
                                SELECT DATE(g.service_date) as service_date,
                                       g.name as group_name, g.id as group_id, g.service_order,
                                       s.full_name, s.voice_category, s.voice_level
                                FROM groups g
                                JOIN group_assignments ga ON g.id = ga.group_id
                                JOIN singers s ON ga.singer_id = s.id
                                WHERE TO_CHAR(g.service_date, 'YYYY-MM') = ?
                                ORDER BY g.service_date, g.service_order, g.name, s.voice_category, s.voice_level DESC
                            ");
                            $stmt->execute([$reportMonth]);
                            $detailedDaily = $stmt->fetchAll();

                            // Group by date
                            $dailyDetails = [];
                            foreach ($detailedDaily as $row) {
                                $dailyDetails[$row['service_date']][] = $row;
                            }
                            ?>

                            <?php foreach ($dailyDetails as $date => $dayData): ?>
                                <?php
                                // Group by group for this date
                                $groupsByDate = [];
                                foreach ($dayData as $row) {
                                    $groupsByDate[$row['group_name']][] = $row;
                                }
                                ?>

                                <div class="daily-detail-section" style="margin-bottom: 2rem; border: 1px solid #ddd; border-radius: 8px; padding: 1rem;">
                                    <h6 style="margin-bottom: 1rem; color: #333; border-bottom: 2px solid #ddd; padding-bottom: 0.5rem;">
                                        <?php echo date('l, F j, Y', strtotime($date)); ?>
                                        <small style="font-weight: normal; color: #666;">
                                            (<?php echo count($groupsByDate); ?> groups, <?php echo count($dayData); ?> singers)
                                        </small>
                                    </h6>

                                    <?php foreach ($groupsByDate as $groupName => $singers): ?>
                                        <div class="group-detail" style="margin-bottom: 1.5rem;">
                                            <h6 style="color: #007bff; margin-bottom: 0.5rem; font-size: 1rem;">
                                                Group: <?php echo htmlspecialchars($groupName); ?>
                                                <small style="font-weight: normal; color: #666;">
                                                    (<?php echo count($singers); ?> singers)
                                                </small>
                                            </h6>

                                            <div class="table-container">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Singer Name</th>
                                                            <th>Voice Category</th>
                                                            <th>Voice Level</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($singers as $singer): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($singer['full_name']); ?></td>
                                                                <td><?php echo htmlspecialchars($singer['voice_category']); ?></td>
                                                                <td><?php echo htmlspecialchars($singer['voice_level']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- Summary Table -->
                            <h6 style="margin-top: 2rem;">Monthly Summary Overview</h6>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Groups Created</th>
                                            <th>Singers Assigned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthlyStats as $day): ?>
                                            <tr>
                                                <td><?php echo date('l, M j', strtotime($day['service_date'])); ?></td>
                                                <td><?php echo $day['group_count']; ?></td>
                                                <td><?php echo $day['singer_count']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>No groups found for this month.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php elseif ($report_type === 'movements'): ?>
                <!-- Movement History Report -->
                <div class="form-container">
                    <h4> Movement History - Singer Movements by Group and Date</h4>
                    <p class="description">Click on any group below to see detailed movement history for that group on that date.</p>

                    <?php
                    // Get all groups with their singer counts
                    $stmt = $pdo->query("
                        SELECT g.id, g.name, g.service_date, g.service_order,
                               COUNT(ga.singer_id) as singer_count
                        FROM groups g
                        LEFT JOIN group_assignments ga ON g.id = ga.group_id
                        GROUP BY g.id, g.name, g.service_date, g.service_order
                        ORDER BY g.service_date DESC, g.service_order ASC
                    ");
                    $allGroups = $stmt->fetchAll();

                    // Group by date for display
                    $groupsByDate = [];
                    foreach ($allGroups as $group) {
                        $dateKey = $group['service_date'];
                        $groupsByDate[$dateKey][] = $group;
                    }
                    ?>

                    <h5>Groups by Date - Click to View Movements</h5>

                    <?php if (!empty($groupsByDate)): ?>
                        <div class="export-actions">
                            <button onclick="previewReport('movements-report')" class="btn export-btn preview-btn"> Preview</button>
                            <button onclick="exportToExcel('movements')" class="btn export-btn excel-btn">📊 Export Excel</button>
                            <button onclick="downloadReport('movements-report', 'Movement_History_All_Months')" class="btn export-btn pdf-btn">📄 Download PDF</button>
                        </div>

                        <div id="movements-report" class="movements-report">
                            <?php foreach ($groupsByDate as $date => $dateGroups): ?>
                                <div class="date-section" style="margin-bottom: 2rem; border: 1px solid #ddd; border-radius: 8px; padding: 1rem;">
                                    <h4 style="color: #007bff; margin-bottom: 1rem; border-bottom: 2px solid #007bff; padding-bottom: 0.5rem;">
                                        <?php echo date('l, F j, Y', strtotime($date)); ?>
                                    </h4>

                                    <div class="groups-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                        <?php foreach ($dateGroups as $group): ?>
                                            <div class="group-card" style="border: 1px solid #dee2e6; border-radius: 6px; padding: 1rem; background: #f8f9fa; cursor: pointer;" onclick="showGroupMovements(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name']); ?>', '<?php echo $date; ?>')">
                                                <h5 style="margin: 0 0 0.5rem 0; color: #333;"><?php echo htmlspecialchars($group['name']); ?></h5>
                                                <div style="color: #666; font-size: 0.9rem;">
                                                    <?php echo $group['singer_count']; ?> singers
                                                </div>
                                                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #007bff;">
                                                    Click to view movements
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Modal for group movement details -->
                        <div id="movement-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 2rem; max-width: 90%; max-height: 90%; overflow-y: auto; width: 800px;">
                                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1rem;">
                                    <h3 id="modal-title" style="margin: 0;">Group Movements</h3>
                                    <button onclick="closeMovementModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
                                </div>
                                <div id="modal-content">
                                    <!-- Movement details will be loaded here -->
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <p>No groups found. Create some groups first to see movement history.</p>
                    <?php endif; ?>
                </div>
                <?php elseif ($report_type === 'mixing'): ?>
                <!-- Mixing Tracking Report -->
                <div class="form-container">
                    <h4>🔄 Mixing Tracking - Singer Movement Analysis</h4>
                    <p class="description">Track how singers move between groups over time. Shows movement patterns and group stability.</p>

                    <?php
                    // Get all created groups ordered by date
                    $stmt = $pdo->query("
                        SELECT g.id, g.name, g.service_date, COUNT(ga.singer_id) as singer_count
                        FROM groups g
                        LEFT JOIN group_assignments ga ON g.id = ga.group_id
                        GROUP BY g.id, g.name, g.service_date
                        ORDER BY g.service_date DESC, g.service_order ASC
                    ");
                    $allGroups = $stmt->fetchAll();

                    // Group by date for easier processing
                    $groupsByDate = [];
                    foreach ($allGroups as $group) {
                        $dateKey = $group['service_date'];
                        $groupsByDate[$dateKey][] = $group;
                    }

                    // Sort dates chronologically
                    ksort($groupsByDate);

                    $mixingData = [];
                    $previousAssignments = null;
                    $previousDate = null;

                    foreach ($groupsByDate as $date => $dateGroups) {
                        if ($previousAssignments !== null && $previousDate !== null) {
                            // Compare current with previous
                            $currentAssignments = [];

                            // Get singers for current date groups
                            foreach ($dateGroups as $group) {
                                $stmt = $pdo->prepare("
                                    SELECT ga.singer_id, s.full_name, g.name as group_name
                                    FROM group_assignments ga
                                    JOIN singers s ON ga.singer_id = s.id
                                    JOIN groups g ON ga.group_id = g.id
                                    WHERE ga.group_id = ?
                                ");
                                $stmt->execute([$group['id']]);
                                $currentAssignments[$group['name']] = array_column($stmt->fetchAll(), 'singer_id');
                            }

                            // Calculate movements
                            $movements = [];
                            $groupSummary = [];

                            // For each previous group
                            foreach ($previousAssignments as $prevGroupName => $prevSingers) {
                                $groupSummary[$prevGroupName] = [
                                    'total' => count($prevSingers),
                                    'moved' => 0,
                                    'stayed' => 0
                                ];

                                // Check where each singer went
                                foreach ($prevSingers as $singerId) {
                                    $foundInCurrent = false;
                                    foreach ($currentAssignments as $currGroupName => $currSingers) {
                                        if (in_array($singerId, $currSingers)) {
                                            if ($prevGroupName !== $currGroupName) {
                                                // Singer moved to different group
                                                $movements[] = [
                                                    'date' => $date,
                                                    'from_group' => $prevGroupName,
                                                    'to_group' => $currGroupName,
                                                    'singer_id' => $singerId,
                                                    'moved' => true
                                                ];
                                                $groupSummary[$prevGroupName]['moved']++;
                                            } else {
                                                // Singer stayed in same group
                                                $groupSummary[$prevGroupName]['stayed']++;
                                            }
                                            $foundInCurrent = true;
                                            break;
                                        }
                                    }

                                    if (!$foundInCurrent) {
                                        // Singer not found in current assignments (edge case)
                                        $groupSummary[$prevGroupName]['stayed']++;
                                    }
                                }
                            }

                            $mixingData[] = [
                                'from_date' => $previousDate,
                                'to_date' => $date,
                                'movements' => $movements,
                                'group_summary' => $groupSummary,
                                'total_movements' => count($movements)
                            ];
                        }

                        // Prepare for next iteration
                        $previousAssignments = [];
                        foreach ($dateGroups as $group) {
                            $stmt = $pdo->prepare("
                                SELECT ga.singer_id
                                FROM group_assignments ga
                                WHERE ga.group_id = ?
                            ");
                            $stmt->execute([$group['id']]);
                            $previousAssignments[$group['name']] = array_column($stmt->fetchAll(), 'singer_id');
                        }
                        $previousDate = $date;
                    }
                    ?>

                    <h5>Mixing Tracking Report</h5>

                    <?php if (!empty($mixingData)): ?>
                        <div id="mixing-report" class="mixing-report">
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>From Group</th>
                                            <th>To Group</th>
                                            <th>Moved</th>
                                            <th>Not Moved</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $allMovementRows = [];
                                        foreach ($mixingData as $period) {
                                            if (!empty($period['movements'])) {
                                                // Group movements by from/to combination for this period
                                                $movementSummary = [];
                                                foreach ($period['movements'] as $movement) {
                                                    $key = $movement['from_group'] . '→' . $movement['to_group'];
                                                    if (!isset($movementSummary[$key])) {
                                                        $movementSummary[$key] = [
                                                            'from' => $movement['from_group'],
                                                            'to' => $movement['to_group'],
                                                            'moved' => 0
                                                        ];
                                                    }
                                                    $movementSummary[$key]['moved']++;
                                                }

                                                // Calculate not moved for each from group
                                                foreach ($movementSummary as $key => $summary) {
                                                    $fromGroup = $summary['from'];
                                                    $totalInGroup = $period['group_summary'][$fromGroup]['total'];
                                                    $movedFromGroup = array_sum(array_column(array_filter($movementSummary, fn($m) => $m['from'] === $fromGroup), 'moved'));
                                                    $movementSummary[$key]['not_moved'] = $totalInGroup - $movedFromGroup;
                                                }

                                                // Add to consolidated data after all calculations
                                                foreach ($movementSummary as $key => $summary) {
                                                    $dateLabel = date('d/m/Y', strtotime($period['from_date'])) . ' → ' . date('d/m/Y', strtotime($period['to_date']));
                                                    $allMovementRows[] = [
                                                        'date' => $dateLabel,
                                                        'from' => $summary['from'],
                                                        'to' => $summary['to'],
                                                        'moved' => $summary['moved'],
                                                        'not_moved' => $summary['not_moved']
                                                    ];
                                                }
                                            }
                                        }

                                        // Sort by date descending
                                        usort($allMovementRows, function($a, $b) {
                                            return strtotime(explode(' → ', $b['date'])[0]) - strtotime(explode(' → ', $a['date'])[0]);
                                        });

                                        foreach ($allMovementRows as $row):
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['date']); ?></td>
                                                <td><?php echo htmlspecialchars($row['from']); ?></td>
                                                <td><?php echo htmlspecialchars($row['to']); ?></td>
                                                <td><?php echo $row['moved']; ?></td>
                                                <td><?php echo $row['not_moved']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                           
                            
                        </div>
                    <?php else: ?>
                        <p>No mixing data available. Need at least two created group dates to track movements.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <style>
                    /* Override form container width for reports page */
                    .form-container {
                        max-width: 100% !important;
                        width: 100% !important;
                    }

                    .service-report {
                        margin-bottom: 2rem;
                        padding: 1rem;
                        border: 1px solid #ddd;
                        border-radius: 8px;
                    }

                    .service-report h6 {
                        margin-bottom: 1rem;
                        color: #333;
                        border-bottom: 2px solid #ddd;
                        padding-bottom: 0.5rem;
                    }

                    .monthly-summary {
                        background: #f8f9fa;
                        padding: 1.5rem;
                        border-radius: 8px;
                        border: 1px solid #e9ecef;
                    }

                    .summary-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                        gap: 1rem;
                        margin-top: 1rem;
                    }

                    .summary-item {
                        background: white;
                        padding: 1rem;
                        border-radius: 6px;
                        text-align: center;
                        border: 1px solid #dee2e6;
                    }
                </style>
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
                <h4>Report Types</h4>
                <p>• Singer Assignment History</p>
                <p>• Daily Group Reports</p>
                <p>• Monthly Activity Summaries</p>
                <p>• Mixing Tracking Analysis</p>
                <p>• Performance Analytics</p>
            </div>

            <div class="footer-section">
                <h4>Navigation</h4>
                <p><a href="groups.php">Manage Groups</a></p>
                <p><a href="singers.php">View Singers</a></p>
                <p><a href="dashboard.php">← Back to Dashboard</a></p>
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
        function previewReport(reportId) {
            // Get the report content
            const reportElement = document.getElementById(reportId);
            if (!reportElement) {
                alert('Report content not found. Please generate a report first.');
                return;
            }

            // Get the report title
            const titleElement = reportElement.previousElementSibling;
            const reportTitle = titleElement ? titleElement.textContent : 'Report';

            // Get search criteria based on report type
            let criteriaText = '';

            if (reportType === 'singer') {
                const searchName = urlParams.get('search_singer');
                if (searchName) {
                    criteriaText = `Singer Name: ${searchName}`;
                }
            } else if (reportType === 'daily') {
                const reportDate = urlParams.get('report_date');
                if (reportDate) {
                    const date = new Date(reportDate);
                    criteriaText = `Selected Date: ${date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}`;
                }
            } else if (reportType === 'monthly') {
                const reportMonth = urlParams.get('report_month');
                if (reportMonth) {
                    const date = new Date(reportMonth + '-01');
                    criteriaText = `Selected Month: ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' })}`;
                }
            }

            // Collect all content for the report
            let fullReportContent = '';

            if (reportType === 'monthly') {
                // For monthly reports, include everything: summary + daily details + final table
                const monthlyContainer = document.querySelector('.form-container');
                if (monthlyContainer) {
                    // Clone and modify the content
                    const clonedContainer = monthlyContainer.cloneNode(true);

                    // Remove export actions from clone
                    const exportActions = clonedContainer.querySelectorAll('.export-actions');
                    exportActions.forEach(action => action.remove());

                    fullReportContent = clonedContainer.innerHTML;
                }
            } else {
                // For other reports, just use the specific report element
                fullReportContent = reportElement.outerHTML;
            }

            // Create print-friendly HTML
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${reportTitle} - Preview</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            margin: 20px;
                            line-height: 1.6;
                        }
                        h1, h2, h3, h4, h5, h6 {
                            color: #333;
                            margin-bottom: 10px;
                            page-break-after: avoid;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        th, td {
                            border: 1px solid #ddd;
                            padding: 8px;
                            text-align: left;
                            font-size: 12px;
                        }
                        th {
                            background-color: #f2f2f2;
                            font-weight: bold;
                        }
                        .summary-grid {
                            display: grid;
                            grid-template-columns: repeat(3, 1fr);
                            gap: 20px;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        .summary-item {
                            text-align: center;
                            padding: 15px;
                            border: 1px solid #ddd;
                            background: #f9f9f9;
                        }
                        .report-criteria {
                            background: #e9ecef;
                            padding: 10px 15px;
                            margin: 15px 0;
                            border-left: 4px solid #007bff;
                            font-weight: bold;
                            color: #495057;
                            page-break-inside: avoid;
                        }
                        .daily-detail-section {
                            margin-bottom: 30px;
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            padding: 15px;
                            page-break-inside: avoid;
                        }
                        .group-detail {
                            margin-bottom: 20px;
                        }
                        .monthly-summary {
                            background: #f8f9fa;
                            padding: 1.5rem;
                            border-radius: 8px;
                            border: 1px solid #e9ecef;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        .export-actions {
                            display: none !important;
                        }
                        .print-button {
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            background: #007bff;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 5px;
                            cursor: pointer;
                            font-size: 14px;
                        }
                        .print-button:hover {
                            background: #0056b3;
                        }
                        @media print {
                            body { margin: 0; font-size: 12px; }
                            .export-actions { display: none !important; }
                            .print-button { display: none !important; }
                            h1 { font-size: 24px; }
                            h2 { font-size: 20px; }
                            h3 { font-size: 18px; }
                            h4 { font-size: 16px; }
                            h5 { font-size: 14px; }
                            h6 { font-size: 13px; }
                            table { font-size: 11px; }
                            .summary-item { font-size: 12px; }
                        }
                    </style>
                </head>
                <body>
                    <button class="print-button" onclick="window.print()">🖨️ Print/Save as PDF</button>
                    <h1>Reverence WorshipTeam - ${reportTitle}</h1>
                    <p>Generated on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                    ${criteriaText ? `<div class="report-criteria">${criteriaText}</div>` : ''}
                    ${fullReportContent}
                </body>
                </html>
            `;

            // Open in new window for preview
            const previewWindow = window.open('', '_blank');
            previewWindow.document.write(printContent);
            previewWindow.document.close();
        }

        function exportToExcel(reportType, param = '') {
            // Get the table data based on report type
            let tableData = [];
            let fileName = '';

            if (reportType === 'singer') {
                const table = document.querySelector('#singer-search-report table');
                if (!table) {
                    alert('No data to export. Please generate a report first.');
                    return;
                }

                // Extract table headers
                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());

                // Extract table rows
                const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => {
                    return Array.from(tr.querySelectorAll('td')).map(td => {
                        // Clean up any HTML and extra whitespace
                        return td.textContent.replace(/\s+/g, ' ').trim();
                    });
                });

                tableData = [headers, ...rows];
                fileName = `Singer_Assignments_${param.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`;

            } else if (reportType === 'daily') {
                const table = document.querySelector('#daily-report table');
                if (!table) {
                    alert('No data to export. Please generate a report first.');
                    return;
                }

                // Extract table headers
                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());

                // Extract table rows
                const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => {
                    return Array.from(tr.querySelectorAll('td')).map(td => {
                        // For daily reports, skip empty group cells
                        const text = td.textContent.replace(/\s+/g, ' ').trim();
                        return text || '';
                    });
                });

                tableData = [headers, ...rows];
                fileName = `Daily_Report_${param}_${new Date().toISOString().split('T')[0]}.csv`;

            } else if (reportType === 'monthly') {
                // For monthly reports, export ALL singer data from daily detail tables
                const detailTables = document.querySelectorAll('.form-container .daily-detail-section .table-container table');
                if (!detailTables || detailTables.length === 0) {
                    alert('No detailed data to export. Please generate a report first.');
                    return;
                }

                // Collect all singer data from daily detail tables
                let allSingerData = [];
                const headers = ['Date', 'Group', 'Singer Name', 'Voice Category', 'Voice Level'];

                detailTables.forEach(table => {
                    // Find the date from the parent section
                    const dailySection = table.closest('.daily-detail-section');
                    const dateHeading = dailySection.querySelector('h6');
                    let sectionDate = '';
                    if (dateHeading) {
                        const dateText = dateHeading.textContent.trim();
                        // Extract date from "Saturday, January 18, 2025" format
                        const dateMatch = dateText.match(/(\w+,\s+\w+\s+\d+,\s+\d{4})/);
                        sectionDate = dateMatch ? dateMatch[1] : dateText;
                    }

                    // Find the group name from the parent group-detail
                    const groupDetail = table.closest('.group-detail');
                    let groupName = '';
                    if (groupDetail) {
                        const groupHeading = groupDetail.querySelector('h6');
                        if (groupHeading) {
                            // Extract group name from "Group: Group Name (X singers)" format
                            const groupMatch = groupHeading.textContent.match(/Group:\s*([^(\n]+)/);
                            groupName = groupMatch ? groupMatch[1].trim() : groupHeading.textContent.trim();
                        }
                    }

                    // Extract singer data from this table
                    const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => {
                        const cells = Array.from(tr.querySelectorAll('td')).map(td => {
                            return td.textContent.replace(/\s+/g, ' ').trim();
                        });

                        if (cells.length >= 3) {
                            return [
                                sectionDate,           // Date
                                groupName,             // Group
                                cells[0] || '',        // Singer Name
                                cells[1] || '',        // Voice Category
                                cells[2] || ''         // Voice Level
                            ];
                        }
                        return null;
                    }).filter(row => row !== null);

                    allSingerData = allSingerData.concat(rows);
                });

                if (allSingerData.length === 0) {
                    alert('No singer data found to export.');
                    return;
                }

                tableData = [headers, ...allSingerData];
                fileName = `Monthly_Detailed_Report_${param}_${new Date().toISOString().split('T')[0]}.csv`;

            } else if (reportType === 'movements') {
                // For movements report, collect data from all groups via AJAX
                const groupCards = document.querySelectorAll('#movements-report .group-card');
                if (!groupCards || groupCards.length === 0) {
                    alert('No groups found to export movement data from.');
                    return;
                }

                // Show loading message
                alert('Collecting movement data from all groups. This may take a moment...');

                // Collect all movement data asynchronously
                let allMovementData = [];
                const headers = ['Date', 'Group', 'Movement Type', 'Singer Name', 'Voice Category', 'Voice Level', 'From Group', 'To Group', 'Notes'];
                let completedRequests = 0;
                const totalRequests = groupCards.length;

                function processMovementData(groupId, groupName, groupDate, data) {
                    // Parse the HTML response to extract movement data
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data, 'text/html');

                    // Extract added singers using specific class
                    const addedSection = doc.querySelector('.added-section');
                    if (addedSection) {
                        const rows = addedSection.querySelectorAll('tbody tr');
                        rows.forEach(row => {
                            const cells = row.querySelectorAll('td');
                            if (cells.length >= 3) {
                                allMovementData.push([
                                    new Date(groupDate).toLocaleDateString('en-US'), // Date
                                    groupName,                                      // Group
                                    'Added',                                        // Movement Type
                                    cells[0].textContent.trim(),                   // Singer Name
                                    cells[1].textContent.trim(),                   // Voice Category
                                    cells[2].textContent.trim(),                   // Voice Level
                                    '',                                             // From Group
                                    groupName,                                     // To Group
                                    'New singer added to group'                    // Notes
                                ]);
                            }
                        });
                    }

                    // Extract removed singers using specific class
                    const removedSection = doc.querySelector('.removed-section');
                    if (removedSection) {
                        const rows = removedSection.querySelectorAll('tbody tr');
                        rows.forEach(row => {
                            const cells = row.querySelectorAll('td');
                            if (cells.length >= 3) {
                                allMovementData.push([
                                    new Date(groupDate).toLocaleDateString('en-US'), // Date
                                    groupName,                                      // Group
                                    'Removed',                                     // Movement Type
                                    cells[0].textContent.trim(),                   // Singer Name
                                    cells[1].textContent.trim(),                   // Voice Category
                                    cells[2].textContent.trim(),                   // Voice Level
                                    groupName,                                     // From Group
                                    '',                                             // To Group
                                    'Singer removed from group'                    // Notes
                                ]);
                            }
                        });
                    }

                    // Extract transferred singers using specific class
                    const transferredSection = doc.querySelector('.transferred-section');
                    if (transferredSection) {
                        const rows = transferredSection.querySelectorAll('tbody tr');
                        rows.forEach(row => {
                            const cells = row.querySelectorAll('td');
                            if (cells.length >= 4) {
                                const fromTo = cells[3].textContent.trim().split(' → ');
                                allMovementData.push([
                                    new Date(groupDate).toLocaleDateString('en-US'), // Date
                                    groupName,                                      // Group
                                    'Transferred',                                 // Movement Type
                                    cells[0].textContent.trim(),                   // Singer Name
                                    cells[1].textContent.trim(),                   // Voice Category
                                    cells[2].textContent.trim(),                   // Voice Level
                                    fromTo[0] || '',                               // From Group
                                    fromTo[1] || '',                               // To Group
                                    'Singer transferred between groups'            // Notes
                                ]);
                            }
                        });
                    }

                    completedRequests++;
                    if (completedRequests === totalRequests) {
                        // All requests completed, create CSV
                        if (allMovementData.length === 0) {
                            alert('No movement data found to export.');
                            return;
                        }

                        tableData = [headers, ...allMovementData];
                        fileName = `Movement_History_All_Groups_${new Date().toISOString().split('T')[0]}.csv`;

                        // Convert to CSV
                        const csvContent = tableData.map(row =>
                            row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')
                        ).join('\n');

                        // Create download link
                        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement('a');
                        const url = URL.createObjectURL(blob);
                        link.setAttribute('href', url);
                        link.setAttribute('download', fileName);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        // Clean up
                        URL.revokeObjectURL(url);
                        document.body.removeChild(link);
                    }
                }

                // Make AJAX requests for all groups
                groupCards.forEach(card => {
                    const onclickAttr = card.getAttribute('onclick');
                    if (onclickAttr) {
                        // Extract groupId, groupName, and groupDate from onclick attribute
                        const match = onclickAttr.match(/showGroupMovements\((\d+),\s*'([^']+)',\s*'([^']+)'\)/);
                        if (match) {
                            const groupId = match[1];
                            const groupName = match[2];
                            const groupDate = match[3];

                            fetch(`reports.php?action=get_group_movements&group_id=${groupId}&date=${groupDate}`)
                                .then(response => response.text())
                                .then(data => {
                                    processMovementData(groupId, groupName, groupDate, data);
                                })
                                .catch(error => {
                                    console.error(`Error loading data for ${groupName}:`, error);
                                    completedRequests++;
                                });
                        } else {
                            completedRequests++;
                        }
                    } else {
                        completedRequests++;
                    }
                });
                return; // Exit early since we're handling this asynchronously

            } else if (reportType === 'mixing') {
                const table = document.querySelector('#mixing-report table');
                if (!table) {
                    alert('No data to export. Please generate a report first.');
                    return;
                }

                // Extract table headers
                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());

                // Extract table rows
                const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => {
                    return Array.from(tr.querySelectorAll('td')).map(td => {
                        return td.textContent.replace(/\s+/g, ' ').trim();
                    });
                });

                tableData = [headers, ...rows];
                fileName = `Mixing_Tracking_Report_${new Date().toISOString().split('T')[0]}.csv`;
            }

            if (tableData.length === 0) {
                alert('No data available for export.');
                return;
            }

            // Convert to CSV
            const csvContent = tableData.map(row =>
                row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')
            ).join('\n');

            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', fileName);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Clean up
            URL.revokeObjectURL(url);
            document.body.removeChild(link);
        }

        function showGroupMovements(groupId, groupName, groupDate) {
            // Show modal
            document.getElementById('movement-modal').style.display = 'block';
            document.getElementById('modal-title').textContent = `Movements for ${groupName} - ${new Date(groupDate).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}`;

            // Load movement data via AJAX
            fetch(`reports.php?action=get_group_movements&group_id=${groupId}&date=${groupDate}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('modal-content').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('modal-content').innerHTML = '<p>Error loading movement data. Please try again.</p>';
                    console.error('Error:', error);
                });
        }

        function closeMovementModal() {
            document.getElementById('movement-modal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('movement-modal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        function downloadReport(reportId, fileName) {
            // Get the report content
            const urlParams = new URLSearchParams(window.location.search);
            const reportType = urlParams.get('report_type') || 'singer';

            let reportElement = null;
            if (reportType !== 'monthly') {
                reportElement = document.getElementById(reportId);
                if (!reportElement) {
                    alert('Report content not found. Please generate a report first.');
                    return;
                }
            }

            // Get the report title
            let titleElement = null;
            if (reportElement) {
                titleElement = reportElement.previousElementSibling;
            } else if (reportType === 'monthly') {
                // For monthly, find the h5 title
                titleElement = document.querySelector('.form-container h5');
            }
            const reportTitle = titleElement ? titleElement.textContent : 'Report';



            if (reportType === 'singer') {
                const searchName = urlParams.get('search_singer');
                if (searchName) {
                    criteriaText = `Singer Name: ${searchName}`;
                }
            } else if (reportType === 'daily') {
                const reportDate = urlParams.get('report_date');
                if (reportDate) {
                    const date = new Date(reportDate);
                    criteriaText = `Selected Date: ${date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}`;
                }
            } else if (reportType === 'monthly') {
                const reportMonth = urlParams.get('report_month');
                if (reportMonth) {
                    const date = new Date(reportMonth + '-01');
                    criteriaText = `Selected Month: ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' })}`;
                }
            }

            // Collect all content for the report with consistent formatting
            let fullReportContent = '';

            if (reportType === 'monthly') {
                // For monthly reports, include everything: summary + daily details + final table
                const monthlyContainer = document.querySelector('.form-container');
                if (monthlyContainer) {
                    // Clone and modify the content
                    const clonedContainer = monthlyContainer.cloneNode(true);

                    // Remove export actions from clone
                    const exportActions = clonedContainer.querySelectorAll('.export-actions');
                    exportActions.forEach(action => action.remove());

                    fullReportContent = clonedContainer.innerHTML;
                }
            } else {
                // For other reports, wrap in consistent monthly-style formatting
                if (reportElement) {
                    // Create a container with monthly report styling
                    const reportContainer = document.createElement('div');
                    reportContainer.className = 'monthly-report-container';

                    // Add a summary section like monthly reports
                    const summaryDiv = document.createElement('div');
                    summaryDiv.className = 'monthly-summary';
                    summaryDiv.innerHTML = `
                        <h6>Report Summary</h6>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <strong>Report Type:</strong> ${reportType.charAt(0).toUpperCase() + reportType.slice(1)} Report
                            </div>
                            <div class="summary-item">
                                <strong>Generated:</strong> ${new Date().toLocaleDateString()}
                            </div>
                            <div class="summary-item">
                                <strong>Records:</strong> ${reportElement.querySelectorAll('tbody tr').length}
                            </div>
                        </div>
                    `;

                    // Add the report content in a daily-detail-section style
                    const detailSection = document.createElement('div');
                    detailSection.className = 'daily-detail-section';
                    detailSection.style.marginBottom = '2rem';
                    detailSection.style.border = '1px solid #ddd';
                    detailSection.style.borderRadius = '8px';
                    detailSection.style.padding = '1rem';

                    const reportTitle = document.createElement('h6');
                    reportTitle.style.marginBottom = '1rem';
                    reportTitle.style.color = '#333';
                    reportTitle.style.borderBottom = '2px solid #ddd';
                    reportTitle.style.paddingBottom = '0.5rem';
                    reportTitle.textContent = reportTitle;

                    detailSection.appendChild(reportTitle);
                    detailSection.appendChild(reportElement.cloneNode(true));

                    reportContainer.appendChild(summaryDiv);
                    reportContainer.appendChild(detailSection);

                    fullReportContent = reportContainer.innerHTML;
                }
            }

            // Create print-friendly HTML for PDF generation
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${reportTitle}</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            margin: 20px;
                            line-height: 1.6;
                        }
                        h1, h2, h3, h4, h5, h6 {
                            color: #333;
                            margin-bottom: 10px;
                            page-break-after: avoid;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        th, td {
                            border: 1px solid #ddd;
                            padding: 8px;
                            text-align: left;
                            font-size: 12px;
                        }
                        th {
                            background-color: #f2f2f2;
                            font-weight: bold;
                        }
                        .summary-grid {
                            display: grid;
                            grid-template-columns: repeat(3, 1fr);
                            gap: 20px;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        .summary-item {
                            text-align: center;
                            padding: 15px;
                            border: 1px solid #ddd;
                            background: #f9f9f9;
                        }
                        .report-criteria {
                            background: #e9ecef;
                            padding: 10px 15px;
                            margin: 15px 0;
                            border-left: 4px solid #007bff;
                            font-weight: bold;
                            color: #495057;
                            page-break-inside: avoid;
                        }
                        .daily-detail-section {
                            margin-bottom: 30px;
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            padding: 15px;
                            page-break-inside: avoid;
                        }
                        .group-detail {
                            margin-bottom: 20px;
                        }
                        .monthly-summary {
                            background: #f8f9fa;
                            padding: 1.5rem;
                            border-radius: 8px;
                            border: 1px solid #e9ecef;
                            margin: 20px 0;
                            page-break-inside: avoid;
                        }
                        .export-actions {
                            display: none !important;
                        }
                        @media print {
                            body { margin: 0; font-size: 12px; }
                            .export-actions { display: none !important; }
                            h1 { font-size: 24px; }
                            h2 { font-size: 20px; }
                            h3 { font-size: 18px; }
                            h4 { font-size: 16px; }
                            h5 { font-size: 14px; }
                            h6 { font-size: 13px; }
                            table { font-size: 11px; }
                            .summary-item { font-size: 12px; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Reverence WorshipTeam - ${reportTitle}</h1>
                    <p>Generated on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                    ${criteriaText ? `<div class="report-criteria">${criteriaText}</div>` : ''}
                    ${fullReportContent}
                </body>
                </html>
            `;

            // Temporarily replace page content for printing
            const originalContent = document.body.innerHTML;
            const originalTitle = document.title;

            document.body.innerHTML = printContent;
            document.title = `${reportTitle} - PDF`;

            // Trigger print dialog
            window.print();

            // Restore original content after a short delay
            setTimeout(() => {
                document.body.innerHTML = originalContent;
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</body>
</html>

<?php
function getServiceTimeDisplay($serviceOrder) {
    $suffixes = ['th', 'st', 'nd', 'rd'];
    $value = $serviceOrder % 100;

    if ($value >= 11 && $value <= 13) {
        $suffix = 'th';
    } else {
        $suffix = $suffixes[$value % 10] ?? 'th';
    }

    return $serviceOrder . $suffix . ' Service';
}
?>
