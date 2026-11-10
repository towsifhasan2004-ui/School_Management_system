
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('expenses.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function er_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   FILTERS
========================================= */

$fromDate = trim($_GET['from_date'] ?? date('Y-m-01'));
$toDate = trim($_GET['to_date'] ?? date('Y-m-d'));
$categoryId = (int)($_GET['category_id'] ?? 0);
$status = trim($_GET['status'] ?? '');

$allowedStatuses = [
    'draft',
    'pending',
    'approved',
    'rejected',
    'paid',
    'cancelled'
];

$validDate = function ($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (!$validDate($fromDate) || !$validDate($toDate)) {
    die('Invalid date format.');
}

if ($fromDate > $toDate) {
    die('From date cannot be later than To date.');
}

if ($categoryId < 0) {
    $categoryId = 0;
}

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/* =========================================
   BUILD FILTER QUERY
========================================= */

$where = [
    'e.expense_date >= ?',
    'e.expense_date <= ?'
];

$params = [$fromDate, $toDate];
$types = 'ss';

if ($categoryId > 0) {
    $where[] = 'e.expense_category_id = ?';
    $params[] = $categoryId;
    $types .= 'i';
}

if ($status !== '') {
    $where[] = 'e.approval_status = ?';
    $params[] = $status;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

/* =========================================
   REUSABLE PREPARED STATEMENT BINDER
========================================= */

function er_bind_params($stmt, $types, &$params) {
    if (!$params) {
        return;
    }

    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

/* =========================================
   LOAD CATEGORIES
========================================= */

$categories = $conn->query("
    SELECT id, category_name_en, category_name_bn
    FROM expense_categories
    ORDER BY category_name_en ASC
");

/* =========================================
   SUMMARY
========================================= */

$summarySql = "
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(amount), 0) AS total_amount,

        COALESCE(SUM(
            CASE WHEN approval_status = 'paid'
            THEN amount ELSE 0 END
        ), 0) AS paid_amount,

        COALESCE(SUM(
            CASE WHEN approval_status = 'pending'
            THEN amount ELSE 0 END
        ), 0) AS pending_amount,

        COALESCE(SUM(
            CASE WHEN approval_status = 'approved'
            THEN amount ELSE 0 END
        ), 0) AS approved_amount,

        COALESCE(SUM(
            CASE WHEN approval_status = 'rejected'
            THEN amount ELSE 0 END
        ), 0) AS rejected_amount

    FROM expenses e
    WHERE $whereSql
";

$stmt = $conn->prepare($summarySql);
er_bind_params($stmt, $types, $params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* =========================================
   CATEGORY SUMMARY
========================================= */

$categorySql = "
    SELECT
        ec.id,
        ec.category_name_en,
        ec.category_name_bn,
        COUNT(e.id) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expense_categories ec
    INNER JOIN expenses e
        ON e.expense_category_id = ec.id
    WHERE $whereSql
    GROUP BY ec.id, ec.category_name_en, ec.category_name_bn
    ORDER BY total_amount DESC
";

$categoryParams = $params;

$stmt = $conn->prepare($categorySql);
er_bind_params($stmt, $types, $categoryParams);
$stmt->execute();
$categoryReport = $stmt->get_result();
$stmt->close();

/* =========================================
   EXPENSE DETAILS
========================================= */

$detailSql = "
    SELECT
        e.id,
        e.expense_no,
        e.expense_date,
        e.description,
        e.amount,
        e.reference_no,
        e.approval_status,
        ec.category_name_en,
        ec.category_name_bn,
        pm.method_name_en
    FROM expenses e
    INNER JOIN expense_categories ec
        ON ec.id = e.expense_category_id
    LEFT JOIN payment_methods pm
        ON pm.id = e.payment_method_id
    WHERE $whereSql
    ORDER BY e.expense_date DESC, e.id DESC
";

$detailParams = $params;

$stmt = $conn->prepare($detailSql);
er_bind_params($stmt, $types, $detailParams);
$stmt->execute();
$expenseResult = $stmt->get_result();
$stmt->close();

/* =========================================
   CSV EXPORT
========================================= */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $exportSql = "
        SELECT
            e.expense_no,
            e.expense_date,
            ec.category_name_en,
            ec.category_name_bn,
            e.description,
            e.amount,
            pm.method_name_en,
            e.reference_no,
            e.approval_status
        FROM expenses e
        INNER JOIN expense_categories ec
            ON ec.id = e.expense_category_id
        LEFT JOIN payment_methods pm
            ON pm.id = e.payment_method_id
        WHERE $whereSql
        ORDER BY e.expense_date DESC, e.id DESC
    ";

    $exportParams = $params;

    $stmt = $conn->prepare($exportSql);
    er_bind_params($stmt, $types, $exportParams);
    $stmt->execute();
    $exportResult = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sisms_expense_report_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Expense No',
        'Date',
        'Category',
        'Category Bangla',
        'Description',
        'Amount',
        'Payment Method',
        'Reference No',
        'Approval Status'
    ]);

    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['expense_no'],
            $row['expense_date'],
            $row['category_name_en'],
            $row['category_name_bn'],
            $row['description'],
            $row['amount'],
            $row['method_name_en'],
            $row['reference_no'],
            $row['approval_status']
        ]);
    }

    fclose($output);
    $stmt->close();
    exit;
}

