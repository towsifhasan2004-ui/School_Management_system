
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function elb_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   VALIDATE SELECTED YEAR
========================================= */

$selectedYear = (int)($_GET['year'] ?? date('Y'));

if ($selectedYear < 1901 || $selectedYear > 2155) {
    $selectedYear = (int)date('Y');
}

$yearStart = sprintf('%04d-01-01', $selectedYear);
$yearEnd = sprintf('%04d-12-31', $selectedYear);

/* =========================================
   SAVE / UPDATE LEAVE ENTITLEMENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    try {

        if (($_POST['action'] ?? '') !== 'save_balance') {
            throw new Exception('Invalid action.');
        }

        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $entitlementYear = (int)($_POST['entitlement_year'] ?? 0);

        $entitledDaysInput = trim($_POST['entitled_days'] ?? '');
        $adjustmentDaysInput = trim($_POST['adjustment_days'] ?? '0');
        $remarks = trim($_POST['remarks'] ?? '');

        if (
            !$employeeId ||
            !$leaveTypeId ||
            $entitlementYear < 1901 ||
            $entitlementYear > 2155
        ) {
            throw new Exception('Please select a valid employee, leave type and year.');
        }

        if (
            $entitledDaysInput === '' ||
            !is_numeric($entitledDaysInput) ||
            !is_numeric($adjustmentDaysInput)
        ) {
            throw new Exception('Enter valid numeric leave days.');
        }

        $entitledDays = (float)$entitledDaysInput;
        $adjustmentDays = (float)$adjustmentDaysInput;

        if (
            $entitledDays < 0 ||
            $adjustmentDays < 0 ||
            $entitledDays > 999999 ||
            $adjustmentDays > 999999
        ) {
            throw new Exception('Leave days must be between 0 and 999999.');
        }

        if (mb_strlen($remarks) > 500) {
            throw new Exception('Remarks cannot exceed 500 characters.');
        }

        // Check employee.
        $stmt = $conn->prepare("
            SELECT id
            FROM employees
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $employeeId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Employee not found.');
        }

        // Check active leave type.
        $stmt = $conn->prepare("
            SELECT id
            FROM employee_leave_types
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("i", $leaveTypeId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Selected leave type is not active.');
        }

        // Insert new balance or update existing balance.
        $stmt = $conn->prepare("
            INSERT INTO employee_leave_balances
            (
                employee_id,
                leave_type_id,
                entitlement_year,
                entitled_days,
                adjustment_days,
                remarks
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                entitled_days = VALUES(entitled_days),
                adjustment_days = VALUES(adjustment_days),
                remarks = VALUES(remarks),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->bind_param(
            "iiidds",
            $employeeId,
            $leaveTypeId,
            $entitlementYear,
            $entitledDays,
            $adjustmentDays,
            $remarks
        );

        $stmt->execute();

        redirect(
            'employee_leave_balance.php?year=' .
            $entitlementYear . '&success=saved'
        );

    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   SUCCESS MESSAGE
========================================= */

if (isset($_GET['success']) && $_GET['success'] === 'saved') {
    $message = 'Employee leave balance saved successfully.';
}

/* =========================================
   LOAD EMPLOYEES
========================================= */

$employees = [];

