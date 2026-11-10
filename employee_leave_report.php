
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function elr_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   FILTERS
========================================= */

$today = date('Y-m-d');

$fromDate = $_GET['from_date'] ?? date('Y-01-01');
$toDate = $_GET['to_date'] ?? $today;
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$leaveTypeId = (int)($_GET['leave_type_id'] ?? 0);

$validDate = function ($date) {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (!$validDate($fromDate)) {
    $fromDate = date('Y-01-01');
}

if (!$validDate($toDate)) {
    $toDate = $today;
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/* =========================================
   LOAD LEAVE TYPES
========================================= */

$leaveTypes = [];

$result = $conn->query("
    SELECT id, type_name
    FROM employee_leave_types
    ORDER BY type_name
");

while ($row = $result->fetch_assoc()) {
    $leaveTypes[] = $row;
}

/* =========================================
   BUILD REPORT QUERY

   Includes requests that overlap the
   selected reporting period.
========================================= */

$where = [
    "lr.start_date <= ?",
    "lr.end_date >= ?"
];

$params = [$toDate, $fromDate];
$types = 'ss';

if ($status !== '') {
    $where[] = "lr.approval_status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($leaveTypeId > 0) {
    $where[] = "lr.leave_type_id = ?";
    $params[] = $leaveTypeId;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(
        e.employee_code LIKE ?
        OR e.first_name_en LIKE ?
        OR e.last_name_en LIKE ?
        OR lt.type_name LIKE ?
    )";

    $term = '%' . $search . '%';

    for ($i = 0; $i < 4; $i++) {
        $params[] = $term;
    }

    $types .= 'ssss';
}

$sql = "
    SELECT
        lr.id,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.reason,
        lr.approval_status,
        lr.review_remarks,
        lr.reviewed_at,
        lr.created_at,

        e.employee_code,
        e.first_name_en,
        e.last_name_en,

        lt.type_name,
        lt.is_paid,

        requester.username AS requester_name,
        reviewer.username AS reviewer_name

    FROM employee_leave_requests lr

    INNER JOIN employees e
        ON e.id = lr.employee_id

    INNER JOIN employee_leave_types lt
        ON lt.id = lr.leave_type_id

    LEFT JOIN users requester
        ON requester.id = lr.requested_by

    LEFT JOIN users reviewer
        ON reviewer.id = lr.reviewed_by

    WHERE " . implode(" AND ", $where) . "

    ORDER BY lr.start_date DESC, lr.id DESC
    LIMIT 5000
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();

$rows = [];

$summary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0
];

$totalRequests = 0;
$approvedDays = 0;
$paidApprovedDays = 0;
$unpaidApprovedDays = 0;

while ($row = $result->fetch_assoc()) {

    $totalRequests++;

    $currentStatus = $row['approval_status'];

    if (isset($summary[$currentStatus])) {
        $summary[$currentStatus]++;
    }

    // Calculate only the portion of leave inside the
    // selected reporting period.
    $leaveStart = max($row['start_date'], $fromDate);
    $leaveEnd = min($row['end_date'], $toDate);

    $start = new DateTime($leaveStart);
    $end = new DateTime($leaveEnd);

    $periodDays = (int)$start->diff($end)->days + 1;

    $row['period_days'] = $periodDays;

    if ($currentStatus === 'approved') {
        $approvedDays += $periodDays;

        if ((int)$row['is_paid'] === 1) {
            $paidApprovedDays += $periodDays;
        } else {
            $unpaidApprovedDays += $periodDays;
        }
    }

    $rows[] = $row;
}

/* =========================================
   CSV EXPORT
========================================= */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'employee_leave_report_' .
        date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Employee Code',
        'Employee Name',
        'Leave Type',
        'Paid / Unpaid',
        'Start Date',
        'End Date',
        'Days in Report Period',
        'Request Total Days',
        'Status',
        'Reason',
        'Requested By',
        'Reviewed By',
        'Reviewed At',
        'Review Remarks'
    ]);

    foreach ($rows as $row) {

        fputcsv($output, [
            $row['employee_code'],
            trim($row['first_name_en'] . ' ' . $row['last_name_en']),
            $row['type_name'],
            $row['is_paid'] ? 'Paid' : 'Unpaid',
            $row['start_date'],
            $row['end_date'],
            $row['period_days'],
            $row['total_days'],
            $row['approval_status'],
            $row['reason'],
            $row['requester_name'],
            $row['reviewer_name'],
            $row['reviewed_at'],
            $row['review_remarks']
        ]);
    }

    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Leave Reports | Sezua SISMS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    background: #f3f6fa;
    color: #1e293b;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1500px;
    margin: auto;
}

h1 {
    color: #123d32;
    margin-bottom: 7px;
}

.subtitle {
    color: #64748b;
    margin-bottom: 24px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    margin-bottom: 22px;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
}

.grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.stat {
    background: white;
    padding: 19px;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.stat span {
    display: block;
    color: #64748b;
    font-size: 13px;
}

.stat strong {
    display: block;
    font-size: 26px;
    margin-top: 9px;
}

.filters {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: end;
}

.filters div {
    flex: 1;
    min-width: 150px;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
}

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #d5dce5;
    border-radius: 7px;
}

button, .btn {
    display: inline-block;
    border: 0;
    padding: 11px 16px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    font-size: 13px;
}

.btn-primary {
    background: #123d32;
    color: white;
}

.btn-success {
    background: #15803d;
    color: white;
}

