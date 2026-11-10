
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function emp_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';
$editEmployee = null;

$employmentTypes = [
    'permanent',
    'contractual',
    'part_time',
    'temporary'
];

$employmentStatuses = [
    'active',
    'on_leave',
    'resigned',
    'terminated'
];

/* =========================================
   HANDLE POST REQUESTS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        /* =================================
           ADD EMPLOYEE
        ================================= */

        if ($action === 'add' || $action === 'update') {

            $id = (int)($_POST['employee_id'] ?? 0);

            $firstName = trim($_POST['first_name_en'] ?? '');
            $lastName = trim($_POST['last_name_en'] ?? '');
            $nameBn = trim($_POST['full_name_bn'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $departmentId = (int)($_POST['department_id'] ?? 0);
            $designationId = (int)($_POST['designation_id'] ?? 0);
            $joiningDate = trim($_POST['joining_date'] ?? '');
            $employmentType = $_POST['employment_type'] ?? 'permanent';
            $employmentStatus = $_POST['employment_status'] ?? 'active';
            $address = trim($_POST['address'] ?? '');

            if ($firstName === '' || $phone === '' || $joiningDate === '') {
                throw new Exception('First name, phone and joining date are required.');
            }

            if (mb_strlen($firstName) > 100 ||
                mb_strlen($lastName) > 100 ||
                mb_strlen($nameBn) > 200 ||
                mb_strlen($phone) > 25 ||
                mb_strlen($email) > 150) {
                throw new Exception('One or more fields exceed the allowed length.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            $date = DateTime::createFromFormat('Y-m-d', $joiningDate);

            if (!$date || $date->format('Y-m-d') !== $joiningDate) {
                throw new Exception('Invalid joining date.');
            }

            if (!in_array($employmentType, $employmentTypes, true)) {
                throw new Exception('Invalid employment type.');
            }

            if (!in_array($employmentStatus, $employmentStatuses, true)) {
                throw new Exception('Invalid employment status.');
            }

            $departmentId = $departmentId > 0 ? $departmentId : null;
            $designationId = $designationId > 0 ? $designationId : null;
            $email = $email !== '' ? $email : null;
            $lastName = $lastName !== '' ? $lastName : null;
            $nameBn = $nameBn !== '' ? $nameBn : null;
            $address = $address !== '' ? $address : null;

            // Validate department and designation IDs.
            if ($departmentId !== null) {
                $check = $conn->prepare("SELECT id FROM departments WHERE id = ?");
                $check->bind_param("i", $departmentId);
                $check->execute();

                if (!$check->get_result()->num_rows) {
                    throw new Exception('Selected department was not found.');
                }

                $check->close();
            }

            if ($designationId !== null) {
                $check = $conn->prepare("SELECT id FROM designations WHERE id = ?");
                $check->bind_param("i", $designationId);
                $check->execute();

                if (!$check->get_result()->num_rows) {
                    throw new Exception('Selected designation was not found.');
                }

                $check->close();
            }

            if ($action === 'add') {

                // Generate a unique employee code.
                $employeeCode = 'SZ-EMP-' . date('Y') . '-' .
                    strtoupper(bin2hex(random_bytes(3)));

                $stmt = $conn->prepare("
                    INSERT INTO employees (
                        employee_code,
                        first_name_en,
                        last_name_en,
                        full_name_bn,
                        phone,
                        email,
                        department_id,
                        designation_id,
                        joining_date,
                        employment_type,
                        employment_status,
                        address
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "ssssssiissss",
                    $employeeCode,
                    $firstName,
                    $lastName,
                    $nameBn,
                    $phone,
                    $email,
                    $departmentId,
                    $designationId,
                    $joiningDate,
                    $employmentType,
                    $employmentStatus,
                    $address
                );

                $stmt->execute();
                $stmt->close();

                $message = 'Employee registered successfully. Employee Code: ' . $employeeCode;
            }

            /* =================================
               UPDATE EMPLOYEE
            ================================= */

            else {

    if ($id <= 0) {
        throw new Exception('Invalid employee record.');
    }

    $stmt = $conn->prepare("
        UPDATE employees SET
            first_name_en = ?,
            last_name_en = ?,
            full_name_bn = ?,
            phone = ?,
            email = ?,
            department_id = ?,
            designation_id = ?,
            joining_date = ?,
            employment_type = ?,
            employment_status = ?,
            address = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "sssssiiisssi",
        $firstName,
        $lastName,
        $nameBn,
        $phone,
        $email,
        $departmentId,
        $designationId,
        $joiningDate,
        $employmentType,
        $employmentStatus,
        $address,
        $id
    );

    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();

        $check = $conn->prepare("SELECT id FROM employees WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            throw new Exception('Employee record not found.');
        }
    } else {
        $stmt->close();
    }

    $message = 'Employee information updated successfully.';
}
        }

        else {
            throw new Exception('Invalid request.');
        }

    } catch (mysqli_sql_exception $e) {

        error_log('Employee management error: ' . $e->getMessage());

        if ((int)$e->getCode() === 1062) {
            $message = 'Duplicate employee information. Please check the record.';
        } else {
            $message = 'Database error. Please check your information and try again.';
        }

        $messageType = 'danger';

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD EMPLOYEE FOR EDIT
========================================= */

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT *
        FROM employees
        WHERE id = ?
    ");

    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $editEmployee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editEmployee) {
        $message = 'Employee record not found.';
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD DEPARTMENTS AND DESIGNATIONS
========================================= */

$departments = $conn->query("
    SELECT id, department_name_en
    FROM departments
    ORDER BY department_name_en
");

$designations = $conn->query("
    SELECT id, designation_name_en
    FROM designations
    ORDER BY designation_name_en
");

/* =========================================
   SEARCH AND FILTER
========================================= */

$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "(
        e.employee_code LIKE ?
        OR e.first_name_en LIKE ?
        OR e.last_name_en LIKE ?
        OR e.full_name_bn LIKE ?
        OR e.phone LIKE ?
        OR e.email LIKE ?
    )";

    $like = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if (in_array($filterStatus, $employmentStatuses, true)) {
    $where[] = "e.employment_status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        e.*,
        d.department_name_en,
        dg.designation_name_en
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations dg ON dg.id = e.designation_id
    $whereSql
    ORDER BY e.id DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$stmt->execute();
$employees = $stmt->get_result();

/* =========================================
   SUMMARY
========================================= */

$summary = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(employment_status = 'active') AS active,
        SUM(employment_status = 'on_leave') AS on_leave,
        SUM(employment_status IN ('resigned','terminated')) AS inactive
    FROM employees
")->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employee Management | SISMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
            font-family: "Segoe UI", sans-serif;
            color: #263445;
        }

        .topbar {
            background: #102b46;
            color: white;
            padding: 17px 25px;
        }

        .topbar h4 {
            margin: 0;
            font-weight: 700;
        }

        .page-container {
            max-width: 1500px;
            margin: auto;
            padding: 28px 18px;
        }

        .page-heading {
            color: #102b46;
            font-weight: 750;
        }

        .panel, .summary-card {
            background: white;
            border-radius: 13px;
            padding: 22px;
            box-shadow: 0 4px 18px rgba(20, 40, 70, .06);
            margin-bottom: 22px;
        }

        .summary-card {
            border-left: 4px solid #d8ad4c;
        }

        .summary-number {
            font-size: 27px;
            font-weight: 750;
            color: #102b46;
        }

        .form-label {
            font-weight: 600;
        }

        .btn-primary {
            background: #102b46;
            border-color: #102b46;
        }

        .btn-primary:hover {
            background: #1d466c;
            border-color: #1d466c;
        }

        .table thead th {
            background: #102b46;
            color: white;
            white-space: nowrap;
            padding: 13px;
        }

        .table td {
            vertical-align: middle;
            padding: 12px;
        }

        .status-active {
            background: #d9f5e5;
            color: #137044;
        }

        .status-on_leave {
            background: #fff1ce;
            color: #8b6500;
        }

        .status-resigned,
        .status-terminated {
            background: #fbe0e0;
            color: #a52a2a;
        }

        .employee-code {
            color: #102b46;
            font-weight: 700;
        }

        @media(max-width: 768px) {
            .page-container {
                padding: 18px 10px;
            }

            .panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="topbar">
    <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4>
            <i class="fa-solid fa-users me-2"></i>
            SISMS Human Resource Management
        </h4>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-house me-1"></i>
            Dashboard
        </a>
    </div>
</div>

<div class="page-container">

    <div class="mb-4">
        <h3 class="page-heading">Employee & Teacher Management</h3>
        <p class="text-muted mb-0">
            Register and manage teachers, administrative employees and support staff.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= emp_h($messageType) ?> alert-dismissible fade show">
            <?= emp_h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->
    <div class="row g-3 mb-3">

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="text-muted">Total Employees</div>
                <div class="summary-number">
                    <?= (int)($summary['total'] ?? 0) ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="text-muted">Active Employees</div>
                <div class="summary-number text-success">
                    <?= (int)($summary['active'] ?? 0) ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="text-muted">On Leave</div>
                <div class="summary-number text-warning">
                    <?= (int)($summary['on_leave'] ?? 0) ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="text-muted">Resigned / Terminated</div>
                <div class="summary-number text-danger">
                    <?= (int)($summary['inactive'] ?? 0) ?>
                </div>
            </div>
        </div>

    </div>

    <!-- EMPLOYEE FORM -->
    <div class="panel">

        <h5 class="fw-bold mb-3">
            <i class="fa-solid fa-<?= $editEmployee ? 'pen-to-square' : 'user-plus' ?> me-2"></i>
            <?= $editEmployee ? 'Edit Employee Information' : 'Register New Employee' ?>
        </h5>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= emp_h(csrf_token()) ?>">

            <input type="hidden" name="action"
                   value="<?= $editEmployee ? 'update' : 'add' ?>">

            <?php if ($editEmployee): ?>
                <input type="hidden" name="employee_id"
                       value="<?= (int)$editEmployee['id'] ?>">
            <?php endif; ?>

            <?php if ($editEmployee): ?>
                <div class="alert alert-light border">
                    Employee Code:
                    <strong><?= emp_h($editEmployee['employee_code']) ?></strong>
                    <span class="text-muted ms-2">Employee codes cannot be changed here.</span>
                </div>
            <?php endif; ?>

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">First Name (English) *</label>
                    <input type="text" name="first_name_en"
                           class="form-control" maxlength="100" required
                           value="<?= emp_h($editEmployee['first_name_en'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Last Name (English)</label>
                    <input type="text" name="last_name_en"
                           class="form-control" maxlength="100"
                           value="<?= emp_h($editEmployee['last_name_en'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Full Name (Bangla)</label>
                    <input type="text" name="full_name_bn"
                           class="form-control" maxlength="200"
                           value="<?= emp_h($editEmployee['full_name_bn'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone Number *</label>
                    <input type="text" name="phone"
                           class="form-control" maxlength="25" required
                           value="<?= emp_h($editEmployee['phone'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email"
                           class="form-control" maxlength="150"
                           value="<?= emp_h($editEmployee['email'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Joining Date *</label>
                    <input type="date" name="joining_date"
                           class="form-control" required
                           value="<?= emp_h($editEmployee['joining_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="0">Select Department</option>

                        <?php while ($department = $departments->fetch_assoc()): ?>
                            <option value="<?= (int)$department['id'] ?>"
                                <?= (int)($editEmployee['department_id'] ?? 0) === (int)$department['id'] ? 'selected' : '' ?>>
                                <?= emp_h($department['department_name_en']) ?>
                            </option>
                        <?php endwhile; ?>

                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" class="form-select">
                        <option value="0">Select Designation</option>

                        <?php while ($designation = $designations->fetch_assoc()): ?>
                            <option value="<?= (int)$designation['id'] ?>"
                                <?= (int)($editEmployee['designation_id'] ?? 0) === (int)$designation['id'] ? 'selected' : '' ?>>
                                <?= emp_h($designation['designation_name_en']) ?>
                            </option>
                        <?php endwhile; ?>

                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Employment Type *</label>
                    <select name="employment_type" class="form-select" required>
                        <?php foreach ($employmentTypes as $type): ?>
                            <option value="<?= emp_h($type) ?>"
                                <?= ($editEmployee['employment_type'] ?? 'permanent') === $type ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_', ' ', $type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($editEmployee): ?>
                    <div class="col-md-4">
                        <label class="form-label">Employment Status *</label>
                        <select name="employment_status" class="form-select" required>
                            <?php foreach ($employmentStatuses as $statusOption): ?>
                                <option value="<?= emp_h($statusOption) ?>"
                                    <?= ($editEmployee['employment_status'] ?? 'active') === $statusOption ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', $statusOption)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="employment_status" value="active">
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= emp_h($editEmployee['address'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i>
                        <?= $editEmployee ? 'Update Employee' : 'Register Employee' ?>
                    </button>

                    <?php if ($editEmployee): ?>
                        <a href="employees.php" class="btn btn-light border">
                            Cancel Editing
                        </a>
                    <?php endif; ?>
                </div>

            </div>

        </form>

    </div>

    <!-- EMPLOYEE LIST -->
    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">
                <i class="fa-solid fa-address-book me-2"></i>
                Employee Directory
            </h5>

            <span class="text-muted small">
                Maximum 300 records displayed
            </span>
        </div>

        <form method="GET" class="row g-2 mb-3">

            <div class="col-md-6">
                <input type="search" name="search"
                       class="form-control"
                       placeholder="Search employee code, name, phone or email"
                       value="<?= emp_h($search) ?>">
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Employment Statuses</option>

                    <?php foreach ($employmentStatuses as $s): ?>
                        <option value="<?= emp_h($s) ?>"
                            <?= $filterStatus === $s ? 'selected' : '' ?>>
                            <?= ucwords(str_replace('_', ' ', $s)) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fa-solid fa-search me-1"></i> Search
                </button>

                <a href="employees.php" class="btn btn-light border">
                    Reset
                </a>
            </div>

        </form>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee Code</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Joining Date</th>
                        <th>Employment Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($employees->num_rows > 0): ?>

                    <?php $i = 1; ?>

                    <?php while ($employee = $employees->fetch_assoc()): ?>

                        <tr>
                            <td><?= $i++ ?></td>

                            <td class="employee-code">
                                <?= emp_h($employee['employee_code']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= emp_h(trim($employee['first_name_en'] . ' ' . ($employee['last_name_en'] ?? ''))) ?>
                                </strong>

                                <?php if ($employee['full_name_bn']): ?>
                                    <br>
                                    <small class="text-muted">
                                        <?= emp_h($employee['full_name_bn']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td><?= emp_h($employee['phone']) ?></td>

                            <td>
                                <?= emp_h($employee['department_name_en'] ?: '-') ?>
                            </td>

                            <td>
                                <?= emp_h($employee['designation_name_en'] ?: '-') ?>
                            </td>

                            <td>
                                <?= date('d M Y', strtotime($employee['joining_date'])) ?>
                            </td>

                            <td>
                                <?= emp_h(ucwords(str_replace('_', ' ', $employee['employment_type']))) ?>
                            </td>

                            <td>
                                <span class="badge status-<?= emp_h($employee['employment_status']) ?>">
                                    <?= emp_h(ucwords(str_replace('_', ' ', $employee['employment_status']))) ?>
                                </span>
                            </td>

                            <td>
                                <a href="employees.php?edit=<?= (int)$employee['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            No employee records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
