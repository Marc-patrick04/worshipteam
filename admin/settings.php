<?php
require_once '../includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_credentials'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newUsername = trim($_POST['new_username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Get current user data
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (empty($newUsername)) {
            $error = 'New username cannot be empty.';
        } elseif (strlen($newUsername) < 3) {
            $error = 'Username must be at least 3 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif (!empty($newPassword) && strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters long.';
        } else {
            try {
                $pdo->beginTransaction();

                // Check if new username is already taken by another user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$newUsername, $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $error = 'Username is already taken by another user.';
                } else {
                    // Update username
                    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                    $stmt->execute([$newUsername, $_SESSION['user_id']]);

                    // Update password if provided
                    if (!empty($newPassword)) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                    }

                    $pdo->commit();

                    // Update session username
                    $_SESSION['username'] = $newUsername;

                    $message = 'Account credentials updated successfully!';
                    logAction('update_credentials', 'Updated account username and/or password');
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Error updating credentials: ' . $e->getMessage();
            }
        }
    }
}

// Get current user info
$stmt = $pdo->prepare("SELECT username, role, created_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Reverence Worship Team</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .settings-container {
            max-width: 600px;
            margin: 2rem auto;
        }

        .settings-section {
            background: var(--primary-white);
            border: 2px solid var(--border-gray);
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .settings-section h3 {
            color: var(--primary-black);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent-yellow);
            font-size: 1.4rem;
        }

        .current-info {
            background: rgba(255, 215, 0, 0.1);
            border: 1px solid var(--accent-yellow);
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-label {
            font-weight: 600;
            color: var(--primary-black);
        }

        .info-value {
            color: var(--dark-gray);
        }

        .credentials-form .form-group {
            margin-bottom: 1.5rem;
        }

        .credentials-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary-black);
        }

        .credentials-form input {
            width: 100%;
            padding: 0.75rem;
            padding-right: 3rem; /* Space for eye icon */
            border: 2px solid var(--border-gray);
            border-radius: 6px;
            font-size: 1rem;
            background: var(--primary-white);
            transition: all 0.3s ease;
        }

        .password-input-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--dark-gray);
            font-size: 1.2rem;
            padding: 0.25rem;
            border-radius: 3px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: var(--primary-black);
            background: rgba(255, 215, 0, 0.1);
        }

        .password-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.3);
        }

        .credentials-form input:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }

        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            display: none;
        }

        .password-strength.weak {
            color: #dc3545;
        }

        .password-strength.medium {
            color: #ffc107;
        }

        .password-strength.strong {
            color: #28a745;
        }

        .security-tips {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid #28a745;
            border-radius: 6px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .security-tips h4 {
            color: #155724;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .security-tips ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .security-tips li {
            margin-bottom: 0.25rem;
            color: #155724;
            font-size: 0.9rem;
        }

        .update-btn {
            background: linear-gradient(135deg, var(--primary-black) 0%, #333 100%);
            color: var(--primary-white);
            border: 2px solid var(--accent-yellow);
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .update-btn:hover {      
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 215, 0, 0.4);
        }

        .warning-box {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .warning-box p {
            margin: 0;
            color: #856404;
            font-weight: 500;
        }
    </style>
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
                <a href="reports.php">Reports</a>
                <a href="images.php">Manage Images</a>
                <a href="settings.php" class="active">Settings</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>

        <div class="admin-main">
            <div class="admin-header">
                <h1>Account Settings</h1>
            </div>

            <div class="admin-content">
                <?php if ($message): ?>
                    <div class="message success">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="message error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="settings-container">
                    

                    <!-- Change Credentials -->
                    <div class="settings-section">
                        <h3> Change Account Credentials</h3>

                        <div class="warning-box">
                            <p>⚠️ <strong>Important:</strong> Changing your credentials will require you to log in again with the new information.</p>
                        </div>

                        <form method="POST" class="credentials-form">
                            <div class="form-group">
                                <label for="current_password">Current Password *</label>
                                <div class="password-input-container">
                                    <input type="password" id="current_password" name="current_password"
                                           placeholder="Enter your current password" required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('current_password')" aria-label="Toggle password visibility">
                                        👁️
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="new_username">New Username *</label>
                                <input type="text" id="new_username" name="new_username"
                                       placeholder="Enter new username"
                                       value="<?php echo htmlspecialchars($_POST['new_username'] ?? $currentUser['username']); ?>"
                                       required minlength="3">
                            </div>

                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <div class="password-input-container">
                                    <input type="password" id="new_password" name="new_password"
                                           placeholder="Enter new password (leave blank to keep current)">
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password')" aria-label="Toggle password visibility">
                                        👁️
                                    </button>
                                </div>
                                <div class="password-strength" id="passwordStrength"></div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <div class="password-input-container">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                           placeholder="Confirm new password">
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')" aria-label="Toggle password visibility">
                                        👁️
                                    </button>
                                </div>
                            </div>

                            <div class="security-tips">
                                <h4> Password Security Tips</h4>
                                <ul>
                                    <li>Use at least 8 characters</li>
                                    <li>Include uppercase and lowercase letters</li>
                                    <li>Add numbers and special characters</li>
                                    <li>Avoid common words or personal information</li>
                                </ul>
                            </div>

                            <button type="submit" name="change_credentials" class="update-btn">
                                 Update Account Credentials
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                <img src="../assets/Logo Reverence-Photoroom.png" alt="Reverence WorshipTeam Logo" class="footer-logo">
                <h3>Reverence WorshipTeam</h3>
                <p>Settings - Managing your account security and preferences.</p>
            </div>

            <div class="footer-section footer-scripture">
                <blockquote>
                    <p><strong>Psalm 96:7-9</strong></p>
                    <p>Give praise to the Lord, you who belong to all peoples, give glory to him and take up his praise.</p>
                </blockquote>
            </div>

            <div class="footer-section">
                <h4>Admin Links</h4>
                <p><a href="dashboard.php">Dashboard</a></p>
                <p><a href="singers.php">Manage Singers</a></p>
                <p><a href="groups.php">Manage Groups</a></p>
                <p><a href="settings.php">Settings</a></p>
            </div>

            <div class="footer-section">
                <h4>Security</h4>
                <p>• Change password regularly</p>
                <p>• Use strong passwords</p>
                <p>• Keep credentials secure</p>
                <p><a href="../logout.php">Logout</a></p>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="copyright">
                <p>&copy; 2026 Reverence WorshipTeam. All rights reserved.</p>
                <p>Made with <span class="heart">❤️</span> for gospel ministry</p>
            </div>
        </div>
    </footer>

    <script src="../js/main.js"></script>
    <script>
        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthIndicator = document.getElementById('passwordStrength');

            if (password.length === 0) {
                strengthIndicator.style.display = 'none';
                return;
            }

            strengthIndicator.style.display = 'block';

            let strength = 0;
            let feedback = [];

            if (password.length >= 8) strength++;
            else feedback.push('At least 8 characters');

            if (/[a-z]/.test(password)) strength++;
            else feedback.push('Lowercase letter');

            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('Uppercase letter');

            if (/[0-9]/.test(password)) strength++;
            else feedback.push('Number');

            if (/[^A-Za-z0-9]/.test(password)) strength++;
            else feedback.push('Special character');

            strengthIndicator.className = 'password-strength';

            if (strength <= 2) {
                strengthIndicator.classList.add('weak');
                strengthIndicator.textContent = 'Weak password - ' + feedback.slice(0, 2).join(', ');
            } else if (strength <= 4) {
                strengthIndicator.classList.add('medium');
                strengthIndicator.textContent = 'Medium strength - Add: ' + feedback.slice(0, 1).join(', ');
            } else {
                strengthIndicator.classList.add('strong');
                strengthIndicator.textContent = 'Strong password! ✅';
            }
        });

        // Confirm password validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;

            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Password visibility toggle function
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const toggleBtn = input.parentElement.querySelector('.password-toggle');

            if (input.type === 'password') {
                input.type = 'text';
                toggleBtn.textContent = '🙈'; // Closed eye when visible
                toggleBtn.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                toggleBtn.textContent = '👁️'; // Open eye when hidden
                toggleBtn.setAttribute('aria-label', 'Show password');
            }
        }
    </script>
</body>
</html>
