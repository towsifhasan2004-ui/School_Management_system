
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Check whether the current user is logged in.
 */
function require_login()
{
    if (empty($_SESSION['user_id'])) {
        redirect(BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Get the current user's roles.
 */
function get_user_roles()
{
    global $conn;

    $roles = [];

    if (empty($_SESSION['user_id'])) {
        return $roles;
    }

    $stmt = $conn->prepare("
        SELECT r.role_key, r.role_name_en
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");

    $userId = (int) $_SESSION['user_id'];

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $roles[] = $row;
    }

    $stmt->close();

    return $roles;
}

/**
 * Check whether the current user is Super Admin.
 */
function is_super_admin()
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    foreach (get_user_roles() as $role) {
        if ($role['role_key'] === 'super_admin') {
            return true;
        }
    }

    return false;
}

/**
 * Check whether the current user has a permission.
 * Super Admin automatically has all permissions.
 */
function has_permission($permissionKey)
{
    global $conn;

    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if (is_super_admin()) {
        return true;
    }

    $sql = "
        SELECT COUNT(*) AS total
        FROM user_roles ur
        INNER JOIN role_permissions rp
            ON ur.role_id = rp.role_id
        INNER JOIN permissions p
            ON rp.permission_id = p.id
        WHERE ur.user_id = ?
        AND p.permission_key = ?
    ";

    $stmt = $conn->prepare($sql);

    $userId = (int) $_SESSION['user_id'];

    $stmt->bind_param("is", $userId, $permissionKey);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return (int) ($result['total'] ?? 0) > 0;
}

/**
 * Require a specific permission.
 */
function require_permission($permissionKey)
{
    require_login();

    if (!has_permission($permissionKey)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to access this page.');
    }
}
