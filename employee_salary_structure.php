
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function ess_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   SAVE EMPLOYEE SALARY COMPONENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    try {

        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $componentId = (int)($_POST['component_id'] ?? 0);
        $amountInput = trim($_POST['amount'] ?? '');
        $effectiveFrom = $_POST['effective_from'] ?? '';
        $effectiveToInput = trim($_POST['effective_to'] ?? '');

        if ($employeeId <= 0 || $componentId <= 0) {
            throw new Exception('Please select an employee and salary component.');
        }

        if ($amountInput === '' || !is_numeric($amountInput)) {
            throw new Exception('Please enter a valid amount or percentage.');
        }

        $amount = (float)$amountInput;

        if (!is_finite($amount) || $amount < 0 || $amount > 9999999999.99) {
            throw new Exception('Please enter a valid non-negative amount.');
        }

        $dateFrom = DateTime::createFromFormat('!Y-m-d', $effectiveFrom);

        if (!$dateFrom || $dateFrom->format('Y-m-d') !== $effectiveFrom) {
            throw new Exception('Please select a valid effective start date.');
        }

        $effectiveTo = null;

        if ($effectiveToInput !== '') {

            $dateTo = DateTime::createFromFormat('!Y-m-d', $effectiveToInput);

            if (!$dateTo || $dateTo->format('Y-m-d') !== $effectiveToInput) {
                throw new Exception('Please select a valid effective end date.');
            }

            if ($effectiveToInput < $effectiveFrom) {
                throw new Exception('End date cannot be earlier than start date.');
            }

            $effectiveTo = $effectiveToInput;
        }

        $conn->begin_transaction();

        /* VERIFY EMPLOYEE */

        $stmt = $conn->prepare("
            SELECT id
            FROM employees
            WHERE id = ?
              AND employment_status = 'active'
            FOR UPDATE
        ");

        $stmt->bind_param("i", $employeeId);
        $stmt->execute();

        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            throw new Exception('Selected employee does not exist or is not active.');
        }

        /* VERIFY COMPONENT */

        $stmt = $conn->prepare("
            SELECT id, calculation_type
            FROM salary_components
            WHERE id = ?
              AND status = 'active'
            FOR UPDATE
        ");

        $stmt->bind_param("i", $componentId);
        $stmt->execute();

        $component = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$component) {
            throw new Exception('Selected salary component is not active.');
        }

        /*
         * Prevent overlapping assignments for the same
         * employee and component.
         */

        $stmt = $conn->prepare("
            SELECT id
            FROM employee_salary_components
            WHERE employee_id = ?
              AND component_id = ?
              AND effective_from <= COALESCE(?, '9999-12-31')
              AND COALESCE(effective_to, '9999-12-31') >= ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->bind_param(
            "iiss",
            $employeeId,
            $componentId,
            $effectiveTo,
            $effectiveFrom
        );

        $stmt->execute();
        $overlap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($overlap) {
            throw new Exception(
                'This employee already has an overlapping assignment for this component. Please adjust the existing dates first.'
            );
        }

        /* INSERT SALARY COMPONENT */

        $stmt = $conn->prepare("
            INSERT INTO employee_salary_components (
                employee_id,
                component_id,
                amount,
                effective_from,
                effective_to
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "iidss",
            $employeeId,
            $componentId,
            $amount,
            $effectiveFrom,
            $effectiveTo
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();

        $message = 'Employee salary component assigned successfully.';

    } catch (mysqli_sql_exception $e) {

        $conn->rollback();

        error_log('Employee salary structure error: ' . $e->getMessage());

        if ((int)$e->getCode() === 1062) {
            $message = 'This salary assignment already exists.';
        } else {
            $message = 'Database operation failed. Please check the database and try again.';
        }

        $messageType = 'danger';

    } catch (Exception $e) {

        $conn->rollback();

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD ACTIVE EMPLOYEES
========================================= */

$employees = [];

$result = $conn->query("
    SELECT
        id,
        employee_code,
        first_name_en,
        last_name_en,
        phone
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY first_name_en, last_name_en
");

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

/* =========================================
   LOAD ACTIVE SALARY COMPONENTS
========================================= */

$components = [];

$result = $conn->query("
    SELECT
        id,
        component_name,
        component_type,
        calculation_type
    FROM salary_components
    WHERE status = 'active'
    ORDER BY
        FIELD(component_type, 'earning', 'deduction'),
        component_name
");

while ($row = $result->fetch_assoc()) {
    $components[] = $row;
}

/* =========================================
   LOAD EXISTING SALARY STRUCTURES
========================================= */

$structures = [];

$result = $conn->query("
    SELECT
        esc.id,
        esc.employee_id,
        esc.component_id,
        esc.amount,
        esc.effective_from,
        esc.effective_to,

        e.employee_code,
        e.first_name_en,
        e.last_name_en,

        sc.component_name,
        sc.component_type,
        sc.calculation_type

    FROM employee_salary_components esc

    INNER JOIN employees e
        ON e.id = esc.employee_id

    INNER JOIN salary_components sc
        ON sc.id = esc.component_id

    ORDER BY
        e.first_name_en,
        esc.effective_from DESC,
        sc.component_name
");

while ($row = $result->fetch_assoc()) {
    $structures[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Salary Structure | Sezua SISMS</title>

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

.main-card {
    background: white;
    border: 1px solid #e6ebf1;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 24px;
}

.section-title {
    color: #173b60;
    font-weight: 700;
    margin-bottom: 20px;
}

.form-control,
.form-select {
    min-height: 43px;
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

.badge-fixed {
    background: #e1ecfa;
    color: #174c83;
}

.badge-percentage {
    background: #fff0ce;
    color: #805900;
}

.btn-primary {
    background: #173b60;
    border-color: #173b60;
}

.btn-primary:hover {
    background: #102d4b;
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

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>

                <h3 class="mb-1">
                    <i class="fa-solid fa-users-gear me-2"></i>
                    Employee Salary Structure
                </h3>

                <p class="mb-0 opacity-75">
                    Assign earnings and deductions to individual employees.
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

        <div class="alert alert-<?= ess_h($messageType) ?> alert-dismissible fade show">

            <?= ess_h($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- ASSIGN COMPONENT -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-circle-plus me-2"></i>
            Assign Salary Component
        </h5>

        <?php if (empty($employees)): ?>

            <div class="alert alert-warning">
                No active employees found. Please register employees first.
            </div>

        <?php elseif (empty($components)): ?>

            <div class="alert alert-warning">
                No active salary components found. Please create salary components first.
            </div>

        <?php else: ?>

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= ess_h(csrf_token()) ?>">

                <div class="row g-3">

                    <!-- EMPLOYEE -->

                    <div class="col-md-6">

                        <label class="form-label">
                            Select Employee *
                        </label>

                        <select name="employee_id"
                                class="form-select"
                                required>

                            <option value="">Choose Employee</option>

                            <?php foreach ($employees as $employee): ?>

                                <option value="<?= (int)$employee['id'] ?>">

                                    <?= ess_h(
                                        $employee['employee_code'] . ' - ' .
                                        $employee['first_name_en'] . ' ' .
                                        $employee['last_name_en']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- COMPONENT -->

                    <div class="col-md-6">

                        <label class="form-label">
                            Salary Component *
                        </label>

                        <select name="component_id"
                                id="component_id"
                                class="form-select"
                                required>

                            <option value="">Choose Component</option>

                            <?php foreach ($components as $component): ?>

                                <option
                                    value="<?= (int)$component['id'] ?>"
                                    data-type="<?= ess_h($component['component_type']) ?>"
                                    data-calculation="<?= ess_h($component['calculation_type']) ?>"
                                >

                                    <?= ess_h($component['component_name']) ?>
                                    -
                                    <?= ucfirst($component['component_type']) ?>
                                    (<?= ucfirst($component['calculation_type']) ?>)

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- AMOUNT -->

                    <div class="col-md-4">

                        <label class="form-label" id="amount_label">
                            Amount *
                        </label>

                        <input type="number"
                               name="amount"
                               class="form-control"
                               min="0"
                               max="9999999999.99"
                               step="0.01"
                               placeholder="Enter amount"
                               required>

                        <small class="text-muted" id="amount_help">
                            Enter the fixed amount in BDT.
                        </small>

                    </div>

                    <!-- START DATE -->

                    <div class="col-md-4">

                        <label class="form-label">
                            Effective From *
                        </label>

                        <input type="date"
                               name="effective_from"
                               class="form-control"
                               value="<?= date('Y-m-d') ?>"
                               required>

                    </div>

                    <!-- END DATE -->

                    <div class="col-md-4">

                        <label class="form-label">
                            Effective To
                        </label>

                        <input type="date"
                               name="effective_to"
                               class="form-control">

                        <small class="text-muted">
                            Leave empty if the assignment has no end date.
                        </small>

                    </div>

                    <div class="col-12">

                        <button type="submit" class="btn btn-primary">

                            <i class="fa-solid fa-floppy-disk me-1"></i>
                            Assign Component

                        </button>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>

    <!-- EXISTING STRUCTURES -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-table-list me-2"></i>
            Employee Salary Assignments
        </h5>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Component</th>
                        <th>Type</th>
                        <th>Calculation</th>
                        <th>Value</th>
                        <th>Effective From</th>
                        <th>Effective To</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (empty($structures)): ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No employee salary assignments found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($structures as $index => $structure): ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td>

                                <strong>
                                    <?= ess_h(
                                        $structure['first_name_en'] . ' ' .
                                        $structure['last_name_en']
                                    ) ?>
                                </strong>

                                <br>

                                <small class="text-muted">
                                    <?= ess_h($structure['employee_code']) ?>
                                </small>

                            </td>

                            <td>
                                <?= ess_h($structure['component_name']) ?>
                            </td>

                            <td>

                                <?php if ($structure['component_type'] === 'earning'): ?>

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

                                <?php if ($structure['calculation_type'] === 'fixed'): ?>

                                    <span class="badge badge-fixed">
                                        Fixed
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-percentage">
                                        Percentage
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <strong>
                                    <?php if ($structure['calculation_type'] === 'percentage'): ?>

                                        <?= number_format((float)$structure['amount'], 2) ?>%

                                    <?php else: ?>

                                        ৳<?= number_format((float)$structure['amount'], 2) ?>

                                    <?php endif; ?>
                                </strong>
                            </td>

                            <td>
                                <?= ess_h(date(
                                    'd M Y',
                                    strtotime($structure['effective_from'])
                                )) ?>
                            </td>

                            <td>

                                <?php if ($structure['effective_to']): ?>

                                    <?= ess_h(date(
                                        'd M Y',
                                        strtotime($structure['effective_to'])
                                    )) ?>

                                <?php else: ?>

                                    <span class="badge bg-success">
                                        Ongoing
                                    </span>

                                <?php endif; ?>

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

        <strong>Important:</strong>

        Fixed components are stored as BDT amounts.
        Percentage components are stored as percentage values.
        Actual percentage calculations will be implemented during payroll generation.

        Each assignment has its own effective dates. Overlapping assignments
        for the same employee and component are not allowed.

    </div>

</div>

<script>

const componentSelect = document.getElementById('component_id');
const amountLabel = document.getElementById('amount_label');
const amountHelp = document.getElementById('amount_help');

if (componentSelect) {

    componentSelect.addEventListener('change', function () {

        const selected = this.options[this.selectedIndex];

        const calculation = selected.dataset.calculation || 'fixed';

        if (calculation === 'percentage') {

            amountLabel.textContent = 'Percentage (%) *';

            amountHelp.textContent =
                'Enter a percentage value. Example: 10 for 10%.';

        } else {

            amountLabel.textContent = 'Amount (BDT) *';

            amountHelp.textContent =
                'Enter the fixed amount in BDT.';

        }

    });

}

</script>

</body>
</html>
