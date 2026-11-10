
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';
$inTransaction = false;

function pg_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   GENERATE MONTHLY PAYROLL
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    try {

        $monthInput = trim($_POST['payroll_month'] ?? '');

        $monthDate = DateTime::createFromFormat('!Y-m', $monthInput);

        if (
            !$monthDate ||
            $monthDate->format('Y-m') !== $monthInput
        ) {
            throw new Exception('Please select a valid payroll month.');
        }

        $payrollMonth = $monthDate->format('Y-m-01');

        $monthEndDate = clone $monthDate;
        $monthEndDate->modify('last day of this month');

        $monthEnd = $monthEndDate->format('Y-m-d');

        $generatedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($generatedBy <= 0) {
            throw new Exception('Your user session is invalid. Please log in again.');
        }

        /* =====================================
           CHECK EXISTING PAYROLL
        ===================================== */

        $stmt = $conn->prepare("
            SELECT id, status
            FROM payroll_runs
            WHERE payroll_month = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $payrollMonth);
        $stmt->execute();

        $existingRun = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($existingRun) {
            throw new Exception(
                'Payroll already exists for ' .
                $monthDate->format('F Y') .
                '. Existing status: ' .
                ucfirst($existingRun['status']) . '.'
            );
        }

        /* =====================================
           LOAD ACTIVE EMPLOYEES
        ===================================== */

        $employees = [];

        $result = $conn->query("
            SELECT
                id,
                employee_code,
                first_name_en,
                last_name_en
            FROM employees
            WHERE employment_status = 'active'
            ORDER BY first_name_en, last_name_en
        ");

        while ($row = $result->fetch_assoc()) {

            $employees[(int)$row['id']] = $row;

        }

        if (empty($employees)) {
            throw new Exception('No active employees found.');
        }

        /* =====================================
           LOAD EFFECTIVE SALARY ASSIGNMENTS

           FIX:
           Include assignments that overlap
           ANY DATE in the selected month.

           Latest assignment wins for each
           employee/component combination.
        ===================================== */

        $assignments = [];

        $stmt = $conn->prepare("
            SELECT
                esc.id AS assignment_id,
                esc.employee_id,
                esc.component_id,
                esc.amount,
                esc.effective_from,
                esc.effective_to,

                sc.component_name,
                sc.component_type,
                sc.calculation_type

            FROM employee_salary_components esc

            INNER JOIN salary_components sc
                ON sc.id = esc.component_id

            WHERE esc.effective_from <= ?
              AND (
                    esc.effective_to IS NULL
                    OR esc.effective_to >= ?
                  )
              AND sc.status = 'active'

            ORDER BY
                esc.employee_id ASC,
                esc.component_id ASC,
                esc.effective_from DESC,
                esc.id DESC
        ");

        $stmt->bind_param(
            "ss",
            $monthEnd,
            $payrollMonth
        );

        $stmt->execute();

        $assignmentResult = $stmt->get_result();

        /*
         * Because results are sorted by latest
         * effective_from first, the first record
         * for each employee/component is selected.
         */

        $selectedAssignments = [];

        while ($row = $assignmentResult->fetch_assoc()) {

            $employeeId = (int)$row['employee_id'];
            $componentId = (int)$row['component_id'];

            if (!isset($employees[$employeeId])) {
                continue;
            }

            $key = $employeeId . '_' . $componentId;

            if (isset($selectedAssignments[$key])) {
                continue;
            }

            $selectedAssignments[$key] = true;

            $assignments[$employeeId][] = $row;
        }

        $stmt->close();

        /* =====================================
           CALCULATE EMPLOYEE PAYROLL
        ===================================== */

        $payrollData = [];

        $missingBasicSalary = [];
        $invalidPayroll = [];

        $grandGross = 0;
        $grandDeduction = 0;
        $grandNet = 0;

        foreach ($employees as $employeeId => $employee) {

            $employeeName = trim(
                $employee['first_name_en'] . ' ' .
                $employee['last_name_en']
            );

            $employeeLabel =
                $employee['employee_code'] . ' - ' . $employeeName;

            $employeeAssignments = $assignments[$employeeId] ?? [];

            if (empty($employeeAssignments)) {

                $missingBasicSalary[] =
                    $employeeLabel . ' (No effective salary assignment)';

                continue;
            }

            /* =================================
               FIND BASIC SALARY
            ================================= */

            $basicSalary = null;

            foreach ($employeeAssignments as $assignment) {

                $componentName = strtolower(
                    trim($assignment['component_name'])
                );

                $componentType = strtolower(
                    trim($assignment['component_type'])
                );

                if (
                    $componentName === 'basic salary' &&
                    $componentType === 'earning'
                ) {

                    if ($assignment['calculation_type'] !== 'fixed') {

                        $invalidPayroll[] =
                            $employeeLabel .
                            ': Basic Salary must use Fixed Amount calculation.';

                        continue 2;
                    }

                    $basicSalary = (float)$assignment['amount'];

                    break;
                }
            }

            if ($basicSalary === null || $basicSalary <= 0) {

                $missingBasicSalary[] =
                    $employeeLabel .
                    ' (Missing valid Basic Salary assignment)';

                continue;
            }

            /* =================================
               CALCULATE COMPONENTS
            ================================= */

            $componentSnapshots = [];

            $grossSalary = 0;
            $totalDeduction = 0;

            foreach ($employeeAssignments as $assignment) {

                $assignedValue = (float)$assignment['amount'];

                $componentName = $assignment['component_name'];

                $componentType = strtolower(
                    trim($assignment['component_type'])
                );

                $calculationType = strtolower(
                    trim($assignment['calculation_type'])
                );

                if (
                    !is_finite($assignedValue) ||
                    $assignedValue < 0
                ) {

                    $invalidPayroll[] =
                        $employeeLabel .
                        ': Invalid amount for ' .
                        $componentName;

                    continue 2;
                }

                /* VALIDATE CALCULATION TYPE */

                if (!in_array(
                    $calculationType,
                    ['fixed', 'percentage'],
                    true
                )) {

                    $invalidPayroll[] =
                        $employeeLabel .
                        ': Invalid calculation type for ' .
                        $componentName;

                    continue 2;
                }

                /* CALCULATE AMOUNT */

                if ($calculationType === 'percentage') {

                    /*
                     * All percentage-based components
                     * use Basic Salary as the base.
                     */

                    $calculatedAmount = round(
                        ($basicSalary * $assignedValue) / 100,
                        2
                    );

                } else {

                    $calculatedAmount = round(
                        $assignedValue,
                        2
                    );

                }

                if (
                    !is_finite($calculatedAmount) ||
                    $calculatedAmount < 0
                ) {

                    $invalidPayroll[] =
                        $employeeLabel .
                        ': Invalid calculated amount for ' .
                        $componentName;

                    continue 2;
                }

                /* ADD EARNINGS AND DEDUCTIONS */

                if ($componentType === 'earning') {

                    $grossSalary += $calculatedAmount;

                } elseif ($componentType === 'deduction') {

                    $totalDeduction += $calculatedAmount;

                } else {

                    $invalidPayroll[] =
                        $employeeLabel .
                        ': Invalid component type for ' .
                        $componentName;

                    continue 2;
                }

                /* SAVE COMPONENT SNAPSHOT */

                $componentSnapshots[] = [
                    'name' => $componentName,
                    'type' => $componentType,
                    'amount' => $calculatedAmount
                ];
            }

            $grossSalary = round($grossSalary, 2);
            $totalDeduction = round($totalDeduction, 2);

            $netSalary = round(
                $grossSalary - $totalDeduction,
                2
            );

            /* VALIDATE NET SALARY */

            if ($grossSalary <= 0) {

                $invalidPayroll[] =
                    $employeeLabel .
                    ': Gross salary must be greater than zero.';

                continue;
            }

            if ($netSalary < 0) {

                $invalidPayroll[] =
                    $employeeLabel .
                    ': Total deductions exceed gross salary.';

                continue;
            }

            /* ADD TO PAYROLL DATA */

            $payrollData[] = [
                'employee_id' => $employeeId,
                'gross' => $grossSalary,
                'deduction' => $totalDeduction,
                'net' => $netSalary,
                'components' => $componentSnapshots
            ];

            $grandGross += $grossSalary;
            $grandDeduction += $totalDeduction;
            $grandNet += $netSalary;
        }

        /* =====================================
           VALIDATION BEFORE SAVING
        ===================================== */

        if (!empty($missingBasicSalary)) {

            $details = implode(
                ', ',
                array_slice($missingBasicSalary, 0, 10)
            );

            $extra = count($missingBasicSalary) > 10
                ? ' and ' .
                    (count($missingBasicSalary) - 10) .
                    ' more'
                : '';

            throw new Exception(
                'Payroll was not generated. These active employees need an effective Basic Salary assignment: ' .
                $details . $extra
            );
        }

        if (!empty($invalidPayroll)) {

            $details = implode(
                ' | ',
                array_slice($invalidPayroll, 0, 10)
            );

            throw new Exception(
                'Payroll validation failed: ' . $details
            );
        }

        if (empty($payrollData)) {
            throw new Exception('No eligible employee payroll records found.');
        }

        $employeeCount = count($payrollData);

        $grandGross = round($grandGross, 2);
        $grandDeduction = round($grandDeduction, 2);
        $grandNet = round($grandNet, 2);

        /* =====================================
           SAVE PAYROLL TRANSACTION
        ===================================== */

        $conn->begin_transaction();

        $inTransaction = true;

        /* CREATE PAYROLL RUN */

        $stmt = $conn->prepare("
            INSERT INTO payroll_runs (
                payroll_month,
                total_employees,
                total_gross,
                total_deductions,
                total_net,
                status,
                generated_by
            )
            VALUES (?, ?, ?, ?, ?, 'draft', ?)
        ");

        $stmt->bind_param(
            "sidddi",
            $payrollMonth,
            $employeeCount,
            $grandGross,
            $grandDeduction,
            $grandNet,
            $generatedBy
        );

        $stmt->execute();

        $payrollRunId = $conn->insert_id;

        $stmt->close();

        /* PREPARE PAYROLL ITEM INSERT */

        $itemStmt = $conn->prepare("
            INSERT INTO payroll_items (
                payroll_run_id,
                employee_id,
                gross_salary,
                total_deduction,
                net_salary,
                payment_status
            )
            VALUES (?, ?, ?, ?, ?, 'unpaid')
        ");

        /* PREPARE COMPONENT SNAPSHOT INSERT */

        $componentStmt = $conn->prepare("
            INSERT INTO payroll_item_components (
                payroll_item_id,
                component_name,
                component_type,
                amount
            )
            VALUES (?, ?, ?, ?)
        ");

        /* INSERT EMPLOYEE PAYROLL */

        foreach ($payrollData as $item) {

            $employeeId = (int)$item['employee_id'];

            $gross = (float)$item['gross'];
            $deduction = (float)$item['deduction'];
            $net = (float)$item['net'];

            $itemStmt->bind_param(
                "iiddd",
                $payrollRunId,
                $employeeId,
                $gross,
                $deduction,
                $net
            );

            $itemStmt->execute();

            $payrollItemId = $conn->insert_id;

            /* INSERT COMPONENT SNAPSHOTS */

            foreach ($item['components'] as $component) {

                $componentName = $component['name'];
                $componentType = $component['type'];
                $componentAmount = (float)$component['amount'];

                $componentStmt->bind_param(
                    "issd",
                    $payrollItemId,
                    $componentName,
                    $componentType,
                    $componentAmount
                );

                $componentStmt->execute();
            }
        }

        $itemStmt->close();
        $componentStmt->close();

        /* COMMIT */

        $conn->commit();

        $inTransaction = false;

        $message =
            'Draft payroll generated successfully for ' .
            $monthDate->format('F Y') .
            '. Employees: ' . $employeeCount .
            ', Gross: ৳' . number_format($grandGross, 2) .
            ', Deductions: ৳' . number_format($grandDeduction, 2) .
            ', Net: ৳' . number_format($grandNet, 2) .
            '. Payroll Run ID: ' . $payrollRunId;

        $messageType = 'success';

    } catch (mysqli_sql_exception $e) {

        if ($inTransaction) {
            $conn->rollback();
            $inTransaction = false;
        }

        error_log(
            'Payroll generation SQL error: ' .
            $e->getMessage()
        );

        if ((int)$e->getCode() === 1062) {

            $message = 'Payroll already exists for this month.';

        } else {

            $message =
                'A database error occurred. Check the server error log.';
        }

        $messageType = 'danger';

    } catch (Throwable $e) {

        if ($inTransaction) {
            $conn->rollback();
            $inTransaction = false;
        }

        error_log(
            'Payroll generation error: ' .
            $e->getMessage()
        );

        $message = $e->getMessage();

        $messageType = 'danger';
    }
}

/* =========================================
   LOAD PAYROLL RUN HISTORY
========================================= */

$payrollRuns = [];

$result = $conn->query("
    SELECT
        id,
        payroll_month,
        total_employees,
        total_gross,
        total_deductions,
        total_net,
        status,
        created_at
    FROM payroll_runs
    ORDER BY payroll_month DESC
    LIMIT 100
");

while ($row = $result->fetch_assoc()) {
    $payrollRuns[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Monthly Payroll Generation | Sezua SISMS</title>

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

.form-control {
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

.btn-primary {
    background: #173b60;
    border-color: #173b60;
}

.btn-primary:hover {
    background: #102d4b;
}

.status-draft {
    background: #fff0ce;
    color: #805900;
}

.status-processed {
    background: #dceaff;
    color: #174c83;
}

.status-paid {
    background: #d9f4e5;
    color: #147442;
}

.status-cancelled {
    background: #fde2e2;
    color: #a32626;
}

@media(max-width: 576px) {

    .main-card {
        padding: 15px;
    }

    .page-header {
        padding: 18px;
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
                    <i class="fa-solid fa-calculator me-2"></i>
                    Monthly Payroll Generation
                </h3>

                <p class="mb-0 opacity-75">
                    Calculate employee salaries and create monthly payroll drafts.
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

        <div class="alert alert-<?= pg_h($messageType) ?> alert-dismissible fade show">

            <?= pg_h($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- GENERATE PAYROLL -->

    <div class="main-card">

        <h5 class="section-title">

            <i class="fa-solid fa-file-circle-plus me-2"></i>
            Generate New Monthly Payroll

        </h5>

        <div class="alert alert-info">

            <strong>Calculation rules:</strong>

            <ul class="mb-0 mt-2">

                <li>
                    All percentage-based earnings and deductions are calculated using Basic Salary.
                </li>

                <li>
                    Salary assignments overlapping the selected month are considered.
                </li>

                <li>
                    If multiple assignments exist for the same component, the latest effective assignment is selected.
                </li>

                <li>
                    Assignments effective during the month are included for the full month; no automatic prorating is applied.
                </li>

                <li>
                    Only active salary components and active employees are included.
                </li>

            </ul>

        </div>

        <form method="POST"
              onsubmit="return confirm('Generate draft payroll for this month? This action cannot be repeated for the same month.');">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= pg_h(csrf_token()) ?>">

            <div class="row g-3 align-items-end">

                <div class="col-md-6">

                    <label class="form-label">
                        Select Payroll Month *
                    </label>

                    <input type="month"
                           name="payroll_month"
                           class="form-control"
                           value="<?= pg_h($_POST['payroll_month'] ?? date('Y-m')) ?>"
                           required>

                </div>

                <div class="col-md-6">

                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-calculator me-2"></i>
                        Generate Draft Payroll

                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- PAYROLL HISTORY -->

    <div class="main-card">

        <h5 class="section-title">

            <i class="fa-solid fa-clock-rotate-left me-2"></i>
            Payroll History

        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>

                        <th>Run ID</th>
                        <th>Payroll Month</th>
                        <th>Employees</th>
                        <th>Gross Salary</th>
                        <th>Deductions</th>
                        <th>Net Salary</th>
                        <th>Status</th>
                        <th>Created</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($payrollRuns)): ?>

                    <tr>

                        <td colspan="8"
                            class="text-center text-muted py-4">

                            No payroll runs have been generated yet.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($payrollRuns as $run): ?>

                        <?php

                        $status = strtolower(
                            trim($run['status'])
                        );

                        $allowedStatuses = [
                            'draft',
                            'processed',
                            'paid',
                            'cancelled'
                        ];

                        if (!in_array($status, $allowedStatuses, true)) {
                            $statusClass = 'bg-secondary text-white';
                        } else {
                            $statusClass = 'status-' . $status;
                        }

                        ?>

                        <tr>

                            <td>
                                <strong>
                                    #<?= (int)$run['id'] ?>
                                </strong>
                            </td>

                            <td>

                                <?= pg_h(date(
                                    'F Y',
                                    strtotime($run['payroll_month'])
                                )) ?>

                            </td>

                            <td>
                                <?= number_format((int)$run['total_employees']) ?>
                            </td>

                            <td>
                                ৳<?= number_format((float)$run['total_gross'], 2) ?>
                            </td>

                            <td>
                                ৳<?= number_format((float)$run['total_deductions'], 2) ?>
                            </td>

                            <td>

                                <strong>
                                    ৳<?= number_format((float)$run['total_net'], 2) ?>
                                </strong>

                            </td>

                            <td>

                                <span class="badge <?= pg_h($statusClass) ?>">

                                    <?= pg_h(ucfirst($status)) ?>

                                </span>

                            </td>

                            <td>

                                <?= pg_h(date(
                                    'd M Y',
                                    strtotime($run['created_at'])
                                )) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- IMPORTANT NOTICE -->

    <div class="alert alert-warning">

        <i class="fa-solid fa-triangle-exclamation me-2"></i>

        <strong>Important:</strong>

        This module creates draft payroll only. It does not mark salaries
        as processed or paid. Those actions will be handled in separate
        verification and payment modules.

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
