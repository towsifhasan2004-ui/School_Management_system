
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function pr_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   PROCESS PAYROLL
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $runId = (int)($_POST['payroll_run_id'] ?? 0);

    try {

        if ($runId <= 0) {
            throw new Exception('Invalid payroll run.');
        }

        $processedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($processedBy <= 0) {
            throw new Exception('Invalid user session.');
        }

        $conn->begin_transaction();

        /* LOCK PAYROLL RUN */

        $stmt = $conn->prepare("
            SELECT *
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

        if ($run['status'] !== 'draft') {
            throw new Exception('Only draft payroll can be processed.');
        }

        /* =====================================
           VALIDATE PAYROLL ITEM TOTALS
        ===================================== */

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS employee_count,
                COALESCE(SUM(gross_salary), 0) AS total_gross,
                COALESCE(SUM(total_deduction), 0) AS total_deductions,
                COALESCE(SUM(net_salary), 0) AS total_net
            FROM payroll_items
            WHERE payroll_run_id = ?
        ");

        $stmt->bind_param("i", $runId);
        $stmt->execute();

        $totals = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)$totals['employee_count'] !== (int)$run['total_employees']) {
            throw new Exception('Employee count does not match payroll run totals.');
        }

        if ((int)$totals['employee_count'] <= 0) {
            throw new Exception('No employee payroll records found.');
        }

        if (
            abs((float)$totals['total_gross'] - (float)$run['total_gross']) > 0.01 ||
            abs((float)$totals['total_deductions'] - (float)$run['total_deductions']) > 0.01 ||
            abs((float)$totals['total_net'] - (float)$run['total_net']) > 0.01
        ) {
            throw new Exception('Payroll totals do not match employee records.');
        }

        /* =====================================
           VALIDATE EACH EMPLOYEE BREAKDOWN
        ===================================== */

        $stmt = $conn->prepare("
            SELECT
                pi.id,
                pi.gross_salary,
                pi.total_deduction,
                pi.net_salary,

                COALESCE(SUM(
                    CASE
                        WHEN pic.component_type = 'earning'
                        THEN pic.amount ELSE 0
                    END
                ), 0) AS component_earnings,

                COALESCE(SUM(
                    CASE
                        WHEN pic.component_type = 'deduction'
                        THEN pic.amount ELSE 0
                    END
                ), 0) AS component_deductions

            FROM payroll_items pi

            LEFT JOIN payroll_item_components pic
                ON pic.payroll_item_id = pi.id

            WHERE pi.payroll_run_id = ?

            GROUP BY
                pi.id,
                pi.gross_salary,
                pi.total_deduction,
                pi.net_salary
        ");

        $stmt->bind_param("i", $runId);
        $stmt->execute();

        $validationResult = $stmt->get_result();

        while ($item = $validationResult->fetch_assoc()) {

            if (
                abs((float)$item['gross_salary'] -
                    (float)$item['component_earnings']) > 0.01
            ) {
                throw new Exception(
                    'An employee earning breakdown does not match the payroll total.'
                );
            }

            if (
                abs((float)$item['total_deduction'] -
                    (float)$item['component_deductions']) > 0.01
            ) {
                throw new Exception(
                    'An employee deduction breakdown does not match the payroll total.'
                );
            }

            if (
                abs(
                    (float)$item['net_salary'] -
                    (
                        (float)$item['gross_salary'] -
                        (float)$item['total_deduction']
                    )
                ) > 0.01
            ) {
                throw new Exception(
                    'An employee net salary calculation is incorrect.'
                );
            }
        }

        $stmt->close();

        /* =====================================
           UPDATE PAYROLL STATUS
        ===================================== */

        $stmt = $conn->prepare("
            UPDATE payroll_runs
            SET
                status = 'processed',
                processed_by = ?,
                processed_at = NOW()
            WHERE id = ?
              AND status = 'draft'
        ");

        $stmt->bind_param("ii", $processedBy, $runId);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new Exception('Payroll processing failed. Please refresh and try again.');
        }

        $stmt->close();

        $conn->commit();

        $message = 'Payroll successfully processed. Payroll Run ID: #' . $runId;

    } catch (mysqli_sql_exception $e) {

        $conn->rollback();

        error_log('Payroll review SQL error: ' . $e->getMessage());

        $message = 'A database error occurred while processing payroll.';
        $messageType = 'danger';

    } catch (Exception $e) {

        $conn->rollback();

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   SELECT PAYROLL RUN
========================================= */

$selectedRunId = (int)($_GET['run_id'] ?? 0);

$selectedRun = null;
$payrollItems = [];

if ($selectedRunId > 0) {

    $stmt = $conn->prepare("
        SELECT
            pr.*,
            u.username AS generated_username,
            pu.username AS processed_username

        FROM payroll_runs pr

        LEFT JOIN users u
            ON u.id = pr.generated_by

        LEFT JOIN users pu
            ON pu.id = pr.processed_by

        WHERE pr.id = ?
    ");

    $stmt->bind_param("i", $selectedRunId);
    $stmt->execute();

    $selectedRun = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedRun) {

        $stmt = $conn->prepare("
            SELECT
                pi.id,
                pi.employee_id,
                pi.gross_salary,
                pi.total_deduction,
                pi.net_salary,
                pi.payment_status,

                e.employee_code,
                e.first_name_en,
                e.last_name_en

            FROM payroll_items pi

            INNER JOIN employees e
                ON e.id = pi.employee_id

            WHERE pi.payroll_run_id = ?

            ORDER BY e.first_name_en, e.last_name_en
        ");

        $stmt->bind_param("i", $selectedRunId);
        $stmt->execute();

        $itemResult = $stmt->get_result();

        while ($row = $itemResult->fetch_assoc()) {

            $row['components'] = [];

            $payrollItems[(int)$row['id']] = $row;
        }

        $stmt->close();

        /* LOAD COMPONENT SNAPSHOTS */

        if (!empty($payrollItems)) {

            $itemIds = array_keys($payrollItems);

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

            $types = str_repeat('i', count($itemIds));

            $stmt = $conn->prepare("
                SELECT
                    payroll_item_id,
                    component_name,
                    component_type,
                    amount
                FROM payroll_item_components
                WHERE payroll_item_id IN ($placeholders)
                ORDER BY component_type, component_name
            ");

            $stmt->bind_param($types, ...$itemIds);
            $stmt->execute();

            $componentResult = $stmt->get_result();

            while ($component = $componentResult->fetch_assoc()) {

                $itemId = (int)$component['payroll_item_id'];

                $payrollItems[$itemId]['components'][] = $component;
            }

            $stmt->close();
        }

    } else {

        $message = 'Selected payroll run was not found.';
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD ALL PAYROLL RUNS
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

<title>Payroll Review | Sezua SISMS</title>

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

.table th {
    background: #edf2f7;
    color: #33445a;
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
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

.badge-earning {
    background: #d9f4e5;
    color: #147442;
}

.badge-deduction {
    background: #fde2e2;
    color: #a32626;
}

.employee-card {
    border: 1px solid #e6ebf1;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 15px;
}

.employee-name {
    color: #173b60;
    font-weight: 700;
}

.btn-primary {
    background: #173b60;
    border-color: #173b60;
}

.btn-primary:hover {
    background: #102d4b;
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
                    <i class="fa-solid fa-file-invoice-dollar me-2"></i>
                    Payroll Review & Processing
                </h3>

                <p class="mb-0 opacity-75">
                    Review employee salary details and process monthly payroll.
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

        <div class="alert alert-<?= pr_h($messageType) ?> alert-dismissible fade show">

            <?= pr_h($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- PAYROLL RUN HISTORY -->

    <div class="main-card">

        <h5 class="section-title">
            <i class="fa-solid fa-clock-rotate-left me-2"></i>
            Monthly Payroll Runs
        </h5>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>
                        <th>Run ID</th>
                        <th>Month</th>
                        <th>Employees</th>
                        <th>Gross</th>
                        <th>Deductions</th>
                        <th>Net</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (empty($payrollRuns)): ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No payroll runs found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($payrollRuns as $run): ?>

                        <tr>

                            <td>#<?= (int)$run['id'] ?></td>

                            <td>
                                <?= pr_h(date(
                                    'F Y',
                                    strtotime($run['payroll_month'])
                                )) ?>
                            </td>

                            <td>
                                <?= (int)$run['total_employees'] ?>
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
                                <span class="badge status-<?= pr_h($run['status']) ?>">
                                    <?= pr_h(ucfirst($run['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <a href="?run_id=<?= (int)$run['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fa-solid fa-eye me-1"></i>
                                    Review
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- SELECTED PAYROLL DETAILS -->

    <?php if ($selectedRun): ?>

        <div class="main-card">

            <h5 class="section-title">
                <i class="fa-solid fa-clipboard-check me-2"></i>
                Payroll Details:
                <?= pr_h(date(
                    'F Y',
                    strtotime($selectedRun['payroll_month'])
                )) ?>
            </h5>

            <div class="row g-3 mb-4">

                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <small class="text-muted">Total Employees</small>
                        <h4 class="mb-0">
                            <?= (int)$selectedRun['total_employees'] ?>
                        </h4>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <small class="text-muted">Gross Salary</small>
                        <h5 class="mb-0">
                            ৳<?= number_format((float)$selectedRun['total_gross'], 2) ?>
                        </h5>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <small class="text-muted">Total Deductions</small>
                        <h5 class="mb-0">
                            ৳<?= number_format((float)$selectedRun['total_deductions'], 2) ?>
                        </h5>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <small class="text-muted">Net Salary</small>
                        <h5 class="mb-0 text-success">
                            ৳<?= number_format((float)$selectedRun['total_net'], 2) ?>
                        </h5>
                    </div>
                </div>

            </div>

            <p class="text-muted small">
                Generated by:
                <?= pr_h($selectedRun['generated_username'] ?? 'Unknown') ?>
                |
                Generated:
                <?= pr_h($selectedRun['created_at']) ?>
            </p>

            <hr>

            <h6 class="fw-bold mb-3">Employee-wise Salary Breakdown</h6>

            <?php if (empty($payrollItems)): ?>

                <div class="alert alert-warning">
                    No employee payroll records found for this run.
                </div>

            <?php else: ?>

                <?php foreach ($payrollItems as $item): ?>

                    <div class="employee-card">

                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

                            <div>

                                <div class="employee-name">
                                    <?= pr_h(
                                        $item['first_name_en'] . ' ' .
                                        $item['last_name_en']
                                    ) ?>
                                </div>

                                <small class="text-muted">
                                    Employee Code:
                                    <?= pr_h($item['employee_code']) ?>
                                </small>

                            </div>

                            <span class="badge bg-secondary">
                                <?= pr_h(ucfirst($item['payment_status'])) ?>
                            </span>

                        </div>

                        <div class="table-responsive">

                            <table class="table table-sm">

                                <thead>
                                    <tr>
                                        <th>Component</th>
                                        <th>Type</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php if (empty($item['components'])): ?>

                                    <tr>
                                        <td colspan="3" class="text-muted text-center">
                                            No component breakdown found.
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($item['components'] as $component): ?>

                                        <tr>

                                            <td>
                                                <?= pr_h($component['component_name']) ?>
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

                                            <td class="text-end">
                                                ৳<?= number_format((float)$component['amount'], 2) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                                </tbody>

                                <tfoot>

                                    <tr>
                                        <th colspan="2">Gross Salary</th>
                                        <th class="text-end">
                                            ৳<?= number_format((float)$item['gross_salary'], 2) ?>
                                        </th>
                                    </tr>

                                    <tr>
                                        <th colspan="2">Total Deduction</th>
                                        <th class="text-end text-danger">
                                            ৳<?= number_format((float)$item['total_deduction'], 2) ?>
                                        </th>
                                    </tr>

                                    <tr>
                                        <th colspan="2">Net Salary</th>
                                        <th class="text-end text-success">
                                            ৳<?= number_format((float)$item['net_salary'], 2) ?>
                                        </th>
                                    </tr>

                                </tfoot>

                            </table>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

            <!-- PROCESS BUTTON -->

            <?php if ($selectedRun['status'] === 'draft'): ?>

                <div class="alert alert-warning mt-3">

                    <i class="fa-solid fa-triangle-exclamation me-2"></i>

                    Review all employee salary calculations before processing.
                    Processing changes the payroll status and locks this workflow
                    against further processing.

                </div>

                <form method="POST"
                      onsubmit="return confirm('Are you sure you have reviewed all salary calculations and want to process this payroll?');">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= pr_h(csrf_token()) ?>">

                    <input type="hidden"
                           name="payroll_run_id"
                           value="<?= (int)$selectedRun['id'] ?>">

                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-check-circle me-2"></i>
                        Process Payroll

                    </button>

                </form>

            <?php else: ?>

                <div class="alert alert-success mt-3">

                    <i class="fa-solid fa-circle-check me-2"></i>

                    This payroll has already been
                    <strong><?= pr_h($selectedRun['status']) ?></strong>.

                    <?php if ($selectedRun['processed_at']): ?>

                        <br>

                        Processed at:
                        <?= pr_h($selectedRun['processed_at']) ?>

                        <br>

                        Processed by:
                        <?= pr_h($selectedRun['processed_username'] ?? 'Unknown') ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
