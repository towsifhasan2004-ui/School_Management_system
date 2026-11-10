
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.view');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function invoice_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function invoice_money($value) {
    return number_format((float)$value, 2);
}

/* ==========================================
   VALIDATE INVOICE ID
========================================== */

$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceId || $invoiceId < 1) {
    http_response_code(400);
    exit('Invalid invoice ID.');
}

/* ==========================================
   LOAD INVOICE AND STUDENT DETAILS
========================================== */

$sql = "
    SELECT
        i.id,
        i.invoice_no,
        i.invoice_date,
        i.due_date,
        i.billing_period_start,
        i.billing_period_end,
        i.subtotal,
        i.discount_amount,
        i.total_amount,
        i.status,
        i.notes,

        s.id AS student_id,
        s.admission_no,
        s.registration_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,
        s.photo_path,

        e.roll_no,

        c.class_name_en,
        c.class_name_bn,

        sec.section_name_en,
        sec.section_name_bn,

        sh.shift_name_en,

        ay.year_name

    FROM invoices i

    INNER JOIN students s
        ON s.id = i.student_id

    INNER JOIN enrollments e
        ON e.id = i.enrollment_id

    INNER JOIN classes c
        ON c.id = e.class_id

    INNER JOIN sections sec
        ON sec.id = e.section_id

    INNER JOIN shifts sh
        ON sh.id = e.shift_id

    INNER JOIN academic_years ay
        ON ay.id = e.academic_year_id

    WHERE i.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoiceId);
$stmt->execute();

$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

/* ==========================================
   LOAD INVOICE ITEMS
========================================== */

$stmt = $conn->prepare("
    SELECT
        description_en,
        description_bn,
        quantity,
        unit_amount,
        total_amount
    FROM invoice_items
    WHERE invoice_id = ?
    ORDER BY id ASC
");

$stmt->bind_param("i", $invoiceId);
$stmt->execute();

$itemsResult = $stmt->get_result();
$items = [];

while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
}

$stmt->close();

/* ==========================================
   LOAD POSTED PAYMENT DETAILS
========================================== */

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.receipt_no,
        p.payment_date,
        p.amount,
        p.transaction_reference,
        pm.method_name_en,
        pa.allocated_amount

    FROM payment_allocations pa

    INNER JOIN payments p
        ON p.id = pa.payment_id

    INNER JOIN payment_methods pm
        ON pm.id = p.payment_method_id

    WHERE pa.invoice_id = ?
      AND p.status = 'posted'

    ORDER BY p.payment_date ASC, p.id ASC
");

$stmt->bind_param("i", $invoiceId);
$stmt->execute();

$paymentResult = $stmt->get_result();

$payments = [];
$paidAmount = 0;

while ($row = $paymentResult->fetch_assoc()) {
    $payments[] = $row;
    $paidAmount += (float)$row['allocated_amount'];
}

$stmt->close();

/* ==========================================
   CALCULATE BALANCE
========================================== */

$totalAmount = (float)$invoice['total_amount'];
$balanceDue = max(0, $totalAmount - $paidAmount);

if ($balanceDue <= 0.009) {
    $displayStatus = 'PAID';
} elseif ($paidAmount > 0) {
    $displayStatus = 'PARTIALLY PAID';
} elseif (
    $invoice['status'] === 'overdue' ||
    (
        $invoice['status'] === 'issued' &&
        strtotime($invoice['due_date']) < strtotime(date('Y-m-d'))
    )
) {
    $displayStatus = 'OVERDUE';
} else {
    $displayStatus = strtoupper($invoice['status']);
}

$studentName = trim(
    $invoice['first_name_en'] . ' ' . $invoice['last_name_en']
);

$photoPath = '';

if (!empty($invoice['photo_path'])) {
    $photoPath = $invoice['photo_path'];
}

$billingPeriod = '';

if (
    !empty($invoice['billing_period_start']) &&
    !empty($invoice['billing_period_end'])
) {
    $billingPeriod =
        date('d M Y', strtotime($invoice['billing_period_start'])) .
        ' - ' .
        date('d M Y', strtotime($invoice['billing_period_end']));
}

$schoolName = 'SEZUA INTERNATIONAL SCHOOL';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
    Invoice <?= invoice_h($invoice['invoice_no']) ?>
    | <?= invoice_h($schoolName) ?>
</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<style>
:root {
    --navy: #102c4a;
    --gold: #c69b45;
    --light: #f5f7fa;
    --border: #dce2e9;
}

body {
    background: #edf1f5;
    font-family: Arial, Helvetica, sans-serif;
    color: #263445;
    padding: 25px 12px;
}

