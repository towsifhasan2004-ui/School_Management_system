<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function mbill_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mbill_columns($conn, $table) {
    $out = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($row = $result->fetch_assoc()) {
        $out[] = $row['Field'];
    }
    return $out;
}

function mbill_name_expression($columns) {
    foreach (['full_name_en', 'student_name_en', 'name_en', 'full_name'] as $column) {
        if (in_array($column, $columns, true)) {
            return "COALESCE(NULLIF(TRIM(s.`$column`), ''), NULLIF(TRIM(CONCAT_WS(' ', s.first_name_en, s.last_name_en)), ''), 'Unknown Student')";
        }
    }
    return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', s.first_name_en, s.last_name_en)), ''), 'Unknown Student')";
}

function mbill_queue_sms($conn, $studentId, $phone, $message) {
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($phone) < 11) return false;

    $stmt = $conn->prepare("
        INSERT INTO sms_outbox (student_id, phone_no, message, status, created_at)
        VALUES (?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param('iss', $studentId, $phone, $message);
    $stmt->execute();
    $stmt->close();
    return true;
}

$message = '';
$messageType = 'success';
$generated = 0;
$skipped = 0;
$smsQueued = 0;
$skipDetails = [];

$years = $conn->query("
    SELECT id, year_name, is_current
    FROM academic_years
    ORDER BY start_date DESC
")->fetch_all(MYSQLI_ASSOC);

$currentYear = $conn->query("
    SELECT id
    FROM academic_years
    WHERE is_current = 1
    LIMIT 1
")->fetch_assoc();

$defaultYearId = (int)($currentYear['id'] ?? 0);

$yearId = (int)($_POST['academic_year_id'] ?? $_GET['academic_year_id'] ?? $defaultYearId);
$billingMonth = trim($_POST['billing_month'] ?? $_GET['billing_month'] ?? date('Y-m'));
$invoiceDate = trim($_POST['invoice_date'] ?? date('Y-m-d'));
$dueDate = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+10 days')));
$feeCategoryId = (int)($_POST['fee_category_id'] ?? $_GET['fee_category_id'] ?? 0);
$targetStudentId = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);

$monthObject = DateTime::createFromFormat('!Y-m', $billingMonth);
if (!$monthObject || $monthObject->format('Y-m') !== $billingMonth) {
    $billingMonth = date('Y-m');
}
$periodStart = $billingMonth . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));

$invoiceDateObject = DateTime::createFromFormat('!Y-m-d', $invoiceDate);
$dueDateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);
if (!$invoiceDateObject || $invoiceDateObject->format('Y-m-d') !== $invoiceDate) {
    $invoiceDate = date('Y-m-d');
}
if (!$dueDateObject || $dueDateObject->format('Y-m-d') !== $dueDate) {
    $dueDate = date('Y-m-d', strtotime('+10 days'));
}

$studentColumns = mbill_columns($conn, 'students');
$nameExpression = mbill_name_expression($studentColumns);
$bnNameExpression = in_array('full_name_bn', $studentColumns, true) ? 's.full_name_bn' : "''";

$feeCategories = $conn->query("SELECT id, category_name_en, category_name_bn FROM fee_categories WHERE status = 'active' ORDER BY category_name_en")->fetch_all(MYSQLI_ASSOC);

$studentOptions = [];
if ($yearId > 0) {
    $optionSql = "SELECT en.student_id, s.admission_no, $nameExpression AS student_name
                  FROM enrollments en INNER JOIN students s ON s.id = en.student_id
                  WHERE en.academic_year_id = ? AND en.enrollment_status = 'active' AND s.status = 'active'
                  ORDER BY s.admission_no";
    $optionStmt = $conn->prepare($optionSql);
    $optionStmt->bind_param('i', $yearId);
    $optionStmt->execute();
    $studentOptions = $optionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $optionStmt->close();
}

