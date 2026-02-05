<?php
require_once 'includes/config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $message = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];

            logAction('login', 'User logged in');

            // Redirect admins to dashboard, others to index
            if ($user['role'] === 'admin') {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: index.php');
            }
            exit;
        } else {
            $message = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Reverence WorshipTeam</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Custom login page styles */
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-black) 0%, #222 50%, #111 100%);
            position: relative;
            overflow: hidden;
        }

        .login-page::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 215, 0, 0.05) 50%, transparent 70%);
            pointer-events: none;
            animation: backgroundShift 20s ease-in-out infinite;
        }

        @keyframes backgroundShift {
            0%, 100% { transform: translateX(-20px) translateY(-20px); }
            50% { transform: translateX(20px) translateY(20px); }
        }

        .login-card {
            background: var(--primary-white);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            padding: 2.5rem;
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
            border: 3px solid black;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo {
            margin-bottom: 1.5rem;
            position: relative;
        }

        
      
        .login-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2.2rem;
            color: var(--primary-black);
            background: black;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        .login-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--dark-gray);
            font-weight: 400;
            opacity: 0.9;
        }

        .login-form {
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary-black);
            font-size: 0.95rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem 3rem 0.875rem 1rem; /* Extra right padding for eye icon */
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            background: var(--primary-white);
            transition: all 0.3s ease;
            box-sizing: border-box;
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
            box-shadow: 0 0 0 2px black;
        }

        .form-group input:focus {
            outline: none;
            border-color: black;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
            background: #fefefe;
        }

        .form-group input::placeholder {
            color: #adb5bd;
        }

        .login-btn {
            width: 100%;
            background: black;
            color: var(--primary-white);
            border: 2px solid black;
            padding: 0.875rem 1.5rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

       
        

        .login-btn:hover::before {
            left: 100%;
        }

        .login-footer {
            text-align: center;
            border-top: 1px solid #e1e5e9;
            padding-top: 1.5rem;
        }

       

        .login-footer a {
            color: black;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .login-footer a:hover {
            color: var(--primary-black);
        }

        
        
        .credential-item {
            background: var(--primary-white);
            padding: 0.5rem;
            border-radius: 4px;
            border: 1px solid #e1e5e9;
            font-family: monospace;
            font-size: 0.8rem;
        }

        /* Error message styling */
        .login-message {
            background: linear-gradient(135deg, #f8d7da 0%, #fce4e6 100%);
            color: #721c24;
            border: 2px solid #f5c6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 500;
            position: relative;
        }

        .login-message::before {
            content: '⚠️';
            position: absolute;
            left: 1rem;
            top: 1rem;
            font-size: 1.2rem;
        }

        .login-message p {
            margin: 0;
            padding-left: 1.5rem;
        }

        /* Responsive design */
        @media (max-width: 480px) {
            .login-card {
                padding: 2rem 1.5rem;
                margin: 1rem;
            }

            .login-header h1 {
                font-size: 1.8rem;
            }

            .login-header h2 {
                font-size: 1rem;
            }

            .demo-credentials {
                grid-template-columns: 1fr;
            }
        }

        /* Loading animation */
        .login-btn.loading {
            position: relative;
            color: transparent;
        }

        .login-btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-white);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <div class="login-header">
                
                <h1>Reverence WorshipTeam</h1>
                <h2>Gospel Choir Management System</h2>
            </div>


            <?php if ($message): ?>
                <div class="login-message">
                    <p><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="login-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           placeholder="Enter your username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input-container">
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password"
                               required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password')" aria-label="Toggle password visibility">
                            👁️
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                     Sign In 
                </button>
            </form>

            <div class="login-footer">
                <p><a href="index.php">← Back to Homepage</a></p>
                
            </div>
        </div>
    </div>

    <script src="js/main.js"></script>
    <script>
        // Add loading state to login button
        document.querySelector('.login-form').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.textContent = 'Signing In...';
        });

        // Auto-focus username field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (!usernameField.value) {
                usernameField.focus();
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
