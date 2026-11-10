
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function elc_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   SELECT MONTH
========================================= */

$monthInput = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthInput)) {
    $monthInput = date('Y-m');
}

$monthStart = DateTime::createFromFormat('!Y-m-d', $monthInput . '-01');

if (!$monthStart) {
    $monthInput = date('Y-m');
    $monthStart = new DateTime(date('Y-m-01'));
}

$monthEnd = (clone $monthStart)
    ->modify('last day of this month');

$previousMonth = (clone $monthStart)
    ->modify('-1 month')
    ->format('Y-m');

$nextMonth = (clone $monthStart)
    ->modify('+1 month')
    ->format('Y-m');

$startDate = $monthStart->format('Y-m-d');
$endDate = $monthEnd->format('Y-m-d');

$daysInMonth = (int)$monthStart->format('t');
$firstWeekday = (int)$monthStart->format('w');

/* =========================================
   LOAD LEAVE REQUESTS
========================================= */

$stmt = $conn->prepare("
    SELECT
        lr.id,
        lr.employee_id,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.approval_status,
        lr.reason,

        e.employee_code,
        e.first_name_en,
        e.last_name_en,

        lt.type_name

    FROM employee_leave_requests lr

    INNER JOIN employees e
        ON e.id = lr.employee_id

    INNER JOIN employee_leave_types lt
        ON lt.id = lr.leave_type_id

    WHERE lr.start_date <= ?
      AND lr.end_date >= ?
      AND lr.approval_status IN ('approved', 'pending')

    ORDER BY
        lr.approval_status,
        e.first_name_en,
        lr.start_date
");

$stmt->bind_param("ss", $endDate, $startDate);
$stmt->execute();

$result = $stmt->get_result();

$calendarData = [];
$approvedCount = 0;
$pendingCount = 0;
$approvedEmployeeIds = [];
$pendingEmployeeIds = [];

while ($row = $result->fetch_assoc()) {

    $start = max($row['start_date'], $startDate);
    $end = min($row['end_date'], $endDate);

    $current = new DateTime($start);
    $last = new DateTime($end);

    while ($current <= $last) {

        $day = (int)$current->format('j');

        $calendarData[$day][] = $row;

        $current->modify('+1 day');
    }

    if ($row['approval_status'] === 'approved') {
        $approvedCount++;
        $approvedEmployeeIds[$row['employee_id']] = true;
    } else {
        $pendingCount++;
        $pendingEmployeeIds[$row['employee_id']] = true;
    }
}

/* =========================================
   MONTH NAME
========================================= */

$monthTitle = $monthStart->format('F Y');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Leave Calendar | Sezua SISMS</title>

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
    max-width: 1450px;
    margin: auto;
}

h1 {
    color: #123d32;
    margin-bottom: 7px;
}