$totalRecords = (int)($summary['total_records'] ?? 0);

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'expense_reports.php?' . http_build_query($exportQuery);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Expense Reports | SISMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
            font-family: "Segoe UI", sans-serif;
            color: #263445;
        }

        .topbar {
            background: #102b46;
            color: white;
            padding: 17px 25px;
        }

        .topbar h4 {
            margin: 0;
            font-weight: 700;
        }

        .page-container {
            max-width: 1500px;
            margin: auto;
            padding: 28px 18px;
        }

        .page-heading {
            color: #102b46;
            font-weight: 750;
        }

        .panel, .summary-card {
            background: white;
            border-radius: 13px;
            padding: 21px;
            box-shadow: 0 4px 18px rgba(20, 40, 70, .06);
        }

        .summary-card {
            height: 100%;
            border-left: 4px solid #d8ad4c;
        }

        .summary-label {
            color: #718096;
            font-size: 13px;
            font-weight: 600;
        }

        .summary-number {
            font-size: 25px;
            font-weight: 750;
            color: #102b46;
            margin-top: 5px;
        }

        .btn-primary {
            background: #102b46;
            border-color: #102b46;
        }

        .btn-primary:hover {
            background: #1d466c;
            border-color: #1d466c;
        }

        .table thead th {
            background: #102b46;
            color: white;
            white-space: nowrap;
            padding: 13px;
        }

        .table td {
            vertical-align: middle;
            padding: 12px;
        }

        .status-badge {
            text-transform: capitalize;
            font-size: 11px;
            padding: 6px 9px;
        }

        .status-paid {
            background: #d9f5e5;
            color: #137044;
        }

        .status-pending {
            background: #fff1ce;
            color: #8b6500;
        }

        .status-rejected, .status-cancelled {
            background: #fbe0e0;
            color: #a52a2a;
        }

        .status-approved {
            background: #dceaff;
            color: #174b8a;
        }

        .status-draft {
            background: #eceff3;
            color: #657080;
        }

        @media(max-width: 768px) {
            .page-container {
                padding: 18px 10px;
            }

            .panel {
                padding: 14px;
            }
        }
    </style>
</head>

<body>

<div class="topbar">
    <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4>
            <i class="fa-solid fa-chart-column me-2"></i>
            SISMS Financial Reports
        </h4>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-house me-1"></i>
            Dashboard
        </a>
    </div>
</div>

