<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'includes/access_control.php';

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM service_requests WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$request_id = isset($_GET['request_id']) ? $_GET['request_id'] : null;
if ($request_id) {
    log_action($conn, $_SESSION['user_id'], 'TRACK_REQUEST', 'SERVICE_REQUEST', $request_id, 'Tracked the status of request ID: $request_id.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Service Requests</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <script src="https://kit.fontawesome.com/64d58efce2.js" crossorigin="anonymous"></script>
    <style>
        /* =========== Google Fonts ============ */
        @import url("https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap");

        /* =============== Globals ============== */
        * {
            font-family: "Ubuntu", sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --blue: #2a2185;
            --white: #fff;
            --gray: #f5f5f5;
            --black1: #222;
            --black2: #999;
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;
            background: var(--gray);
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
            background: var(--white);
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 7px 25px rgba(0, 0, 0, 0.08);
        }

        h2 {
            font-weight: 600;
            color: var(--blue);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table thead td {
            font-weight: 600;
        }

        table tr {
            color: var(--black1);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        table tr:last-child {
            border-bottom: none;
        }

        table tbody tr:hover {
            background: var(--blue);
            color: var(--white);
        }

        table tr td {
            padding: 10px;
        }

        table tr td:last-child {
            text-align: end;
        }

        table tr td:nth-child(2) {
            text-align: end;
        }

        table tr td:nth-child(3) {
            text-align: center;
        }

        .status.delivered {
            padding: 2px 4px;
            background: #8de02c;
            color: var(--white);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }

        .status.pending {
            padding: 2px 4px;
            background: #e9b10a;
            color: var(--white);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }

        .status.return {
            padding: 2px 4px;
            background: #f00;
            color: var(--white);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }

        .status.inProgress {
            padding: 2px 4px;
            background: #1795ce;
            color: var(--white);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }

        a {
            text-decoration: none;
            color: var(--blue);
        }

        a:hover {
            text-decoration: underline;
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            transition: background 0.3s;
        }

        .btn-success {
            background: #28a745;
            color: var(--white);
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: #ffc107;
            color: #222;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            transition: background 0.3s;
        }

        .btn-warning:hover {
            background: #e0a800;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Track Service Requests</h2>
        <table>
            <thead>
                <tr>
                    <td>Request ID</td>
                    <td>Category</td>
                    <td>Status</td>
                    <td>Date Submitted</td>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['request_id']; ?></td>
                        <td><?php echo $row['category']; ?></td>
                        <td>
                            <?php if ($row['status'] === 'Awaiting User Confirmation'): ?>
                                <form method="POST" action="confirm_request.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                </form>
                            <?php else: ?>
                                <?= $row['status'] ?>
                            <?php endif; ?>
                            <?php if ($row['status'] === 'Pending' || $row['status'] === 'In Progress'): ?>
                                <a href="submit_complaint.php?request_id=<?= $row['request_id'] ?>" class="btn btn-warning btn-sm">
                                    <i class="fas fa-exclamation-circle"></i> Submit Complaint
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <a href="user_dashboard.php">Back to Dashboard</a>
    </div>
</body>
</html>

<?php
$stmt->close();
$conn->close();
?>