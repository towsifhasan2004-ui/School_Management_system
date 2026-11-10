
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function payroll_report_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function payroll_report_csv_safe($value) {
    $value = (string)$value;

    if ($value !== '' && preg_match('/^[=\-+@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

$message = '';
$runs = [];
$selectedRun = null;
$employees = [];

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;

$paymentStatus = $_GET['payment_status'] ?? 'all';
$allowedStatuses = ['all', 'paid', 'unpaid'];

if (!in_array($paymentStatus, $allowedStatuses, true)) {
    $paymentStatus = 'all';
}

$search = trim($_GET['search'] ?? '');
$searchLike = '%' . $search . '%';

try {

    /* ==========================================
       LOAD PAYROLL RUNS
    ========================================== */

    $runResult = $conn->query("
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
        LIMIT 24
    ");

    while ($row = $runResult->fetch_assoc()) {
        $runs[] = $row;
    }

    /* ==========================================
       SELECT PAYROLL RUN
    ========================================== */

    if ($runId <= 0 && !empty($runs)) {
        $runId = (int)$runs[0]['id'];
    }

    if ($runId > 0) {

        $runStmt = $conn->prepare("
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
            WHERE id = ?
            LIMIT 1
        ");

        $runStmt->bind_param("i", $runId);
        $runStmt->execute();

        $selectedRun = $runStmt->get_result()->fetch_assoc();

        $runStmt->close();

        if (!$selectedRun) {
            $message = 'Selected payroll run was not found.';
            $runId = 0;
        }
    }

    /* ==========================================
       LOAD EMPLOYEE PAYROLL DETAILS
    ========================================== */

    if ($selectedRun) {

        $employeeSql = "
            SELECT
                pi.id AS payroll_item_id,
                pi.employee_id,
                pi.gross_salary,
                pi.total_deduction,
                pi.net_salary,
                pi.payment_status,
                pi.payment_date,
                pi.remarks,
                e.employee_code,
                e.first_name_en,
                e.last_name_en
            FROM payroll_items pi
            INNER JOIN employees e
                ON e.id = pi.employee_id
            WHERE pi.payroll_run_id = ?
              AND (? = 'all' OR pi.payment_status = ?)
              AND (
                    ? = ''
                    OR CONCAT_WS(
                        ' ',
                        e.employee_code,
                        e.first_name_en,
                        e.last_name_en
                    ) LIKE ?
              )
            ORDER BY e.employee_code ASC
        ";

        $employeeStmt = $conn->prepare($employeeSql);

        $employeeStmt->bind_param(
            "issss",
            $runId,
            $paymentStatus,
            $paymentStatus,
            $search,
            $searchLike
        );

        $employeeStmt->execute();

        $employeeResult = $employeeStmt->get_result();

        while ($row = $employeeResult->fetch_assoc()) {
            $employees[] = $row;
        }

        $employeeStmt->close();
    }

    /* ==========================================
       FULL RUN SUMMARY
    ========================================== */

    $summary = [
        'employee_count' => 0,
        'paid_count' => 0,
        'unpaid_count' => 0,
        'gross_total' => 0,
        'deduction_total' => 0,
        'net_total' => 0
    ];

    if ($selectedRun) {

        $summaryStmt = $conn->prepare("
            SELECT
                COUNT(*) AS employee_count,
                SUM(CASE WHEN payment_status = 'paid'
                    THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN payment_status = 'unpaid'
                    THEN 1 ELSE 0 END) AS unpaid_count,
                COALESCE(SUM(gross_salary), 0) AS gross_total,
                COALESCE(SUM(total_deduction), 0) AS deduction_total,
                COALESCE(SUM(net_salary), 0) AS net_total
            FROM payroll_items
            WHERE payroll_run_id = ?
        ");

        $summaryStmt->bind_param("i", $runId);
        $summaryStmt->execute();

        $summary = $summaryStmt->get_result()->fetch_assoc();

        $summaryStmt->close();
    }

    /* ==========================================
       CSV EXPORT
    ========================================== */

    if (
        $selectedRun &&
        isset($_GET['export']) &&
        $_GET['export'] === 'csv'
    ) {

        if (empty($employees)) {
            die('No employee records available for export.');
        }

        $filename = 'payroll_report_' .
            date('Y_m', strtotime($selectedRun['payroll_month'])) .
            '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'Employee Code',
            'Employee Name',
            'Gross Salary',
            'Total Deduction',
            'Net Salary',
            'Payment Status',
            'Payment Date'
        ]);

        foreach ($employees as $employee) {

            $employeeName = trim(
                $employee['first_name_en'] . ' ' .
                ($employee['last_name_en'] ?? '')
            );

            fputcsv($output, [
                payroll_report_csv_safe($employee['employee_code']),
                payroll_report_csv_safe($employeeName),
                number_format((float)$employee['gross_salary'], 2, '.', ''),
                number_format((float)$employee['total_deduction'], 2, '.', ''),
                number_format((float)$employee['net_salary'], 2, '.', ''),
                $employee['payment_status'],
                $employee['payment_date'] ?? ''
            ]);
        }

        fclose($output);
        exit;
    }

} catch (Throwable $e) {
    error_log('Payroll report error: ' . $e->getMessage());
    $message = 'Unable to load payroll report. Please check the database and try again.';
}

$exportQuery = http_build_query([
    'run_id' => $runId,
    'payment_status' => $paymentStatus,
    'search' => $search,
    'export' => 'csv'
]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payroll Reports | SISMS</title>

<style>
:root {
    --navy: #102b46;
    --deep: #071b30;
    --gold: #d8b45c;
    --background: #f3f6fa;
    --border: #e1e7ef;
    --text: #243447;
    --muted: #718096;
    --green: #16845b;
    --red: #c0392b;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1450px;
    margin: auto;
    padding: 25px;
}

.topbar {
    background: linear-gradient(120deg, var(--deep), var(--navy));
    color: white;
    padding: 24px;
    border-radius: 13px;
    margin-bottom: 22px;
    border-bottom: 3px solid var(--gold);
}

.topbar h1 {
    margin: 0 0 8px;
    font-size: 25px;
}

.topbar p {
    margin: 0;
    color: #d8e2ee;
    font-size: 14px;
}

.card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(20, 40, 60, .04);
}

.card h2 {
    font-size: 18px;
    margin: 0 0 18px;
    color: var(--navy);
}

.filters {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1.5fr auto;
    gap: 13px;
    align-items: end;
}

label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
}

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: white;
    outline: none;
}

input:focus, select:focus {
    border-color: var(--gold);
}

.btn {
    display: inline-block;
    border: none;
    border-radius: 7px;
    padding: 11px 16px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
}

.btn-primary {
    background: var(--navy);
    color: white;
}

.btn-export {
    background: var(--green);
    color: white;
}

.btn-light {
    background: #edf1f6;
    color: var(--navy);
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.stat {
    background: white;
    border: 1px solid var(--border);
    border-radius: 11px;
    padding: 18px;
}

.stat small {
    display: block;
    color: var(--muted);
    margin-bottom: 10px;
}

.stat strong {
    font-size: 21px;
    color: var(--navy);
    overflow-wrap: anywhere;
}

.stat.paid strong {
    color: var(--green);
}

.stat.unpaid strong {
    color: var(--red);
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    padding: 13px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    font-size: 13px;
}

th {
    background: #f4f7fa;
    color: var(--navy);
    white-space: nowrap;
}

td.number {
    text-align: right;
    font-weight: bold;
    white-space: nowrap;
}

.status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-paid {
    color: #116b49;
    background: #e1f5eb;
}

.status-unpaid {
    color: #a32e25;
    background: #fde9e7;
}

.status-draft {
    color: #805d12;
    background: #fff3cd;
}

.status-processed {
    color: #145b87;
    background: #e4f2fc;
}

.status-cancelled {
    color: #6b7280;
    background: #eceff3;
}

.message {
    background: #fff4e5;
    border: 1px solid #f0d49a;
    padding: 13px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 17px;
}

.empty {
    text-align: center;
    padding: 35px;
    color: var(--muted);
}

.run-info {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 18px;
}

@media(max-width: 950px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters {
        grid-template-columns: 1fr 1fr;
    }
}

@media(max-width: 550px) {
    .container {
        padding: 12px;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .topbar h1 {
        font-size: 21px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="topbar">
        <h1>PAYROLL REPORTS</h1>
        <p>Monthly payroll summary, employee salary details and payment tracking</p>
    </div>

    <?php if ($message): ?>
        <div class="message">
            <?= payroll_report_h($message) ?>
        </div>
    <?php endif; ?>

    <!-- FILTERS -->

    <div class="card">

        <h2>Report Filters</h2>

        <form method="GET">

            <div class="filters">

                <div>
                    <label>Select Payroll Month</label>

                    <select name="run_id" required>

                        <?php foreach ($runs as $run): ?>

                            <option
                                value="<?= (int)$run['id'] ?>"
                                <?= ((int)$run['id'] === $runId) ? 'selected' : '' ?>
                            >
                                <?= date('F Y', strtotime($run['payroll_month'])) ?>
                                - <?= ucfirst($run['status']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label>Payment Status</label>

                    <select name="payment_status">

                        <option value="all" <?= $paymentStatus === 'all' ? 'selected' : '' ?>>
                            All Employees
                        </option>

                        <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>
                            Paid
                        </option>

                        <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>
                            Unpaid
                        </option>

                    </select>
                </div>

                <div>
                    <label>Search Employee</label>

                    <input
                        type="text"
                        name="search"
                        value="<?= payroll_report_h($search) ?>"
                        placeholder="Employee code or employee name"
                    >
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">
                        Generate Report
                    </button>
                </div>

            </div>

        </form>

    </div>

    <?php if ($selectedRun): ?>

        <!-- PAYROLL RUN INFORMATION -->

        <div class="card">

            <h2>Monthly Payroll Summary</h2>

            <div class="run-info">

                <span>
                    <strong>Month:</strong>
                    <?= date('F Y', strtotime($selectedRun['payroll_month'])) ?>
                </span>

                <span>
                    <strong>Run ID:</strong>
                    #<?= (int)$selectedRun['id'] ?>
                </span>

                <span>
                    <strong>Run Status:</strong>
                    <span class="status status-<?= payroll_report_h($selectedRun['status']) ?>">
                        <?= payroll_report_h($selectedRun['status']) ?>
                    </span>
                </span>

            </div>

            <div class="stats">

                <div class="stat">
                    <small>Total Employees</small>
                    <strong><?= (int)$summary['employee_count'] ?></strong>
                </div>

                <div class="stat paid">
                    <small>Paid Employees</small>
                    <strong><?= (int)$summary['paid_count'] ?></strong>
                </div>

                <div class="stat unpaid">
                    <small>Unpaid Employees</small>
                    <strong><?= (int)$summary['unpaid_count'] ?></strong>
                </div>

                <div class="stat">
                    <small>Total Gross Salary</small>
                    <strong>৳<?= number_format((float)$summary['gross_total'], 2) ?></strong>
                </div>

                <div class="stat">
                    <small>Total Net Salary</small>
                    <strong>৳<?= number_format((float)$summary['net_total'], 2) ?></strong>
                </div>

            </div>

            <p>
                <strong>Total Deductions:</strong>
                ৳<?= number_format((float)$summary['deduction_total'], 2) ?>
            </p>

        </div>

        <!-- EMPLOYEE DETAILS -->

        <div class="card">

            <h2>Employee Payroll Details</h2>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>SL</th>
                            <th>Employee Code</th>
                            <th>Employee Name</th>
                            <th>Gross Salary</th>
                            <th>Total Deduction</th>
                            <th>Net Salary</th>
                            <th>Payment Status</th>
                            <th>Payment Date</th>
                            <th>Payslip</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($employees)): ?>

                        <tr>
                            <td colspan="9" class="empty">
                                No employee payroll records found for these filters.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employees as $index => $employee): ?>

                            <?php
                            $employeeName = trim(
                                $employee['first_name_en'] . ' ' .
                                ($employee['last_name_en'] ?? '')
                            );
                            ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>
                                    <?= payroll_report_h($employee['employee_code']) ?>
                                </td>

                                <td>
                                    <?= payroll_report_h($employeeName) ?>
                                </td>

                                <td class="number">
                                    ৳<?= number_format((float)$employee['gross_salary'], 2) ?>
                                </td>

                                <td class="number">
                                    ৳<?= number_format((float)$employee['total_deduction'], 2) ?>
                                </td>

                                <td class="number">
                                    ৳<?= number_format((float)$employee['net_salary'], 2) ?>
                                </td>

                                <td>
                                    <span class="status status-<?= payroll_report_h($employee['payment_status']) ?>">
                                        <?= payroll_report_h($employee['payment_status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= $employee['payment_date']
                                        ? date('d M Y', strtotime($employee['payment_date']))
                                        : '-' ?>
                                </td>

                                <td>

                                    <?php if (in_array($selectedRun['status'], ['processed', 'paid'], true)): ?>

                                        <a
                                            class="btn btn-light"
                                            href="payslip.php?item_id=<?= (int)$employee['payroll_item_id'] ?>"
                                            target="_blank"
                                        >
                                            View
                                        </a>

                                    <?php else: ?>

                                        <span>-</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="actions">

                <a
                    class="btn btn-export"
                    href="?<?= payroll_report_h($exportQuery) ?>"
                >
                    Export CSV
                </a>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print()"
                >
                    Print Report
                </button>

                <a href="payroll_reports.php" class="btn btn-light">
                    Clear Filters
                </a>

            </div>

        </div>

    <?php else: ?>

        <div class="card empty">
            <h2>No Payroll Run Found</h2>
            <p>Generate a monthly payroll first using monthly_payroll.php.</p>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