/* Generate bills for all active students in the selected academic year. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if ($yearId < 1) throw new Exception('Please select an academic year.');
        if ($feeCategoryId < 0) throw new Exception('Invalid fee type selected.');
        if ($targetStudentId < 0) throw new Exception('Invalid student selected.');
        if ($feeCategoryId > 0) {
            $categoryCheck = $conn->prepare("SELECT id FROM fee_categories WHERE id = ? AND status = 'active' LIMIT 1");
            $categoryCheck->bind_param('i', $feeCategoryId);
            $categoryCheck->execute();
            $categoryExists = $categoryCheck->get_result()->num_rows > 0;
            $categoryCheck->close();
            if (!$categoryExists) throw new Exception('Selected fee type is not active or does not exist.');
        }
        if ($invoiceDate > $dueDate) throw new Exception('Due date cannot be earlier than invoice date.');

        $check = $conn->prepare("SELECT id FROM academic_years WHERE id = ? LIMIT 1");
        $check->bind_param('i', $yearId);
        $check->execute();
        $validYear = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$validYear) throw new Exception('Selected academic year was not found.');

        $studentsSql = "
            SELECT en.id AS enrollment_id, en.student_id, en.class_id, en.shift_id,
                   en.roll_no, s.admission_no,
                   $nameExpression AS student_name_en,
                   $bnNameExpression AS full_name_bn,
                   c.class_name_en, sec.section_name_en, sh.shift_name_en,
                   (
                       SELECT g.phone
                       FROM student_guardians sg
                       INNER JOIN guardians g ON g.id = sg.guardian_id
                       WHERE sg.student_id = s.id
                         AND g.phone IS NOT NULL AND g.phone <> ''
                       ORDER BY sg.is_primary DESC, sg.id ASC
                       LIMIT 1
                   ) AS guardian_phone
            FROM enrollments en
            INNER JOIN students s ON s.id = en.student_id
            INNER JOIN classes c ON c.id = en.class_id
            INNER JOIN sections sec ON sec.id = en.section_id
            INNER JOIN shifts sh ON sh.id = en.shift_id
            WHERE en.academic_year_id = ?
              AND en.enrollment_status = 'active'
              AND s.status = 'active'
            ORDER BY c.class_order, sec.section_name_en, sh.shift_name_en,
                     CAST(en.roll_no AS UNSIGNED), en.roll_no
        ";

        if ($targetStudentId > 0) {
            $studentsSql = str_replace("ORDER BY c.class_order", "AND en.student_id = ? ORDER BY c.class_order", $studentsSql);
        }
        $studentStmt = $conn->prepare($studentsSql);
        if ($targetStudentId > 0) {
            $studentStmt->bind_param('ii', $yearId, $targetStudentId);
        } else {
            $studentStmt->bind_param('i', $yearId);
        }
        $studentStmt->execute();
        $students = $studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $studentStmt->close();

        if (!$students) throw new Exception('No active enrolled students found in this academic year.');

        $feeStmt = $conn->prepare("
            SELECT fs.id, fs.class_id, fs.shift_id, fs.fee_category_id,
                   fs.amount, fs.frequency, fs.effective_from,
                   fc.category_name_en, fc.category_name_bn
            FROM fee_structures fs
            INNER JOIN fee_categories fc ON fc.id = fs.fee_category_id
            WHERE fs.academic_year_id = ?
              AND fs.status = 'active'
              AND fc.status = 'active'
              AND fs.effective_from <= ?
              AND (fs.effective_to IS NULL OR fs.effective_to >= ?)
            ORDER BY fs.class_id, fc.category_name_en
        ");
        $feeStmt->bind_param('iss', $yearId, $periodEnd, $periodStart);
        $feeStmt->execute();
        $feeStructures = $feeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $feeStmt->close();

        if (!$feeStructures) {
            throw new Exception('No active fee setup overlaps the selected billing month. Please configure fees first.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) throw new Exception('Your login session is invalid. Please sign in again.');

        $conn->begin_transaction();

        foreach ($students as $student) {
            $enrollmentId = (int)$student['enrollment_id'];
            $studentId = (int)$student['student_id'];
            $classId = (int)$student['class_id'];
            $shiftId = (int)$student['shift_id'];

            // Duplicate protection is per fee type. A student may receive
            // another invoice for a different fee category in the same month.
            if ($feeCategoryId > 0) {
                $duplicateStmt = $conn->prepare("
                    SELECT i.id
                    FROM invoices i
                    INNER JOIN invoice_items ii ON ii.invoice_id = i.id
                    WHERE i.enrollment_id = ?
                      AND i.billing_period_start = ?
                      AND i.billing_period_end = ?
                      AND i.status <> 'cancelled'
                      AND ii.fee_category_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $duplicateStmt->bind_param('issi', $enrollmentId, $periodStart, $periodEnd, $feeCategoryId);
            } else {
                $duplicateStmt = $conn->prepare("
                    SELECT id FROM invoices
                    WHERE enrollment_id = ?
                      AND billing_period_start = ?
                      AND billing_period_end = ?
                      AND status <> 'cancelled'
                    LIMIT 1
                    FOR UPDATE
                ");
                $duplicateStmt->bind_param('iss', $enrollmentId, $periodStart, $periodEnd);
            }
            $duplicateStmt->execute();
            $duplicate = $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();

            if ($duplicate) {
                $skipped++;
                $skipDetails[] = [
                    'student' => $student['student_name_en'],
                    'admission' => $student['admission_no'],
                    'class' => $student['class_name_en'],
                    'reason' => $feeCategoryId > 0 ? 'This fee type has already been invoiced for this student in the selected month.' : 'A non-cancelled invoice already exists for this month.'
                ];
                continue;
            }

            $studentFees = [];
            foreach ($feeStructures as $fee) {
                if ((int)$fee['class_id'] !== $classId) continue;
                if ($feeCategoryId > 0 && (int)$fee['fee_category_id'] !== $feeCategoryId) continue;
                if ($fee['shift_id'] !== null && (int)$fee['shift_id'] !== $shiftId) continue;

                // Monthly fees recur. Other frequencies are charged only in their effective_from month.
                if ($fee['frequency'] !== 'monthly'
                    && date('Y-m', strtotime($fee['effective_from'])) !== $billingMonth) {
                    continue;
                }
                $studentFees[] = $fee;
            }

            if (!$studentFees) {
                $skipped++;
                $skipDetails[] = [
                    'student' => $student['student_name_en'],
                    'admission' => $student['admission_no'],
                    'class' => $student['class_name_en'],
                    'reason' => 'No fee assignment is due for this class and shift in the selected month.'
                ];
                continue;
            }

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $invoiceItems = [];

            foreach ($studentFees as $fee) {
                $baseAmount = (float)$fee['amount'];
                if ($baseAmount <= 0) continue;

                $categoryId = (int)$fee['fee_category_id'];
                $discountStmt = $conn->prepare("
                    SELECT discount_type, discount_value
                    FROM student_fee_discounts
                    WHERE student_id = ?
                      AND status = 'approved'
                      AND valid_from <= ?
                      AND (valid_to IS NULL OR valid_to >= ?)
                      AND (fee_category_id IS NULL OR fee_category_id = ?)
                    ORDER BY id
                ");
                $discountStmt->bind_param('issi', $studentId, $periodEnd, $periodStart, $categoryId);
                $discountStmt->execute();
                $discounts = $discountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $discountStmt->close();

                $discountAmount = 0.0;
                foreach ($discounts as $discount) {
                    $value = (float)$discount['discount_value'];
                    $discountAmount += $discount['discount_type'] === 'percentage'
                        ? $baseAmount * ($value / 100)
                        : $value;
                }
                $discountAmount = min($baseAmount, max(0, $discountAmount));
                $netAmount = $baseAmount - $discountAmount;

                $subtotal += $baseAmount;
                $discountTotal += $discountAmount;
                $invoiceItems[] = [
                    'category_id' => $categoryId,
                    'name_en' => $fee['category_name_en'],
                    'name_bn' => $fee['category_name_bn'],
                    'amount' => $baseAmount,
                    'net_amount' => $netAmount
                ];
            }

            if (!$invoiceItems) {
                $skipped++;
                $skipDetails[] = [
                    'student' => $student['student_name_en'],
                    'admission' => $student['admission_no'],
                    'class' => $student['class_name_en'],
                    'reason' => 'All matching fee amounts were zero.'
                ];
                continue;
            }

            $totalAmount = max(0, $subtotal - $discountTotal);
            $invoiceNo = 'SZ-INV-' . date('Ym', strtotime($periodStart)) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $selectedFeeName = 'All due fee types';
            if ($feeCategoryId > 0) {
                foreach ($feeCategories as $category) {
                    if ((int)$category['id'] === $feeCategoryId) { $selectedFeeName = $category['category_name_en']; break; }
                }
            }
            $notes = 'Fee type: ' . $selectedFeeName . ' | Billing period: ' . date('F Y', strtotime($periodStart));

            $invoiceStmt = $conn->prepare("
                INSERT INTO invoices
                (invoice_no, student_id, enrollment_id, invoice_date, due_date,
                 billing_period_start, billing_period_end, subtotal, discount_amount,
                 total_amount, status, notes, created_by, issued_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, NOW())
            ");
            $invoiceStmt->bind_param(
                'siissssdddsi',
                $invoiceNo, $studentId, $enrollmentId, $invoiceDate, $dueDate,
                $periodStart, $periodEnd, $subtotal, $discountTotal, $totalAmount,
                $notes, $userId
            );
            $invoiceStmt->execute();
            $invoiceId = (int)$conn->insert_id;
            $invoiceStmt->close();

            $itemStmt = $conn->prepare("
                INSERT INTO invoice_items
                (invoice_id, fee_category_id, description_en, description_bn,
                 quantity, unit_amount, total_amount)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ");
            foreach ($invoiceItems as $item) {
                $categoryId = (int)$item['category_id'];
                $nameEn = (string)$item['name_en'];
                $nameBn = (string)($item['name_bn'] ?? '');
                $unitAmount = (float)$item['amount'];
                $itemTotal = (float)$item['net_amount'];
                $itemStmt->bind_param('iissdd', $invoiceId, $categoryId, $nameEn, $nameBn, $unitAmount, $itemTotal);
                $itemStmt->execute();
            }
            $itemStmt->close();

            $generated++;
            if (!empty($student['guardian_phone'])) {
                $smsText = "Dear Guardian, fee bill {$invoiceNo} of BDT "
                    . number_format($totalAmount, 2)
                    . " has been issued for {$student['student_name_en']}. Due Date: {$dueDate}. - Sezua International School";
                if (mbill_queue_sms($conn, $studentId, $student['guardian_phone'], $smsText)) {
                    $smsQueued++;
                }
            }
        }

        $conn->commit();

        $message = "{$generated} bill(s) generated for " . date('F Y', strtotime($periodStart))
            . ". {$skipped} student(s) skipped. {$smsQueued} SMS message(s) queued.";
        if ($generated === 0) $messageType = 'warning';

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Monthly billing error: ' . $e->getMessage());
        $message = 'Monthly billing failed: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

/* Preview count: active enrollments in the selected year. */
$previewCount = 0;
if ($yearId > 0) {
    $previewStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM enrollments en
        INNER JOIN students s ON s.id = en.student_id
        WHERE en.academic_year_id = ?
          AND en.enrollment_status = 'active'
          AND s.status = 'active'
    ");
    $previewStmt->bind_param('i', $yearId);
    $previewStmt->execute();
    $previewCount = (int)($previewStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $previewStmt->close();
}

$recentStmt = $conn->prepare("
    SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total_amount, i.status,
           s.admission_no,
           $nameExpression AS student_name_en,
           c.class_name_en
    FROM invoices i
    INNER JOIN students s ON s.id = i.student_id
    INNER JOIN enrollments en ON en.id = i.enrollment_id
    INNER JOIN classes c ON c.id = en.class_id
    WHERE i.billing_period_start = ? AND i.billing_period_end = ?
    ORDER BY i.created_at DESC
    LIMIT 30
");
$recentStmt->bind_param('ss', $periodStart, $periodEnd);
$recentStmt->execute();
$recentInvoices = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentStmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice Generation | Sezua International School</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<style>
:root{--navy:#0b2947;--deep:#071b30;--gold:#e8c66f}
body{background:#f3f6fa;font-family:"Segoe UI",Arial,sans-serif;color:#243447}
.topbar{background:var(--navy);color:#fff;padding:16px 24px;border-bottom:3px solid var(--gold)}
.wrap{max-width:1150px;margin:28px auto;padding:0 15px}
.panel,.stat{background:#fff;border:1px solid #e4eaf0;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 4px 15px rgba(11,41,71,.04)}
h3,h5{color:var(--navy);font-weight:800}
.form-label{font-size:13px;font-weight:700}
.form-control,.form-select{min-height:44px}
.btn-primary{background:var(--navy);border-color:var(--navy)}
.btn-gold{background:var(--gold);border-color:var(--gold);color:var(--deep);font-weight:800;min-height:48px}
.table thead th{background:var(--navy);color:#fff;font-size:12px;white-space:nowrap}
.table td{font-size:13px;vertical-align:middle}
.note{background:#f8fafc;border-left:4px solid var(--gold);padding:12px 15px;border-radius:7px;font-size:13px}
.small-muted{font-size:12px;color:#718096}
</style>
</head>
<body>
<div class="topbar">
 <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
  <strong><i class="fa-solid fa-school me-2"></i>SEZUA INTERNATIONAL SCHOOL</strong>
  <div class="d-flex gap-3 flex-wrap">
   <a class="text-white text-decoration-none" href="dashboard.php">Dashboard</a>
   <a class="text-white text-decoration-none" href="fee_structures.php">Fee Setup</a>
   <a class="text-white text-decoration-none" href="collect_payment.php">Collect Payment</a>
   <a class="text-white text-decoration-none" href="invoices.php">Invoices</a>
  </div>
 </div>
</div>
<div class="wrap">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h3 class="mb-1"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Generate Fee Invoices</h3><div class="text-muted small">Create monthly or additional fee invoices by fee category.</div></div>
  <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
 </div>

 <?php if($message): ?><div class="alert alert-<?= mbill_h($messageType) ?>"><?= mbill_h($message) ?></div><?php endif; ?>

 <div class="row g-3 mb-3">
  <div class="col-md-6"><div class="stat mb-0"><div class="small-muted">Active students in selected year</div><h3 class="mb-0"><?= number_format($previewCount) ?></h3></div></div>
  <div class="col-md-6"><div class="stat mb-0"><div class="small-muted">Bills already created for selected month</div><h3 class="mb-0"><?= number_format(count($recentInvoices)) ?></h3></div></div>
 </div>

 <div class="panel">
  <h5 class="mb-2">Create Invoices for Students</h5>
  <p class="text-muted small">Choose a fee type, academic year, student and billing month. You can issue a separate invoice for another fee type even when the selected student already has a bill for the same month.</p>
  <form method="post" onsubmit="return confirm('Generate bills for all eligible active students for this month? Existing bills and payments will not be changed. A different fee type can be invoiced separately.');">
   <input type="hidden" name="csrf_token" value="<?= mbill_h(csrf_token()) ?>">
   <div class="row g-3">
    <div class="col-md-4">
     <label class="form-label">Academic Year *</label>
     <select class="form-select" name="academic_year_id" required>
      <option value="">Select academic year</option>
      <?php foreach($years as $year): ?>
       <option value="<?= (int)$year['id'] ?>" <?= $yearId===(int)$year['id']?'selected':'' ?>><?= mbill_h($year['year_name']) ?><?= (int)$year['is_current']===1?' (Current)':'' ?></option>
      <?php endforeach; ?>
     </select>
    </div>
    <div class="col-md-4">
     <label class="form-label">Invoice Fee Type *</label>
     <select class="form-select" name="fee_category_id" required>
      <option value="0" <?= $feeCategoryId===0?'selected':'' ?>>All due fee types (one combined invoice)</option>
      <?php foreach($feeCategories as $category): ?>
       <option value="<?= (int)$category['id'] ?>" <?= $feeCategoryId===(int)$category['id']?'selected':'' ?>><?= mbill_h($category['category_name_en']) ?></option>
      <?php endforeach; ?>
     </select>
     <div class="small-muted mt-1">Select e.g. Tuition, Examination, Transport or another configured fee category.</div>
    </div>
    <div class="col-md-4">
     <label class="form-label">Student *</label>
     <select class="form-select" name="student_id" required>
      <option value="0" <?= $targetStudentId===0?'selected':'' ?>>All active students</option>
      <?php foreach($studentOptions as $studentOption): ?>
       <option value="<?= (int)$studentOption['student_id'] ?>" <?= $targetStudentId===(int)$studentOption['student_id']?'selected':'' ?>><?= mbill_h($studentOption['admission_no']) ?> - <?= mbill_h($studentOption['student_name']) ?></option>
      <?php endforeach; ?>
     </select>
     <div class="small-muted mt-1">Choose one student to issue an additional individual invoice, or leave All active students selected.</div>
    </div>
    <div class="col-md-4">
     <label class="form-label">Billing Month *</label>
     <input class="form-control" type="month" name="billing_month" value="<?= mbill_h($billingMonth) ?>" required>
    </div>
    <div class="col-md-4">
     <label class="form-label">Invoice Date *</label>
     <input class="form-control" type="date" name="invoice_date" value="<?= mbill_h($invoiceDate) ?>" required>
    </div>
    <div class="col-md-4">
     <label class="form-label">Payment Due Date *</label>
     <input class="form-control" type="date" name="due_date" value="<?= mbill_h($dueDate) ?>" required>
    </div>
   </div>
   <div class="note mt-3"><strong>Important:</strong> Only the selected fee type is included for the selected student(s). If you select a specific fee type, the system skips a student only when that same fee type has already been invoiced for the selected month. Other fee types can still be invoiced separately. Choosing All due fee types creates one combined invoice and skips students who already have any invoice for that month. Existing invoices, posted payments, allocations and receipts are preserved.</div>
   <button class="btn btn-gold px-4 mt-3"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Generate Invoices</button>
  </form>
 </div>

 <div class="panel">
  <h5 class="mb-3">Bills for <?= mbill_h(date('F Y', strtotime($periodStart))) ?></h5>
  <div class="table-responsive">
   <table class="table table-bordered table-hover mb-0">
    <thead><tr><th>Invoice No.</th><th>Admission No.</th><th>Student</th><th>Class</th><th>Invoice Date</th><th>Due Date</th><th>Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php if($recentInvoices): foreach($recentInvoices as $invoice): ?>
     <tr>
      <td><a href="payment_receipt.php?id=<?= (int)$invoice['id'] ?>"><?= mbill_h($invoice['invoice_no']) ?></a></td>
      <td><?= mbill_h($invoice['admission_no']) ?></td>
      <td><?= mbill_h($invoice['student_name_en']) ?></td>
      <td><?= mbill_h($invoice['class_name_en']) ?></td>
      <td><?= mbill_h($invoice['invoice_date']) ?></td>
      <td><?= mbill_h($invoice['due_date']) ?></td>
      <td class="fw-bold">৳<?= number_format((float)$invoice['total_amount'],2) ?></td>
      <td><?= mbill_h(ucfirst(str_replace('_',' ',$invoice['status']))) ?></td>
     </tr>
    <?php endforeach; else: ?>
     <tr><td colspan="8" class="text-center text-muted py-4">No bills found for this month.</td></tr>
    <?php endif; ?>
    </tbody>
   </table>
  </div>
  <div class="small-muted mt-2">Showing the latest 30 bills.</div>
 </div>

 <?php if($skipDetails): ?>
 <div class="panel">
  <h5 class="mb-3">Students Skipped (<?= count($skipDetails) ?>)</h5>
  <div class="table-responsive"><table class="table table-bordered table-sm">
   <thead><tr><th>Admission No.</th><th>Student</th><th>Class</th><th>Reason</th></tr></thead>
   <tbody><?php foreach($skipDetails as $item): ?><tr><td><?= mbill_h($item['admission']) ?></td><td><?= mbill_h($item['student']) ?></td><td><?= mbill_h($item['class']) ?></td><td><?= mbill_h($item['reason']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
 </div>
 <?php endif; ?>
</div>
</body>
</html>
