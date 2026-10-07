<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error_message = "";
$success_message = "";

// Handle forgot password request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['forgot_password'])) {
    require_valid_csrf_token();
    $username = trim($_POST['username']);
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_id = $row['user_id'];
        
        // Store the reset request in the database
        $stmt = $conn->prepare("INSERT INTO password_reset_requests (user_id, requested_at) VALUES (?, NOW())");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Add a notification for the superadmin
        $notification_message = "Password reset request submitted for username: $username.";
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES ((SELECT user_id FROM users WHERE role = 'superadmin' LIMIT 1), ?, NOW())");
        $stmt->bind_param("s", $notification_message);
        $stmt->execute();
        
        $success_message = "Request submitted. An administrator will reset your password.";
    } else {
        $error_message = "Username not found.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMU SRMS - Forgot Password</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* General Styles */
        body {
            font-family: 'Ubuntu', sans-serif;
            background: linear-gradient(to bottom right, #2a2185, #1a1666);
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .container {
            background: #fff;
            color: #333;
            border-radius: 10px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            padding: 20px;
            text-align: center;
        }

        .header {
            margin-bottom: 20px;
        }

        .header .logo img {
            height: 50px;
        }

        .header h2 {
            font-size: 1.5rem;
            color: #2a2185;
            margin-top: 10px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2a2185;
        }

        .form-group .input-icon {
            position: absolute;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            color: #999;
        }

        .form-control {
            width: 100%;
            padding: 10px 10px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: #2a2185;
            outline: none;
            box-shadow: 0 0 5px rgba(42, 33, 133, 0.5);
        }

        .btn {
            background: #2a2185;
            color: #fff;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #1a1666;
        }

        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .footer {
            margin-top: 20px;
        }

        .footer a {
            color: #2a2185;
            text-decoration: none;
            font-weight: bold;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <img src="image/amulogo.png" alt="AMU Logo">
                <h1>AMU SRMS</h1>
            </div>
            <h2>Password Recovery</h2>
        </div>
        
        <div class="body">
            <?php if ($success_message): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($success_message)): ?>
                <form method="POST" action="forgot_password.php">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required>
                    </div>
                    
                    <button type="submit" name="forgot_password" class="btn">
                        <i class="fas fa-key"></i> Request Password Reset
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="footer">
                <p> Remember your password? <a href="login.php">Login here</a></p>
            </div>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>