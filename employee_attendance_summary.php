
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function eas_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$month = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

$startDate = $month . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

$sql = "
    SELECT
        e.id AS employee_id,
        e.employee_code,
        e.first_name_en,
        e.last_name_en,
        e.phone,
        d.department_name_en,

        COUNT(ea.id) AS total_recorded,

        SUM(ea.attendance_status = 'present') AS present_days,
        SUM(ea.attendance_status = 'absent') AS absent_days,
        SUM(ea.attendance_status = 'late') AS late_days,
        SUM(ea.attendance_status = 'half_day') AS half_days,
        SUM(ea.attendance_status = 'on_leave') AS leave_days

    FROM employees e

    LEFT JOIN departments d
        ON d.id = e.department_id

    LEFT JOIN employee_attendance ea
        ON ea.employee_id = e.id
        AND ea.attendance_date BETWEEN ? AND ?

    WHERE e.employment_status = 'active'

    GROUP BY
        e.id,
        e.employee_code,
        e.first_name_en,
        e.last_name_en,
        e.phone,
        d.department_name_en

    ORDER BY e.employee_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();

$result = $stmt->get_result();

$employees = [];

$totals = [
    'total_recorded' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'late_days' => 0,
    'half_days' => 0,
    'leave_days' => 0
];

while ($row = $result->fetch_assoc()) {

    foreach ($totals as $key => $value) {
        $row[$key] = (int)($row[$key] ?? 0);
        $totals[$key] += $row[$key];
    }

    // Attendance percentage = Present records / All recorded days.
    // Late is not counted as present in this percentage.
    $row['attendance_percentage'] = $row['total_recorded'] > 0
        ? ($row['present_days'] / $row['total_recorded']) * 100
        : 0;

    $employees[] = $row;
}

$stmt->close();

/* CSV EXPORT */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'employee_monthly_attendance_' . $month . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename . '"'
    );

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'Employee Code',
        'Employee Name',
        'Department',
        'Phone',
        'Recorded Days',
        'Present',
        'Absent',
        'Late',
        'Half Day',
        'On Leave',
        'Attendance Percentage'
    ]);

    foreach ($employees as $row) {

        fputcsv($output, [
            $row['employee_code'],
            trim($row['first_name_en'] . ' ' . $row['last_name_en']),
            $row['department_name_en'] ?? '',
            $row['phone'],
            $row['total_recorded'],
            $row['present_days'],
            $row['absent_days'],
            $row['late_days'],
            $row['half_days'],
            $row['leave_days'],
            number_format($row['attendance_percentage'], 2) . '%'
        ]);
    }

    fclose($output);
    exit;
}

$totalEmployees = count($employees);

$exportUrl = '?' . http_build_query([
    'month' => $month,
    'export' => 'csv'
]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Monthly Attendance Summary | SISMS</title>

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
    margin-bottom: 22px;
}

h2 {
    color: #123354;
    margin: 0 0 7px;
}

p {
    color: #64748b;
}

.card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
}

label {
    display: block;
    font-weight: bold;
    font-size: 13px;
    margin-bottom: 7px;
}

input {
    padding: 11px;
    border: 1px solid #d5dce5;
    border-radius: 7px;
}

.btn {
    display: inline-block;
    padding: 11px 15px;
    border-radius: 7px;
    border: none;
    text-decoration: none;
    cursor: pointer;
    font-weight: bold;
    font-size: 13px;
}

.primary {
    background: #123b60;
    color: white;
}

.success {
    background: #16834b;
    color: white;
}

.light {
    background: #e8edf3;
    color: #1e293b;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat {
    background: white;
    padding: 18px;
    border-radius: 10px;
    border-left: 4px solid #123b60;
}

.stat small {
    color: #64748b;
    display: block;
    margin-bottom: 8px;
}

.stat strong {
    font-size: 24px;
}