<div class="page-container">

    <div class="mb-4">
        <h3 class="page-heading">Expense Reports</h3>
        <p class="text-muted mb-0">
            Review school expenses by date, category and approval status.
        </p>
    </div>

    <!-- FILTERS -->
    <div class="panel mb-4">

        <h5 class="fw-bold mb-3">
            <i class="fa-solid fa-filter me-2"></i>
            Report Filters
        </h5>

        <form method="GET" class="row g-3">

            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date"
                       name="from_date"
                       class="form-control"
                       value="<?= er_h($fromDate) ?>"
                       required>
            </div>

            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date"
                       name="to_date"
                       class="form-control"
                       value="<?= er_h($toDate) ?>"
                       required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Expense Category</label>
                <select name="category_id" class="form-select">
                    <option value="0">All Categories</option>

                    <?php while ($cat = $categories->fetch_assoc()): ?>
                        <option value="<?= (int)$cat['id'] ?>"
                            <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= er_h($cat['category_name_en']) ?>
                        </option>
                    <?php endwhile; ?>

                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Approval Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>

                    <?php foreach ($allowedStatuses as $s): ?>
                        <option value="<?= er_h($s) ?>"
                            <?= $status === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>
                    Generate Report
                </button>

                <a href="expense_reports.php" class="btn btn-light border">
                    Reset
                </a>

                <a href="<?= er_h($exportUrl) ?>" class="btn btn-success">
                    <i class="fa-solid fa-file-csv me-1"></i>
                    Export CSV
                </a>

                <button type="button" onclick="window.print()"
                        class="btn btn-outline-secondary">
                    <i class="fa-solid fa-print me-1"></i>
                    Print
                </button>
            </div>

        </form>

    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-label">Total Expenses</div>
                <div class="summary-number">
                    ৳<?= number_format((float)$summary['total_amount'], 2) ?>
                </div>
                <small class="text-muted">
                    <?= $totalRecords ?> records
                </small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-label">Paid Expenses</div>
                <div class="summary-number text-success">
                    ৳<?= number_format((float)$summary['paid_amount'], 2) ?>
                </div>
                <small class="text-muted">Marked as paid</small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-label">Pending Expenses</div>
                <div class="summary-number text-warning">
                    ৳<?= number_format((float)$summary['pending_amount'], 2) ?>
                </div>
                <small class="text-muted">Awaiting approval</small>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="summary-label">Approved Expenses</div>
                <div class="summary-number">
                    ৳<?= number_format((float)$summary['approved_amount'], 2) ?>
                </div>
                <small class="text-muted">Not necessarily paid</small>
            </div>
        </div>

    </div>

    <!-- CATEGORY REPORT -->
    <div class="panel mb-4">

        <h5 class="fw-bold mb-3">
            <i class="fa-solid fa-chart-pie me-2"></i>
            Category-wise Expense Summary
        </h5>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Category</th>
                        <th>Number of Expenses</th>
                        <th>Total Amount</th>
                        <th>Percentage of Total</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($categoryReport->num_rows > 0): ?>

                    <?php $i = 1; ?>

                    <?php while ($row = $categoryReport->fetch_assoc()): ?>

                        <?php
                            $percentage = (float)$summary['total_amount'] > 0
                                ? ((float)$row['total_amount'] / (float)$summary['total_amount']) * 100
                                : 0;
                        ?>

                        <tr>
                            <td><?= $i++ ?></td>

                            <td class="fw-semibold">
                                <?= er_h($row['category_name_en']) ?>
                            </td>

                            <td><?= (int)$row['expense_count'] ?></td>

                            <td>
                                ৳<?= number_format((float)$row['total_amount'], 2) ?>
                            </td>

                            <td style="min-width:180px">
                                <div class="d-flex justify-content-between">
                                    <small><?= number_format($percentage, 1) ?>%</small>
                                </div>

                                <div class="progress" style="height:7px">
                                    <div class="progress-bar"
                                         role="progressbar"
                                         style="width: <?= min(100, max(0, $percentage)) ?>%">
                                    </div>
                                </div>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No expense data found for the selected filters.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- EXPENSE DETAILS -->
    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">
                <i class="fa-solid fa-receipt me-2"></i>
                Expense Details
            </h5>

            <span class="text-muted">
                <?= $totalRecords ?> records found
            </span>
        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Expense No.</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Payment Method</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($expenseResult->num_rows > 0): ?>

                    <?php $i = 1; ?>

                    <?php while ($expense = $expenseResult->fetch_assoc()): ?>

                        <tr>
                            <td><?= $i++ ?></td>

                            <td class="fw-semibold">
                                <?= er_h($expense['expense_no']) ?>
                            </td>

                            <td>
                                <?= date('d M Y', strtotime($expense['expense_date'])) ?>
                            </td>

                            <td>
                                <?= er_h($expense['category_name_en']) ?>
                            </td>

                            <td style="min-width:180px">
                                <?= nl2br(er_h($expense['description'])) ?>
                            </td>

                            <td>
                                <?= er_h($expense['method_name_en'] ?: '-') ?>
                            </td>

                            <td>
                                <?= er_h($expense['reference_no'] ?: '-') ?>
                            </td>

                            <td class="fw-bold">
                                ৳<?= number_format((float)$expense['amount'], 2) ?>
                            </td>

                            <td>
                                <span class="badge status-badge status-<?= er_h($expense['approval_status']) ?>">
                                    <?= er_h($expense['approval_status']) ?>
                                </span>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            No expenses found for the selected filters.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="text-muted small mt-2">
            Report period:
            <?= er_h(date('d M Y', strtotime($fromDate))) ?>
            to
            <?= er_h(date('d M Y', strtotime($toDate))) ?>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
