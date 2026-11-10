
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function ear_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ==========================================
   VALIDATE FILTERS
========================================== */

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');

$fromDate = $_GET['from_date'] ?? $defaultFrom;
$toDate = $_GET['to_date'] ?? $today;
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$allowedStatuses = [
    'present',
    'absent',
    'late',
    'half_day',
    'on_leave'
];

function ear_valid_date($date) {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

$error = '';

if (!ear_valid_date($fromDate) || !ear_valid_date($toDate)) {
    $error = 'Please select valid dates.';
    $fromDate = $defaultFrom;
    $toDate = $today;
}

if ($fromDate > $toDate) {
    $error = 'From date cannot be later than To date.';
    $fromDate = $defaultFrom;
    $toDate = $today;
}

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$search = substr($search, 0, 100);

/* ==========================================
   DYNAMIC QUERY CONDITIONS
========================================== */

$where = " WHERE ea.attendance_date BETWEEN ? AND ? ";

$params = [$fromDate, $toDate];
$types = "ss";

if ($status !== '') {
    $where .= " AND ea.attendance_status = ? ";
    $params[] = $status;
    $types .= "s";
}

if ($search !== '') {
    $where .= "
        AND (
            e.employee_code LIKE ?
            OR e.first_name_en LIKE ?
            OR e.last_name_en LIKE ?
            OR e.phone LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= "ssss";
}

/* ==========================================
   PREPARED STATEMENT HELPER
========================================== */

function ear_execute($conn, $sql, $types, &$params) {
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $bindValues = [];
        $bindValues[] = $types;

        foreach ($params as $key => &$value) {
            $bindValues[] = &$value;
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bindValues
        );
    }

    $stmt->execute();

    return $stmt;
}

/* ==========================================
   SUMMARY TOTALS
========================================== */

$summarySql = "
    SELECT
        COUNT(*) AS total_records,
        COUNT(DISTINCT ea.employee_id) AS total_employees,

        SUM(ea.attendance_status = 'present') AS present_count,
        SUM(ea.attendance_status = 'absent') AS absent_count,
        SUM(ea.attendance_status = 'late') AS late_count,
        SUM(ea.attendance_status = 'half_day') AS half_day_count,
        SUM(ea.attendance_status = 'on_leave') AS leave_count

    FROM employee_attendance ea

    INNER JOIN employees e
        ON e.id = ea.employee_id

    $where
";

$summaryStmt = ear_execute(
    $conn,
    $summarySql,
    $types,
    $params
);

$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

/* ==========================================
   ATTENDANCE DETAILS
========================================== */

$detailsSql = "
    SELECT
        ea.id,
        ea.attendance_date,
        ea.attendance_status,
        ea.check_in_time,
        ea.check_out_time,
        ea.remarks,

        e.id AS employee_id,
        e.employee_code,
        e.first_name_en,
        e.last_name_en,
        e.phone,

        d.department_name_en

    FROM employee_attendance ea

    INNER JOIN employees e
        ON e.id = ea.employee_id

    LEFT JOIN departments d
        ON d.id = e.department_id

    $where

    ORDER BY ea.attendance_date DESC, e.employee_code ASC

    LIMIT 10000
";

$detailsStmt = ear_execute(
    $conn,
    $detailsSql,
    $types,
    $params
);

$result = $detailsStmt->get_result();

$records = [];

while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

$detailsStmt->close();

/* ==========================================
   CSV EXPORT
========================================== */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'employee_attendance_' .
        $fromDate . '_to_' . $toDate . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'Date',
        'Employee Code',
        'Employee Name',
        'Department',
        'Phone',
        'Status',
        'Check In',
        'Check Out',
        'Remarks'
    ]);

    foreach ($records as $row) {
        $employeeName = trim(
            $row['first_name_en'] . ' ' . $row['last_name_en']
        );

        fputcsv($output, [
            $row['attendance_date'],
            $row['employee_code'],
            $employeeName,
            $row['department_name_en'] ?? '',
            $row['phone'],
            ucfirst(str_replace('_', ' ', $row['attendance_status'])),
            $row['check_in_time'] ?? '',
            $row['check_out_time'] ?? '',
            $row['remarks'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

function ear_status_label($status) {
    return ucfirst(str_replace('_', ' ', $status));
}

function ear_status_class($status) {
    $classes = [
        'present' => 'present',
        'absent' => 'absent',
        'late' => 'late',
        'half_day' => 'half-day',
        'on_leave' => 'leave'
    ];

    return $classes[$status] ?? '';
}

$exportQuery = http_build_query([
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'status' => $status,
    'search' => $search,
    'export' => 'csv'
]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Attendance Report | SISMS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    background: #f3f6fa;
    font-family: Arial, sans-serif;
    color: #1e293b;
}

.container {
    max-width: 1400px;
    margin: auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.header h2 {
    margin: 0 0 7px;
    color: #123354;
}

.header p {
    margin: 0;
    color: #64748b;
}

.btn {
    display: inline-block;
    padding: 10px 15px;
    border-radius: 7px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}

.btn-primary {
    background: #123b60;
    color: white;
}

.btn-success {
    background: #16834b;
    color: white;
}

.btn-light {
    background: #e8edf3;
    color: #1e293b;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    margin-bottom: 22px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    align-items: end;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
    font-size: 13px;
}

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #d5dce5;
    border-radius: 7px;
    background: white;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 22px;
}

.stat {
    background: white;
    padding: 18px;
    border-radius: 10px;
    border-left: 4px solid #123b60;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.stat small {
    display: block;
    color: #64748b;
    margin-bottom: 8px;
}

.stat strong {
    font-size: 25px;
}

.stat.present { border-color: #16834b; }
.stat.absent { border-color: #dc3545; }
.stat.late { border-color: #e59b20; }
.stat.leave { border-color: #64748b; }

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #e8edf2;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f5f7fa;
    color: #334155;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: capitalize;
}

.status.present {
    background: #d9f7e5;
    color: #087443;
}

.status.absent {
    background: #ffe0e0;
    color: #b42323;
}

.status.late {
    background: #fff0cc;
    color: #946000;
}

.status.half-day {
    background: #e4e8ff;
    color: #3545a0;
}

.status.leave {
    background: #e7ebef;
    color: #475569;
}

.alert {
    padding: 12px 15px;
    background: #fff0f0;
    color: #a32121;
    border-radius: 8px;
    margin-bottom: 18px;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #64748b;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
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

    table {
        min-width: 0;
    }

    th, td {
        font-size: 10px;
        padding: 7px;
    }
}

@media(max-width: 600px) {
    body {
        padding: 12px;
    }

    .card {
        padding: 15px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h2>Employee Attendance Report</h2>
            <p>Review employee attendance records and daily statistics.</p>
        </div>

        <div class="actions no-print">
            <a href="employee_attendance.php" class="btn btn-light">
                Take Attendance
            </a>

            <a href="dashboard.php" class="btn btn-light">
                Dashboard
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert">
            <?= ear_h($error) ?>
        </div>
    <?php endif; ?>

    <div class="card no-print">
        <form method="GET">

            <div class="filters">

                <div>
                    <label>From Date</label>
                    <input type="date"
                           name="from_date"
                           value="<?= ear_h($fromDate) ?>"
                           required>
                </div>

                <div>
                    <label>To Date</label>
                    <input type="date"
                           name="to_date"
                           value="<?= ear_h($toDate) ?>"
                           required>
                </div>

                <div>
                    <label>Attendance Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $item): ?>
                            <option value="<?= ear_h($item) ?>"
                                <?= $status === $item ? 'selected' : '' ?>>
                                <?= ear_h(ear_status_label($item)) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label>Search Employee</label>
                    <input type="text"
                           name="search"
                           placeholder="Code, name or phone"
                           value="<?= ear_h($search) ?>">
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        Generate Report
                    </button>

                    <a href="employee_attendance_report.php"
                       class="btn btn-light">
                        Reset
                    </a>
                </div>

            </div>

        </form>
    </div>

    <!-- SUMMARY -->

    <div class="stats">

        <div class="stat">
            <small>Total Attendance Records</small>
            <strong><?= number_format((int)$summary['total_records']) ?></strong>
        </div>

        <div class="stat">
            <small>Employees Recorded</small>
            <strong><?= number_format((int)$summary['total_employees']) ?></strong>
        </div>

        <div class="stat present">
            <small>Present</small>
            <strong><?= number_format((int)$summary['present_count']) ?></strong>
        </div>

        <div class="stat absent">
            <small>Absent</small>
            <strong><?= number_format((int)$summary['absent_count']) ?></strong>
        </div>

        <div class="stat late">
            <small>Late</small>
            <strong><?= number_format((int)$summary['late_count']) ?></strong>
        </div>

        <div class="stat">
            <small>Half Day</small>
            <strong><?= number_format((int)$summary['half_day_count']) ?></strong>
        </div>

        <div class="stat leave">
            <small>On Leave</small>
            <strong><?= number_format((int)$summary['leave_count']) ?></strong>
        </div>

    </div>

    <!-- REPORT TABLE -->

    <div class="card">

        <div class="header">
            <div>
                <h2 style="font-size:18px;">Attendance Details</h2>
                <p>
                    <?= ear_h($fromDate) ?> to <?= ear_h($toDate) ?>
                </p>
            </div>

            <div class="actions no-print">
                <a href="?<?= ear_h($exportQuery) ?>"
                   class="btn btn-success">
                    Export CSV
                </a>

                <button type="button"
                        onclick="window.print()"
                        class="btn btn-primary">
                    Print Report
                </button>
            </div>
        </div>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Employee Code</th>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($records)): ?>

                    <tr>
                        <td colspan="10" class="empty">
                            No attendance records found for the selected filters.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($records as $index => $row): ?>

                        <?php
                        $employeeName = trim(
                            $row['first_name_en'] . ' ' . $row['last_name_en']
                        );

                        $statusClass = ear_status_class(
                            $row['attendance_status']
                        );
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td><?= ear_h($row['attendance_date']) ?></td>

                            <td><?= ear_h($row['employee_code']) ?></td>

                            <td><?= ear_h($employeeName) ?></td>

                            <td>
                                <?= ear_h($row['department_name_en'] ?? '-') ?>
                            </td>

                            <td><?= ear_h($row['phone']) ?></td>

                            <td>
                                <span class="status <?= ear_h($statusClass) ?>">
                                    <?= ear_h(
                                        ear_status_label($row['attendance_status'])
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= ear_h($row['check_in_time'] ?: '-') ?>
                            </td>

                            <td>
                                <?= ear_h($row['check_out_time'] ?: '-') ?>
                            </td>

                            <td><?= ear_h($row['remarks'] ?: '-') ?></td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>

        <p style="color:#64748b;font-size:12px;margin-top:15px;">
            Showing <?= number_format(count($records)) ?> records.
            Maximum 10,000 records per report/export.
        </p>

    </div>

</div>

</body>
</html>
