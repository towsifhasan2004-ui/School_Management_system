
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.view');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function ph_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ph_money($value) {
    return number_format((float)$value, 2);
}

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedStatuses = ['posted', 'pending', 'reversed', 'refunded'];

$sql = "
    SELECT
        p.id,
        p.receipt_no,
        p.payment_date,
        p.amount,
        p.transaction_reference,
        p.status,
        p.remarks,

        s.admission_no,
        s.first_name_en,
        s.last_name_en,

        pm.method_name_en,

        u.username AS received_by,

        COALESCE(alloc.invoice_numbers, '') AS invoice_numbers,
        COALESCE(alloc.allocated_total, 0) AS allocated_total,

        rev.reversal_date,
        rev.reversal_type,
        rev.reason AS reversal_reason

    FROM payments p

    INNER JOIN students s ON s.id = p.student_id
    INNER JOIN payment_methods pm ON pm.id = p.payment_method_id
    INNER JOIN users u ON u.id = p.received_by

    LEFT JOIN (
        SELECT
            pa.payment_id,
            GROUP_CONCAT(
                DISTINCT i.invoice_no
                ORDER BY i.invoice_no
                SEPARATOR ', '
            ) AS invoice_numbers,
            SUM(pa.allocated_amount) AS allocated_total
        FROM payment_allocations pa
        INNER JOIN invoices i ON i.id = pa.invoice_id
        GROUP BY pa.payment_id
    ) alloc ON alloc.payment_id = p.id

    LEFT JOIN (
        SELECT
            pr.payment_id,
            MAX(pr.reversal_date) AS reversal_date,
            MAX(pr.reversal_type) AS reversal_type,
            MAX(pr.reason) AS reason
        FROM payment_reversals pr
        GROUP BY pr.payment_id
    ) rev ON rev.payment_id = p.id

    WHERE 1=1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            p.receipt_no LIKE ?
            OR s.admission_no LIKE ?
            OR s.first_name_en LIKE ?
            OR s.last_name_en LIKE ?
            OR p.transaction_reference LIKE ?
            OR i_placeholder.receipt_search IS NULL
        )
    ";

    // The placeholder expression is replaced below.
    $sql = str_replace(
        "OR i_placeholder.receipt_search IS NULL",
        "",
        $sql
    );

    $like = '%' . $search . '%';

    $params = [$like, $like, $like, $like, $like];
    $types = 'sssss';
}

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    $sql .= " AND p.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($dateFrom !== '') {
    $dateObj = DateTime::createFromFormat('Y-m-d', $dateFrom);

    if ($dateObj && $dateObj->format('Y-m-d') === $dateFrom) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    } else {
        $dateFrom = '';
    }
}

if ($dateTo !== '') {
    $dateObj = DateTime::createFromFormat('Y-m-d', $dateTo);

    if ($dateObj && $dateObj->format('Y-m-d') === $dateTo) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    } else {
        $dateTo = '';
    }
}

$sql .= " ORDER BY p.payment_date DESC, p.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$stmt->execute();
$result = $stmt->get_result();

$payments = [];
$totalCollected = 0;
$totalReversed = 0;