.stat.green { border-color: #16834b; }
.stat.red { border-color: #dc3545; }
.stat.orange { border-color: #e59b20; }

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #e8edf2;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f5f7fa;
}

.percentage {
    font-weight: bold;
    color: #123b60;
}

.no-record {
    color: #dc3545;
    font-weight: bold;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #64748b;
}

@media print {
    body {
        padding: 0;
        background: white;
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
        padding: 6px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h2>Monthly Attendance Summary</h2>
            <p>Employee-wise attendance performance for the selected month.</p>
        </div>

        <div class="no-print">
            <a href="employee_attendance.php" class="btn light">
                Take Attendance
            </a>

            <a href="employee_attendance_report.php" class="btn light">
                Detailed Report
            </a>

            <a href="dashboard.php" class="btn light">
                Dashboard
            </a>
        </div>
    </div>

    <div class="card no-print">

        <form method="GET" class="filters">

            <div>
                <label>Select Month</label>
                <input type="month"
                       name="month"
                       value="<?= eas_h($month) ?>"
                       required>
            </div>

            <button type="submit" class="btn primary">
                Generate Summary
            </button>

            <a href="employee_attendance_summary.php"
               class="btn light">
                Current Month
            </a>

            <a href="<?= eas_h($exportUrl) ?>"
               class="btn success">
                Export CSV
            </a>

            <button type="button"
                    onclick="window.print()"
                    class="btn primary">
                Print Summary
            </button>

        </form>

    </div>

    <div class="stats">

        <div class="stat">
            <small>Active Employees</small>
            <strong><?= number_format($totalEmployees) ?></strong>
        </div>

        <div class="stat green">
            <small>Total Present</small>
            <strong><?= number_format($totals['present_days']) ?></strong>
        </div>

        <div class="stat red">
            <small>Total Absent</small>
            <strong><?= number_format($totals['absent_days']) ?></strong>
        </div>

        <div class="stat orange">
            <small>Total Late</small>
            <strong><?= number_format($totals['late_days']) ?></strong>
        </div>

        <div class="stat">
            <small>Total Half Days</small>
            <strong><?= number_format($totals['half_days']) ?></strong>
        </div>

        <div class="stat">
            <small>Total On Leave</small>
            <strong><?= number_format($totals['leave_days']) ?></strong>
        </div>

    </div>

    <div class="card">

        <div class="header">
            <div>
                <h2 style="font-size:18px;">Employee-wise Summary</h2>
                <p>
                    Reporting month: <?= eas_h(date('F Y', strtotime($startDate))) ?>
                </p>
            </div>
        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee Code</th>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th>Phone</th>
                        <th>Recorded Days</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Half Day</th>
                        <th>On Leave</th>
                        <th>Present %</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($employees)): ?>

                    <tr>
                        <td colspan="12" class="empty">
                            No active employees found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($employees as $index => $employee): ?>

                        <?php
                        $employeeName = trim(
                            $employee['first_name_en'] . ' ' .
                            $employee['last_name_en']
                        );
                        ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td><?= eas_h($employee['employee_code']) ?></td>

                            <td><?= eas_h($employeeName) ?></td>

                            <td>
                                <?= eas_h($employee['department_name_en'] ?? '-') ?>
                            </td>

                            <td><?= eas_h($employee['phone']) ?></td>

                            <td><?= number_format($employee['total_recorded']) ?></td>

                            <td><?= number_format($employee['present_days']) ?></td>

                            <td><?= number_format($employee['absent_days']) ?></td>

                            <td><?= number_format($employee['late_days']) ?></td>

                            <td><?= number_format($employee['half_days']) ?></td>

                            <td><?= number_format($employee['leave_days']) ?></td>

                            <td>
                                <?php if ($employee['total_recorded'] > 0): ?>
                                    <span class="percentage">
                                        <?= number_format(
                                            $employee['attendance_percentage'],
                                            2
                                        ) ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="no-record">No records</span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p style="font-size:12px;margin-top:15px;">
            Attendance percentage = Present days ÷ Total recorded attendance days × 100.
            Late, absent, half-day and leave records are included in the denominator.
            Employees without records show "No records".
        </p>

    </div>

</div>

</body>
</html>
