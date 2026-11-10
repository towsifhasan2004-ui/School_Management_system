
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('employees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function leave_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   HANDLE POST ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        /* SUBMIT LEAVE REQUEST */

        if ($action === 'add') {

            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            $start = DateTime::createFromFormat('!Y-m-d', $startDate);
            $end = DateTime::createFromFormat('!Y-m-d', $endDate);

            if (
                !$employeeId ||
                !$leaveTypeId ||
                !$start ||
                !$end ||
                $start->format('Y-m-d') !== $startDate ||
                $end->format('Y-m-d') !== $endDate
            ) {
                throw new Exception('Please provide valid employee, leave type and dates.');
            }

            if ($end < $start) {
                throw new Exception('End date cannot be earlier than start date.');
            }

            if ($reason === '') {
                throw new Exception('Please enter a leave reason.');
            }

            if (mb_strlen($reason) > 5000) {
                throw new Exception('Leave reason is too long.');
            }

            $totalDays = (int)$start->diff($end)->days + 1;

            // Verify employee is active.
            $stmt = $conn->prepare("
                SELECT id
                FROM employees
                WHERE id = ?
                  AND employment_status = 'active'
                LIMIT 1
            ");
            $stmt->bind_param("i", $employeeId);
            $stmt->execute();

            if (!$stmt->get_result()->fetch_assoc()) {
                throw new Exception('Selected employee is not active.');
            }

            // Verify leave type is active.
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
                throw new Exception('Selected leave type is not available.');
            }

            // Prevent overlapping pending or approved leave.
            $stmt = $conn->prepare("
                SELECT id
                FROM employee_leave_requests
                WHERE employee_id = ?
                  AND approval_status IN ('pending', 'approved')
                  AND start_date <= ?
                  AND end_date >= ?
                LIMIT 1
            ");
            $stmt->bind_param(
                "iss",
                $employeeId,
                $endDate,
                $startDate
            );
            $stmt->execute();

            if ($stmt->get_result()->fetch_assoc()) {
                throw new Exception(
                    'This employee already has a pending or approved leave request during these dates.'
                );
            }

            $requestedBy = (int)$_SESSION['user_id'];

            $stmt = $conn->prepare("
                INSERT INTO employee_leave_requests
                (
                    employee_id,
                    leave_type_id,
                    start_date,
                    end_date,
                    total_days,
                    reason,
                    approval_status,
                    requested_by
                )
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");

            $stmt->bind_param(
                "iissisi",
                $employeeId,
                $leaveTypeId,
                $startDate,
                $endDate,
                $totalDays,
                $reason,
                $requestedBy
            );

            $stmt->execute();

            redirect('employee_leave.php?success=added');
        }

        /* APPROVE OR REJECT */

        elseif ($action === 'review') {

            $requestId = (int)($_POST['request_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $reviewRemarks = trim($_POST['review_remarks'] ?? '');

            if (!$requestId || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new Exception('Invalid leave review request.');
            }

            if (mb_strlen($reviewRemarks) > 5000) {
                throw new Exception('Review remarks are too long.');
            }

            $reviewedBy = (int)$_SESSION['user_id'];

            $conn->begin_transaction();

            try {

                $stmt = $conn->prepare("
                    SELECT *
                    FROM employee_leave_requests
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $requestId);
                $stmt->execute();

                $request = $stmt->get_result()->fetch_assoc();

                if (!$request) {
                    throw new Exception('Leave request not found.');
                }

                if ($request['approval_status'] !== 'pending') {
                    throw new Exception('Only pending requests can be reviewed.');
                }

                // Do not approve overlapping requests.
                if ($decision === 'approved') {

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM employee_leave_requests
                        WHERE employee_id = ?
                          AND id <> ?
                          AND approval_status = 'approved'
                          AND start_date <= ?
                          AND end_date >= ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $stmt->bind_param(
                        "iiss",
                        $request['employee_id'],
                        $requestId,
                        $request['end_date'],
                        $request['start_date']
                    );

                    $stmt->execute();

                    if ($stmt->get_result()->fetch_assoc()) {
                        throw new Exception(
                            'Another approved leave overlaps these dates.'
                        );
                    }
                }

                $stmt = $conn->prepare("
                    UPDATE employee_leave_requests
                    SET approval_status = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE id = ?
                      AND approval_status = 'pending'
                ");

                $stmt->bind_param(
                    "sisi",
                    $decision,
                    $reviewedBy,
                    $reviewRemarks,
                    $requestId
                );

                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    throw new Exception('The request could not be updated.');
                }

                $conn->commit();

                redirect('employee_leave.php?success=reviewed');

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        else {
            throw new Exception('Invalid action.');
        }

    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   SUCCESS MESSAGES
========================================= */

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $message = 'Leave request submitted successfully.';
    } elseif ($_GET['success'] === 'reviewed') {
        $message = 'Leave request reviewed successfully.';
    }
}

/* =========================================
   LOAD ACTIVE EMPLOYEES
========================================= */

$employees = [];

$result = $conn->query("
    SELECT id, employee_code, first_name_en, last_name_en
    FROM employees
    WHERE employment_status = 'active'
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

$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$where = [];
$params = [];
$types = '';

if ($statusFilter !== '') {
    $where[] = "lr.approval_status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(
        e.employee_code LIKE ?
        OR e.first_name_en LIKE ?
        OR e.last_name_en LIKE ?
        OR lt.type_name LIKE ?
    )";

    $searchTerm = '%' . $search . '%';

    $types .= 'ssss';

    for ($i = 0; $i < 4; $i++) {
        $params[] = $searchTerm;
    }
}

$sql = "
    SELECT
        lr.*,
        e.employee_code,
        e.first_name_en,
        e.last_name_en,
        lt.type_name,
        lt.is_paid,
        requester.username AS requester_name,
        reviewer.username AS reviewer_name
    FROM employee_leave_requests lr
    INNER JOIN employees e ON e.id = lr.employee_id
    INNER JOIN employee_leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN users requester ON requester.id = lr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = lr.reviewed_by
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY
    CASE lr.approval_status
        WHEN 'pending' THEN 1
        WHEN 'approved' THEN 2
        WHEN 'rejected' THEN 3
        ELSE 4
    END,
    lr.created_at DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$requests = $stmt->get_result();

/* =========================================
   SUMMARY
========================================= */

$summary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0
];

$result = $conn->query("
    SELECT approval_status, COUNT(*) AS total
    FROM employee_leave_requests
    GROUP BY approval_status
");

while ($row = $result->fetch_assoc()) {
    $summary[$row['approval_status']] = (int)$row['total'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Leave Management | Sezua SISMS</title>

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

h1 {
    margin: 0 0 8px;
    color: #123d32;
}

.subtitle {
    color: #64748b;
    margin-bottom: 25px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 22px;
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
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.stat strong {
    display: block;
    font-size: 27px;
    margin-top: 8px;
}

.stat span {
    color: #64748b;
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
    min-height: 90px;
    resize: vertical;
}

.full {
    grid-column: 1 / -1;
}

button, .btn {
    border: 0;
    border-radius: 7px;
    padding: 10px 16px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
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

.btn-danger {
    background: #dc2626;
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
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: top;
    font-size: 13px;
}

th {
    background: #f8fafc;
    color: #334155;
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

.review-form {
    min-width: 190px;
}

.review-form textarea {
    min-height: 55px;
    margin-bottom: 7px;
}

.review-buttons {
    display: flex;
    gap: 6px;
}

.filter-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.filter-form input,
.filter-form select {
    width: auto;
    min-width: 180px;
}

@media(max-width: 800px) {
    body { padding: 12px; }
    .grid { grid-template-columns: repeat(2, 1fr); }
    .form-grid { grid-template-columns: 1fr; }
    .full { grid-column: auto; }
}

@media(max-width: 420px) {
    .grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>

<body>

<div class="container">

    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <h1 style="margin:0;">Employee Leave Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">
            Dashboard
        </a>
    </div>
    <p class="subtitle">
        Manage employee leave applications, approvals and records.
    </p>

    <?php if ($message): ?>
        <div class="alert alert-<?= leave_h($messageType) ?>">
            <?= leave_h($message) ?>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="stat">
            <span>Pending Requests</span>
            <strong><?= $summary['pending'] ?></strong>
        </div>

        <div class="stat">
            <span>Approved Requests</span>
            <strong><?= $summary['approved'] ?></strong>
        </div>

        <div class="stat">
            <span>Rejected Requests</span>
            <strong><?= $summary['rejected'] ?></strong>
        </div>

        <div class="stat">
            <span>Cancelled Requests</span>
            <strong><?= $summary['cancelled'] ?></strong>
        </div>
    </div>

    <!-- ADD LEAVE REQUEST -->

    <div class="card">
        <h2>Submit Employee Leave Request</h2>

        <?php if (!$employees || !$leaveTypes): ?>
            <div class="alert alert-danger">
                Please ensure that active employees and active leave types
                are available before submitting a request.
            </div>
        <?php else: ?>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= leave_h(csrf_token()) ?>">

            <input type="hidden" name="action" value="add">

            <div class="form-grid">

                <div>
                    <label>Employee *</label>

                    <select name="employee_id" required>
                        <option value="">Select Employee</option>

                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int)$employee['id'] ?>">
                                <?= leave_h(
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
                                <?= leave_h($type['type_name']) ?>
                                (<?= $type['is_paid'] ? 'Paid' : 'Unpaid' ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label>Start Date *</label>
                    <input type="date" name="start_date" required>
                </div>

                <div>
                    <label>End Date *</label>
                    <input type="date" name="end_date" required>
                </div>

                <div class="full">
                    <label>Reason *</label>
                    <textarea name="reason"
                              maxlength="5000"
                              required
                              placeholder="Enter reason for leave..."></textarea>
                </div>

                <div class="full">
                    <button type="submit" class="btn btn-primary">
                        Submit Leave Request
                    </button>
                </div>

            </div>
        </form>

        <?php endif; ?>
    </div>

    <!-- LEAVE REQUEST LIST -->

    <div class="card">

        <h2>Employee Leave Requests</h2>

        <form method="GET" class="filter-form">

            <input type="text"
                   name="search"
                   placeholder="Search employee or leave type"
                   value="<?= leave_h($search) ?>">

            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= leave_h($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= ucfirst($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-primary" type="submit">
                Search
            </button>

            <a href="employee_leave.php" class="btn btn-light">
                Reset
            </a>

        </form>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Paid?</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($requests->num_rows === 0): ?>

                    <tr>
                        <td colspan="8">No leave requests found.</td>
                    </tr>

                <?php else: ?>

                    <?php while ($row = $requests->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= leave_h(
                                        $row['first_name_en'] . ' ' .
                                        $row['last_name_en']
                                    ) ?>
                                </strong>
                                <br>
                                <small>
                                    <?= leave_h($row['employee_code']) ?>
                                </small>
                            </td>

                            <td><?= leave_h($row['type_name']) ?></td>

                            <td>
                                <?= leave_h($row['start_date']) ?>
                                <br>to<br>
                                <?= leave_h($row['end_date']) ?>
                            </td>

                            <td>
                                <?= leave_h($row['total_days']) ?>
                            </td>

                            <td>
                                <?= nl2br(leave_h($row['reason'])) ?>
                            </td>

                            <td>
                                <?= $row['is_paid'] ? 'Yes' : 'No' ?>
                            </td>

                            <td>
                                <span class="badge <?= leave_h($row['approval_status']) ?>">
                                    <?= leave_h($row['approval_status']) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($row['approval_status'] === 'pending'): ?>

                                    <form method="POST" class="review-form">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= leave_h(csrf_token()) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="review">

                                        <input type="hidden"
                                               name="request_id"
                                               value="<?= (int)$row['id'] ?>">

                                        <textarea name="review_remarks"
                                                  maxlength="5000"
                                                  placeholder="Review remarks (optional)"></textarea>

                                        <div class="review-buttons">

                                            <button type="submit"
                                                    name="decision"
                                                    value="approved"
                                                    class="btn btn-success"
                                                    onclick="return confirm('Approve this leave request?')">
                                                Approve
                                            </button>

                                            <button type="submit"
                                                    name="decision"
                                                    value="rejected"
                                                    class="btn btn-danger"
                                                    onclick="return confirm('Reject this leave request?')">
                                                Reject
                                            </button>

                                        </div>
                                    </form>

                                <?php else: ?>

                                    <small>
                                        Reviewed by:
                                        <?= leave_h($row['reviewer_name'] ?? 'N/A') ?>
                                        <br>
                                        <?= leave_h($row['reviewed_at'] ?? '') ?>
                                        <br>
                                        <?= nl2br(leave_h($row['review_remarks'] ?? '')) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php endif; ?>

                </tbody>

            </table>
        </div>

        <p style="color:#64748b;font-size:12px;margin-top:12px;">
            Maximum 300 requests displayed per search.
        </p>

    </div>

</div>

</body>
</html>
