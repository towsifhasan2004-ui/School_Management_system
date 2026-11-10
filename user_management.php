
<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

/* =========================================
   PERMISSION HELPERS
========================================= */

function um_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function um_is_super_admin() {
    static $isSuperAdmin = null;

    if ($isSuperAdmin !== null) {
        return $isSuperAdmin;
    }

    $roles = get_user_roles();

    foreach ($roles as $role) {
        if (($role['role_key'] ?? '') === 'super_admin') {
            $isSuperAdmin = true;
            return true;
        }
    }

    $isSuperAdmin = false;
    return false;
}

function um_can($permission) {
    if (um_is_super_admin()) {
        return true;
    }

    return has_permission($permission);
}

/*
 * Super Admin is recognized directly by role.
 * Other users must have users.view permission.
 */
if (!um_can('users.view')) {
    http_response_code(403);
    exit('Access denied. You do not have permission to view user accounts.');
}

$message = '';
$messageType = 'success';
$inTransaction = false;

/* =========================================
   CHECK TARGET ACCOUNT TYPE
   Only student and teacher accounts can be
   reset/deactivated from this page.
========================================= */

function um_is_managed_account($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN r.role_key IN ('student', 'teacher')
                     THEN 1 ELSE 0 END) AS managed_roles,
            SUM(CASE WHEN r.role_key = 'super_admin'
                     THEN 1 ELSE 0 END) AS admin_roles
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (
        (int)($row['managed_roles'] ?? 0) > 0 &&
        (int)($row['admin_roles'] ?? 0) === 0
    );
}

