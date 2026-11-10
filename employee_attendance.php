
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function staff_att_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$allowedStatuses = [
    'present',
    'absent',
    'late',
    'half_day',
    'on_leave'
];

$attendanceDate = $_GET['date'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$message = '';
$messageType = 'success';
$employees = [];

$userId = (int)($_SESSION['user_id'] ?? 0);

/* ==========================================
   VALIDATE DATE
========================================== */

$dateObject = DateTime::createFromFormat('!Y-m-d', $attendanceDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $attendanceDate) {
    $attendanceDate = date('Y-m-d');
}

/* ==========================================
   SAVE ATTENDANCE
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $attendanceDate = trim($_POST['attendance_date'] ?? '');

    $dateObject = DateTime::createFromFormat('!Y-m-d', $attendanceDate);

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $attendanceDate
    ) {
        $message = 'Invalid attendance date.';
        $messageType = 'danger';

    } elseif ($userId <= 0) {

        $message = 'Your login session is invalid. Please log in again.';
        $messageType = 'danger';

    } else {

        $attendanceRows = $_POST['attendance'] ?? [];

        if (!is_array($attendanceRows)) {
            $attendanceRows = [];
        }

        $savedCount = 0;
        $skippedCount = 0;
        $inTransaction = false;

        try {

            /* Get only active employee IDs */

            $allowedEmployees = [];

            $allowedResult = $conn->query("
                SELECT id
                FROM employees
                WHERE employment_status = 'active'
            ");

            while ($employee = $allowedResult->fetch_assoc()) {
                $allowedEmployees[(int)$employee['id']] = true;
            }

            $conn->begin_transaction();
            $inTransaction = true;

            $saveStmt = $conn->prepare("
                INSERT INTO employee_attendance (
                    employee_id,
                    attendance_date,
                    attendance_status,
                    check_in_time,
                    check_out_time,
                    remarks,
                    marked_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)

                ON DUPLICATE KEY UPDATE
                    attendance_status = VALUES(attendance_status),
                    check_in_time = VALUES(check_in_time),
                    check_out_time = VALUES(check_out_time),
                    remarks = VALUES(remarks),
                    marked_by = VALUES(marked_by)
            ");

            foreach ($attendanceRows as $employeeId => $row) {

                $employeeId = (int)$employeeId;

                if (
                    $employeeId <= 0 ||
                    !isset($allowedEmployees[$employeeId]) ||
                    !is_array($row)
                ) {
                    continue;
                }

                $status = trim($row['status'] ?? '');

                // Blank means leave this employee unmarked.
                if ($status === '') {
                    $skippedCount++;
                    continue;
                }

                if (!in_array($status, $allowedStatuses, true)) {
                    throw new Exception(
                        'Invalid attendance status for employee ID ' .
                        $employeeId
                    );
                }

                $checkIn = trim($row['check_in'] ?? '');
                $checkOut = trim($row['check_out'] ?? '');
                $remarks = trim($row['remarks'] ?? '');

                if (strlen($remarks) > 500) {
                    throw new Exception(
                        'Remarks cannot exceed 500 characters.'
                    );
                }

                /* Validate time format */

                if (
                    $checkIn !== '' &&
                    !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $checkIn)
                ) {
                    throw new Exception('Invalid check-in time.');
                }

                if (
                    $checkOut !== '' &&
                    !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $checkOut)
                ) {
                    throw new Exception('Invalid check-out time.');
                }

                if (
                    $checkIn !== '' &&
                    $checkOut !== '' &&
                    $checkOut < $checkIn
                ) {
                    throw new Exception(
                        'Check-out time cannot be earlier than check-in time.'
                    );
                }

                // Store empty values as NULL.
                $checkIn = $checkIn !== '' ? $checkIn . ':00' : null;
                $checkOut = $checkOut !== '' ? $checkOut . ':00' : null;
                $remarks = $remarks !== '' ? $remarks : null;

                $saveStmt->bind_param(
                    "isssssi",
                    $employeeId,
                    $attendanceDate,
                    $status,
                    $checkIn,
                    $checkOut,
                    $remarks,
                    $userId
                );

                $saveStmt->execute();

                $savedCount++;
            }

            $saveStmt->close();

            $conn->commit();
            $inTransaction = false;

            // Prevent duplicate submission after refresh.
            header(
                'Location: employee_attendance.php?date=' .
                urlencode($attendanceDate) .
                '&saved=' . $savedCount .
                '&skipped=' . $skippedCount
            );

            exit;

        } catch (Throwable $e) {

            if ($inTransaction) {
                $conn->rollback();
            }

            error_log('Employee attendance error: ' . $e->getMessage());

            $message = 'Unable to save attendance. Please check the entered information and try again.';
            $messageType = 'danger';
        }
    }
}

/* ==========================================
   SUCCESS MESSAGE
========================================== */

