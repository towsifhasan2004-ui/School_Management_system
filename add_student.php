
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('students.create');
require_permission('enrollments.manage');

$errors = [];

$years = $conn->query("
    SELECT id, year_name
    FROM academic_years
    WHERE status = 'active' AND is_current = 1
    LIMIT 1
")->fetch_assoc();

$classes = $conn->query("
    SELECT id, class_name_en
    FROM classes
    WHERE status = 'active'
    ORDER BY class_order
")->fetch_all(MYSQLI_ASSOC);

$sections = $conn->query("
    SELECT id, class_id, section_name_en
    FROM sections
    WHERE status = 'active'
    ORDER BY class_id, section_name_en
")->fetch_all(MYSQLI_ASSOC);

$shifts = $conn->query("
    SELECT id, shift_name_en
    FROM shifts
    WHERE status = 'active'
    ORDER BY id
")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $firstName = trim($_POST['first_name_en'] ?? '');
    $lastName = trim($_POST['last_name_en'] ?? '');
    $nameBn = trim($_POST['full_name_bn'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $bloodGroup = trim($_POST['blood_group'] ?? '');
    $birthReg = trim($_POST['birth_registration_no'] ?? '');
    $presentAddress = trim($_POST['present_address'] ?? '');
    $permanentAddress = trim($_POST['permanent_address'] ?? '');
    $admissionDate = $_POST['admission_date'] ?? '';

    $guardianName = trim($_POST['guardian_name'] ?? '');
    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    $guardianEmail = trim($_POST['guardian_email'] ?? '');
    $guardianOccupation = trim($_POST['guardian_occupation'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');

    $classId = (int)($_POST['class_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $rollNo = trim($_POST['roll_no'] ?? '');

    if ($firstName === '') $errors[] = 'Student first name is required.';
    if (!$dob || strtotime($dob) === false) $errors[] = 'Valid date of birth is required.';
    if (!$admissionDate || strtotime($admissionDate) === false) $errors[] = 'Valid admission date is required.';
    if (!in_array($gender, ['male', 'female', 'other'], true)) $errors[] = 'Select a valid gender.';
    if ($guardianName === '') $errors[] = 'Guardian name is required.';
    if ($guardianPhone === '') $errors[] = 'Guardian phone is required.';
    if ($relationship === '') $errors[] = 'Guardian relationship is required.';
    if ($classId < 1 || $sectionId < 1 || $shiftId < 1 || $rollNo === '') {
        $errors[] = 'Class, section, shift and roll number are required.';
    }
    if (!$years) $errors[] = 'No current academic year is configured. Set up an academic year first.';

    if ($guardianEmail !== '' && !filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid guardian email address.';
    }

    // Verify that the selected section belongs to the selected class.
    if (!$errors) {
        $check = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM sections
            WHERE id = ? AND class_id = ? AND status = 'active'
        ");
        $check->bind_param('ii', $sectionId, $classId);
        $check->execute();
        $validSection = (int)$check->get_result()->fetch_assoc()['total'];
        $check->close();

        if (!$validSection) $errors[] = 'The selected section does not belong to this class.';
    }

    if (!$errors) {
        $check = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM shifts
            WHERE id = ? AND status = 'active'
        ");
        $check->bind_param('i', $shiftId);
        $check->execute();
        $validShift = (int)$check->get_result()->fetch_assoc()['total'];
        $check->close();

        if (!$validShift) $errors[] = 'Select a valid active shift.';
    }

    if (!$errors) {
        try {
            $conn->begin_transaction();

            // Random, unique admission number.
            $admissionNo = 'SZ-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));

            $stmt = $conn->prepare("
                INSERT INTO students (
                    admission_no, first_name_en, last_name_en, full_name_bn,
                    date_of_birth, gender, blood_group, birth_registration_no,
                    present_address, permanent_address, admission_date, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");

            $stmt->bind_param(
                'sssssssssss',
                $admissionNo, $firstName, $lastName, $nameBn,
                $dob, $gender, $bloodGroup, $birthReg,
                $presentAddress, $permanentAddress, $admissionDate
            );
            $stmt->execute();
            $studentId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO guardians (
                    name_en, phone, email, occupation, address
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'sssss',
                $guardianName, $guardianPhone, $guardianEmail,
                $guardianOccupation, $presentAddress
            );
            $stmt->execute();
            $guardianId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO student_guardians (
                    student_id, guardian_id, relationship,
                    is_primary, is_emergency_contact, can_receive_notifications
                ) VALUES (?, ?, ?, 1, 1, 1)
            ");
            $stmt->bind_param('iis', $studentId, $guardianId, $relationship);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO enrollments (
                    student_id, academic_year_id, class_id,
                    section_id, shift_id, roll_no,
                    enrollment_date, enrollment_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");

            $yearId = (int)$years['id'];
            $stmt->bind_param(
                'iiiiiss',
                $studentId, $yearId, $classId,
                $sectionId, $shiftId, $rollNo, $admissionDate
            );
            $stmt->execute();
            $stmt->close();

            // Record the admission in the audit log.
            $audit = $conn->prepare("
                INSERT INTO audit_logs
                    (user_id, action, module_name, record_table, record_id, new_values, ip_address)
                VALUES (?, 'CREATE', 'students', 'students', ?, ?, ?)
            ");

            $actorId = (int)$_SESSION['user_id'];
            $auditData = json_encode([
                'admission_no' => $admissionNo,
                'student_name' => trim($firstName . ' ' . $lastName)
            ], JSON_UNESCAPED_UNICODE);
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $audit->bind_param('iiss', $actorId, $studentId, $auditData, $ip);
            $audit->execute();
            $audit->close();

            $conn->commit();

            redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&created=1');

        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Student admission error: ' . $e->getMessage());
            $errors[] = 'Admission could not be completed. Please check the server error log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Admission | Sezua</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="container-fluid p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h3 class="page-title mb-1">New Student Admission</h3>
            <p class="text-muted mb-0">Register a student and assign their class.</p>
        </div>
        <a href="students.php" class="btn btn-outline-secondary">Back to Students</a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$years): ?>
        <div class="alert alert-warning">
            No current academic year found. Create an active academic year marked as current before admitting students.
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-panel mb-4">
            <h5>Student Information</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">First Name (English) *</label>
                    <input name="first_name_en" class="form-control" required value="<?= e($_POST['first_name_en'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Last Name (English)</label>
                    <input name="last_name_en" class="form-control" value="<?= e($_POST['last_name_en'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Full Name (Bangla)</label>
                    <input name="full_name_bn" class="form-control" value="<?= e($_POST['full_name_bn'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth *</label>
                    <input type="date" name="date_of_birth" class="form-control" required value="<?= e($_POST['date_of_birth'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gender *</label>
                    <select name="gender" class="form-select" required>
                        <option value="">Select gender</option>
                        <option value="male" <?= (($_POST['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= (($_POST['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="">Select blood group</option>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $blood): ?>
                            <option value="<?= $blood ?>"><?= $blood ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Birth Registration Number</label>
                    <input name="birth_registration_no" class="form-control" value="<?= e($_POST['birth_registration_no'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Admission Date *</label>
                    <input type="date" name="admission_date" class="form-control" required value="<?= e($_POST['admission_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Present Address</label>
                    <textarea name="present_address" class="form-control" rows="2"><?= e($_POST['present_address'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Permanent Address</label>
                    <textarea name="permanent_address" class="form-control" rows="2"><?= e($_POST['permanent_address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="form-panel mb-4">
            <h5>Guardian Information</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Guardian Name *</label>
                    <input name="guardian_name" class="form-control" required value="<?= e($_POST['guardian_name'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Relationship *</label>
                    <select name="relationship" class="form-select" required>
                        <option value="">Select relationship</option>
                        <?php foreach (['Father','Mother','Brother','Sister','Uncle','Aunt','Legal Guardian'] as $relation): ?>
                            <option value="<?= e($relation) ?>" <?= (($_POST['relationship'] ?? '') === $relation) ? 'selected' : '' ?>><?= e($relation) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Phone Number *</label>
                    <input name="guardian_phone" class="form-control" required value="<?= e($_POST['guardian_phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="guardian_email" class="form-control" value="<?= e($_POST['guardian_email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Occupation</label>
                    <input name="guardian_occupation" class="form-control" value="<?= e($_POST['guardian_occupation'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-panel mb-4">
            <h5>Academic Enrollment</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Academic Year</label>
                    <input class="form-control" value="<?= e($years['year_name'] ?? 'Not configured') ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Class *</label>
                    <select name="class_id" id="class_id" class="form-select" required>
                        <option value="">Select class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int)$class['id'] ?>" <?= ((int)($_POST['class_id'] ?? 0) === (int)$class['id']) ? 'selected' : '' ?>>
                                <?= e($class['class_name_en']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Section *</label>
                    <select name="section_id" id="section_id" class="form-select" required>
                        <option value="">Select section</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= (int)$section['id'] ?>"
                                data-class="<?= (int)$section['class_id'] ?>"
                                <?= ((int)($_POST['section_id'] ?? 0) === (int)$section['id']) ? 'selected' : '' ?>>
                                <?= e($section['section_name_en']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Shift *</label>
                    <select name="shift_id" class="form-select" required>
                        <option value="">Select shift</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= (int)$shift['id'] ?>" <?= ((int)($_POST['shift_id'] ?? 0) === (int)$shift['id']) ? 'selected' : '' ?>>
                                <?= e($shift['shift_name_en']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Class Roll Number *</label>
                    <input name="roll_no" class="form-control" required value="<?= e($_POST['roll_no'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
            <a href="students.php" class="btn btn-light border">Cancel</a>
            <button type="submit" class="btn btn-school" <?= !$years ? 'disabled' : '' ?>>
                <i class="fa-solid fa-floppy-disk me-1"></i> Save Admission
            </button>
        </div>
    </form>
</div>

<script>
const classSelect = document.getElementById('class_id');
const sectionSelect = document.getElementById('section_id');

function filterSections() {
    const selectedClass = classSelect.value;

    Array.from(sectionSelect.options).forEach((option, index) => {
        if (index === 0) return;

        const matches = option.dataset.class === selectedClass;
        option.hidden = !matches;
        option.disabled = !matches;

        if (!matches && option.selected) {
            sectionSelect.value = '';
        }
    });
}

classSelect.addEventListener('change', filterSections);
filterSections();
</script>
</body>
</html>
