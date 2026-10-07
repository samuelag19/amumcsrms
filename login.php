<?php
require_once __DIR__ . '/includes/session.php';
include 'includes/access_control.php';
require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} 

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_valid_csrf_token();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip_address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $cleanup = $conn->prepare("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    $cleanup->execute();
    $cleanup->close();

    $rate_stmt = $conn->prepare("SELECT COUNT(*) AS attempts FROM login_attempts WHERE username = ? AND ip_address = ? AND attempted_at >= NOW() - INTERVAL 15 MINUTE");
    $rate_stmt->bind_param("ss", $username, $ip_address);
    $rate_stmt->execute();
    $attempts = (int) $rate_stmt->get_result()->fetch_assoc()['attempts'];
    $rate_stmt->close();

    if ($attempts >= 5) {
        $error_message = "Too many unsuccessful attempts. Please try again in 15 minutes.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, password, role, is_active FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password'])) {
            $clear_attempts = $conn->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?");
            $clear_attempts->bind_param("ss", $username, $ip_address);
            $clear_attempts->execute();
            $clear_attempts->close();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];

            $update_login = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $update_login->bind_param("i", $user['user_id']);
            $update_login->execute();
            $update_login->close();

            log_action($conn, $user['user_id'], 'LOGIN', 'USER', $user['user_id'], 'User logged in successfully.');

            $dashboard = [
                'admin' => 'admin_dashboard.php',
                'staff' => 'user_dashboard.php',
                'technician' => 'technician_dashboard.php',
                'superadmin' => 'superadmin_dashboard.php',
            ];
            header('Location: ' . $dashboard[$user['role']]);
            exit;
        }

        $record_attempt = $conn->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
        $record_attempt->bind_param("ss", $username, $ip_address);
        $record_attempt->execute();
        $record_attempt->close();
        $error_message = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMU SRMS - Login</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2a2185;
            --secondary: #1a1666;
            --accent: #ff4444;
            --light: #f5f5f5;
            --dark: #222;
            --gray: #999;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Ubuntu', sans-serif;
        }
        
        body {
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(rgba(42,33,133,0.8), rgba(42,33,133,0.8)), 
                        url('https://www.amu.edu.et/wp-content/uploads/2022/05/AMU-Campus.jpg') no-repeat center center/cover;
        }
        
        .login-container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1;
        }
        
        .login-header {
            background-color: var(--primary);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }
        
        .login-header::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 30px;
            background-color: var(--primary);
            rotate: 45deg;
            z-index: -1;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .logo img {
            height: 50px;
            margin-right: 10px;
        }
        
        .logo h1 {
            font-size: 1.5rem;
        }
        
        .login-body {
            padding: 2.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 42px;
            color: var(--gray);
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 40px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(42,33,133,0.1);
            outline: none;
        }
        
        .btn {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 4px;
            background-color: var(--accent);
            color: white;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }
        
        .btn:hover {
            background-color: #e03e3e;
            transform: translateY(-2px);
        }
        
        .error-message {
            color: var(--accent);
            text-align: center;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--gray);
        }
        
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .login-footer a:hover {
            color: var(--secondary);
        }
        
        @media (max-width: 576px) {
            .login-container {
                margin: 1rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <img src="image/amulogo.png" alt="AMU Logo">
                <h1>AMU SRMS</h1>
            </div>
            <h2>Service Request Management System</h2>
        </div>
        
        <div class="login-body">
            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <div class="login-footer">
                <p><a href="forgot_password.php">Forgot password?</a></p>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>