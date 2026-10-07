<?php
// Start the session (required to access session variables)
require_once __DIR__ . '/includes/session.php';

// Include access control to use log_action function
include 'includes/access_control.php';

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$conn = db_connect();

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log logout action
if (isset($_SESSION['user_id'])) {
    log_action($conn, $_SESSION['user_id'], 'LOGOUT', 'USER', $_SESSION['user_id'], 'User logged out successfully.');
}

// Unset all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page after logout
header("Location: login.php");
exit;
?>