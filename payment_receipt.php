
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.view');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function pr_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function pr_money($value) {
    return number_format((float)$value, 2);
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$paymentId || $paymentId < 1) {
    http_response_code(400);
    exit('Invalid receipt ID.');
}

/* ==========================================
   LOAD PAYMENT AND STUDENT DETAILS
========================================== */

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.receipt_no,
        p.payment_date,
        p.amount,
        p.transaction_reference,
        p.remarks,
        p.status,

        pm.method_name_en,

        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,

        u.username AS received_by_username

    FROM payments p

    INNER JOIN payment_methods pm
        ON pm.id = p.payment_method_id

    INNER JOIN students s
        ON s.id = p.student_id

    INNER JOIN users u
        ON u.id = p.received_by

    WHERE p.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $paymentId);
$stmt->execute();

$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    http_response_code(404);
    exit('Payment receipt not found.');
}

/* ==========================================
   LOAD ALLOCATED INVOICES
========================================== */

$stmt = $conn->prepare("
    SELECT
        i.invoice_no,
        pa.allocated_amount

    FROM payment_allocations pa

    INNER JOIN invoices i
        ON i.id = pa.invoice_id

    WHERE pa.payment_id = ?
    ORDER BY pa.id ASC
");

$stmt->bind_param("i", $paymentId);
$stmt->execute();

$result = $stmt->get_result();
$allocations = [];

while ($row = $result->fetch_assoc()) {
    $allocations[] = $row;
}

$stmt->close();

$studentName = trim(
    $payment['first_name_en'] . ' ' . $payment['last_name_en']
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Receipt <?= pr_h($payment['receipt_no']) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<style>
body {
    background: #edf1f5;
    font-family: Arial, Helvetica, sans-serif;
    padding: 25px 12px;
    color: #263445;
}

.receipt {
    max-width: 780px;
    background: #fff;
    margin: auto;
    padding: 45px;
    border-radius: 10px;
    box-shadow: 0 6px 25px rgba(0,0,0,.07);
}

.school-name {
    color: #102c4a;
    font-size: 25px;
    font-weight: 800;
}

.receipt-title {
    color: #c69b45;
    font-size: 29px;
    font-weight: 800;
    letter-spacing: 2px;
}

.receipt-no {
    color: #102c4a;
    font-weight: 700;
}

.gold-line {
    height: 3px;
    background: #c69b45;
    margin: 22px 0;
}

.info-label {
    font-size: 12px;
    color: #758294;
    margin-bottom: 4px;
}

.info-value {
    font-weight: 600;
    margin-bottom: 17px;
}

.amount-panel {
    background: #f3f6fa;
    border: 1px solid #dce2e9;
    border-radius: 9px;
    padding: 25px;
    text-align: center;
    margin: 25px 0;
}

.amount {
    color: #102c4a;
    font-size: 34px;
    font-weight: 800;
}

.table thead th {
    background: #102c4a;
    color: white;
    font-size: 13px;
}

.receipt-footer {
    border-top: 1px solid #dce2e9;
    margin-top: 50px;
    padding-top: 20px;
    text-align: center;
    font-size: 12px;
    color: #758294;
}

@media print {
    body {
        background: #fff;
        padding: 0;
    }

    .receipt {
        max-width: 100%;
        padding: 20px;
        box-shadow: none;
    }

    .no-print {
        display: none !important;
    }

    @page {
        size: A4;
        margin: 15mm;
    }
}
</style>
</head>

<body>

<div class="d-flex justify-content-between align-items-center mb-3 mx-auto no-print"
     style="max-width:780px">

    <a href="collect_payment.php" class="btn btn-outline-secondary">
        &larr; Back to Payment Collection
    </a>

    <button onclick="window.print()" class="btn btn-primary">
        Print Receipt
    </button>

</div>

<div class="receipt">

    <div class="row align-items-center">

        <div class="col-7">
            <div class="school-name">
                SEZUA INTERNATIONAL SCHOOL
            </div>

            <div class="text-muted small">
                Official Student Payment Receipt
            </div>
        </div>

        <div class="col-5 text-end">
            <div class="receipt-title">RECEIPT</div>

            <div class="receipt-no">
                <?= pr_h($payment['receipt_no']) ?>
            </div>
        </div>

    </div>

    <div class="gold-line"></div>

    <?php if ($payment['status'] !== 'posted'): ?>

        <div class="alert alert-warning">
            This payment is currently
            <?= pr_h(strtoupper($payment['status'])) ?>.
            It is not a confirmed posted payment.
        </div>

    <?php endif; ?>

    <div class="row">

        <div class="col-md-6">
            <div class="info-label">Student Name</div>
            <div class="info-value">
                <?= pr_h($studentName) ?>
            </div>

            <?php if (!empty($payment['full_name_bn'])): ?>
                <div class="info-label">বাংলা নাম</div>
                <div class="info-value">
                    <?= pr_h($payment['full_name_bn']) ?>
                </div>
            <?php endif; ?>

            <div class="info-label">Admission Number</div>
            <div class="info-value">
                <?= pr_h($payment['admission_no']) ?>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-label">Receipt Number</div>
            <div class="info-value">
                <?= pr_h($payment['receipt_no']) ?>
            </div>

            <div class="info-label">Payment Date</div>
            <div class="info-value">
                <?= date(
                    'd M Y, h:i A',
                    strtotime($payment['payment_date'])
                ) ?>
            </div>

            <div class="info-label">Payment Method</div>
            <div class="info-value">
                <?= pr_h($payment['method_name_en']) ?>
            </div>
        </div>

    </div>

    <div class="amount-panel">
        <div class="info-label">AMOUNT RECEIVED</div>

        <div class="amount">
            ৳ <?= pr_money($payment['amount']) ?>
        </div>

        <div class="text-muted small">
            <?= pr_h($payment['status'] === 'posted' ? 'Payment Posted' : strtoupper($payment['status'])) ?>
        </div>
    </div>

    <h6 class="fw-bold mb-3" style="color:#102c4a">
        Payment Allocation
    </h6>

    <div class="table-responsive">
        <table class="table table-bordered">

            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th class="text-end">Allocated Amount</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($allocations) > 0): ?>

                    <?php foreach ($allocations as $allocation): ?>
                        <tr>
                            <td>
                                <?= pr_h($allocation['invoice_no']) ?>
                            </td>

                            <td class="text-end">
                                ৳ <?= pr_money($allocation['allocated_amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="2" class="text-center text-muted">
                            No invoice allocation recorded.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>
    </div>

    <?php if (!empty($payment['transaction_reference'])): ?>
        <div class="mt-3">
            <div class="info-label">Transaction Reference</div>
            <div class="info-value">
                <?= pr_h($payment['transaction_reference']) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($payment['remarks'])): ?>
        <div class="mt-3">
            <div class="info-label">Remarks</div>
            <div class="info-value">
                <?= nl2br(pr_h($payment['remarks'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row mt-4">

        <div class="col-6">
            <div style="border-top:1px solid #263445; width:180px; padding-top:8px; margin-top:45px; font-size:12px;">
                Student / Guardian
            </div>
        </div>

        <div class="col-6 d-flex justify-content-end">
            <div style="border-top:1px solid #263445; width:180px; padding-top:8px; margin-top:45px; text-align:center; font-size:12px;">
                Authorized Signature
            </div>
        </div>

    </div>

    <div class="receipt-footer">
        <strong>SEZUA INTERNATIONAL SCHOOL</strong>
        <br>
        Received by: <?= pr_h($payment['received_by_username']) ?>
        <br>
        Thank you for your payment.
        <br>
        This is a computer-generated receipt.
    </div>

</div>

</body>
</html>
