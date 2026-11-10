<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('fees.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function fs2_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';

$frequencyLabels = [
    'monthly'  => 'Monthly',
    'one_time' => 'One Time',
    'term'     => 'Term',
    'yearly'   => 'Yearly',
    'custom'   => 'Custom'
];

/* Save a fee assignment or activate/deactivate an existing assignment.
   Existing fee structures are never deleted or overwritten. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $categoryId = (int)($_POST['fee_category_id'] ?? 0);
            $newCategory = trim($_POST['new_category_name'] ?? '');
            $newCategoryBn = trim($_POST['new_category_name_bn'] ?? '');
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $frequency = $_POST['frequency'] ?? 'monthly';
            $effectiveFrom = trim($_POST['effective_from'] ?? '');
            $effectiveTo = trim($_POST['effective_to'] ?? '');

            if ($yearId < 1 || $classId < 1) {
                throw new Exception('Select an academic year and class.');
            }
            if ($amount === false || $amount <= 0) {
                throw new Exception('Enter a fee amount greater than zero.');
            }
            if (!isset($frequencyLabels[$frequency])) {
                throw new Exception('Select a valid fee frequency.');
            }
            $from = DateTime::createFromFormat('!Y-m-d', $effectiveFrom);
            if (!$from || $from->format('Y-m-d') !== $effectiveFrom) {
                throw new Exception('Select a valid effective start date.');
            }
            if ($effectiveTo !== '') {
                $to = DateTime::createFromFormat('!Y-m-d', $effectiveTo);
                if (!$to || $to->format('Y-m-d') !== $effectiveTo || $effectiveTo < $effectiveFrom) {
                    throw new Exception('The end date must be valid and not earlier than the start date.');
                }
            }

            if ($newCategory === '' && $categoryId < 1) {
                throw new Exception('Choose an existing fee type or enter a new fee name.');
            }
            if (mb_strlen($newCategory) > 120 || mb_strlen($newCategoryBn) > 120) {
                throw new Exception('Fee names cannot exceed 120 characters.');
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT id FROM academic_years WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $yearId);
            $stmt->execute();
            if (!$stmt->get_result()->num_rows) {
                throw new Exception('Academic year not found.');
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            if (!$stmt->get_result()->num_rows) {
                throw new Exception('Selected class is inactive or unavailable.');
            }
            $stmt->close();

            $shiftValue = null;
            if ($shiftId > 0) {
                $stmt = $conn->prepare("SELECT id FROM shifts WHERE id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param('i', $shiftId);
                $stmt->execute();
                if (!$stmt->get_result()->num_rows) {
                    throw new Exception('Selected shift is inactive or unavailable.');
                }
                $stmt->close();
                $shiftValue = $shiftId;
            }

            if ($newCategory !== '') {
                $stmt = $conn->prepare("SELECT id FROM fee_categories WHERE LOWER(category_name_en) = LOWER(?) LIMIT 1");
                $stmt->bind_param('s', $newCategory);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    $categoryId = (int)$existing['id'];
                    $stmt = $conn->prepare("UPDATE fee_categories SET category_name_bn = ?, is_recurring = ?, status = 'active' WHERE id = ?");
                    $recurring = ($frequency === 'monthly') ? 1 : 0;
                    $stmt->bind_param('sii', $newCategoryBn, $recurring, $categoryId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $categoryCode = 'CUSTOM-' . strtoupper(bin2hex(random_bytes(6)));
                    $recurring = ($frequency === 'monthly') ? 1 : 0;
                    $stmt = $conn->prepare("INSERT INTO fee_categories (category_code, category_name_en, category_name_bn, is_recurring, status) VALUES (?, ?, ?, ?, 'active')");
                    $stmt->bind_param('sssi', $categoryCode, $newCategory, $newCategoryBn, $recurring);
                    $stmt->execute();
                    $categoryId = (int)$conn->insert_id;
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("SELECT id FROM fee_categories WHERE id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param('i', $categoryId);
                $stmt->execute();
                if (!$stmt->get_result()->num_rows) {
                    throw new Exception('Selected fee category is inactive or unavailable.');
                }
                $stmt->close();
            }

            $effectiveToValue = ($effectiveTo !== '') ? $effectiveTo : null;
            $stmt = $conn->prepare("
                INSERT INTO fee_structures
                (academic_year_id, class_id, shift_id, fee_category_id, amount, frequency, effective_from, effective_to, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->bind_param('iiiidsss', $yearId, $classId, $shiftValue, $categoryId, $amount, $frequency, $effectiveFrom, $effectiveToValue);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = 'Fee assignment saved successfully.';
        } elseif ($action === 'toggle') {
            $feeId = (int)($_POST['fee_id'] ?? 0);
            if ($feeId < 1) {
                throw new Exception('Invalid fee assignment.');
            }
            $stmt = $conn->prepare("UPDATE fee_structures SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
            $stmt->bind_param('i', $feeId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed === 0) {
                $stmt = $conn->prepare("SELECT id FROM fee_structures WHERE id = ?");
                $stmt->bind_param('i', $feeId);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if (!$exists) {
                    throw new Exception('Fee assignment not found.');
                }
            }
            $message = 'Fee assignment status updated.';
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$years = $conn->query("SELECT id, year_name, is_current FROM academic_years ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$classes = $conn->query("SELECT id, class_name_en, class_order FROM classes WHERE status = 'active' ORDER BY class_order")->fetch_all(MYSQLI_ASSOC);
$shifts = $conn->query("SELECT id, shift_name_en FROM shifts WHERE status = 'active' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT id, category_name_en FROM fee_categories WHERE status = 'active' ORDER BY category_name_en")->fetch_all(MYSQLI_ASSOC);

$filterYear = (int)($_GET['year_id'] ?? 0);
if ($filterYear === 0) {
    foreach ($years as $year) {
        if ((int)$year['is_current'] === 1) { $filterYear = (int)$year['id']; break; }
    }
}
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterStatus = $_GET['status'] ?? 'active';

$sql = "
    SELECT fs.id, fs.amount, fs.frequency, fs.effective_from, fs.effective_to, fs.status,
           ay.year_name, c.class_name_en, sh.shift_name_en, fc.category_name_en
    FROM fee_structures fs
    INNER JOIN academic_years ay ON ay.id = fs.academic_year_id
    INNER JOIN classes c ON c.id = fs.class_id
    INNER JOIN fee_categories fc ON fc.id = fs.fee_category_id
    LEFT JOIN shifts sh ON sh.id = fs.shift_id
    WHERE 1=1
";
$params = [];
$types = '';
if ($filterYear > 0) { $sql .= " AND fs.academic_year_id = ?"; $types .= 'i'; $params[] = $filterYear; }
if ($filterClass > 0) { $sql .= " AND fs.class_id = ?"; $types .= 'i'; $params[] = $filterClass; }
if (in_array($filterStatus, ['active','inactive'], true)) { $sql .= " AND fs.status = ?"; $types .= 's'; $params[] = $filterStatus; }
$sql .= " ORDER BY ay.start_date DESC, c.class_order, fc.category_name_en";
$stmt = $conn->prepare($sql);
if ($params) {
    $bind = [$types];
    foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
$stmt->execute();
$fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = count($fees);
$active = 0;
foreach ($fees as $fee) { if ($fee['status'] === 'active') $active++; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Simple Fee Setup | Sezua International School</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<style>
:root{--navy:#0b2947;--deep:#071b30;--gold:#e8c66f}
body{background:#f3f6fa;font-family:"Segoe UI",Arial,sans-serif;color:#243447}
.topbar{background:var(--navy);color:#fff;padding:16px 24px;border-bottom:3px solid var(--gold)}
.wrap{max-width:1200px;margin:28px auto;padding:0 15px}
.panel,.stat{background:#fff;border:1px solid #e4eaf0;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 4px 15px rgba(11,41,71,.04)}
h3,h5{color:var(--navy);font-weight:800}
.form-label{font-size:13px;font-weight:700}
.form-control,.form-select{min-height:42px}
.table thead th{background:var(--navy);color:#fff;font-size:12px;white-space:nowrap}
.table td{font-size:13px;vertical-align:middle}
.btn-primary{background:var(--navy);border-color:var(--navy)}
.btn-gold{background:var(--gold);border-color:var(--gold);color:var(--deep);font-weight:700}
.muted{font-size:12px;color:#718096}
</style>
</head>
<body>
<div class="topbar">
 <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
  <strong><i class="fa-solid fa-school me-2"></i>SEZUA INTERNATIONAL SCHOOL</strong>
  <div class="d-flex gap-3 flex-wrap">
   <a class="text-white text-decoration-none" href="dashboard.php">Dashboard</a>
   <a class="text-white text-decoration-none" href="generate_invoices.php">Monthly Billing</a>
   <a class="text-white text-decoration-none" href="invoices.php">Invoices</a>
  </div>
 </div>
</div>
<div class="wrap">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h3 class="mb-1">Fee Setup</h3><div class="text-muted small">Set a fee once for a class and academic year.</div></div>
  <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
 </div>
 <?php if ($message): ?><div class="alert alert-<?= fs2_h($messageType) ?>"><?= fs2_h($message) ?></div><?php endif; ?>
 <div class="row g-3 mb-3">
  <div class="col-md-6"><div class="stat"><div class="muted">Assignments in this view</div><h3><?= $total ?></h3></div></div>
  <div class="col-md-6"><div class="stat"><div class="muted">Active assignments in this view</div><h3><?= $active ?></h3></div></div>
 </div>
 <div class="panel">
  <h5 class="mb-3"><i class="fa-solid fa-circle-plus me-2"></i>Add a Fee</h5>
  <form method="post">
   <input type="hidden" name="csrf_token" value="<?= fs2_h(csrf_token()) ?>">
   <input type="hidden" name="action" value="add">
   <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Academic Year *</label><select class="form-select" name="academic_year_id" required><option value="">Select year</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= (int)$y['is_current']===1?'selected':'' ?>><?= fs2_h($y['year_name']) ?><?= (int)$y['is_current']===1?' (Current)':'' ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Class *</label><select class="form-select" name="class_id" required><option value="">Select class</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= fs2_h($c['class_name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Shift</label><select class="form-select" name="shift_id"><option value="0">All shifts</option><?php foreach($shifts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= fs2_h($s['shift_name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Existing Fee Type</label><select class="form-select" name="fee_category_id"><option value="0">Choose if not creating a new fee</option><?php foreach($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= fs2_h($cat['category_name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Or New Fee Name</label><input class="form-control" name="new_category_name" placeholder="Example: Monthly Tuition"></div>
    <div class="col-md-6"><label class="form-label">Bangla Fee Name (optional)</label><input class="form-control" name="new_category_name_bn" placeholder="বাংলা নাম"></div>
    <div class="col-md-3"><label class="form-label">Amount (৳) *</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div>
    <div class="col-md-3"><label class="form-label">Frequency *</label><select class="form-select" name="frequency" required><?php foreach($frequencyLabels as $key=>$label): ?><option value="<?= fs2_h($key) ?>" <?= $key==='monthly'?'selected':'' ?>><?= fs2_h($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Effective From *</label><input class="form-control" type="date" name="effective_from" required></div>
    <div class="col-md-6"><label class="form-label">Effective To</label><input class="form-control" type="date" name="effective_to"><div class="muted">Leave blank if there is no end date.</div></div>
   </div>
   <div class="mt-3"><button class="btn btn-gold px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Save Fee</button></div>
  </form>
 </div>
 <div class="panel">
  <h5 class="mb-3">Existing Fee Assignments</h5>
  <form method="get" class="row g-2 mb-3">
   <div class="col-md-4"><select class="form-select" name="year_id"><option value="0">All academic years</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $filterYear===(int)$y['id']?'selected':'' ?>><?= fs2_h($y['year_name']) ?></option><?php endforeach; ?></select></div>
   <div class="col-md-4"><select class="form-select" name="class_id"><option value="0">All classes</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $filterClass===(int)$c['id']?'selected':'' ?>><?= fs2_h($c['class_name_en']) ?></option><?php endforeach; ?></select></div>
   <div class="col-md-2"><select class="form-select" name="status"><option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option><option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All</option></select></div>
   <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
  </form>
  <div class="table-responsive"><table class="table table-bordered table-hover">
   <thead><tr><th>Year</th><th>Class</th><th>Shift</th><th>Fee</th><th>Amount</th><th>Frequency</th><th>Effective</th><th>Status</th><th>Action</th></tr></thead><tbody>
   <?php if($fees): foreach($fees as $fee): ?>
    <tr>
     <td><?= fs2_h($fee['year_name']) ?></td><td><?= fs2_h($fee['class_name_en']) ?></td><td><?= fs2_h($fee['shift_name_en'] ?: 'All shifts') ?></td>
     <td><?= fs2_h($fee['category_name_en']) ?></td><td class="fw-bold">৳<?= number_format((float)$fee['amount'],2) ?></td>
     <td><?= fs2_h($frequencyLabels[$fee['frequency']] ?? $fee['frequency']) ?></td>
     <td><?= fs2_h($fee['effective_from']) ?><?= $fee['effective_to'] ? ' to '.fs2_h($fee['effective_to']) : ' onward' ?></td>
     <td><span class="badge <?= $fee['status']==='active'?'bg-success':'bg-secondary' ?>"><?= fs2_h(ucfirst($fee['status'])) ?></span></td>
     <td><form method="post" onsubmit="return confirm('Change this fee assignment status?')"><input type="hidden" name="csrf_token" value="<?= fs2_h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="fee_id" value="<?= (int)$fee['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><?= $fee['status']==='active'?'Deactivate':'Activate' ?></button></form></td>
    </tr>
   <?php endforeach; else: ?><tr><td colspan="9" class="text-center text-muted py-4">No fee assignments found.</td></tr><?php endif; ?>
   </tbody></table></div>
 </div>
 <p class="text-muted small text-center">Existing fee assignments and invoices are preserved. No records are deleted by this page.</p>
</div>
</body>
</html>