while ($row = $result->fetch_assoc()) {
    $payments[] = $row;

    if ($row['status'] === 'posted') {
        $totalCollected += (float)$row['amount'];
    }

    if ($row['status'] === 'reversed') {
        $totalReversed += (float)$row['amount'];
    }
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Payment History | Sezua International School</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

<style>
body {
    background: #f3f6fa;
    font-family: Arial, sans-serif;
    color: #263445;
}

.topbar {
    background: #102c4a;
    color: #fff;
    padding: 17px 24px;
}

.container-main {
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 15px;
}

.panel {
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
    margin-bottom: 22px;
}

.page-title {
    color: #102c4a;
    font-weight: 800;
}

.stat-card {
    border: 1px solid #e1e7ee;
    border-radius: 10px;
    padding: 17px;
    height: 100%;
}

.stat-label {
    font-size: 12px;
    color: #758294;
}

.stat-value {
    font-size: 23px;
    font-weight: 800;
    color: #102c4a;
}

.table thead th {
    background: #102c4a;
    color: #fff;
    font-size: 12px;
    white-space: nowrap;
}

.table tbody td {
    font-size: 13px;
    vertical-align: middle;
}

.small-muted {
    font-size: 11px;
    color: #758294;
}
</style>
</head>

<body>

<div class="topbar">
    <strong>
        <i class="fa-solid fa-school me-2"></i>
        SEZUA INTERNATIONAL SCHOOL
    </strong>
    <span class="float-end">Payment History</span>
</div>

<div class="container-main">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>
            <h3 class="page-title mb-1">Payment History</h3>
            <div class="text-muted small">
                Search and review student payment transactions.
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="collect_payment.php" class="btn btn-success">
                <i class="fa-solid fa-plus me-1"></i>
                Collect Payment
            </a>

            <a href="invoices.php" class="btn btn-outline-secondary">
                Invoices
            </a>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-label">Posted Payments in Current Results</div>
                <div class="stat-value text-success">
                    ৳ <?= ph_money($totalCollected) ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-label">Reversed Payments in Current Results</div>
                <div class="stat-value text-danger">
                    ৳ <?= ph_money($totalReversed) ?>
                </div>
            </div>
        </div>

    </div>

    <div class="panel">

        <h5 class="page-title mb-3">Search Transactions</h5>

        <form method="GET" class="row g-3">

            <div class="col-lg-4">
                <label class="form-label">Search</label>
                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?= ph_h($search) ?>"
                    placeholder="Receipt, admission no, student, reference"
                >
            </div>

            <div class="col-lg-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>

                    <?php foreach ($allowedStatuses as $item): ?>
                        <option
                            value="<?= ph_h($item) ?>"
                            <?= $status === $item ? 'selected' : '' ?>
                        >
                            <?= ucfirst($item) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="col-lg-2">
                <label class="form-label">From Date</label>
                <input
                    type="date"
                    name="date_from"
                    class="form-control"
                    value="<?= ph_h($dateFrom) ?>"
                >
            </div>

            <div class="col-lg-2">
                <label class="form-label">To Date</label>
                <input
                    type="date"
                    name="date_to"
                    class="form-control"
                    value="<?= ph_h($dateTo) ?>"
                >
            </div>

            <div class="col-lg-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">
                    <i class="fa-solid fa-filter me-1"></i>
                    Filter
                </button>
            </div>

        </form>

    </div>

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="page-title mb-0">Transactions</h5>
            <span class="badge bg-secondary">
                <?= count($payments) ?> records (maximum 200)
            </span>
        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Invoice(s)</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Received By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($payments): ?>

                        <?php foreach ($payments as $payment): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= ph_h($payment['receipt_no']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= date(
                                        'd M Y h:i A',
                                        strtotime($payment['payment_date'])
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= ph_h(trim(
                                            $payment['first_name_en'] . ' ' .
                                            $payment['last_name_en']
                                        )) ?>
                                    </strong>

                                    <div class="small-muted">
                                        <?= ph_h($payment['admission_no']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= ph_h($payment['invoice_numbers'] ?: '-') ?>
                                </td>

                                <td>
                                    <?= ph_h($payment['method_name_en']) ?>
                                </td>

                                <td>
                                    <?= ph_h(
                                        $payment['transaction_reference'] ?: '-'
                                    ) ?>
                                </td>

                                <td class="text-end fw-bold">
                                    ৳ <?= ph_money($payment['amount']) ?>
                                </td>

                                <td>
                                    <?php
                                    $badgeClass = 'bg-secondary';

                                    if ($payment['status'] === 'posted') {
                                        $badgeClass = 'bg-success';
                                    } elseif ($payment['status'] === 'reversed') {
                                        $badgeClass = 'bg-danger';
                                    } elseif ($payment['status'] === 'refunded') {
                                        $badgeClass = 'bg-warning text-dark';
                                    }
                                    ?>

                                    <span class="badge <?= $badgeClass ?>">
                                        <?= strtoupper(ph_h($payment['status'])) ?>
                                    </span>

                                    <?php if ($payment['status'] === 'reversed'): ?>
                                        <div class="small-muted mt-1">
                                            <?= ph_h($payment['reversal_type'] ?? 'reversal') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= ph_h($payment['received_by']) ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-1">

                                        <a
                                            href="payment_receipt.php?id=<?= (int)$payment['id'] ?>"
                                            class="btn btn-sm btn-outline-primary"
                                            title="View receipt"
                                        >
                                            <i class="fa-solid fa-receipt"></i>
                                        </a>

                                        <?php if ($payment['status'] === 'posted'): ?>
                                            <a
                                                href="reverse_payment.php?id=<?= (int)$payment['id'] ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Reverse payment"
                                            >
                                                <i class="fa-solid fa-rotate-left"></i>
                                            </a>
                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                            <?php if ($payment['status'] === 'reversed' && !empty($payment['reversal_reason'])): ?>
                                <tr>
                                    <td colspan="10" class="bg-light small">
                                        <strong>Reversal reason:</strong>
                                        <?= ph_h($payment['reversal_reason']) ?>

                                        <?php if (!empty($payment['reversal_date'])): ?>
                                            <span class="text-muted ms-2">
                                                <?= date(
                                                    'd M Y h:i A',
                                                    strtotime($payment['reversal_date'])
                                                ) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No payment transactions found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>
