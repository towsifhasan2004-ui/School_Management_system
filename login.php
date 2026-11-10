
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

function getDashboardUrl($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT r.role_key
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY FIELD(
            r.role_key,
            'super_admin',
            'principal',
            'accountant',
            'admission_officer',
            'teacher',
            'student',
            'guardian'
        )
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $role = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$role) {
        return null;
    }

    switch ($role['role_key']) {
        case 'super_admin':
            return BASE_URL . '/dashboard.php';

          case 'teacher':
            return BASE_URL . '/dashboard.php';

        case 'student':
            return BASE_URL . '/dashboard.php';

        case 'guardian':
            return BASE_URL . '/dashboard.php';

        default:
            return BASE_URL . '/dashboard.php';
    }
}

if (!empty($_SESSION['user_id'])) {
    $existingUrl = getDashboardUrl($conn, (int)$_SESSION['user_id']);

    if ($existingUrl) {
        redirect($existingUrl);
    }

    session_unset();
    session_destroy();
    session_start();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($username === '' || $password === '') {

        $error = "Please enter your username and password.";

    } else {

        try {

            $limit = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM login_attempts
                WHERE username_attempted = ?
                AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                AND was_successful = 0
            ");

            $limit->bind_param("s", $username);
            $limit->execute();

            $attempts = (int)$limit->get_result()->fetch_assoc()['total'];
            $limit->close();

            if ($attempts >= 5) {

                $error = "Too many failed attempts. Please try again after 15 minutes.";

            } else {

                $stmt = $conn->prepare("
                    SELECT id, username, password_hash,
                           preferred_language, status
                    FROM users
                    WHERE username = ?
                    LIMIT 1
                ");

                $stmt->bind_param("s", $username);
                $stmt->execute();

                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    $user &&
                    $user['status'] === 'active' &&
                    password_verify($password, $user['password_hash'])
                ) {

                    $dashboardUrl = getDashboardUrl(
                        $conn,
                        (int)$user['id']
                    );

                    if (!$dashboardUrl) {
                        $error = "Your account has no assigned role. Contact the school administrator.";
                    } else {

                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['language'] = $user['preferred_language'];

                        $update = $conn->prepare("
                            UPDATE users
                            SET last_login_at = NOW()
                            WHERE id = ?
                        ");

                        $update->bind_param("i", $user['id']);
                        $update->execute();
                        $update->close();

                        $successAttempt = 1;

                        $log = $conn->prepare("
                            INSERT INTO login_attempts
                            (username_attempted, ip_address, was_successful)
                            VALUES (?, ?, ?)
                        ");

                        $log->bind_param(
                            "ssi",
                            $username,
                            $ip,
                            $successAttempt
                        );

                        $log->execute();
                        $log->close();

                        redirect($dashboardUrl);
                    }

                } else {

                    $failedAttempt = 0;

                    $log = $conn->prepare("
                        INSERT INTO login_attempts
                        (username_attempted, ip_address, was_successful)
                        VALUES (?, ?, ?)
                    ");

                    $log->bind_param(
                        "ssi",
                        $username,
                        $ip,
                        $failedAttempt
                    );

                    $log->execute();
                    $log->close();

                    $error = "Invalid username or password.";
                }
            }

        } catch (Throwable $e) {

            error_log("Login error: " . $e->getMessage());

            $error = "Unable to process login. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Sezua International School</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body class="auth-page">

<div class="auth-card">

    <div class="text-center mb-4">
        <div class="school-logo">S</div>

        <h3 class="mt-3">Sezua International School</h3>

        <p class="text-muted">School Management System</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input type="hidden"
               name="csrf_token"
               value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
            <label class="form-label">Username</label>

            <input
                type="text"
                name="username"
                class="form-control"
                placeholder="Enter your username"
                autocomplete="username"
                required
            >
        </div>

        <div class="mb-4">
            <label class="form-label">Password</label>

            <input
                type="password"
                name="password"
                class="form-control"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit" class="btn btn-school w-100">
            Sign In
        </button>

    </form>

    <div class="text-center mt-4">
        <small class="text-muted">
            &copy; <?= date('Y') ?> Sezua International School
        </small>
    </div>

</div>

</body>
</html>