.invoice-wrapper {
    max-width: 900px;
    margin: auto;
    background: #fff;
    padding: 45px;
    border-radius: 10px;
    box-shadow: 0 8px 35px rgba(0,0,0,.08);
}

.invoice-header {
    border-bottom: 3px solid var(--gold);
    padding-bottom: 22px;
    margin-bottom: 28px;
}

.school-name {
    font-size: 27px;
    font-weight: 800;
    color: var(--navy);
    letter-spacing: 1px;
}

.school-subtitle {
    color: #687789;
    font-size: 13px;
    margin-top: 5px;
}

.invoice-title {
    font-size: 30px;
    font-weight: 800;
    color: var(--navy);
    letter-spacing: 2px;
}

.invoice-number {
    color: var(--gold);
    font-weight: 700;
    font-size: 15px;
}

.section-title {
    color: var(--navy);
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
    padding-bottom: 8px;
    margin-bottom: 14px;
}

.info-label {
    color: #758294;
    font-size: 12px;
    margin-bottom: 3px;
}

.info-value {
    font-weight: 600;
    color: #263445;
    font-size: 14px;
    margin-bottom: 13px;
}

.student-photo {
    width: 75px;
    height: 85px;
    object-fit: cover;
    border: 1px solid var(--border);
    border-radius: 5px;
}

.table thead th {
    background: var(--navy);
    color: #fff;
    font-size: 12px;
    padding: 12px;
    border: none;
}

.table tbody td {
    padding: 12px;
    vertical-align: middle;
    font-size: 13px;
}

.summary-box {
    background: var(--light);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 18px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 14px;
}

.summary-total {
    border-top: 2px solid var(--navy);
    margin-top: 8px;
    padding-top: 12px;
    font-weight: 800;
    font-size: 17px;
    color: var(--navy);
}

.balance {
    color: #b42318;
    font-weight: 800;
}

.status-label {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 20px;
    background: #edf2f7;
    color: var(--navy);
    font-size: 12px;
    font-weight: 800;
}

.invoice-footer {
    margin-top: 45px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: #758294;
}

.signature {
    border-top: 1px solid #263445;
    width: 190px;
    padding-top: 8px;
    margin-top: 55px;
    text-align: center;
    font-size: 12px;
}

