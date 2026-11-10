<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('payments.collect');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function cp_h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function cp_money($v) { return number_format((float)$v, 2); }

$error = '';
$search = trim($_GET['q'] ?? '');
$selectedStudentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$selectedInvoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
$defaultDate = date('Y-m-d\\TH:i');

/* Save a payment. Existing invoice/payment/allocation records are retained. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $methodId = (int)($_POST['payment_method_id'] ?? 0);
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $dateInput = trim($_POST['payment_date'] ?? '');
    $reference = trim($_POST['transaction_reference'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($invoiceId < 1) $error = 'Please select an invoice.';
    elseif ($methodId < 1) $error = 'Please select a payment method.';
    elseif (!is_finite($amount) || $amount <= 0) $error = 'Enter a valid payment amount.';
    elseif ($dateInput === '') $error = 'Please select the payment date and time.';

    if (!$error) {
        $paymentDate = str_replace('T', ' ', $dateInput);
        if (strlen($paymentDate) === 16) $paymentDate .= ':00';
        $dateObject = DateTime::createFromFormat('Y-m-d H:i:s', $paymentDate);
        if (!$dateObject || $dateObject->format('Y-m-d H:i:s') !== $paymentDate) {
            $error = 'Invalid payment date.';
        }
    }

    if (!$error) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, student_id, total_amount, status FROM invoices WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $invoice = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$invoice) throw new Exception('Invoice not found.');
            if (in_array($invoice['status'], ['draft', 'cancelled'], true)) throw new Exception('This invoice is not available for payment.');

            $stmt = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('i', $methodId);
            $stmt->execute();
            $method = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$method) throw new Exception('Selected payment method is inactive or invalid.');

            $stmt = $conn->prepare("SELECT COALESCE(SUM(pa.allocated_amount),0) AS paid_amount FROM payment_allocations pa INNER JOIN payments p ON p.id = pa.payment_id WHERE pa.invoice_id = ? AND p.status = 'posted'");
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $paidRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $alreadyPaid = (float)$paidRow['paid_amount'];
            $invoiceTotal = (float)$invoice['total_amount'];
            $balance = max(0, $invoiceTotal - $alreadyPaid);
            if ($balance <= 0.009) throw new Exception('This invoice is already fully paid.');
            if ($amount > $balance + 0.009) throw new Exception('Payment cannot exceed the outstanding balance of ৳' . number_format($balance, 2));

            $receivedBy = (int)($_SESSION['user_id'] ?? 0);
            if ($receivedBy < 1) throw new Exception('Unable to identify the logged-in user.');
            $studentId = (int)$invoice['student_id'];
            $receiptNo = 'SZR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

            $stmt = $conn->prepare("INSERT INTO payments (receipt_no, student_id, payment_date, amount, payment_method_id, transaction_reference, received_by, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, 'posted', ?)");
            $stmt->bind_param('sisdisis', $receiptNo, $studentId, $paymentDate, $amount, $methodId, $reference, $receivedBy, $remarks);
            $stmt->execute();
            $paymentId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, allocated_amount) VALUES (?, ?, ?)");
            $stmt->bind_param('iid', $paymentId, $invoiceId, $amount);
            $stmt->execute();
            $stmt->close();

            $newBalance = max(0, $invoiceTotal - ($alreadyPaid + $amount));
            $newStatus = $newBalance <= 0.009 ? 'paid' : 'partially_paid';
            $stmt = $conn->prepare("UPDATE invoices SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $invoiceId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header('Location: payment_receipt.php?id=' . $paymentId);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* Find students by admission number or name. */
$students = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT id, admission_no, first_name_en, last_name_en FROM students WHERE admission_no LIKE ? OR first_name_en LIKE ? OR last_name_en LIKE ? ORDER BY first_name_en, last_name_en LIMIT 50");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $students[] = $r;
    $stmt->close();
}

