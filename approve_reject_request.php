<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
$statuses = ['approve' => 'In Progress', 'reject' => 'Rejected'];
if (!$request_id || !isset($statuses[$action])) {
    http_response_code(400);
    exit('Invalid request.');
}

$conn = db_connect();
$stmt = $conn->prepare("UPDATE service_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND status = 'Pending'");
$stmt->bind_param("si", $statuses[$action], $request_id);
$stmt->execute();
$stmt->close();
$conn->close();
header("Location: Admin_dashboard.php");
exit;