.action-bar {
    max-width: 900px;
    margin: 0 auto 18px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

@media print {
    body {
        background: #fff;
        padding: 0;
    }

    .invoice-wrapper {
        max-width: 100%;
        padding: 20px;
        box-shadow: none;
        border-radius: 0;
    }

    .action-bar {
        display: none !important;
    }

    .table thead th {
        background: var(--navy) !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .summary-box,
    .status-label {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    @page {
        size: A4;
        margin: 12mm;
    }
}
</style>
</head>

<body>

<div class="action-bar">
    <a href="invoices.php" class="btn btn-outline-secondary">
        &larr; Back to Invoices
    </a>

    <button type="button" onclick="window.print()" class="btn btn-primary">
        Print Invoice
    </button>
</div>

<div class="invoice-wrapper">

    <!-- SCHOOL HEADER -->

    <div class="invoice-header">
        <div class="row align-items-center">

            <div class="col-7">
                <div class="school-name">
                    <?= invoice_h($schoolName) ?>
                </div>

                <div class="school-subtitle">
                    Official Student Fee Invoice
                </div>
            </div>

            <div class="col-5 text-end">
                <div class="invoice-title">INVOICE</div>

                <div class="invoice-number">
                    <?= invoice_h($invoice['invoice_no']) ?>
                </div>

                <div class="mt-2">
                    <span class="status-label">
                        <?= invoice_h($displayStatus) ?>
                    </span>
                </div>
            </div>

        </div>
    </div>

    <!-- INVOICE INFORMATION -->

    <div class="row mb-4">

        <div class="col-md-6">
            <div class="section-title">Invoice Information</div>

            <div class="info-label">Invoice Number</div>
            <div class="info-value">
                <?= invoice_h($invoice['invoice_no']) ?>
            </div>

            <div class="info-label">Invoice Date</div>
            <div class="info-value">
                <?= date('d M Y', strtotime($invoice['invoice_date'])) ?>
            </div>

            <div class="info-label">Due Date</div>
            <div class="info-value">
                <?= date('d M Y', strtotime($invoice['due_date'])) ?>
            </div>

            <?php if ($billingPeriod): ?>
                <div class="info-label">Billing Period</div>
                <div class="info-value">
                    <?= invoice_h($billingPeriod) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <div class="section-title">Student Information</div>

            <div class="d-flex gap-3">

                <?php if ($photoPath): ?>
                    <img
                        src="<?= invoice_h($photoPath) ?>"
                        class="student-photo"
                        alt="Student Photo"
                    >
                <?php endif; ?>

                <div>
                    <div class="info-label">Student Name</div>
                    <div class="info-value">
                        <?= invoice_h($studentName) ?>
                    </div>

                    <?php if (!empty($invoice['full_name_bn'])): ?>
                        <div class="info-label">বাংলা নাম</div>
                        <div class="info-value">
                            <?= invoice_h($invoice['full_name_bn']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="info-label">Admission Number</div>
                    <div class="info-value">
                        <?= invoice_h($invoice['admission_no']) ?>
                    </div>

                    <div class="info-label">Class / Section</div>
                    <div class="info-value">
                        <?= invoice_h($invoice['class_name_en']) ?>
                        /
                        <?= invoice_h($invoice['section_name_en']) ?>
                    </div>

                    <div class="info-label">Roll / Shift / Year</div>
                    <div class="info-value">
                        <?= invoice_h($invoice['roll_no']) ?>
                        /
                        <?= invoice_h($invoice['shift_name_en']) ?>
                        /
                        <?= invoice_h($invoice['year_name']) ?>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- FEE DETAILS -->

    <div class="section-title">Fee Details</div>

    <div class="table-responsive mb-4">
        <table class="table table-bordered">

            <thead>
                <tr>
                    <th width="7%">SL</th>
                    <th>Description</th>
                    <th width="15%" class="text-end">Quantity</th>
                    <th width="18%" class="text-end">Unit Amount</th>
                    <th width="20%" class="text-end">Total</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($items) > 0): ?>

                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= invoice_h($item['description_en']) ?>
                                </strong>

                                <?php if (!empty($item['description_bn'])): ?>
                                    <br>
                                    <small class="text-muted">
                                        <?= invoice_h($item['description_bn']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <?= invoice_money($item['quantity']) ?>
                            </td>

                            <td class="text-end">
                                <?= invoice_money($item['unit_amount']) ?>
                            </td>

                            <td class="text-end">
                                <?= invoice_money($item['total_amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="text-center text-muted">
                            No invoice items found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>
    </div>

    <!-- PAYMENT SUMMARY -->

    <div class="row justify-content-end">

        <div class="col-md-6">

            <div class="summary-box">

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong>
                        ৳ <?= invoice_money($invoice['subtotal']) ?>
                    </strong>
                </div>

                <div class="summary-row">
                    <span>Discount</span>
                    <strong>
                        ৳ <?= invoice_money($invoice['discount_amount']) ?>
                    </strong>
                </div>

                <div class="summary-row summary-total">
                    <span>Invoice Total</span>
                    <span>
                        ৳ <?= invoice_money($totalAmount) ?>
                    </span>
                </div>

                <div class="summary-row">
                    <span>Total Paid</span>
                    <strong class="text-success">
                        ৳ <?= invoice_money($paidAmount) ?>
                    </strong>
                </div>

                <div class="summary-row balance">
                    <span>Outstanding Balance</span>
                    <span>
                        ৳ <?= invoice_money($balanceDue) ?>
                    </span>
                </div>

            </div>

        </div>

    </div>

    <!-- PAYMENT HISTORY -->

    <?php if (count($payments) > 0): ?>

        <div class="section-title mt-4">
            Payment History
        </div>

        <div class="table-responsive">
            <table class="table table-bordered">

                <thead>
                    <tr>
                        <th>Receipt Number</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th class="text-end">Allocated Amount</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= invoice_h($payment['receipt_no']) ?></td>

                            <td>
                                <?= date(
                                    'd M Y',
                                    strtotime($payment['payment_date'])
                                ) ?>
                            </td>

                            <td>
                                <?= invoice_h($payment['method_name_en']) ?>
                            </td>

                            <td>
                                <?= invoice_h(
                                    $payment['transaction_reference'] ?: '-'
                                ) ?>
                            </td>

                            <td class="text-end">
                                ৳ <?= invoice_money($payment['allocated_amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <!-- NOTES -->

    <?php if (!empty($invoice['notes'])): ?>

        <div class="mt-4">
            <div class="section-title">Notes</div>

            <p class="small">
                <?= nl2br(invoice_h($invoice['notes'])) ?>
            </p>
        </div>

    <?php endif; ?>

    <!-- SIGNATURES -->

    <div class="row mt-4">

        <div class="col-6">
            <div class="signature">
                Student / Guardian Signature
            </div>
        </div>

        <div class="col-6 d-flex justify-content-end">
            <div class="signature">
                Authorized Signature
            </div>
        </div>

    </div>

    <!-- FOOTER -->

    <div class="invoice-footer text-center">
        <strong><?= invoice_h($schoolName) ?></strong>
        <br>
        This is a computer-generated invoice.
        Please keep this document for your records.
    </div>

</div>

</body>
</html>
