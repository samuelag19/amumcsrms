<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header("Location: login.php");
    exit;
}

include 'includes/access_control.php';

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_valid_csrf_token();
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'];

    if (!$request_id || !in_array($status, ['In Progress', 'Awaiting User Confirmation'], true)) {
        http_response_code(400);
        exit('Invalid request update.');
    }
    $query = "UPDATE service_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND assigned_to = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sii", $status, $request_id, $_SESSION['user_id']);

    if ($stmt->execute()) {
        $message = "Status updated successfully!";
    } else {
        $message = "Error updating status.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Request Status</title>
    <style>
        :root {
            --primary-color: #004AAD; /* AMU Blue */
            --secondary-color: #FFFFFF; /* White */
            --accent-color: #007BFF; /* Lighter Blue */
            --text-color: #333333; /* Dark Gray Text */
            --border-color: #E0E0E0; /* Light Gray Border */
        }

        body {
            font-family: Arial, sans-serif;
            background-color: var(--secondary-color);
            margin: 0;
            padding: 0;
        }

        .form-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #F9F9F9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        input[type="hidden"], select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }

        button {
            padding: 10px 15px;
            background-color: var(--primary-color);
            color: var(--secondary-color);
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #003C8F; /* Darker AMU Blue */
        }

        .success {
            color: green;
            margin-top: 10px;
        }

        .error {
            color: red;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Update Request Status</h2>
        <?php if (!empty($message)): ?>
            <p class="<?php echo strpos($message, "successfully") !== false ? "success" : "error"; ?>">
                <?php echo $message; ?>
            </p>
        <?php endif; ?>
        <form method="POST" action="update_request_status.php">
            <?= csrf_field() ?>
            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) ($_GET['request_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <label for="status">Select Status:</label>
            <select id="status" name="status" required>
                <option value="In Progress">In Progress</option>
                <option value="Awaiting User Confirmation">Awaiting User Confirmation</option>
            </select>

            <button type="submit">Update Status</button>
        </form>
        <a href="user_dashboard.php">Back to Dashboard</a>
    </div>
</body>
</html>

<?php
$conn->close();
?>