/* Load selected student, including after a failed payment submission. */
$selectedStudent = null;
if ($selectedStudentId > 0) {
    $stmt = $conn->prepare("SELECT id, admission_no, first_name_en, last_name_en FROM students WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $selectedStudentId);
    $stmt->execute();
    $selectedStudent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* If an invoice was selected directly, derive its student. */
if (!$selectedStudent && $selectedInvoiceId > 0) {
    $stmt = $conn->prepare("SELECT s.id, s.admission_no, s.first_name_en, s.last_name_en FROM invoices i INNER JOIN students s ON s.id = i.student_id WHERE i.id = ? LIMIT 1");
    $stmt->bind_param('i', $selectedInvoiceId);
    $stmt->execute();
    $selectedStudent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($selectedStudent) $selectedStudentId = (int)$selectedStudent['id'];
}

/* Outstanding invoices for the selected student. */
$invoices = [];
if ($selectedStudent) {
    $stmt = $conn->prepare("SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total_amount, i.status, c.class_name_en, sec.section_name_en, COALESCE(paid.paid_amount,0) AS paid_amount FROM invoices i LEFT JOIN enrollments e ON e.id = i.enrollment_id LEFT JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id LEFT JOIN (SELECT pa.invoice_id, SUM(pa.allocated_amount) AS paid_amount FROM payment_allocations pa INNER JOIN payments p ON p.id = pa.payment_id WHERE p.status = 'posted' GROUP BY pa.invoice_id) paid ON paid.invoice_id = i.id WHERE i.student_id = ? AND i.status IN ('issued','partially_paid','overdue') ORDER BY i.invoice_date DESC, i.id DESC");
    $stmt->bind_param('i', $selectedStudentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['balance'] = max(0, (float)$r['total_amount'] - (float)$r['paid_amount']);
        if ($r['balance'] > 0.009) $invoices[] = $r;
    }
    $stmt->close();
}

/* Selected invoice must belong to the selected student and still be payable. */
$selectedInvoice = null;
if ($selectedInvoiceId > 0 && $selectedStudent) {
    foreach ($invoices as $r) {
        if ((int)$r['id'] === $selectedInvoiceId) { $selectedInvoice = $r; break; }
    }
}

$methods = [];
$res = $conn->query("SELECT id, method_name_en FROM payment_methods WHERE status = 'active' ORDER BY method_name_en");
while ($r = $res->fetch_assoc()) $methods[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Collect Student Payment | Sezua International School</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
body{background:#f3f6fa;color:#263445;font-family:Arial,sans-serif}.topbar{background:#102c4a;color:#fff;padding:16px 22px}.wrap{max-width:1100px;margin:28px auto;padding:0 14px}.panel{background:#fff;border-radius:12px;padding:22px;margin-bottom:20px;box-shadow:0 4px 18px rgba(0,0,0,.05)}.heading{color:#102c4a;font-weight:800}.invoice{border:1px solid #e1e7ee;border-radius:9px;padding:15px;margin-top:10px}.invoice:hover{border-color:#c69b45}.balance{font-size:20px;font-weight:800;color:#b42318}.form-label{font-weight:600;font-size:13px}
</style>
</head>
<body>
<div class="topbar"><strong><i class="fa-solid fa-school me-2"></i>SEZUA INTERNATIONAL SCHOOL</strong><span class="float-end">Payment Management</span></div>
<div class="wrap">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4"><div><h3 class="heading mb-1">Collect Student Payment</h3><div class="text-muted small">Search a student, choose an outstanding invoice, and collect payment.</div></div><a href="invoices.php" class="btn btn-outline-secondary"><i class="fa-solid fa-file-invoice me-1"></i>Invoice List</a></div>
  <?php if ($error): ?><div class="alert alert-danger"><?= cp_h($error) ?></div><?php endif; ?>
  <div class="panel">
    <h5 class="heading mb-3"><i class="fa-solid fa-magnifying-glass me-2"></i>1. Find Student</h5>
    <form method="GET" class="row g-2"><div class="col-md-9"><input class="form-control" name="q" value="<?= cp_h($search) ?>" placeholder="Enter admission number or student name" required></div><div class="col-md-3"><button class="btn btn-primary w-100"><i class="fa-solid fa-search me-1"></i>Search Student</button></div></form>
    <?php if ($search !== ''): ?>
      <div class="mt-3"><div class="small text-muted mb-2">Search results (maximum 50)</div>
      <?php if ($students): foreach ($students as $s): ?><div class="invoice d-flex justify-content-between align-items-center flex-wrap gap-2"><div><strong><?= cp_h(trim($s['first_name_en'].' '.$s['last_name_en'])) ?></strong><div class="small text-muted">Admission: <?= cp_h($s['admission_no']) ?></div></div><a class="btn btn-sm btn-outline-primary" href="collect_payment.php?student_id=<?= (int)$s['id'] ?>"><i class="fa-solid fa-file-invoice-dollar me-1"></i>View Bills</a></div><?php endforeach; else: ?><div class="text-muted py-3">No student found. Check the admission number or spelling.</div><?php endif; ?></div>
    <?php endif; ?>
  </div>

  <?php if ($selectedStudent): ?>
  <div class="panel">
    <h5 class="heading mb-1"><i class="fa-solid fa-user-graduate me-2"></i>2. Outstanding Bills</h5>
    <div class="text-muted mb-3"><?= cp_h(trim($selectedStudent['first_name_en'].' '.$selectedStudent['last_name_en'])) ?> · <?= cp_h($selectedStudent['admission_no']) ?></div>
    <?php if ($invoices): foreach ($invoices as $inv): ?><div class="invoice"><div class="row align-items-center g-3"><div class="col-md-5"><strong><?= cp_h($inv['invoice_no']) ?></strong><div class="small text-muted">Date: <?= cp_h($inv['invoice_date']) ?> · Due: <?= cp_h($inv['due_date']) ?></div><div class="small text-muted"><?= cp_h($inv['class_name_en'] ?? '') ?> <?= !empty($inv['section_name_en']) ? '/ '.cp_h($inv['section_name_en']) : '' ?></div></div><div class="col-md-4"><div class="small text-muted">Total: ৳<?= cp_money($inv['total_amount']) ?> · Paid: ৳<?= cp_money($inv['paid_amount']) ?></div><div class="balance">Due: ৳<?= cp_money($inv['balance']) ?></div></div><div class="col-md-3 text-md-end"><a class="btn btn-success" href="collect_payment.php?student_id=<?= $selectedStudentId ?>&invoice_id=<?= (int)$inv['id'] ?>">Select Invoice</a></div></div></div><?php endforeach; else: ?><div class="alert alert-success mb-0">This student has no outstanding invoices.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($selectedInvoice): ?>
  <div class="panel">
    <h5 class="heading mb-3"><i class="fa-solid fa-cash-register me-2"></i>3. Receive Payment</h5>
    <div class="row g-3 p-3 mb-3 rounded" style="background:#f3f6fa"><div class="col-md-4"><div class="small text-muted">Invoice</div><strong><?= cp_h($selectedInvoice['invoice_no']) ?></strong></div><div class="col-md-4"><div class="small text-muted">Student</div><strong><?= cp_h(trim($selectedStudent['first_name_en'].' '.$selectedStudent['last_name_en'])) ?></strong></div><div class="col-md-4"><div class="small text-muted">Outstanding</div><div class="balance">৳<?= cp_money($selectedInvoice['balance']) ?></div></div></div>
    <?php if ($methods): ?>
    <form method="POST" class="row g-3" onsubmit="return confirmPayment();">
      <input type="hidden" name="csrf_token" value="<?= cp_h(csrf_token()) ?>"><input type="hidden" name="invoice_id" value="<?= (int)$selectedInvoice['id'] ?>"><input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
      <div class="col-md-6"><label class="form-label">Payment Amount (৳) *</label><input type="number" class="form-control" name="amount" id="paymentAmount" min="0.01" max="<?= cp_h($selectedInvoice['balance']) ?>" step="0.01" required><div class="form-text">Maximum ৳<?= cp_money($selectedInvoice['balance']) ?></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById('paymentAmount').value=document.getElementById('paymentAmount').max">Pay Full Balance</button></div>
      <div class="col-md-6"><label class="form-label">Payment Method *</label><select class="form-select" name="payment_method_id" required><option value="">Choose method</option><?php foreach ($methods as $m): ?><option value="<?= (int)$m['id'] ?>"><?= cp_h($m['method_name_en']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Payment Date & Time *</label><input type="datetime-local" class="form-control" name="payment_date" value="<?= cp_h($defaultDate) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Transaction Reference</label><input type="text" class="form-control" name="transaction_reference" maxlength="120" placeholder="Bank / bKash / Nagad reference"></div>
      <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" rows="2" maxlength="500"></textarea></div>
      <div class="col-12"><button type="submit" class="btn btn-success px-4"><i class="fa-solid fa-check me-2"></i>Confirm and Collect Payment</button><a class="btn btn-outline-secondary ms-2" href="collect_payment.php?student_id=<?= $selectedStudentId ?>">Cancel</a></div>
    </form>
    <?php else: ?><div class="alert alert-warning mb-0">No active payment methods are configured. Activate a payment method first.</div><?php endif; ?>
  </div>
  <?php elseif ($selectedInvoiceId > 0): ?><div class="alert alert-warning">This invoice is no longer outstanding or is not available for this student.</div><?php endif; ?>
</div>
<script>
function confirmPayment(){const amount=parseFloat(document.getElementById('paymentAmount').value||0);const max=parseFloat(document.getElementById('paymentAmount').max||0);if(amount<=0||amount>max+0.009){alert('Enter an amount within the outstanding balance.');return false;}return confirm('Confirm collection of ৳ '+amount.toFixed(2)+'?');}
</script>
</body>
</html>
