
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.view');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function invoice_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$classId = (int)($_GET['class_id'] ?? 0);
$yearId = (int)($_GET['academic_year_id'] ?? 0);

$allowedStatuses = [
    'draft',
    'issued',
    'partially_paid',
    'paid',
    'overdue',
    'cancelled'
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

/* =========================================
   LOAD FILTER OPTIONS
========================================= */

$classes = $conn->query("
    SELECT id, class_name_en
    FROM classes
    ORDER BY class_order
")->fetch_all(MYSQLI_ASSOC);

$years = $conn->query("
    SELECT id, year_name
    FROM academic_years
    ORDER BY start_date DESC
")->fetch_all(MYSQLI_ASSOC);

/* =========================================
   BUILD INVOICE QUERY
========================================= */

$sql = "
    SELECT
        i.id,
        i.invoice_no,
        i.student_id,
        i.enrollment_id,
        i.invoice_date,
        i.due_date,
        i.billing_period_start,
        i.billing_period_end,
        i.subtotal,
        i.discount_amount,
        i.total_amount,
        i.status,

        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,

        en.roll_no,

        c.class_name_en,
        sec.section_name_en,
        sh.shift_name_en,
        ay.year_name,

        COALESCE(pa.paid_amount, 0) AS paid_amount

    FROM invoices i

    INNER JOIN students s
        ON s.id = i.student_id

    INNER JOIN enrollments en
        ON en.id = i.enrollment_id

    INNER JOIN classes c
        ON c.id = en.class_id

    INNER JOIN sections sec
        ON sec.id = en.section_id

    INNER JOIN shifts sh
        ON sh.id = en.shift_id

    INNER JOIN academic_years ay
        ON ay.id = en.academic_year_id

    LEFT JOIN (
        SELECT
            p.student_id,
            a.invoice_id,
            SUM(a.allocated_amount) AS paid_amount
        FROM payment_allocations a
        INNER JOIN payments p
            ON p.id = a.payment_id
        WHERE p.status = 'posted'
        GROUP BY p.student_id, a.invoice_id
    ) pa ON pa.invoice_id = i.id

    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {

    $sql .= "
        AND (
            i.invoice_no LIKE ?
            OR s.admission_no LIKE ?
            OR s.first_name_en LIKE ?
            OR s.last_name_en LIKE ?
            OR s.full_name_bn LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $types .= 'sssss';

    for ($x = 0; $x < 5; $x++) {
        $params[] = $keyword;
    }
}

if ($classId > 0) {
    $sql .= " AND en.class_id = ?";
    $types .= 'i';
    $params[] = $classId;
}

if ($yearId > 0) {
    $sql .= " AND en.academic_year_id = ?";
    $types .= 'i';
    $params[] = $yearId;
}

if ($statusFilter === 'overdue') {

    $sql .= "
        AND i.status IN ('issued','partially_paid','overdue')
        AND i.due_date < CURDATE()
        AND COALESCE(pa.paid_amount, 0) < i.total_amount
    ";

} elseif ($statusFilter === 'paid') {

    $sql .= "
        AND i.status <> 'cancelled'
        AND COALESCE(pa.paid_amount, 0) >= i.total_amount
        AND i.total_amount > 0
    ";

} elseif ($statusFilter === 'partially_paid') {

    $sql .= "
        AND COALESCE(pa.paid_amount, 0) > 0
        AND COALESCE(pa.paid_amount, 0) < i.total_amount
        AND i.status <> 'cancelled'
    ";

} elseif ($statusFilter !== '') {

    $sql .= " AND i.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

$sql .= " ORDER BY i.created_at DESC LIMIT 500";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$stmt->execute();

$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================
   SUMMARY
========================================= */

$totalInvoices = count($invoices);
$totalBilled = 0;
$totalPaid = 0;
$totalDue = 0;
$overdueCount = 0;

foreach ($invoices as $invoice) {

    $billed = (float)$invoice['total_amount'];
    $paid = min($billed, (float)$invoice['paid_amount']);
    $due = max(0, $billed - $paid);

    $totalBilled += $billed;
    $totalPaid += $paid;
    $totalDue += $due;

    if (
        $due > 0 &&
        $invoice['due_date'] < date('Y-m-d') &&
        $invoice['status'] !== 'cancelled'
    ) {
        $overdueCount++;
    }
}

function invoiceDisplayStatus($invoice) {

    $total = (float)$invoice['total_amount'];
    $paid = (float)$invoice['paid_amount'];

    if ($invoice['status'] === 'cancelled') {
        return 'cancelled';
    }

    if ($invoice['status'] === 'draft') {
        return 'draft';
    }

    if ($total > 0 && $paid >= $total) {
        return 'paid';
    }

    if ($paid > 0 && $paid < $total) {
        return 'partially_paid';
    }

    if ($invoice['due_date'] < date('Y-m-d')) {
        return 'overdue';
    }

    return 'issued';
}

function invoiceStatusBadge($status) {

    $styles = [
        'draft' => 'secondary',
        'issued' => 'primary',
        'partially_paid' => 'warning',
        'paid' => 'success',
        'overdue' => 'danger',
        'cancelled' => 'dark'
    ];

    $labels = [
        'draft' => 'Draft',
        'issued' => 'Issued',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled'
    ];

    $color = $styles[$status] ?? 'secondary';
    $label = $labels[$status] ?? ucfirst($status);

    return '<span class="badge bg-' . $color . '">' .
        invoice_h($label) . '</span>';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Invoice Management | Sezua International School</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

<style>

:root {
    --navy: #0b2947;
    --deep: #071b30;
    --gold: #e8c66f;
}

body {
    background: #f3f6fa;
    font-family: "Segoe UI", sans-serif;
    color: #243447;
}

.topbar {
    background: linear-gradient(110deg, var(--deep), var(--navy));
    color: #fff;
    padding: 20px 0;
    border-bottom: 3px solid var(--gold);
}

.topbar h3 {
    font-weight: 700;
    margin: 0;
}

.topbar p {
    color: #d5e0ec;
    margin: 5px 0 0;
    font-size: 13px;
}

.page-container {
    max-width: 1350px;
    margin: 30px auto;
    padding: 0 15px;
}

.panel {
    background: #fff;
    border: 1px solid #e3e9f0;
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 5px 20px rgba(11,41,71,.05);
}

.panel-title {
    color: var(--navy);
    font-weight: 700;
    margin-bottom: 20px;
    font-size: 18px;
}

.stat-card {
    background: #fff;
    border: 1px solid #e3e9f0;
    border-radius: 12px;
    padding: 20px;
    height: 100%;
}

.stat-label {
    color: #718096;
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    color: var(--navy);
    font-size: 24px;
    font-weight: 800;
    margin-top: 8px;
}

.form-control,
.form-select {
    min-height: 42px;
    border-radius: 8px;
}

.form-label {
    font-size: 13px;
    font-weight: 600;
}

.table thead th {
    background: var(--navy);
    color: #fff;
    font-size: 12px;
    white-space: nowrap;
}

.table td {
    font-size: 13px;
    vertical-align: middle;
}

.amount {
    font-weight: 700;
    white-space: nowrap;
}

.btn-gold {
    background: var(--gold);
    color: var(--deep);
    border: 1px solid var(--gold);
    font-weight: 700;
    border-radius: 8px;
}

.btn-gold:hover {
    background: #f8e6a9;
    color: var(--deep);
}

.footer-note {
    text-align: center;
    color: #8290a0;
    font-size: 12px;
    padding: 20px;
}

@media(max-width:768px) {
    .panel {
        padding: 15px;
    }

    .stat-value {
        font-size: 20px;
    }
}

</style>
</head>

<body>

<div class="topbar">
    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-3">

        <div>
            <h3>
                <i class="fa-solid fa-file-invoice-dollar me-2"></i>
                Invoice Management
            </h3>

            <p>Sezua International School | Finance Management</p>
        </div>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-house me-1"></i>
            Dashboard
        </a>

    </div>
</div>

<div class="page-container">

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">INVOICES FOUND</div>
                <div class="stat-value"><?= $totalInvoices ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">TOTAL BILLED</div>
                <div class="stat-value">
                    ৳<?= number_format($totalBilled, 2) ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">TOTAL PAID</div>
                <div class="stat-value text-success">
                    ৳<?= number_format($totalPaid, 2) ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">TOTAL OUTSTANDING</div>
                <div class="stat-value text-danger">
                    ৳<?= number_format($totalDue, 2) ?>
                </div>
            </div>
        </div>

    </div>

    <!-- FILTER -->

    <div class="panel">

        <div class="panel-title">
            <i class="fa-solid fa-filter me-2"></i>
            Search and Filter Invoices
        </div>

        <form method="GET">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Search</label>

                    <input type="text"
                        name="search"
                        class="form-control"
                        placeholder="Invoice no, admission no or student name"
                        value="<?= invoice_h($search) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Academic Year</label>

                    <select name="academic_year_id" class="form-select">

                        <option value="0">All Academic Years</option>

                        <?php foreach ($years as $year): ?>

                            <option value="<?= (int)$year['id'] ?>"
                                <?= $yearId === (int)$year['id'] ? 'selected' : '' ?>>

                                <?= invoice_h($year['year_name']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Class</label>

                    <select name="class_id" class="form-select">

                        <option value="0">All Classes</option>

                        <?php foreach ($classes as $class): ?>

                            <option value="<?= (int)$class['id'] ?>"
                                <?= $classId === (int)$class['id'] ? 'selected' : '' ?>>

                                <?= invoice_h($class['class_name_en']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Invoice Status</label>

                    <select name="status" class="form-select">

                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $status): ?>

                            <option value="<?= invoice_h($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>>

                                <?= invoice_h(ucwords(str_replace('_', ' ', $status))) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap">

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>
                    Search
                </button>

                <a href="invoices.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-rotate-left me-1"></i>
                    Reset
                </a>

                <a href="generate_invoices.php" class="btn btn-gold ms-md-auto">
                    <i class="fa-solid fa-plus me-1"></i>
                    Generate Invoices
                </a>

            </div>

        </form>

    </div>

    <!-- INVOICE TABLE -->

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <div class="panel-title mb-0">
                <i class="fa-solid fa-list me-2"></i>
                Invoice Records
            </div>

            <span class="text-muted small">
                Showing up to 500 records
            </span>

        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Invoice No.</th>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th>Class</th>
                        <th>Roll</th>
                        <th>Billing Period</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$invoices): ?>

                    <tr>
                        <td colspan="13" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-folder-open fa-2x mb-2"></i>
                            <br>
                            No invoices found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($invoices as $invoice):

                        $total = (float)$invoice['total_amount'];
                        $paid = min($total, (float)$invoice['paid_amount']);
                        $due = max(0, $total - $paid);

                        $displayStatus = invoiceDisplayStatus($invoice);

                        $studentName = trim(
                            $invoice['first_name_en'] . ' ' .
                            $invoice['last_name_en']
                        );

                        $billingPeriod = '';

                        if ($invoice['billing_period_start']) {
                            $billingPeriod = date(
                                'M Y',
                                strtotime($invoice['billing_period_start'])
                            );
                        }

                    ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= invoice_h($invoice['invoice_no']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= invoice_h($studentName) ?>

                                <?php if ($invoice['full_name_bn']): ?>
                                    <div class="text-muted small">
                                        <?= invoice_h($invoice['full_name_bn']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= invoice_h($invoice['admission_no']) ?>
                            </td>

                            <td>
                                <?= invoice_h($invoice['class_name_en']) ?>
                                <div class="text-muted small">
                                    <?= invoice_h($invoice['section_name_en']) ?>
                                    / <?= invoice_h($invoice['shift_name_en']) ?>
                                </div>
                            </td>

                            <td><?= invoice_h($invoice['roll_no']) ?></td>

                            <td><?= invoice_h($billingPeriod ?: '—') ?></td>

                            <td><?= invoice_h($invoice['invoice_date']) ?></td>

                            <td><?= invoice_h($invoice['due_date']) ?></td>

                            <td class="text-end amount">
                                <?= number_format($total, 2) ?>
                            </td>

                            <td class="text-end text-success amount">
                                <?= number_format($paid, 2) ?>
                            </td>

                            <td class="text-end text-danger amount">
                                <?= number_format($due, 2) ?>
                            </td>

                            <td>
                                <?= invoiceStatusBadge($displayStatus) ?>
                            </td>

                            <td>
                                <a href="print_invoice.php?id=<?= (int)$invoice['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                    target="_blank"
                                    title="View and print invoice">

                                    <i class="fa-solid fa-print"></i>
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($overdueCount > 0): ?>

            <div class="alert alert-warning mt-3 mb-0">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                <?= $overdueCount ?> overdue invoice(s) are included in the current results.
            </div>

        <?php endif; ?>

    </div>

    <div class="text-center mb-4">

        <a href="generate_invoices.php" class="btn btn-outline-primary btn-sm">
            Generate Invoices
        </a>

        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            Dashboard
        </a>

    </div>

</div>

<div class="footer-note">
    Sezua International School | Student Information Management System
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
