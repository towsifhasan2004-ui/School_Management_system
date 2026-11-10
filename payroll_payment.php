
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function pp_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   RECORD EMPLOYEE SALARY PAYMENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $runId = (int)($_POST['payroll_run_id'] ?? 0);
    $itemId = (int)($_POST['payroll_item_id'] ?? 0);
    $paymentDate = trim($_POST['payment_date'] ?? '');

    try {

        if ($runId <= 0 || $itemId <= 0) {
            throw new Exception('Invalid payroll or employee record.');
        }

        $dateObject = DateTime::createFromFormat('!Y-m-d', $paymentDate);

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $paymentDate
        ) {
            throw new Exception('Please enter a valid payment date.');
        }

        if ($dateObject > new DateTime('today')) {
            throw new Exception('Payment date cannot be in the future.');
        }

        $paidBy = (int)($_SESSION['user_id'] ?? 0);

        if ($paidBy <= 0) {
            throw new Exception('Invalid user session.');
        }

        $conn->begin_transaction();

        /* LOCK PAYROLL RUN FIRST */

        $stmt = $conn->prepare("
            SELECT id, payroll_month, status
            FROM payroll_runs
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("i", $runId);
        $stmt->execute();

        $run = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$run) {
            throw new Exception('Payroll run not found.');
        }

        if (!in_array($run['status'], ['processed', 'paid'], true)) {
            throw new Exception(
                'Only processed payroll can receive salary payments.'
            );
        }

        /* LOCK THE EMPLOYEE PAYROLL ITEM */

        $stmt = $conn->prepare("
            SELECT id, payment_status
            FROM payroll_items
            WHERE id = ?
              AND payroll_run_id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("ii", $itemId, $runId);
        $stmt->execute();

        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new Exception('Employee payroll record not found.');
        }

        if ($item['payment_status'] === 'paid') {
            throw new Exception('This employee has already been marked as paid.');
        }

        /* MARK EMPLOYEE AS PAID */

        $stmt = $conn->prepare("
            UPDATE payroll_items
            SET
                payment_status = 'paid',
                payment_date = ?
            WHERE id = ?
              AND payroll_run_id = ?
              AND payment_status = 'unpaid'
        ");

        $stmt->bind_param("sii", $paymentDate, $itemId, $runId);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new Exception('Payment update failed. Please refresh the page.');
        }

        $stmt->close();

        /* CHECK REMAINING UNPAID EMPLOYEES */

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total_items,
                SUM(
                    CASE
                        WHEN payment_status = 'unpaid' THEN 1
                        ELSE 0
                    END
                ) AS unpaid_items
            FROM payroll_items
            WHERE payroll_run_id = ?
        ");

        $stmt->bind_param("i", $runId);
        $stmt->execute();

        $paymentSummary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalItems = (int)$paymentSummary['total_items'];
        $unpaidItems = (int)$paymentSummary['unpaid_items'];

        if ($totalItems <= 0) {
            throw new Exception('No employee payroll records found.');
        }

        /* COMPLETE THE PAYROLL RUN WHEN ALL ARE PAID */

        if ($unpaidItems === 0) {

            $stmt = $conn->prepare("
                UPDATE payroll_runs
                SET
                    status = 'paid',
                    paid_by = ?,
                    paid_at = NOW()
                WHERE id = ?
                  AND status = 'processed'
            ");

            $stmt->bind_param("ii", $paidBy, $runId);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        if ($unpaidItems === 0) {
            $message = 'Payment recorded. All employees have been paid. Payroll is now marked as Paid.';
        } else {
            $message = 'Employee salary payment recorded successfully. Remaining unpaid employees: ' . $unpaidItems;
        }

    } catch (mysqli_sql_exception $e) {

        $conn->rollback();

        error_log('Payroll payment SQL error: ' . $e->getMessage());

        $message = 'A database error occurred while recording the payment.';
        $messageType = 'danger';

    } catch (Exception $e) {

        $conn->rollback();

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD PAYROLL RUNS
========================================= */

$payrollRuns = [];

$result = $conn->query("
    SELECT
        pr.id,
        pr.payroll_month,
        pr.total_employees,
        pr.total_gross,
        pr.total_deductions,
        pr.total_net,
        pr.status,
        pr.paid_at,

        COALESCE(SUM(
            CASE
                WHEN pi.payment_status = 'paid' THEN 1
                ELSE 0
            END
        ), 0) AS paid_count,

        COALESCE(SUM(
            CASE
                WHEN pi.payment_status = 'unpaid' THEN 1
                ELSE 0
            END
        ), 0) AS unpaid_count

    FROM payroll_runs pr

    LEFT JOIN payroll_items pi
        ON pi.payroll_run_id = pr.id

    WHERE pr.status IN ('processed', 'paid')

    GROUP BY
        pr.id,
        pr.payroll_month,
        pr.total_employees,
        pr.total_gross,
        pr.total_deductions,
        pr.total_net,
        pr.status,
        pr.paid_at

    ORDER BY pr.payroll_month DESC
    LIMIT 100
");

while ($row = $result->fetch_assoc()) {
    $payrollRuns[] = $row;
}

/* =========================================
   SELECTED PAYROLL RUN
========================================= */

$selectedRunId = (int)($_GET['run_id'] ?? 0);

$selectedRun = null;
$employees = [];

if ($selectedRunId > 0) {

    $stmt = $conn->prepare("
        SELECT *
        FROM payroll_runs
        WHERE id = ?
          AND status IN ('processed', 'paid')
    ");

    $stmt->bind_param("i", $selectedRunId);
    $stmt->execute();

    $selectedRun = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedRun) {

        $stmt = $conn->prepare("
            SELECT
                pi.id AS payroll_item_id,
                pi.employee_id,
                pi.gross_salary,
                pi.total_deduction,
                pi.net_salary,
                pi.payment_status,
                pi.payment_date,

                e.employee_code,
                e.first_name_en,
                e.last_name_en,
                e.phone

            FROM payroll_items pi

            INNER JOIN employees e
                ON e.id = pi.employee_id

            WHERE pi.payroll_run_id = ?

            ORDER BY
                pi.payment_status ASC,
                e.first_name_en ASC,
                e.last_name_en ASC
        ");

        $stmt->bind_param("i", $selectedRunId);
        $stmt->execute();

        $employeeResult = $stmt->get_result();

        while ($row = $employeeResult->fetch_assoc()) {
            $employees[] = $row;
        }

        $stmt->close();

    } else {

        $message = 'Processed or paid payroll run not found.';
        $messageType = 'danger';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payroll Payments | Sezua SISMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
      rel="stylesheet">

<style>

body {
    background: #f3f6fa;
    color: #243247;
    font-family: Arial, sans-serif;
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

.table th {
    background: #edf2f7;
    color: #33445a;
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.status-paid {
    background: #d9f4e5;
    color: #147442;
}

.status-unpaid {
    background: #fff0ce;
    color: #805900;
}

.status-processed {
    background: #dceaff;
    color: #174c83;
}

.status-run-paid {
    background: #d9f4e5;
    color: #147442;
}

.btn-primary {
    background: #173b60;
    border-color: #173b60;
}

.btn-primary:hover {
    background: #102d4b;
}

.summary-box {
    border: 1px solid #e6ebf1;
    border-radius: 10px;
    padding: 16px;
    height: 100%;
}

.summary-box small {
    color: #68788b;
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
                    <i class="fa-solid fa-money-check-dollar me-2"></i>
                    Employee Salary Payments
                </h3>

                <p class="mb-0 opacity-75">
                    Record employee salary payments and track unpaid salaries.
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

        <div class="alert alert-<?= pp_h($messageType) ?> alert-dismissible fade show">

            <?= pp_h($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- PAYROLL RUN LIST -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-calendar-check me-2"></i>
            Processed Payroll Runs
        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Run ID</th>
                        <th>Payroll Month</th>
                        <th>Employees</th>
                        <th>Net Salary</th>
                        <th>Paid</th>
                        <th>Unpaid</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($payrollRuns)): ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No processed payroll runs are available.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($payrollRuns as $run): ?>

                        <tr>

                            <td>#<?= (int)$run['id'] ?></td>

                            <td>
                                <?= pp_h(date(
                                    'F Y',
                                    strtotime($run['payroll_month'])
                                )) ?>
                            </td>

                            <td>
                                <?= (int)$run['total_employees'] ?>
                            </td>

                            <td>
                                <strong>
                                    ৳<?= number_format((float)$run['total_net'], 2) ?>
                                </strong>
                            </td>

                            <td class="text-success fw-bold">
                                <?= (int)$run['paid_count'] ?>
                            </td>

                            <td class="text-danger fw-bold">
                                <?= (int)$run['unpaid_count'] ?>
                            </td>

                            <td>

                                <?php if ($run['status'] === 'paid'): ?>

                                    <span class="badge status-run-paid">
                                        Paid
                                    </span>

                                <?php else: ?>

                                    <span class="badge status-processed">
                                        Processed
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <a href="?run_id=<?= (int)$run['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">

                                    <i class="fa-solid fa-eye me-1"></i>
                                    Manage Payments

                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- SELECTED PAYROLL -->

    <?php if ($selectedRun): ?>

        <div class="main-card">

            <h5 class="section-title">

                <i class="fa-solid fa-users me-2"></i>

                Salary Payments:
                <?= pp_h(date(
                    'F Y',
                    strtotime($selectedRun['payroll_month'])
                )) ?>

            </h5>

            <?php

            $paidCount = 0;
            $unpaidCount = 0;
            $paidAmount = 0;
            $unpaidAmount = 0;

            foreach ($employees as $employee) {

                if ($employee['payment_status'] === 'paid') {
                    $paidCount++;
                    $paidAmount += (float)$employee['net_salary'];
                } else {
                    $unpaidCount++;
                    $unpaidAmount += (float)$employee['net_salary'];
                }
            }

            ?>

            <div class="row g-3 mb-4">

                <div class="col-md-3">

                    <div class="summary-box">

                        <small>Total Employees</small>

                        <h4 class="mb-0">
                            <?= count($employees) ?>
                        </h4>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <small>Paid Employees</small>

                        <h4 class="mb-0 text-success">
                            <?= $paidCount ?>
                        </h4>

                        <small>
                            ৳<?= number_format($paidAmount, 2) ?>
                        </small>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <small>Unpaid Employees</small>

                        <h4 class="mb-0 text-danger">
                            <?= $unpaidCount ?>
                        </h4>

                        <small>
                            ৳<?= number_format($unpaidAmount, 2) ?>
                        </small>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <small>Total Net Payroll</small>

                        <h4 class="mb-0">
                            ৳<?= number_format((float)$selectedRun['total_net'], 2) ?>
                        </h4>

                    </div>

                </div>

            </div>

            <div class="alert alert-info">

                <i class="fa-solid fa-circle-info me-2"></i>

                Mark an employee as paid only after the salary has actually
                been transferred or handed over.

            </div>

            <!-- EMPLOYEE PAYMENT TABLE -->

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead>

                        <tr>
                            <th>Employee</th>
                            <th>Employee Code</th>
                            <th>Phone</th>
                            <th>Net Salary</th>
                            <th>Payment Status</th>
                            <th>Payment Date</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($employees)): ?>

                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No employee payroll records found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employees as $employee): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= pp_h(
                                            $employee['first_name_en'] . ' ' .
                                            $employee['last_name_en']
                                        ) ?>
                                    </strong>

                                </td>

                                <td>
                                    <?= pp_h($employee['employee_code']) ?>
                                </td>

                                <td>
                                    <?= pp_h($employee['phone']) ?>
                                </td>

                                <td>
                                    <strong>
                                        ৳<?= number_format((float)$employee['net_salary'], 2) ?>
                                    </strong>
                                </td>

                                <td>

                                    <?php if ($employee['payment_status'] === 'paid'): ?>

                                        <span class="badge status-paid">
                                            <i class="fa-solid fa-check me-1"></i>
                                            Paid
                                        </span>

                                    <?php else: ?>

                                        <span class="badge status-unpaid">
                                            Unpaid
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= $employee['payment_date']
                                        ? pp_h($employee['payment_date'])
                                        : '—' ?>

                                </td>

                                <td>

                                    <div class="d-flex flex-wrap gap-2">

                                        <a href="payslip.php?item_id=<?= (int)$employee['payroll_item_id'] ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-primary">

                                            <i class="fa-solid fa-file-pdf me-1"></i>
                                            Payslip

                                        </a>

                                        <?php if (
                                            $employee['payment_status'] === 'unpaid' &&
                                            $selectedRun['status'] === 'processed'
                                        ): ?>

                                            <form method="POST"
                                                  class="d-flex flex-wrap gap-2 align-items-center"
                                                  onsubmit="return confirm('Confirm that this employee has actually received the salary?');">

                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= pp_h(csrf_token()) ?>">

                                                <input type="hidden"
                                                       name="payroll_run_id"
                                                       value="<?= (int)$selectedRun['id'] ?>">

                                                <input type="hidden"
                                                       name="payroll_item_id"
                                                       value="<?= (int)$employee['payroll_item_id'] ?>">

                                                <input type="date"
                                                       name="payment_date"
                                                       class="form-control form-control-sm"
                                                       value="<?= pp_h(date('Y-m-d')) ?>"
                                                       max="<?= pp_h(date('Y-m-d')) ?>"
                                                       required
                                                       style="width:145px;">

                                                <button type="submit"
                                                        class="btn btn-sm btn-success">

                                                    <i class="fa-solid fa-check me-1"></i>
                                                    Mark Paid

                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($selectedRun['status'] === 'paid'): ?>

                <div class="alert alert-success mt-3 mb-0">

                    <i class="fa-solid fa-circle-check me-2"></i>

                    All employee salaries have been marked as paid.

                    <?php if (!empty($selectedRun['paid_at'])): ?>

                        <br>

                        Payroll completed at:
                        <?= pp_h($selectedRun['paid_at']) ?>

                    <?php endif; ?>

                </div>

            <?php elseif ($unpaidCount > 0): ?>

                <div class="alert alert-warning mt-3 mb-0">

                    <?= $unpaidCount ?> employee(s) still have unpaid salaries.

                    The payroll run will be marked as Paid automatically
                    after all employee payments are recorded.

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
