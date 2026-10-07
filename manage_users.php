<?php
require_once __DIR__ . '/includes/session.php';
include 'includes/access_control.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    require_valid_csrf_token();
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'] ?? [];

    if (!is_array($selected_users)) {
        http_response_code(400);
        exit('Invalid user selection.');
    }
    $selected_ids = array_filter(
        array_map(static fn($id) => filter_var($id, FILTER_VALIDATE_INT), $selected_users),
        static fn($id) => $id !== false && $id > 0 && $id !== (int) $_SESSION['user_id']
    );
    if ($selected_ids) {
        $user_ids = implode(',', array_unique($selected_ids));

        if ($action === 'activate') {
            $conn->query("UPDATE users SET is_active = 1 WHERE user_id IN ($user_ids) AND role != 'superadmin'");
        } elseif ($action === 'deactivate') {
            $conn->query("UPDATE users SET is_active = 0 WHERE user_id IN ($user_ids) AND role != 'superadmin'");
        } elseif ($action === 'delete') {
            $conn->query("DELETE FROM users WHERE user_id IN ($user_ids) AND role != 'superadmin'");
        }
    }
}

// Handle search and filter
$search_query = $conn->real_escape_string(trim((string) ($_GET['search'] ?? '')));
$role_filter = $_GET['role'] ?? '';
$role_filter = in_array($role_filter, ['admin', 'staff', 'technician'], true) ? $role_filter : '';
$status_filter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';

$query = "SELECT * FROM users WHERE 1=1";
if (!empty($search_query)) {
    $query .= " AND (username LIKE '%$search_query%' OR full_name LIKE '%$search_query%')";
}
if (!empty($role_filter)) {
    $query .= " AND role = '$role_filter'";
}
if (!empty($status_filter)) {
    $query .= " AND is_active = " . ($status_filter === 'active' ? 1 : 0);
}
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 20px auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        a {
            text-decoration: none;
            color: #007BFF;
        }
        a:hover {
            text-decoration: underline;
        }
        button {
            padding: 5px 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: red;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Manage Users</h2>
        <a href="add_user.php" style="float: right; margin-bottom: 10px;">Add New User</a><br><br>

        <!-- Add search and filter form -->
        <form method="GET" style="margin-bottom: 20px;">
            <input type="text" name="search" placeholder="Search by name or username" value="<?php echo htmlspecialchars($search_query); ?>">
            <select name="role">
                <option value="">All Roles</option>
                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="staff" <?php echo $role_filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                <option value="technician" <?php echo $role_filter === 'technician' ? 'selected' : ''; ?>>Technician</option>
            </select>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <button type="submit">Filter</button>
        </form>

        <!-- Add bulk actions form -->
        <form id="bulk-user-actions" method="POST">
            <?= csrf_field() ?>
            <select name="bulk_action" required>
                <option value="">Bulk Actions</option>
                <option value="activate">Activate</option>
                <option value="deactivate">Deactivate</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit">Apply</button>
        </form>

        <table>
                <tr>
                    <th><input type="checkbox" id="select_all"></th>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Full Name</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><input type="checkbox" form="bulk-user-actions" name="selected_users[]" value="<?php echo $row['user_id']; ?>"></td>
                        <td><?php echo $row['user_id']; ?></td>
                        <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['role']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <a href="edit_user.php?user_id=<?php echo (int) $row['user_id']; ?>">Edit</a> |
                            <form method="POST" action="delete_user.php" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
        </table>

        <a href="admin_dashboard.php">Back to Dashboard</a>
    </div>

    <script>
        // Select/Deselect all checkboxes
        document.getElementById('select_all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_users[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>