if (isset($_GET['saved'])) {

    $saved = max(0, (int)$_GET['saved']);
    $skipped = max(0, (int)($_GET['skipped'] ?? 0));

    $message = $saved . ' employee attendance record(s) saved successfully.';

    if ($skipped > 0) {
        $message .= ' ' . $skipped . ' employee(s) left unmarked.';
    }

    $messageType = 'success';
}

/* ==========================================
   LOAD ACTIVE EMPLOYEES AND ATTENDANCE
========================================== */

try {

    $searchLike = '%' . $search . '%';

    $stmt = $conn->prepare("
        SELECT
            e.id,
            e.employee_code,
            e.first_name_en,
            e.last_name_en,
            e.full_name_bn,
            e.phone,

            d.department_name_en,

            a.attendance_status,
            a.check_in_time,
            a.check_out_time,
            a.remarks

        FROM employees e

        LEFT JOIN departments d
            ON d.id = e.department_id

        LEFT JOIN employee_attendance a
            ON a.employee_id = e.id
            AND a.attendance_date = ?

        WHERE e.employment_status = 'active'

        AND (
            ? = ''
            OR e.employee_code LIKE ?
            OR e.first_name_en LIKE ?
            OR e.last_name_en LIKE ?
            OR e.phone LIKE ?
        )

        ORDER BY e.employee_code ASC
    ");

    $stmt->bind_param(
        "ssssss",
        $attendanceDate,
        $search,
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log('Employee attendance loading error: ' . $e->getMessage());

    $message = 'Unable to load employee attendance records.';
    $messageType = 'danger';
}

/* ==========================================
   SUMMARY
========================================== */

$summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'half_day' => 0,
    'on_leave' => 0,
    'unmarked' => 0
];

foreach ($employees as $employee) {

    $status = $employee['attendance_status'];

    if ($status === null || $status === '') {
        $summary['unmarked']++;
    } elseif (isset($summary[$status])) {
        $summary[$status]++;
    }
}

$totalEmployees = count($employees);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Attendance | SISMS</title>

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
    padding: 25px;
    border-radius: 13px;
    border-bottom: 3px solid var(--gold);
    margin-bottom: 22px;
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
    box-shadow: 0 4px 15px rgba(20,40,60,.04);
}

.card h2 {
    color: var(--navy);
    font-size: 18px;
    margin: 0 0 18px;
}

.filters {
    display: grid;
    grid-template-columns: 1fr 1.5fr auto;
    gap: 14px;
    align-items: end;
}

label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
}

input, select, textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 7px;
    font-family: inherit;
    background: white;
    color: var(--text);
}

input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--gold);
}

.btn {
    display: inline-block;
    border: none;
    border-radius: 7px;
    padding: 11px 17px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
}

.btn-primary {
    background: var(--navy);
    color: white;
}

.btn-success {
    background: var(--green);
    color: white;
}

.btn-light {
    background: #edf1f6;
    color: var(--navy);
}

.message {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 14px;
}

.message-success {
    background: #e5f6ed;
    border: 1px solid #b9e5ce;
    color: #116b49;
}

.message-danger {
    background: #fde9e7;
    border: 1px solid #f2c1bc;
    color: #a32e25;
}

