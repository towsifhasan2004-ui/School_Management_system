
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

/* =========================================
   ADD OR UPDATE SALARY COMPONENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        /* ADD COMPONENT */

        if ($action === 'add') {

            $componentName = trim($_POST['component_name'] ?? '');
            $componentType = $_POST['component_type'] ?? '';
            $calculationType = $_POST['calculation_type'] ?? 'fixed';

            if ($componentName === '') {
                throw new Exception('Component name is required.');
            }

            if (!in_array($componentType, ['earning', 'deduction'], true)) {
                throw new Exception('Invalid component type.');
            }

            if (!in_array($calculationType, ['fixed', 'percentage'], true)) {
                throw new Exception('Invalid calculation type.');
            }

            if (strlen($componentName) > 100) {
                throw new Exception('Component name cannot exceed 100 characters.');
            }

            $stmt = $conn->prepare("
                INSERT INTO salary_components
                    (component_name, component_type, calculation_type, status)
                VALUES (?, ?, ?, 'active')
            ");

            $stmt->bind_param(
                "sss",
                $componentName,
                $componentType,
                $calculationType
            );

            $stmt->execute();
            $stmt->close();

            $message = 'Salary component added successfully.';
        }

        /* CHANGE COMPONENT STATUS */

        elseif ($action === 'toggle_status') {

            $componentId = (int)($_POST['component_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($componentId <= 0) {
                throw new Exception('Invalid component ID.');
            }

            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                throw new Exception('Invalid component status.');
            }

            $stmt = $conn->prepare("
                UPDATE salary_components
                SET status = ?
                WHERE id = ?
            ");

            $stmt->bind_param("si", $newStatus, $componentId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $check = $conn->prepare("
                    SELECT id, status
                    FROM salary_components
                    WHERE id = ?
                ");
                $check->bind_param("i", $componentId);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$exists) {
                    throw new Exception('Component not found.');
                }
            }

            $stmt->close();

            $message = 'Component status updated successfully.';
        }

        else {
            throw new Exception('Invalid action.');
        }

    } catch (mysqli_sql_exception $e) {

        if ((int)$e->getCode() === 1062) {
            $message = 'This component already exists for the selected type.';
        } else {
            error_log('Salary component error: ' . $e->getMessage());
            $message = 'Database operation failed. Please check the information.';
        }

        $messageType = 'danger';

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD COMPONENTS
========================================= */

$components = [];

$result = $conn->query("
    SELECT
        id,
        component_name,
        component_type,
        calculation_type,
        status,
        created_at
    FROM salary_components
    ORDER BY
        FIELD(component_type, 'earning', 'deduction'),
        component_name ASC
");

while ($row = $result->fetch_assoc()) {
    $components[] = $row;
}

$totalComponents = count($components);
$activeComponents = 0;
$earningCount = 0;
$deductionCount = 0;

foreach ($components as $component) {

    if ($component['status'] === 'active') {
        $activeComponents++;
    }

    if ($component['component_type'] === 'earning') {
        $earningCount++;
    } else {
        $deductionCount++;
    }
}

function sc_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Salary Components | Sezua SISMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
      rel="stylesheet">

<style>
body {
    background: #f3f6fa;
    font-family: Arial, sans-serif;
    color: #243247;
}

.page-header {
    background: linear-gradient(120deg, #102d4b, #1b4568);
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border: 1px solid #e6ebf1;
    border-radius: 12px;
    padding: 20px;
    height: 100%;
}

.stat-card h3 {
    font-weight: 700;
    margin: 8px 0 0;
}

.main-card {
    background: white;
    border: 1px solid #e6ebf1;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 24px;
}

.section-title {
    font-weight: 700;
    color: #173b60;
    margin-bottom: 18px;
}

.table th {
    background: #edf2f7;
    color: #33445a;
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.badge-earning {
    background: #d9f4e5;
    color: #147442;
}

.badge-deduction {
    background: #fde2e2;
    color: #a32626;
}

.badge-active {
    background: #d9f4e5;
    color: #147442;
}

.badge-inactive {
    background: #e9edf2;
    color: #586575;
}

.btn-primary {
    background: #173b60;
    border-color: #173b60;
}

.btn-primary:hover {
    background: #102d4b;
}

.form-control, .form-select {
    min-height: 43px;
}

@media(max-width: 576px) {
    .main-card {
        padding: 15px;
    }
}
</style>
</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <!-- HEADER -->

    <div class="page-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h3 class="mb-1">
                    <i class="fa-solid fa-money-check-dollar me-2"></i>
                    Salary Components
                </h3>
                <p class="mb-0 opacity-75">
                    Manage employee earnings and salary deductions.
                </p>
            </div>

            <a href="dashboard.php" class="btn btn-light">
                <i class="fa-solid fa-arrow-left me-1"></i>
                Dashboard
            </a>
        </div>
    </div>

    <!-- MESSAGE -->

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= sc_h($messageType) ?> alert-dismissible fade show">
            <?= sc_h($message) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-secondary small">Total Components</div>
                <h3><?= $totalComponents ?></h3>
                <i class="fa-solid fa-list-check text-primary"></i>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-secondary small">Active Components</div>
                <h3><?= $activeComponents ?></h3>
                <i class="fa-solid fa-circle-check text-success"></i>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-secondary small">Earnings</div>
                <h3><?= $earningCount ?></h3>
                <i class="fa-solid fa-arrow-trend-up text-success"></i>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="text-secondary small">Deductions</div>
                <h3><?= $deductionCount ?></h3>
                <i class="fa-solid fa-arrow-trend-down text-danger"></i>
            </div>
        </div>

    </div>

    <!-- ADD COMPONENT -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-circle-plus me-2"></i>
            Add New Salary Component
        </h5>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= sc_h(csrf_token()) ?>">

            <input type="hidden" name="action" value="add">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Component Name *</label>
                    <input type="text"
                           name="component_name"
                           class="form-control"
                           maxlength="100"
                           placeholder="Example: Festival Bonus"
                           required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Component Type *</label>

                    <select name="component_type"
                            class="form-select" required>

                        <option value="">Select Type</option>
                        <option value="earning">Earning</option>
                        <option value="deduction">Deduction</option>

                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Calculation Type *</label>

                    <select name="calculation_type"
                            class="form-select" required>

                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage</option>

                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-plus me-1"></i>
                        Add
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- COMPONENT LIST -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-table-list me-2"></i>
            Salary Component List
        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Component Name</th>
                        <th>Type</th>
                        <th>Calculation</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($components)): ?>

                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No salary components found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($components as $index => $component): ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= sc_h($component['component_name']) ?>
                                </strong>
                            </td>

                            <td>
                                <?php if ($component['component_type'] === 'earning'): ?>

                                    <span class="badge badge-earning">
                                        Earning
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-deduction">
                                        Deduction
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?= $component['calculation_type'] === 'fixed'
                                    ? 'Fixed Amount'
                                    : 'Percentage' ?>
                            </td>

                            <td>
                                <?php if ($component['status'] === 'active'): ?>

                                    <span class="badge badge-active">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-inactive">
                                        Inactive
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?= sc_h(date(
                                    'd M Y',
                                    strtotime($component['created_at'])
                                )) ?>
                            </td>

                            <td>

                                <form method="POST"
                                      onsubmit="return confirm('Change this component status?');">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= sc_h(csrf_token()) ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="toggle_status">

                                    <input type="hidden"
                                           name="component_id"
                                           value="<?= (int)$component['id'] ?>">

                                    <input type="hidden"
                                           name="new_status"
                                           value="<?= $component['status'] === 'active'
                                               ? 'inactive'
                                               : 'active' ?>">

                                    <?php if ($component['status'] === 'active'): ?>

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-ban me-1"></i>
                                            Deactivate
                                        </button>

                                    <?php else: ?>

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-success">
                                            <i class="fa-solid fa-check me-1"></i>
                                            Activate
                                        </button>

                                    <?php endif; ?>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        <strong>Important:</strong> Deactivating a component prevents it from
        being selected for new salary structures. Existing payroll records
        remain unchanged. Percentage calculations will be implemented in
        the payroll calculation stage.
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