.subtitle {
    color: #64748b;
    margin-bottom: 25px;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.stat {
    background: white;
    border-radius: 12px;
    padding: 19px;
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

.month-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.month-navigation h2 {
    margin: 0;
    color: #123d32;
}

.btn {
    display: inline-block;
    padding: 10px 15px;
    border: 0;
    border-radius: 7px;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.btn-primary {
    background: #123d32;
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

.month-form {
    display: flex;
    gap: 9px;
    align-items: center;
    flex-wrap: wrap;
}

.month-form input {
    padding: 10px;
    border: 1px solid #d5dce5;
    border-radius: 7px;
}

.calendar {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 7px;
}

.weekday {
    padding: 12px 5px;
    text-align: center;
    font-weight: bold;
    background: #123d32;
    color: white;
    border-radius: 6px;
    font-size: 13px;
}

.day {
    min-height: 145px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px;
    min-width: 0;
}

.day.empty {
    background: #f1f5f9;
    border-style: dashed;
}

.day-number {
    font-weight: bold;
    margin-bottom: 8px;
    color: #334155;
}

.today {
    border: 2px solid #d4a72c;
}

.leave-item {
    display: block;
    margin-bottom: 6px;
    padding: 7px;
    border-radius: 6px;
    font-size: 11px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.leave-approved {
    background: #dcfce7;
    color: #166534;
    border-left: 3px solid #15803d;
}

.leave-pending {
    background: #fef3c7;
    color: #92400e;
    border-left: 3px solid #d97706;
}

.legend {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    margin-top: 18px;
    font-size: 13px;
}

.legend span {
    display: flex;
    align-items: center;
    gap: 7px;
}

.dot {
    width: 12px;
    height: 12px;
    display: inline-block;
    border-radius: 3px;
}

.dot-approved {
    background: #15803d;
}

.dot-pending {
    background: #d97706;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th, td {
    padding: 11px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    font-size: 13px;
}

th {
    background: #f8fafc;
}

.badge {
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge-approved {
    background: #dcfce7;
    color: #166534;
}

.badge-pending {
    background: #fef3c7;
    color: #92400e;
}

.note {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
}

@media(max-width: 900px) {
    body {
        padding: 12px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .day {
        min-height: 110px;
    }

    .calendar {
        gap: 3px;
    }
}

@media(max-width: 550px) {
    .day {
        min-height: 85px;
        padding: 4px;
    }

    .leave-item {
        font-size: 9px;
        padding: 4px;
    }

    .weekday {
        font-size: 10px;
        padding: 8px 2px;
    }
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

    .day {
        min-height: 100px;
        break-inside: avoid;
    }
}
</style>
</head>

<body>

<div class="container">

    <h1>Employee Leave Calendar</h1>

    <p class="subtitle">
        Monthly overview of approved and pending employee leave.
    </p>

    <!-- SUMMARY -->

    <div class="stats">

        <div class="stat">
            <span>Approved Leave Requests</span>
            <strong><?= $approvedCount ?></strong>
        </div>

        <div class="stat">
            <span>Pending Leave Requests</span>
            <strong><?= $pendingCount ?></strong>
        </div>

        <div class="stat">
            <span>Employees with Approved Leave</span>
            <strong><?= count($approvedEmployeeIds) ?></strong>
        </div>

        <div class="stat">
            <span>Employees with Pending Leave</span>
            <strong><?= count($pendingEmployeeIds) ?></strong>
        </div>

    </div>

    <div class="card">

        <div class="month-navigation">

            <a class="btn btn-light no-print"
               href="?month=<?= elc_h($previousMonth) ?>">
                &laquo; Previous
            </a>

            <h2><?= elc_h($monthTitle) ?></h2>

            <a class="btn btn-light no-print"
               href="?month=<?= elc_h($nextMonth) ?>">
                Next &raquo;
            </a>

        </div>

        <form method="GET" class="month-form no-print">

            <input type="month"
                   name="month"
                   value="<?= elc_h($monthInput) ?>"
                   required>

            <button type="submit" class="btn btn-primary">
                View Month
            </button>

            <button type="button"
                    onclick="window.print()"
                    class="btn btn-secondary">
                Print Calendar
            </button>

        </form>

        <div class="legend">

            <span>
                <i class="dot dot-approved"></i>
                Approved Leave
            </span>

            <span>
                <i class="dot dot-pending"></i>
                Pending Request
            </span>

        </div>

        <div style="height:20px"></div>

        <!-- CALENDAR -->

        <div class="calendar">

            <?php
            $weekdays = ['Sunday', 'Monday', 'Tuesday',
                         'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            foreach ($weekdays as $weekday):
            ?>
                <div class="weekday">
                    <?= elc_h($weekday) ?>
                </div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
                <div class="day empty"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>

                <?php
                $date = sprintf(
                    '%04d-%02d-%02d',
                    (int)$monthStart->format('Y'),
                    (int)$monthStart->format('m'),
                    $day
                );

                $isToday = $date === date('Y-m-d');
                ?>

                <div class="day <?= $isToday ? 'today' : '' ?>">

                    <div class="day-number">
                        <?= $day ?>
                        <?php if ($isToday): ?>
                            <small style="color:#b7791f">Today</small>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($calendarData[$day])): ?>

                        <?php foreach ($calendarData[$day] as $leave): ?>

                            <?php
                            $class = $leave['approval_status'] === 'approved'
                                ? 'leave-approved'
                                : 'leave-pending';
                            ?>

                            <div class="leave-item <?= $class ?>">

                                <strong>
                                    <?= elc_h(
                                        $leave['first_name_en'] . ' ' .
                                        $leave['last_name_en']
                                    ) ?>
                                </strong>

                                <br>

                                <?= elc_h($leave['type_name']) ?>

                                <br>

                                <small>
                                    <?= ucfirst(elc_h($leave['approval_status'])) ?>
                                </small>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endfor; ?>

            <?php
            $remainingCells = (7 - (($firstWeekday + $daysInMonth) % 7)) % 7;

            for ($i = 0; $i < $remainingCells; $i++):
            ?>
                <div class="day empty"></div>
            <?php endfor; ?>

        </div>

    </div>

    <!-- MONTHLY LEAVE LIST -->

    <div class="card">

        <h2>Monthly Leave Schedule</h2>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Total Days</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$calendarData): ?>

                    <tr>
                        <td colspan="6">
                            No approved or pending leave for this month.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php
                    $displayed = [];

                    foreach ($calendarData as $dayEntries) {
                        foreach ($dayEntries as $leave) {
                            $displayed[$leave['id']] = $leave;
                        }
                    }

                    $displayed = array_values($displayed);

                    usort($displayed, function ($a, $b) {
                        return strcmp($a['start_date'], $b['start_date']);
                    });
                    ?>

                    <?php foreach ($displayed as $leave): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= elc_h(
                                        $leave['first_name_en'] . ' ' .
                                        $leave['last_name_en']
                                    ) ?>
                                </strong>
                                <br>
                                <small>
                                    <?= elc_h($leave['employee_code']) ?>
                                </small>
                            </td>

                            <td><?= elc_h($leave['type_name']) ?></td>

                            <td><?= elc_h($leave['start_date']) ?></td>

                            <td><?= elc_h($leave['end_date']) ?></td>

                            <td>
                                <?= number_format((float)$leave['total_days'], 2) ?>
                            </td>

                            <td>
                                <span class="badge badge-<?= elc_h($leave['approval_status']) ?>">
                                    <?= elc_h($leave['approval_status']) ?>
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="note">
            Calendar displays approved and pending requests.
            Rejected and cancelled requests are excluded.
            A multi-day leave appears on every affected calendar date.
        </p>

    </div>

    <a href="dashboard.php"
       class="btn btn-secondary no-print">
        Back to Dashboard
    </a>

</div>

</body>
</html>
