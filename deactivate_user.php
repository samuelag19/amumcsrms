<?php
require_once __DIR__ . '/includes/session.php';
include 'includes/access_control.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id || $user_id === (int) $_SESSION['user_id']) {
    http_response_code(400);
    exit('Invalid user ID.');
}

$conn = db_connect();
$query = "UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE user_id = ? AND (? = 'superadmin' OR role != 'superadmin')";
$stmt = $conn->prepare($query);
$actor_role = $_SESSION['role'];
$stmt->bind_param("is", $user_id, $actor_role);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    log_action($conn, $_SESSION['user_id'], 'TOGGLE_USER_STATUS', 'USER', $user_id, 'Toggled activation status for user ID: ' . $user_id . '.');
}
$stmt->close();
$conn->close();

$redirect_url = ($_SESSION['role'] === 'superadmin') ? 'superadmin_dashboard.php?section=manage_users' : 'Admin_dashboard.php?section=manage_users';
header("Location: $redirect_url");
exit;
?>