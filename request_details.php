<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';
require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff', 'technician'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['request_id'])) {
    $request_id = intval($_GET['request_id']);

    $query = "SELECT * FROM service_requests WHERE request_id = ?";
    if ($_SESSION['role'] === 'staff') {
        $query .= " AND user_id = ?";
    } elseif ($_SESSION['role'] === 'technician') {
        $query .= " AND assigned_to = ?";
    }
    $stmt = $conn->prepare($query);
    if ($_SESSION['role'] === 'admin') {
        $stmt->bind_param("i", $request_id);
    } else {
        $stmt->bind_param("ii", $request_id, $_SESSION['user_id']);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $request = $result->fetch_assoc();
        if (!$request) {
            http_response_code(404);
            exit('Request not found.');
        }
    } else {
        echo "Error fetching request details.";
        exit;
    }

    $stmt->close();
} else {
    echo "Invalid request.";
    exit;
}

// Fetch technician and staff details if assigned
$technician_email = $technician_phone = $staff_email = $staff_phone = 'N/A';

if (!empty($request['assigned_to'])) {
    $tech_query = "SELECT email, phone FROM users WHERE user_id = ?";
    $tech_stmt = $conn->prepare($tech_query);
    $tech_stmt->bind_param("i", $request['assigned_to']);
    $tech_stmt->execute();
    $tech_result = $tech_stmt->get_result()->fetch_assoc();
    $technician_email = $tech_result['email'] ?? 'N/A';
    $technician_phone = $tech_result['phone'] ?? 'N/A';
    $tech_stmt->close();
}

$staff_query = "SELECT email, phone FROM users WHERE user_id = ?";
$staff_stmt = $conn->prepare($staff_query);
$staff_stmt->bind_param("i", $request['user_id']);
$staff_stmt->execute();
$staff_result = $staff_stmt->get_result()->fetch_assoc();
$staff_email = $staff_result['email'] ?? 'N/A';
$staff_phone = $staff_result['phone'] ?? 'N/A';
$staff_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Details</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(to bottom right, #2a2185, #1a1666);
            color: #fff;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            background: #fff;
            color: #333;
            border-radius: 10px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px;
            padding: 20px;
            text-align: center;
        }

        h1 {
            font-size: 2rem;
            color: #2a2185;
            margin-bottom: 20px;
        }

        p {
            font-size: 1rem;
            margin: 10px 0;
            line-height: 1.5;
        }

        p strong {
            color: #2a2185;
        }

        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            font-size: 1rem;
            color: #fff;
            background: #2a2185;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #1a1666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f4f4f4;
            color: #333;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 1.5rem;
            }

            p {
                font-size: 0.9rem;
            }

            .btn {
                font-size: 0.9rem;
                padding: 8px 16px;
            }

            table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Request Details</h1>
        <?php if (isset($request)): ?>
            <table>
                <tr>
                    <th>Request ID</th>
                    <td><?= htmlspecialchars($request['request_id']) ?></td>
                </tr>
                <tr>
                    <th>Category</th>
                    <td><?= htmlspecialchars($request['category']) ?></td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td><?= nl2br(htmlspecialchars($request['description'])) ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><?= htmlspecialchars($request['status']) ?></td>
                </tr>
                <tr>
                    <th>Created At</th>
                    <td><?= htmlspecialchars($request['created_at']) ?></td>
                </tr>
                <tr>
                    <th>Updated At</th>
                    <td><?= htmlspecialchars($request['updated_at']) ?></td>
                </tr>
                <tr>
                    <th>Technician Email</th>
                    <td><?= htmlspecialchars($technician_email) ?></td>
                </tr>
                <tr>
                    <th>Technician Phone</th>
                    <td><?= htmlspecialchars($technician_phone) ?></td>
                </tr>
                <tr>
                    <th>Staff Email</th>
                    <td><?= htmlspecialchars($staff_email) ?></td>
                </tr>
                <tr>
                    <th>Staff Phone</th>
                    <td><?= htmlspecialchars($staff_phone) ?></td>
                </tr>
            </table>
        <?php else: ?>
            <p>Request not found.</p>
        <?php endif; ?>
        <?php
        // Redirect to respective dashboards based on user role
        if ($_SESSION['role'] === 'admin') {
            $dashboard_url = 'admin_dashboard.php';
        } elseif ($_SESSION['role'] === 'technician') {
            $dashboard_url = 'technician_dashboard.php';
        } elseif ($_SESSION['role'] === 'staff') {
            $dashboard_url = 'user_dashboard.php';
        } else {
            $dashboard_url = 'login.php'; // Default to login if role is not recognized
        }
        ?>
        <a href="<?= $dashboard_url ?>" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>