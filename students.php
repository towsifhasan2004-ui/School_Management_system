
<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('students.view');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$sql = "
    SELECT
        s.id,
        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,
        s.gender,
        s.status,
        e.roll_no,
        c.class_name_en,
        sec.section_name_en,
        sh.shift_name_en,
        g.name_en AS guardian_name,
        g.phone AS guardian_phone
    FROM students s
    LEFT JOIN student_guardians sg
        ON sg.student_id = s.id AND sg.is_primary = 1
    LEFT JOIN guardians g ON g.id = sg.guardian_id
    LEFT JOIN enrollments e
        ON e.student_id = s.id
        AND e.academic_year_id = (
            SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1
        )
        AND e.enrollment_status = 'active'
    LEFT JOIN classes c ON c.id = e.class_id
    LEFT JOIN sections sec ON sec.id = e.section_id
    LEFT JOIN shifts sh ON sh.id = e.shift_id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (
        s.admission_no LIKE ?
        OR s.first_name_en LIKE ?
        OR s.last_name_en LIKE ?
        OR s.full_name_bn LIKE ?
        OR g.name_en LIKE ?
        OR g.phone LIKE ?
    )";

    $term = '%' . $search . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $term;
        $types .= 's';
    }
}

if (in_array($status, ['active','transferred','graduated','withdrawn','suspended'], true)) {
    $sql .= " AND s.status = ?";
    $params[] = $status;
    $types .= 's';
}

$sql .= " ORDER BY s.id DESC LIMIT 300";

$stmt = $conn->prepare($sql);

if ($params) {
    $bindParams = [$types];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindParams
    );
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Students | Sezua</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="container-fluid p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h3 class="page-title mb-1">Student Management</h3>
            <p class="text-muted mb-0">Search and manage student records.</p>
        </div>

        <?php if (has_permission('students.create') && has_permission('enrollments.manage')): ?>
            <a href="add_student.php" class="btn btn-school">
                <i class="fa-solid fa-plus me-1"></i> New Admission
            </a>
        <?php endif; ?>
    </div>

    <div class="form-panel mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="form-label">Search students</label>
                <input type="search" name="search" class="form-control"
                       placeholder="Admission no, name or guardian phone"
                       value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Student Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (['active','transferred','graduated','withdrawn','suspended'] as $item): ?>
                        <option value="<?= $item ?>" <?= $status === $item ? 'selected' : '' ?>>
                            <?= ucfirst($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-school flex-grow-1">Search</button>
                <a href="students.php" class="btn btn-light border">Reset</a>
            </div>
        </form>
    </div>

    <div class="form-panel">
        <div class="d-flex justify-content-between mb-3">
            <h5 class="mb-0">Registered Students</h5>
            <span class="text-muted small"><?= count($students) ?> record(s), maximum 300 shown</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Admission No.</th>
                        <th>Student Name</th>
                        <th>Class / Section</th>
                        <th>Roll</th>
                        <th>Shift</th>
                        <th>Guardian</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            No student records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><strong><?= e($student['admission_no']) ?></strong></td>
                            <td>
                                <strong><?= e(trim($student['first_name_en'] . ' ' . $student['last_name_en'])) ?></strong>
                                <?php if ($student['full_name_bn']): ?>
                                    <div class="small text-muted"><?= e($student['full_name_bn']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($student['class_name_en'] ?? 'Not assigned') ?>
                                <div class="small text-muted"><?= e($student['section_name_en'] ?? '') ?></div>
                            </td>
                            <td><?= e($student['roll_no'] ?? '—') ?></td>
                            <td><?= e($student['shift_name_en'] ?? '—') ?></td>
                            <td>
                                <?= e($student['guardian_name'] ?? '—') ?>
                                <div class="small text-muted"><?= e($student['guardian_phone'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="status-pill status-<?= e($student['status']) ?>">
                                    <?= e(ucfirst($student['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_student.php?id=<?= (int)$student['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