.stats {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.stat {
    background: white;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 17px;
}

.stat small {
    display: block;
    color: var(--muted);
    margin-bottom: 9px;
}

.stat strong {
    display: block;
    font-size: 23px;
    color: var(--navy);
}

.stat.present strong {
    color: var(--green);
}

.stat.absent strong {
    color: var(--red);
}

.stat.late strong {
    color: #bd7b13;
}

.stat.leave strong {
    color: #1675a8;
}

.stat.unmarked strong {
    color: #718096;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    font-size: 13px;
    vertical-align: middle;
}

th {
    background: #f4f7fa;
    color: var(--navy);
    white-space: nowrap;
}

td input, td select {
    min-width: 110px;
    padding: 8px;
}

td .remarks-input {
    min-width: 160px;
}

.employee-name {
    font-weight: bold;
    color: var(--navy);
}

.employee-code {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 20px;
}

.table-note {
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 15px;
}

.empty {
    text-align: center;
    padding: 35px;
    color: var(--muted);
}

@media(max-width: 1000px) {
    .stats {
        grid-template-columns: repeat(3, 1fr);
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
        grid-template-columns: repeat(2, 1fr);
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .topbar h1 {
        font-size: 21px;
    }
}

@media print {
    body {
        background: white;
    }

    .container {
        max-width: 100%;
        padding: 0;
    }

    .topbar {
        background: white;
        color: black;
        border: 1px solid #333;
    }

    .topbar p {
        color: #333;
    }

    .filters-card,
    .actions,
    .message {
        display: none !important;
    }

    .card, .stat {
        box-shadow: none;
    }

    input, select {
        border: none;
        appearance: none;
        padding: 0;
    }

    table {
        min-width: 0;
    }

    th, td {
        font-size: 10px;
        padding: 7px;
    }
}

</style>

</head>

<body>

<div class="container">

    <div class="topbar">

        <h1>EMPLOYEE ATTENDANCE</h1>

        <p>
            Daily attendance management for teachers and school employees
        </p>

    </div>

    <?php if ($message): ?>

        <div class="message message-<?= staff_att_h($messageType) ?>">
            <?= staff_att_h($message) ?>
        </div>

    <?php endif; ?>

    <!-- FILTERS -->

    <div class="card filters-card">

        <h2>Attendance Filters</h2>

        <form method="GET">

            <div class="filters">

                <div>

                    <label>Attendance Date</label>

                    <input
                        type="date"
                        name="date"
                        value="<?= staff_att_h($attendanceDate) ?>"
                        required
                    >

                </div>

                <div>

                    <label>Search Employee</label>

                    <input
                        type="text"
                        name="search"
                        value="<?= staff_att_h($search) ?>"
                        placeholder="Employee code, name or phone"
                    >

                </div>

                <div>

                    <button type="submit" class="btn btn-primary">
                        Load Employees
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- SUMMARY -->

    <div class="stats">

        <div class="stat">
            <small>Total Employees</small>
            <strong><?= $totalEmployees ?></strong>
        </div>

        <div class="stat present">
            <small>Present</small>
            <strong><?= $summary['present'] ?></strong>
        </div>

        <div class="stat absent">
            <small>Absent</small>
            <strong><?= $summary['absent'] ?></strong>
        </div>

        <div class="stat late">
            <small>Late</small>
            <strong><?= $summary['late'] ?></strong>
        </div>

        <div class="stat leave">
            <small>On Leave</small>
            <strong><?= $summary['on_leave'] + $summary['half_day'] ?></strong>
        </div>

        <div class="stat unmarked">
            <small>Not Marked</small>
            <strong><?= $summary['unmarked'] ?></strong>
        </div>

    </div>

    <!-- ATTENDANCE FORM -->

    <div class="card">

        <h2>Daily Attendance Register</h2>

        <p class="table-note">
            Date: <strong><?= staff_att_h($attendanceDate) ?></strong>
            |
            Select a status for each employee you want to mark.
        </p>

        <?php if (empty($employees)): ?>

            <div class="empty">
                <h3>No Active Employees Found</h3>
                <p>Add active employees before taking attendance.</p>
            </div>

        <?php else: ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= staff_att_h(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="attendance_date"
                    value="<?= staff_att_h($attendanceDate) ?>"
                >

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th>SL</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Attendance Status</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Remarks</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($employees as $index => $employee): ?>

                            <?php
                            $employeeId = (int)$employee['id'];

                            $currentStatus = $employee['attendance_status'] ?? '';

                            $checkInValue = !empty($employee['check_in_time'])
                                ? substr($employee['check_in_time'], 0, 5)
                                : '';

                            $checkOutValue = !empty($employee['check_out_time'])
                                ? substr($employee['check_out_time'], 0, 5)
                                : '';
                            ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>

                                    <div class="employee-name">

                                        <?= staff_att_h(
                                            trim(
                                                $employee['first_name_en'] . ' ' .
                                                ($employee['last_name_en'] ?? '')
                                            )
                                        ) ?>

                                    </div>

                                    <div class="employee-code">

                                        <?= staff_att_h($employee['employee_code']) ?>

                                    </div>

                                </td>

                                <td>
                                    <?= staff_att_h(
                                        $employee['department_name_en'] ?: 'Not Assigned'
                                    ) ?>
                                </td>

                                <td>

                                    <select
                                        name="attendance[<?= $employeeId ?>][status]"
                                    >

                                        <option value="">
                                            -- Not Marked --
                                        </option>

                                        <?php foreach ($allowedStatuses as $status): ?>

                                            <option
                                                value="<?= staff_att_h($status) ?>"
                                                <?= $currentStatus === $status ? 'selected' : '' ?>
                                            >
                                                <?= staff_att_h(
                                                    ucwords(str_replace('_', ' ', $status))
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </td>

                                <td>

                                    <input
                                        type="time"
                                        name="attendance[<?= $employeeId ?>][check_in]"
                                        value="<?= staff_att_h($checkInValue) ?>"
                                    >

                                </td>

                                <td>

                                    <input
                                        type="time"
                                        name="attendance[<?= $employeeId ?>][check_out]"
                                        value="<?= staff_att_h($checkOutValue) ?>"
                                    >

                                </td>

                                <td>

                                    <input
                                        type="text"
                                        class="remarks-input"
                                        name="attendance[<?= $employeeId ?>][remarks]"
                                        value="<?= staff_att_h($employee['remarks'] ?? '') ?>"
                                        maxlength="500"
                                        placeholder="Optional remarks"
                                    >

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <div class="actions">

                    <button
                        type="submit"
                        class="btn btn-success"
                        onclick="return confirm('Save attendance for <?= count($employees) ?> displayed employees?');"
                    >
                        Save Attendance
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        onclick="window.print()"
                    >
                        Print Register
                    </button>

                    <a
                        href="employee_attendance.php"
                        class="btn btn-light"
                    >
                        Clear Search
                    </a>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