.btn-secondary {
    background: #475569;
    color: white;
}

.btn-light {
    background: #e2e8f0;
    color: #1e293b;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: top;
    font-size: 13px;
}

th {
    background: #f8fafc;
}

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.pending { background: #fef3c7; color: #92400e; }
.approved { background: #dcfce7; color: #166534; }
.rejected { background: #fee2e2; color: #991b1b; }
.cancelled { background: #e2e8f0; color: #334155; }

.note {
    font-size: 12px;
    color: #64748b;
    line-height: 1.6;
}

@media(max-width: 800px) {
    body { padding: 12px; }
    .grid { grid-template-columns: repeat(2, 1fr); }
}

@media print {
    body {
        background: white;
        padding: 0;
    }

    .no-print {
        display: none !important;
    }

    .card, .stat {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>
</head>

<body>

<div class="container">

    <h1>Employee Leave Reports</h1>

    <p class="subtitle">
        Review employee leave history, approval status and leave usage.
    </p>

    <!-- SUMMARY -->

    <div class="grid">

        <div class="stat">
            <span>Total Requests</span>
            <strong><?= $totalRequests ?></strong>
        </div>

        <div class="stat">
            <span>Pending Requests</span>
            <strong><?= $summary['pending'] ?></strong>
        </div>

        <div class="stat">
            <span>Approved Leave Days</span>
            <strong><?= number_format($approvedDays, 2) ?></strong>
        </div>

        <div class="stat">
            <span>Rejected Requests</span>
            <strong><?= $summary['rejected'] ?></strong>
        </div>

    </div>

    <!-- FILTERS -->

    <div class="card no-print">

        <h2>Report Filters</h2>

        <form method="GET" class="filters">

            <div>
                <label>From Date</label>
                <input type="date"
                       name="from_date"
                       value="<?= elr_h($fromDate) ?>"
                       required>
            </div>

            <div>
                <label>To Date</label>
                <input type="date"
                       name="to_date"
                       value="<?= elr_h($toDate) ?>"
                       required>
            </div>

            <div>
                <label>Leave Status</label>
                <select name="status">
                    <option value="">All Statuses</option>

                    <?php foreach ($allowedStatuses as $s): ?>
                        <option value="<?= elr_h($s) ?>"
                            <?= $status === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Leave Type</label>
                <select name="leave_type_id">
                    <option value="0">All Leave Types</option>

                    <?php foreach ($leaveTypes as $type): ?>
                        <option value="<?= (int)$type['id'] ?>"
                            <?= $leaveTypeId === (int)$type['id']
                                ? 'selected' : '' ?>>
                            <?= elr_h($type['type_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Employee Search</label>
                <input type="text"
                       name="search"
                       value="<?= elr_h($search) ?>"
                       placeholder="Name or employee code">
            </div>

            <div>
                <button type="submit" class="btn btn-primary">
                    Generate Report
                </button>
            </div>

        </form>

        <div style="margin-top:15px;display:flex;gap:10px;flex-wrap:wrap;">

            <a class="btn btn-success"
               href="?<?= elr_h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
                Export CSV
            </a>

            <button type="button"
                    class="btn btn-secondary"
                    onclick="window.print()">
                Print Report
            </button>

            <a href="employee_leave_report.php"
               class="btn btn-light">
                Reset
            </a>

        </div>

    </div>

    <!-- REPORT TABLE -->

    <div class="card">

        <h2>Leave Application Details</h2>

        <p class="note">
            Period: <?= elr_h($fromDate) ?> to <?= elr_h($toDate) ?>.
            The report includes requests overlapping this period.
            Approved leave days show only the portion falling inside the period.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Dates</th>
                        <th>Period Days</th>
                        <th>Paid?</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Requested By</th>
                        <th>Reviewed By</th>
                        <th>Review Remarks</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$rows): ?>

                    <tr>
                        <td colspan="10">No leave records found.</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($rows as $row): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= elr_h(
                                        $row['first_name_en'] . ' ' .
                                        $row['last_name_en']
                                    ) ?>
                                </strong>
                                <br>
                                <small>
                                    <?= elr_h($row['employee_code']) ?>
                                </small>
                            </td>

                            <td><?= elr_h($row['type_name']) ?></td>

                            <td>
                                <?= elr_h($row['start_date']) ?>
                                <br>to<br>
                                <?= elr_h($row['end_date']) ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['period_days'], 2) ?>
                            </td>

                            <td>
                                <?= $row['is_paid'] ? 'Yes' : 'No' ?>
                            </td>

                            <td>
                                <span class="badge <?= elr_h($row['approval_status']) ?>">
                                    <?= elr_h($row['approval_status']) ?>
                                </span>
                            </td>

                            <td><?= nl2br(elr_h($row['reason'])) ?></td>

                            <td><?= elr_h($row['requester_name'] ?? 'N/A') ?></td>

                            <td>
                                <?= elr_h($row['reviewer_name'] ?? 'N/A') ?>
                                <br>
                                <small><?= elr_h($row['reviewed_at'] ?? '') ?></small>
                            </td>

                            <td>
                                <?= nl2br(elr_h($row['review_remarks'] ?? '')) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="note">
            Maximum 5,000 records are included in this report.
            Paid and unpaid approved leave days are also available in the CSV export.
        </p>

    </div>

    <a href="dashboard.php" class="btn btn-secondary no-print">
        Back to Dashboard
    </a>

</div>

</body>
</html>
