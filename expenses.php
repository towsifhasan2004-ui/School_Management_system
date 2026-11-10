
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('expenses.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function exp_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';

/* =========================================
   SAVE NEW EXPENSE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $categoryId = (int)($_POST['expense_category_id'] ?? 0);
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $approvalStatus = $_POST['approval_status'] ?? 'pending';

        $createdBy = (int)$_SESSION['user_id'];

        if ($categoryId <= 0) {
            throw new Exception('Please select an expense category.');
        }

        if (!$expenseDate || !strtotime($expenseDate)) {
            throw new Exception('Please enter a valid expense date.');
        }

        if ($description === '') {
            throw new Exception('Please enter an expense description.');
        }

        if ($amount <= 0) {
            throw new Exception('Expense amount must be greater than zero.');
        }

        if (!in_array($approvalStatus, ['draft', 'pending'], true)) {
            throw new Exception('Invalid expense status.');
        }

        // Validate active category
        $check = $conn->prepare("
            SELECT id
            FROM expense_categories
            WHERE id = ? AND status = 'active'
            LIMIT 1
        ");
        $check->bind_param("i", $categoryId);
        $check->execute();

        if (!$check->get_result()->fetch_assoc()) {
            throw new Exception('Selected expense category is unavailable.');
        }

        // Validate payment method if selected
        if ($paymentMethodId > 0) {
            $methodCheck = $conn->prepare("
                SELECT id
                FROM payment_methods
                WHERE id = ? AND status = 'active'
                LIMIT 1
            ");
            $methodCheck->bind_param("i", $paymentMethodId);
            $methodCheck->execute();

            if (!$methodCheck->get_result()->fetch_assoc()) {
                throw new Exception('Selected payment method is unavailable.');
            }
        } else {
            $paymentMethodId = null;
        }

        $expenseNo = 'EXP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            INSERT INTO expenses (
                expense_no,
                expense_category_id,
                expense_date,
                description,
                amount,
                payment_method_id,
                reference_no,
                approval_status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sissdissi",
            $expenseNo,
            $categoryId,
            $expenseDate,
            $description,
            $amount,
            $paymentMethodId,
            $referenceNo,
            $approvalStatus,
            $createdBy
        );

        $stmt->execute();
        $newExpenseId = $conn->insert_id;

        $conn->commit();

        header("Location: expenses.php?success=1");
        exit;

    } catch (Throwable $e) {
        if ($conn->errno || $conn->connect_errno === 0) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // No active transaction to roll back.
            }
        }

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   SUCCESS MESSAGE
========================================= */

if (isset($_GET['success'])) {
    $message = 'Expense recorded successfully.';
}

/* =========================================
   LOAD CATEGORIES AND PAYMENT METHODS
========================================= */

$categories = $conn->query("
    SELECT id, category_name_en, category_name_bn
    FROM expense_categories
    WHERE status = 'active'
    ORDER BY category_name_en
");

$paymentMethods = $conn->query("
    SELECT id, method_name_en
    FROM payment_methods
    WHERE status = 'active'
    ORDER BY method_name_en
");

/* =========================================
   SEARCH AND FILTER
========================================= */

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$allowedStatuses = [
    'draft',
    'pending',
    'approved',
    'rejected',
    'paid',
    'cancelled'
];

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
        creator.username AS created_by_name,
        approver.username AS approved_by_name
    FROM expenses e
    INNER JOIN expense_categories c
        ON c.id = e.expense_category_id
    LEFT JOIN payment_methods pm
        ON pm.id = e.payment_method_id
    LEFT JOIN users creator
        ON creator.id = e.created_by
    LEFT JOIN users approver
        ON approver.id = e.approved_by
    WHERE 1=1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            e.expense_no LIKE ?
            OR e.description LIKE ?
            OR e.reference_no LIKE ?
            OR c.category_name_en LIKE ?
        )
    ";

    $keyword = "%{$search}%";

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= 'ssss';
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND e.approval_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT 300";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$stmt->execute();
$expenses = $stmt->get_result();

/* =========================================
   SUMMARY
========================================= */

$summary = $conn->query("
    SELECT
        COUNT(*) AS total_expenses,
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(SUM(
            CASE WHEN approval_status = 'pending'
            THEN amount ELSE 0 END
        ), 0) AS pending_amount,
        COALESCE(SUM(
            CASE WHEN approval_status = 'paid'
            THEN amount ELSE 0 END
        ), 0) AS paid_amount
    FROM expenses
")->fetch_assoc();

function expense_status_badge($status) {
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
        exp_h(ucfirst($status)) .
        '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Expense Management - Sezua International School</title>

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

        .page-content {
            padding: 25px;
        }

        .panel {
            background: white;
            border: 1px solid #e5eaf0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(20, 40, 70, .04);
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e5eaf0;
            height: 100%;
        }

        .summary-card h3 {
            font-weight: 700;
            color: #0b2947;
        }

        .summary-card small {
            color: #718096;
        }

        .form-label {
            font-weight: 600;
            font-size: 14px;
        }

        .form-control, .form-select {
            min-height: 43px;
        }

        .btn-primary {
            background: #0b2947;
            border-color: #0b2947;
        }

        .btn-primary:hover {
            background: #173b60;
            border-color: #173b60;
        }

        .table th {
            background: #f0f4f8;
            color: #34445a;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .amount {
            font-weight: 700;
            white-space: nowrap;
        }

        @media(max-width: 768px) {
            .page-content {
                padding: 13px;
            }

            .panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="topbar d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="fa-solid fa-wallet me-2"></i>Expense Management</h4>
        <small>Sezua International School</small>
    </div>

    <a href="dashboard.php" class="btn btn-outline-light btn-sm">
        <i class="fa-solid fa-gauge me-1"></i> Dashboard
    </a>
</div>

<div class="container-fluid page-content">

    <?php if ($message): ?>
        <div class="alert alert-<?= exp_h($messageType) ?> alert-dismissible fade show">
            <?= exp_h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Total Expenses</small>
                <h3><?= number_format((int)$summary['total_expenses']) ?></h3>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Total Recorded Amount</small>
                <h3>৳<?= number_format((float)$summary['total_amount'], 2) ?></h3>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Pending Amount</small>
                <h3>৳<?= number_format((float)$summary['pending_amount'], 2) ?></h3>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="summary-card">
                <small>Paid Amount</small>
                <h3>৳<?= number_format((float)$summary['paid_amount'], 2) ?></h3>
            </div>
        </div>

    </div>

    <!-- ADD EXPENSE -->

    <div class="panel">

        <h5 class="mb-3">
            <i class="fa-solid fa-circle-plus me-2"></i>
            Record New Expense
        </h5>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= exp_h(csrf_token()) ?>">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Expense Category *</label>

                    <select name="expense_category_id" class="form-select" required>
                        <option value="">Select Category</option>

                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <option value="<?= (int)$cat['id'] ?>">
                                <?= exp_h($cat['category_name_en']) ?>
                                <?= $cat['category_name_bn']
                                    ? ' / ' . exp_h($cat['category_name_bn'])
                                    : '' ?>
                            </option>
                        <?php endwhile; ?>

                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Expense Date *</label>

                    <input type="date"
                           name="expense_date"
                           class="form-control"
                           value="<?= date('Y-m-d') ?>"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Amount (৳) *</label>

                    <input type="number"
                           name="amount"
                           class="form-control"
                           min="0.01"
                           step="0.01"
                           placeholder="Enter amount"
                           required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Description *</label>

                    <textarea name="description"
                              class="form-control"
                              rows="3"
                              placeholder="Describe the expense..."
                              required></textarea>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Payment Method</label>

                    <select name="payment_method_id" class="form-select">
                        <option value="0">Not selected</option>

                        <?php while ($method = $paymentMethods->fetch_assoc()): ?>
                            <option value="<?= (int)$method['id'] ?>">
                                <?= exp_h($method['method_name_en']) ?>
                            </option>
                        <?php endwhile; ?>

                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Reference Number</label>

                    <input type="text"
                           name="reference_no"
                           class="form-control"
                           maxlength="120"
                           placeholder="Voucher / transaction no.">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Initial Status</label>

                    <select name="approval_status" class="form-select">
                        <option value="pending">Pending Approval</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>

                <div class="col-md-8 d-flex align-items-end justify-content-end">
                    <button type="reset" class="btn btn-light border me-2">
                        <i class="fa-solid fa-rotate-left me-1"></i> Reset
                    </button>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i>
                        Save Expense
                    </button>
                </div>

            </div>
        </form>
    </div>

    <!-- EXPENSE LIST -->

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0">
                <i class="fa-solid fa-list me-2"></i>
                Expense Records
            </h5>

            <span class="text-muted small">Latest 300 records</span>
        </div>

        <form method="GET" class="row g-2 mb-3">

            <div class="col-md-5">
                <input type="text"
                       name="search"
                       class="form-control"
                       placeholder="Search expense no, category, description..."
                       value="<?= exp_h($search) ?>">
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>

                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= exp_h($status) ?>"
                            <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= exp_h(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">
                    <i class="fa-solid fa-search me-1"></i> Search
                </button>
            </div>

            <div class="col-md-2">
                <a href="expenses.php" class="btn btn-light border w-100">
                    Reset
                </a>
            </div>

        </form>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Expense No.</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Payment Method</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($expenses->num_rows > 0): ?>

                    <?php while ($row = $expenses->fetch_assoc()): ?>

                        <tr>
                            <td>
                                <strong><?= exp_h($row['expense_no']) ?></strong>
                            </td>

                            <td><?= date('d M Y', strtotime($row['expense_date'])) ?></td>

                            <td><?= exp_h($row['category_name_en']) ?></td>

                            <td>
                                <?= exp_h($row['description']) ?>

                                <?php if ($row['reference_no']): ?>
                                    <br>
                                    <small class="text-muted">
                                        Ref: <?= exp_h($row['reference_no']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td><?= exp_h($row['method_name_en'] ?? 'Not specified') ?></td>

                            <td class="amount">
                                ৳<?= number_format((float)$row['amount'], 2) ?>
                            </td>

                            <td><?= expense_status_badge($row['approval_status']) ?></td>

                            <td><?= exp_h($row['created_by_name'] ?? '-') ?></td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No expense records found.
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
