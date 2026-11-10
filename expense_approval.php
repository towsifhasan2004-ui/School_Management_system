
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('expenses.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function ea_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/* =========================================
   PROCESS APPROVAL ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');

        if ($expenseId <= 0) {
            throw new Exception('Invalid expense selected.');
        }

        if (!in_array($action, ['approve', 'reject', 'paid'], true)) {
            throw new Exception('Invalid action.');
        }

        if ($action === 'reject' && $rejectionReason === '') {
            throw new Exception('Please provide a rejection reason.');
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT id, expense_no, created_by, approval_status
            FROM expenses
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("i", $expenseId);
        $stmt->execute();

        $expense = $stmt->get_result()->fetch_assoc();

        if (!$expense) {
            throw new Exception('Expense record not found.');
        }

        if ($action === 'approve') {

            if ($expense['approval_status'] !== 'pending') {
                throw new Exception('Only pending expenses can be approved.');
            }

            if ((int)$expense['created_by'] === $currentUserId) {
                throw new Exception('You cannot approve an expense created by yourself.');
            }

            $update = $conn->prepare("
                UPDATE expenses
                SET approval_status = 'approved',
                    approved_by = ?
                WHERE id = ?
            ");

            $update->bind_param("ii", $currentUserId, $expenseId);
            $update->execute();

            $message = 'Expense approved successfully.';

        } elseif ($action === 'reject') {

            if ($expense['approval_status'] !== 'pending') {
                throw new Exception('Only pending expenses can be rejected.');
            }

            if ((int)$expense['created_by'] === $currentUserId) {
                throw new Exception('You cannot reject your own expense.');
            }

            /*
             * The current expenses schema has no rejection_reason column.
             * Store the reason in the description without losing its
             * original content.
             */

            $reasonText = "\n\n[REJECTED: " . $rejectionReason . "]";

            $update = $conn->prepare("
                UPDATE expenses
                SET approval_status = 'rejected',
                    approved_by = ?,
                    description = CONCAT(description, ?)
                WHERE id = ?
            ");

            $update->bind_param(
                "isi",
                $currentUserId,
                $reasonText,
                $expenseId
            );

            $update->execute();

            $message = 'Expense rejected successfully.';

        } elseif ($action === 'paid') {

            if ($expense['approval_status'] !== 'approved') {
                throw new Exception('Only approved expenses can be marked as paid.');
            }

            $update = $conn->prepare("
                UPDATE expenses
                SET approval_status = 'paid'
                WHERE id = ?
            ");

            $update->bind_param("i", $expenseId);
            $update->execute();

            $message = 'Expense marked as paid successfully.';
        }

        $conn->commit();

        header("Location: expense_approval.php?message=" . urlencode($message));
        exit;

    } catch (Throwable $e) {

        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   GET REDIRECT MESSAGE
========================================= */

if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

/* =========================================
   LOAD SUMMARY
========================================= */

$summary = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(approval_status = 'pending') AS pending_count,
        SUM(approval_status = 'approved') AS approved_count,
        SUM(approval_status = 'rejected') AS rejected_count,
        SUM(approval_status = 'paid') AS paid_count,
        COALESCE(SUM(
            CASE WHEN approval_status = 'pending'
            THEN amount ELSE 0 END
        ), 0) AS pending_amount,
        COALESCE(SUM(
            CASE WHEN approval_status = 'approved'
            THEN amount ELSE 0 END
        ), 0) AS approved_amount
    FROM expenses
")->fetch_assoc();

/* =========================================
   FILTER
========================================= */

$status = $_GET['status'] ?? 'pending';

$allowedStatuses = [
    'pending',
    'approved',
    'rejected',
    'paid',
    'draft',
    'cancelled',
    'all'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

/* =========================================
   LOAD EXPENSE RECORDS
========================================= */

$sql = "
    SELECT
        e.id,
        e.expense_no,
        e.expense_date,
        e.description,
        e.amount,
        e.reference_no,
        e.approval_status,
        c.category_name_en,
        pm.method_name_en,
        creator.username AS creator_name,
        approver.username AS approver_name,
        e.created_by,
        e.approved_by
    FROM expenses e
    INNER JOIN expense_categories c
        ON c.id = e.expense_category_id
    LEFT JOIN payment_methods pm
        ON pm.id = e.payment_method_id
    LEFT JOIN users creator
        ON creator.id = e.created_by
    LEFT JOIN users approver
        ON approver.id = e.approved_by
";

if ($status !== 'all') {
    $sql .= " WHERE e.approval_status = ?";
}

$sql .= " ORDER BY
    CASE e.approval_status
        WHEN 'pending' THEN 1
        WHEN 'approved' THEN 2
        WHEN 'draft' THEN 3
        ELSE 4
    END,
    e.expense_date DESC,
    e.id DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);

if ($status !== 'all') {
    $stmt->bind_param("s", $status);
}

$stmt->execute();
$expenses = $stmt->get_result();

function ea_badge($status) {
    $colors = [
        'draft' => 'secondary',
        'pending' => 'warning',
        'approved' => 'info',
        'rejected' => 'danger',
        'paid' => 'success',
        'cancelled' => 'dark'
    ];

    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' . $color . '">' .
        ea_h(ucfirst($status)) .
        '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Expense Approval - Sezua International School</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        body {
            background: #f3f6fa;
            font-family: "Segoe UI", sans-serif;
            color: #243247;
        }

        .topbar {
            background: #0b2947;
            color: white;
            padding: 17px 25px;
        }

        .topbar h4 {
            margin: 0;
            font-weight: 700;
        }

        .content {
            padding: 25px;
        }

        .panel, .summary-card {
            background: white;
            border: 1px solid #e5eaf0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(20,40,70,.04);
        }

        .summary-card {
            height: 100%;
        }

        .summary-card h3 {
            color: #0b2947;
            font-weight: 700;
            margin-top: 8px;
        }

        .summary-card small {
            color: #718096;
        }

        .table th {
            background: #f0f4f8;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .action-buttons {
            min-width: 190px;
        }

        .description {
            min-width: 180px;
            max-width: 300px;
            white-space: normal;
        }

        @media(max-width:768px) {
            .content {
                padding: 13px;
            }
        }
    </style>
</head>

<body>

<div class="topbar d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>
        <h4>
            <i class="fa-solid fa-clipboard-check me-2"></i>
            Expense Approval
        </h4>
        <small>Sezua International School</small>
    </div>

    <a href="dashboard.php" class="btn btn-outline-light btn-sm">
        <i class="fa-solid fa-gauge me-1"></i>
        Dashboard
    </a>

</div>

<div class="container-fluid content">

    <?php if ($message): ?>
        <div class="alert alert-<?= ea_h($messageType) ?> alert-dismissible fade show">
            <?= ea_h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Total Expenses</small>
                <h3><?= number_format((int)$summary['total']) ?></h3>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Pending Approval</small>
                <h3><?= number_format((int)$summary['pending_count']) ?></h3>
                <small>৳<?= number_format((float)$summary['pending_amount'], 2) ?></small>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Approved</small>
                <h3><?= number_format((int)$summary['approved_count']) ?></h3>
                <small>৳<?= number_format((float)$summary['approved_amount'], 2) ?></small>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Paid</small>
                <h3><?= number_format((int)$summary['paid_count']) ?></h3>
            </div>
        </div>

    </div>

    <!-- FILTER -->

    <div class="panel mb-4">

        <form method="GET" class="row g-2 align-items-end">

            <div class="col-md-4">
                <label class="form-label">Expense Status</label>

                <select name="status" class="form-select">
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>
                        Pending Approval
                    </option>

                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>
                        Approved
                    </option>

                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>
                        Rejected
                    </option>

                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>
                        Paid
                    </option>

                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>
                        Draft
                    </option>

                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>
                        Cancelled
                    </option>

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All Expenses
                    </option>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">
                    <i class="fa-solid fa-filter me-1"></i>
                    Filter
                </button>
            </div>

        </form>

    </div>

    <!-- EXPENSE TABLE -->

    <div class="panel">

        <h5 class="mb-3">
            <i class="fa-solid fa-list-check me-2"></i>
            Expense Approval Records
        </h5>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Expense No.</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Created By</th>
                        <th>Approved By</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($expenses->num_rows > 0): ?>

                    <?php while ($row = $expenses->fetch_assoc()): ?>

                        <tr>

                            <td>
                                <strong><?= ea_h($row['expense_no']) ?></strong>
                                <?php if ($row['reference_no']): ?>
                                    <br>
                                    <small class="text-muted">
                                        Ref: <?= ea_h($row['reference_no']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td><?= date('d M Y', strtotime($row['expense_date'])) ?></td>

                            <td><?= ea_h($row['category_name_en']) ?></td>

                            <td class="description">
                                <?= nl2br(ea_h($row['description'])) ?>
                            </td>

                            <td class="fw-bold">
                                ৳<?= number_format((float)$row['amount'], 2) ?>
                            </td>

                            <td><?= ea_h($row['creator_name'] ?? '-') ?></td>

                            <td><?= ea_h($row['approver_name'] ?? '-') ?></td>

                            <td><?= ea_badge($row['approval_status']) ?></td>

                            <td class="action-buttons">

                                <?php if ($row['approval_status'] === 'pending'): ?>

                                    <?php if ((int)$row['created_by'] !== $currentUserId): ?>

                                        <form method="POST" class="mb-2"
                                              onsubmit="return confirm('Approve this expense?');">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= ea_h(csrf_token()) ?>">

                                            <input type="hidden" name="expense_id"
                                                   value="<?= (int)$row['id'] ?>">

                                            <input type="hidden" name="action"
                                                   value="approve">

                                            <button class="btn btn-success btn-sm w-100">
                                                <i class="fa-solid fa-check me-1"></i>
                                                Approve
                                            </button>

                                        </form>

                                        <form method="POST"
                                              onsubmit="return confirm('Reject this expense?');">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= ea_h(csrf_token()) ?>">

                                            <input type="hidden" name="expense_id"
                                                   value="<?= (int)$row['id'] ?>">

                                            <input type="hidden" name="action"
                                                   value="reject">

                                            <input type="text"
                                                   name="rejection_reason"
                                                   class="form-control form-control-sm mb-1"
                                                   placeholder="Rejection reason"
                                                   required>

                                            <button class="btn btn-danger btn-sm w-100">
                                                <i class="fa-solid fa-xmark me-1"></i>
                                                Reject
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <small class="text-muted">
                                            Own expense
                                        </small>

                                    <?php endif; ?>

                                <?php elseif ($row['approval_status'] === 'approved'): ?>

                                    <form method="POST"
                                          onsubmit="return confirm('Mark this expense as paid?');">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= ea_h(csrf_token()) ?>">

                                        <input type="hidden" name="expense_id"
                                               value="<?= (int)$row['id'] ?>">

                                        <input type="hidden" name="action"
                                               value="paid">

                                        <button class="btn btn-primary btn-sm w-100">
                                            <i class="fa-solid fa-money-bill-wave me-1"></i>
                                            Mark as Paid
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="text-muted small">No action</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            No expense records found for this status.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