/* =========================================
   PROCESS FORM ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        /* =================================
           CREATE STUDENT / TEACHER ACCOUNT
        ================================= */

        if ($action === 'create') {

            if (!um_can('users.create')) {
                throw new Exception('You do not have permission to create accounts.');
            }

            $accountType = $_POST['account_type'] ?? '';
            $recordId = (int)($_POST['record_id'] ?? 0);

            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!in_array($accountType, ['student', 'teacher'], true)) {
                throw new Exception('Invalid account type selected.');
            }

            if ($recordId <= 0 || $username === '' || $password === '') {
                throw new Exception('Please complete all required fields.');
            }

            if (strlen($username) > 100) {
                throw new Exception('Username cannot exceed 100 characters.');
            }

            if (strlen($password) < 8) {
                throw new Exception('Password must contain at least 8 characters.');
            }

            if (strlen($email) > 150 || strlen($phone) > 25) {
                throw new Exception('Email or phone number is too long.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            $emailValue = ($email === '') ? null : $email;
            $phoneValue = ($phone === '') ? null : $phone;

            $conn->begin_transaction();
            $inTransaction = true;

            /* Lock and validate the linked record */

            if ($accountType === 'student') {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM students
                    WHERE id = ?
                      AND status = 'active'
                      AND user_id IS NULL
                    FOR UPDATE
                ");

            } else {

                $stmt = $conn->prepare("
                    SELECT id
                    FROM employees
                    WHERE id = ?
                      AND employment_status = 'active'
                      AND user_id IS NULL
                    FOR UPDATE
                ");
            }

            $stmt->bind_param("i", $recordId);
            $stmt->execute();

            $linkedRecord = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$linkedRecord) {
                throw new Exception(
                    'Selected record is inactive, already linked, or does not exist.'
                );
            }

            /* Find the required role */

            $stmt = $conn->prepare("
                SELECT id
                FROM roles
                WHERE role_key = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $accountType);
            $stmt->execute();

            $role = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$role) {
                throw new Exception(
                    'The selected role does not exist in the roles table.'
                );
            }

            /* Create user */

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users (
                    username,
                    email,
                    phone,
                    password_hash,
                    preferred_language,
                    status,
                    must_change_password
                )
                VALUES (?, ?, ?, ?, 'en', 'active', 1)
            ");

            $stmt->bind_param(
                "ssss",
                $username,
                $emailValue,
                $phoneValue,
                $passwordHash
            );

            $stmt->execute();

            $newUserId = (int)$conn->insert_id;
            $stmt->close();

            /* Assign role */

            $roleId = (int)$role['id'];

            $stmt = $conn->prepare("
                INSERT INTO user_roles (user_id, role_id)
                VALUES (?, ?)
            ");

            $stmt->bind_param("ii", $newUserId, $roleId);
            $stmt->execute();
            $stmt->close();

            /* Link account to student or employee */

            if ($accountType === 'student') {

                $stmt = $conn->prepare("
                    UPDATE students
                    SET user_id = ?
                    WHERE id = ?
                      AND user_id IS NULL
                      AND status = 'active'
                ");

            } else {

                $stmt = $conn->prepare("
                    UPDATE employees
                    SET user_id = ?
                    WHERE id = ?
                      AND user_id IS NULL
                      AND employment_status = 'active'
                ");
            }

            $stmt->bind_param("ii", $newUserId, $recordId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception('Could not link the account to the selected record.');
            }

            $stmt->close();

            $conn->commit();
            $inTransaction = false;

            $message = ucfirst($accountType) .
                ' account created and linked successfully. The user must change the temporary password after login.';
        }

        /* =================================
           RESET PASSWORD
        ================================= */

        elseif ($action === 'reset_password') {

            if (!um_can('users.update')) {
                throw new Exception('You do not have permission to reset passwords.');
            }

            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if ($userId <= 0 || strlen($newPassword) < 8) {
                throw new Exception('Enter a valid account and a password of at least 8 characters.');
            }

            if ($userId === (int)$_SESSION['user_id']) {
                throw new Exception('You cannot reset your own password from this page.');
            }

            if (!um_is_managed_account($conn, $userId)) {
                throw new Exception('Only linked student and teacher accounts can be managed here.');
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE users
                SET password_hash = ?,
                    must_change_password = 1,
                    password_changed_at = NULL
                WHERE id = ?
                  AND status = 'active'
            ");

            $stmt->bind_param("si", $passwordHash, $userId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception('Account not found or password was not updated.');
            }

            $stmt->close();

            $message = 'Password reset successfully. The user must change the temporary password.';
        }

        /* =================================
           DEACTIVATE ACCOUNT
        ================================= */

        elseif ($action === 'deactivate') {

            if (!um_can('users.update')) {
                throw new Exception('You do not have permission to deactivate accounts.');
            }

            $userId = (int)($_POST['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new Exception('Invalid account selected.');
            }

            if ($userId === (int)$_SESSION['user_id']) {
                throw new Exception('You cannot deactivate your own account.');
            }

            if (!um_is_managed_account($conn, $userId)) {
                throw new Exception('Only linked student and teacher accounts can be deactivated here.');
            }

            $stmt = $conn->prepare("
                UPDATE users
                SET status = 'inactive'
                WHERE id = ?
                  AND status = 'active'
            ");

            $stmt->bind_param("i", $userId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception('Account not found or already inactive.');
            }

            $stmt->close();

            $message = 'Account deactivated. The linked student or employee record has been preserved.';
        }

        else {
            throw new Exception('Invalid request.');
        }

    } catch (Throwable $e) {

        if ($inTransaction) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $inTransaction = false;
        }

        if ((int)$e->getCode() === 1062) {
            $message = 'Username, email, or phone number already exists. Please use a different value.';
        } else {
            $message = $e->getMessage();
        }

        $messageType = 'danger';
    }
}

/* =========================================
   LOAD UNLINKED ACTIVE STUDENTS
========================================= */

$studentRows = [];

$result = $conn->query("
    SELECT
        id,
        admission_no,
        first_name_en,
        last_name_en
    FROM students
    WHERE status = 'active'
      AND user_id IS NULL
    ORDER BY first_name_en, last_name_en
");

while ($row = $result->fetch_assoc()) {
    $studentRows[] = [
        'id' => (int)$row['id'],
        'label' => trim(
            $row['first_name_en'] . ' ' .
            ($row['last_name_en'] ?? '')
        ) . ' (' . $row['admission_no'] . ')'
    ];
}

/* =========================================
   LOAD UNLINKED ACTIVE TEACHERS
========================================= */

$teacherRows = [];

$result = $conn->query("
    SELECT
        id,
        employee_code,
        first_name_en,
        last_name_en
    FROM employees
    WHERE employment_status = 'active'
      AND user_id IS NULL
    ORDER BY first_name_en, last_name_en
");

while ($row = $result->fetch_assoc()) {
    $teacherRows[] = [
        'id' => (int)$row['id'],
        'label' => trim(
            $row['first_name_en'] . ' ' .
            ($row['last_name_en'] ?? '')
        ) . ' (' . $row['employee_code'] . ')'
    ];
}

/* =========================================
   LOAD EXISTING ACCOUNTS
========================================= */

$accounts = $conn->query("
    SELECT
        u.id,
        u.username,
        u.email,
        u.phone,
        u.status,
        u.created_at,

        GROUP_CONCAT(
            DISTINCT r.role_name_en
            ORDER BY r.role_name_en
            SEPARATOR ', '
        ) AS role_names,

        GROUP_CONCAT(
            DISTINCT r.role_key
            SEPARATOR ','
        ) AS role_keys,

        s.admission_no,
        CONCAT_WS(' ', s.first_name_en, s.last_name_en) AS student_name,

        e.employee_code,
        CONCAT_WS(' ', e.first_name_en, e.last_name_en) AS employee_name

    FROM users u

    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id

    LEFT JOIN students s ON s.user_id = u.id
    LEFT JOIN employees e ON e.user_id = u.id

    GROUP BY
        u.id,
        u.username,
        u.email,
        u.phone,
        u.status,
        u.created_at,
        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        e.employee_code,
        e.first_name_en,
        e.last_name_en

    ORDER BY u.id DESC
");

$canCreate = um_can('users.create');
$canUpdate = um_can('users.update');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | SISMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f3f6fa;
            font-family: Arial, sans-serif;
        }

        .page-title {
            color: #123653;
            font-weight: 700;
        }

        .page-card {
            background: #fff;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,.05);
        }

        .section-title {
            color: #123653;
            font-weight: 700;
            font-size: 18px;
        }

        .btn-school {
            background: #123653;
            color: #fff;
        }

        .btn-school:hover {
            background: #0b2947;
            color: #fff;
        }

        .table th {
            white-space: nowrap;
            background: #edf2f7;
        }

        .table td {
            vertical-align: middle;
        }
    </style>
</head>

<body>

<div class="container-fluid py-4 px-3 px-lg-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

        <div>
            <h3 class="page-title mb-1">User Account Management</h3>
            <p class="text-muted mb-0">
                Create and manage teacher and student login accounts.
            </p>
        </div>

        <a href="<?= um_h(BASE_URL) ?>/dashboard.php"
           class="btn btn-outline-secondary">
            Back to Dashboard
        </a>

    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= um_h($messageType) ?> alert-dismissible fade show">
            <?= um_h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <!-- CREATE ACCOUNT -->

    <?php if ($canCreate): ?>

    <div class="card page-card mb-4">

        <div class="card-body p-4">

            <h5 class="section-title mb-3">Create New Account</h5>

            <form method="POST" autocomplete="off">

                <input type="hidden" name="csrf_token"
                       value="<?= um_h(csrf_token()) ?>">

                <input type="hidden" name="action" value="create">

                <div class="row g-3">

                    <div class="col-md-4">

                        <label class="form-label">Account Type *</label>

                        <select name="account_type"
                                id="accountType"
                                class="form-select"
                                required>

                            <option value="">Select account type</option>
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>

                        </select>

                    </div>


                    <div class="col-md-8">

                        <label class="form-label">
                            Existing Student / Employee *
                        </label>

                        <select name="record_id"
                                id="recordId"
                                class="form-select"
                                required
                                disabled>

                            <option value="">First select account type</option>

                        </select>

                    </div>


                    <div class="col-md-4">

                        <label class="form-label">Username *</label>

                        <input type="text"
                               name="username"
                               class="form-control"
                               maxlength="100"
                               required>

                    </div>


                    <div class="col-md-4">

                        <label class="form-label">Email</label>

                        <input type="email"
                               name="email"
                               class="form-control"
                               maxlength="150">

                    </div>


                    <div class="col-md-4">

                        <label class="form-label">Phone</label>

                        <input type="text"
                               name="phone"
                               class="form-control"
                               maxlength="25">

                    </div>


                    <div class="col-md-6">

                        <label class="form-label">
                            Temporary Password *
                        </label>

                        <input type="password"
                               name="password"
                               class="form-control"
                               minlength="8"
                               autocomplete="new-password"
                               required>

                        <small class="text-muted">
                            The user will be required to change this password.
                        </small>

                    </div>


                    <div class="col-12">

                        <button type="submit" class="btn btn-school px-4">
                            Create Account
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <?php endif; ?>


    <!-- ACCOUNT LIST -->

    <div class="card page-card">

        <div class="card-body p-4">

            <h5 class="section-title mb-3">Existing Accounts</h5>

            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">

                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Linked Record</th>
                        <th>Role</th>
                        <th>Email / Phone</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php if ($accounts->num_rows === 0): ?>

                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No accounts found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php while ($row = $accounts->fetch_assoc()): ?>

                            <?php
                            $roleKeys = array_filter(
                                explode(',', (string)$row['role_keys'])
                            );

                            $isManaged =
                                in_array('student', $roleKeys, true) ||
                                in_array('teacher', $roleKeys, true);

                            $isAdmin = in_array('super_admin', $roleKeys, true);

                            $showActions =
                                $isManaged &&
                                !$isAdmin &&
                                $canUpdate &&
                                $row['status'] === 'active';
                            ?>

                            <tr>

                                <td><?= (int)$row['id'] ?></td>

                                <td>
                                    <strong><?= um_h($row['username']) ?></strong>
                                </td>

                                <td>

                                    <?php if ($row['admission_no']): ?>

                                        <?= um_h($row['student_name']) ?><br>

                                        <small class="text-muted">
                                            Student: <?= um_h($row['admission_no']) ?>
                                        </small>

                                    <?php elseif ($row['employee_code']): ?>

                                        <?= um_h($row['employee_name']) ?><br>

                                        <small class="text-muted">
                                            Employee: <?= um_h($row['employee_code']) ?>
                                        </small>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No linked record
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= um_h($row['role_names'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= um_h($row['email'] ?? '-') ?><br>

                                    <small class="text-muted">
                                        <?= um_h($row['phone'] ?? '-') ?>
                                    </small>
                                </td>

                                <td>

                                    <?php if ($row['status'] === 'active'): ?>

                                        <span class="badge bg-success">Active</span>

                                    <?php elseif ($row['status'] === 'inactive'): ?>

                                        <span class="badge bg-secondary">Inactive</span>

                                    <?php else: ?>

                                        <span class="badge bg-danger">
                                            <?= um_h(ucfirst($row['status'])) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td style="min-width:220px">

                                    <?php if ($showActions): ?>

                                        <!-- RESET PASSWORD -->

                                        <form method="POST" class="mb-2">

                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= um_h(csrf_token()) ?>">

                                            <input type="hidden"
                                                   name="action"
                                                   value="reset_password">

                                            <input type="hidden"
                                                   name="user_id"
                                                   value="<?= (int)$row['id'] ?>">

                                            <input type="password"
                                                   name="new_password"
                                                   class="form-control form-control-sm mb-1"
                                                   minlength="8"
                                                   placeholder="New temporary password"
                                                   autocomplete="new-password"
                                                   required>

                                            <button type="submit"
                                                    class="btn btn-sm btn-warning w-100">

                                                Reset Password

                                            </button>

                                        </form>


                                        <!-- DEACTIVATE -->

                                        <form method="POST"
                                              onsubmit="return confirm('Deactivate this account? The linked record will remain unchanged.');">

                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= um_h(csrf_token()) ?>">

                                            <input type="hidden"
                                                   name="action"
                                                   value="deactivate">

                                            <input type="hidden"
                                                   name="user_id"
                                                   value="<?= (int)$row['id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger w-100">

                                                Deactivate Account

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No available actions
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<script>
const studentOptions = <?= json_encode(
    $studentRows,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const teacherOptions = <?= json_encode(
    $teacherRows,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const accountType = document.getElementById('accountType');
const recordSelect = document.getElementById('recordId');

if (accountType && recordSelect) {

    accountType.addEventListener('change', function () {

        let options = [];

        if (this.value === 'student') {
            options = studentOptions;
        } else if (this.value === 'teacher') {
            options = teacherOptions;
        }

        recordSelect.innerHTML = '';

        const firstOption = document.createElement('option');

        firstOption.value = '';

        firstOption.textContent = options.length
            ? 'Select a record'
            : 'No unlinked active records available';

        recordSelect.appendChild(firstOption);

        recordSelect.disabled = options.length === 0;

        options.forEach(function (item) {

            const option = document.createElement('option');

            option.value = item.id;
            option.textContent = item.label;

            recordSelect.appendChild(option);

        });

    });

}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