$result = $conn->query("
    SELECT
        id,
        employee_code,
        first_name_en,
        last_name_en
    FROM employees
    ORDER BY first_name_en, last_name_en
");

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

/* =========================================
   LOAD ACTIVE LEAVE TYPES
========================================= */

$leaveTypes = [];

$result = $conn->query("
    SELECT id, type_name, is_paid
    FROM employee_leave_types
    WHERE status = 'active'
    ORDER BY type_name
");

while ($row = $result->fetch_assoc()) {
    $leaveTypes[] = $row;
}

/* =========================================
   FILTERS
========================================= */

$search = trim($_GET['search'] ?? '');
$filterType = (int)($_GET['leave_type_id'] ?? 0);

$where = [];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "(
        e.employee_code LIKE ?
        OR e.first_name_en LIKE ?
        OR e.last_name_en LIKE ?
    )";

    $term = '%' . $search . '%';

    $types .= 'sss';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($filterType > 0) {
    $where[] = "lb.leave_type_id = ?";
    $types .= 'i';
    $params[] = $filterType;
}

/* =========================================
   LOAD LEAVE BALANCES

   Used days are calculated from approved
   requests overlapping the selected year.
========================================= */

$sql = "
    SELECT
        lb.id,
        lb.employee_id,
        lb.leave_type_id,
        lb.entitlement_year,
        lb.entitled_days,
        lb.adjustment_days,
        lb.remarks,

        e.employee_code,
        e.first_name_en,
        e.last_name_en,

        lt.type_name,
        lt.is_paid,

        COALESCE(used.used_days, 0) AS used_days

    FROM employee_leave_balances lb

    INNER JOIN employees e
        ON e.id = lb.employee_id

    INNER JOIN employee_leave_types lt
        ON lt.id = lb.leave_type_id

    LEFT JOIN (
        SELECT
            lr.employee_id,
            lr.leave_type_id,

            SUM(
                DATEDIFF(
                    LEAST(lr.end_date, ?),
                    GREATEST(lr.start_date, ?)
                ) + 1
            ) AS used_days

        FROM employee_leave_requests lr

        WHERE lr.approval_status = 'approved'
          AND lr.start_date <= ?
          AND lr.end_date >= ?

        GROUP BY
            lr.employee_id,
            lr.leave_type_id

    ) used
        ON used.employee_id = lb.employee_id
       AND used.leave_type_id = lb.leave_type_id

    WHERE lb.entitlement_year = ?
";

if ($where) {
    $sql .= " AND " . implode(" AND ", $where);
}

$sql .= "
    ORDER BY
        e.first_name_en,
        e.last_name_en,
        lt.type_name
    LIMIT 1000
";

$stmt = $conn->prepare($sql);

$allTypes = 'ssssi' . $types;

$allParams = array_merge(
    [
        $yearEnd,
        $yearStart,
        $yearEnd,
        $yearStart,
        $selectedYear
    ],
    $params
);

$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();

$balances = $stmt->get_result();

/* =========================================
   SUMMARY TOTALS
========================================= */

$totalEntitled = 0;
$totalAdjustments = 0;
$totalUsed = 0;
$totalRemaining = 0;

$balanceRows = [];

while ($row = $balances->fetch_assoc()) {

    $entitled = (float)$row['entitled_days'];
    $adjustment = (float)$row['adjustment_days'];
    $used = (float)$row['used_days'];

    $remaining = $entitled + $adjustment - $used;

    $row['remaining_days'] = $remaining;

    $totalEntitled += $entitled;
    $totalAdjustments += $adjustment;
    $totalUsed += $used;
    $totalRemaining += $remaining;

    $balanceRows[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Employee Leave Balance | Sezua SISMS</title>

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
    max-width: 1400px;
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
    background: #fff;
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
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.stat span {
    color: #64748b;
    font-size: 13px;
}

.stat strong {
    display: block;
    font-size: 26px;
    margin-top: 9px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
}

input, select, textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #d5dce5;
    border-radius: 7px;
    font-size: 14px;
}

textarea {
    min-height: 75px;
}

.full {
    grid-column: 1 / -1;
}

button, .btn {
    display: inline-block;
    border: 0;
    padding: 10px 16px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
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

.alert {
    padding: 13px;
    border-radius: 7px;
    margin-bottom: 18px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.filter-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.filter-form input,
.filter-form select {
    width: auto;
    min-width: 190px;
}

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
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f8fafc;
    color: #334155;
}

.remaining-positive {
    color: #15803d;
    font-weight: bold;
}

.remaining-negative {
    color: #dc2626;
    font-weight: bold;
}

.note {
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
}

@media(max-width: 800px) {
    body {
        padding: 12px;
    }

    .grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }
}

@media(max-width: 420px) {
    .grid {
        grid-template-columns: 1fr 1fr;
    }
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

    <h1>Employee Leave Balance Management</h1>

    <p class="subtitle">
        Manage annual leave entitlements, approved leave usage and remaining balances.
    </p>

    <?php if ($message): ?>
        <div class="alert alert-<?= elb_h($messageType) ?>">
            <?= elb_h($message) ?>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->

    <div class="grid">

        <div class="stat">
            <span>Total Entitled Days</span>
            <strong><?= number_format($totalEntitled, 2) ?></strong>
        </div>

        <div class="stat">
            <span>Total Adjustments</span>
            <strong><?= number_format($totalAdjustments, 2) ?></strong>
        </div>

        <div class="stat">
            <span>Approved Leave Used</span>
            <strong><?= number_format($totalUsed, 2) ?></strong>
        </div>

        <div class="stat">
            <span>Total Remaining Days</span>
            <strong><?= number_format($totalRemaining, 2) ?></strong>
        </div>

    </div>

    <!-- ENTITLEMENT FORM -->

    <div class="card no-print">

        <h2>Set Employee Leave Entitlement</h2>

        <form method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= elb_h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="save_balance">

            <div class="form-grid">

                <div>
                    <label>Employee *</label>

                    <select name="employee_id" required>
                        <option value="">Select Employee</option>

                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int)$employee['id'] ?>">
                                <?= elb_h(
                                    $employee['employee_code'] . ' - ' .
                                    $employee['first_name_en'] . ' ' .
                                    $employee['last_name_en']
                                ) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label>Leave Type *</label>

                    <select name="leave_type_id" required>
                        <option value="">Select Leave Type</option>

                        <?php foreach ($leaveTypes as $type): ?>
                            <option value="<?= (int)$type['id'] ?>">
                                <?= elb_h($type['type_name']) ?>
                                (<?= $type['is_paid'] ? 'Paid' : 'Unpaid' ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label>Entitlement Year *</label>

                    <input type="number"
                           name="entitlement_year"
                           min="1901"
                           max="2155"
                           value="<?= (int)$selectedYear ?>"
                           required>
                </div>

                <div>
                    <label>Entitled Days *</label>

                    <input type="number"
                           name="entitled_days"
                           min="0"
                           max="999999"
                           step="0.5"
                           value="0"
                           required>
                </div>

                <div>
                    <label>Additional Adjustment Days</label>

                    <input type="number"
                           name="adjustment_days"
                           min="0"
                           max="999999"
                           step="0.5"
                           value="0">
                </div>

                <div>
                    <label>Remarks</label>

                    <input type="text"
                           name="remarks"
                           maxlength="500"
                           placeholder="Optional remarks">
                </div>

                <div class="full">

                    <button type="submit" class="btn btn-primary">
                        Save / Update Leave Balance
                    </button>

                </div>

            </div>

        </form>

        <p class="note">
            Saving the same employee, leave type and year updates the existing
            entitlement instead of creating a duplicate record.
        </p>

    </div>

    <!-- BALANCE REPORT -->

    <div class="card">

        <h2>Annual Leave Balance Report</h2>

        <form method="GET" class="filter-form no-print">

            <input type="number"
                   name="year"
                   min="1901"
                   max="2155"
                   value="<?= (int)$selectedYear ?>"
                   placeholder="Year">

            <input type="text"
                   name="search"
                   value="<?= elb_h($search) ?>"
                   placeholder="Search employee">

            <select name="leave_type_id">

                <option value="0">All Leave Types</option>

                <?php foreach ($leaveTypes as $type): ?>
                    <option value="<?= (int)$type['id'] ?>"
                        <?= $filterType === (int)$type['id'] ? 'selected' : '' ?>>
                        <?= elb_h($type['type_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <button type="submit" class="btn btn-primary">
                Filter
            </button>

            <a href="employee_leave_balance.php"
               class="btn btn-light">
                Reset
            </a>

            <button type="button"
                    onclick="window.print()"
                    class="btn btn-secondary">
                Print Report
            </button>

        </form>

        <p class="note">
            Reporting year: <?= (int)$selectedYear ?>.
            Used leave includes approved requests whose dates overlap this year.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Paid?</th>
                        <th>Entitled</th>
                        <th>Adjustment</th>
                        <th>Total Available</th>
                        <th>Used</th>
                        <th>Remaining</th>
                        <th>Remarks</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$balanceRows): ?>

                    <tr>
                        <td colspan="9">
                            No leave balance records found for <?= (int)$selectedYear ?>.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($balanceRows as $row): ?>

                        <?php
                        $available =
                            (float)$row['entitled_days'] +
                            (float)$row['adjustment_days'];

                        $remaining = (float)$row['remaining_days'];
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= elb_h(
                                        $row['first_name_en'] . ' ' .
                                        $row['last_name_en']
                                    ) ?>
                                </strong>
                                <br>
                                <small>
                                    <?= elb_h($row['employee_code']) ?>
                                </small>
                            </td>

                            <td><?= elb_h($row['type_name']) ?></td>

                            <td>
                                <?= $row['is_paid'] ? 'Yes' : 'No' ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['entitled_days'], 2) ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['adjustment_days'], 2) ?>
                            </td>

                            <td>
                                <?= number_format($available, 2) ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['used_days'], 2) ?>
                            </td>

                            <td>
                                <span class="<?= $remaining < 0
                                    ? 'remaining-negative'
                                    : 'remaining-positive' ?>">
                                    <?= number_format($remaining, 2) ?>
                                </span>
                            </td>

                            <td><?= elb_h($row['remarks'] ?? '') ?></td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="note">
            Only approved employee leave requests are counted.
            Remaining days may become negative if approved leave exceeds
            the available entitlement.
        </p>

    </div>

    <a href="dashboard.php" class="btn btn-secondary no-print">
        Back to Dashboard
    </a>

</div>

</body>
</html>
