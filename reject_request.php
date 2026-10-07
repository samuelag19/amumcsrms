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
$reason = trim((string) ($_POST['reason'] ?? 'No reason provided'));
if (!$request_id) {
    http_response_code(400);
    exit('Invalid request ID.');
}

$conn = db_connect();
$query = "UPDATE service_requests SET status = 'Rejected', description = CONCAT(description, '\nRejection Reason: ', ?), updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND status = 'Pending'";
$stmt = $conn->prepare($query);
$stmt->bind_param("si", $reason, $request_id);
$stmt->execute();
$stmt->close();
$conn->close();
header("Location: Admin_dashboard.php");
exit;