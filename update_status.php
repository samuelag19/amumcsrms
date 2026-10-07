<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$status = $_POST['status'] ?? '';
if (!$request_id || !in_array($status, ['In Progress', 'Awaiting User Confirmation'], true)) {
    http_response_code(400);
    exit('Invalid request update.');
}

$conn = db_connect();
$stmt = $conn->prepare("UPDATE service_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND assigned_to = ?");
$stmt->bind_param("sii", $status, $request_id, $_SESSION['user_id']);
$stmt->execute();
$stmt->close();
$conn->close();
header("Location: technician_dashboard.php?section=assigned_requests");
exit;