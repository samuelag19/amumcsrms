<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';

header('Content-Type: application/json');

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();
$count = $conn->query("SELECT COUNT(*) AS count FROM service_requests WHERE status = 'Pending'")->fetch_assoc()['count'];
$conn->close();

echo json_encode([
    'count' => (int) $count,
]);
?>