
<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
require_permission('students.update');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$error = '';
$success = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    redirect('students.php');
}

/* GET STUDENT AND PRIMARY GUARDIAN */

$stmt = $conn->prepare("
    SELECT
        s.*,
        g.id AS guardian_id,
        g.name_en AS guardian_name,
        g.name_bn AS guardian_name_bn,
        g.phone AS guardian_phone,
        g.alternate_phone AS guardian_alternate_phone,
        g.email AS guardian_email,
        g.occupation AS guardian_occupation,
        g.address AS guardian_address,
        sg.relationship
    FROM students s
    LEFT JOIN student_guardians sg
        ON sg.student_id = s.id
        AND sg.is_primary = 1
    LEFT JOIN guardians g
        ON g.id = sg.guardian_id
    WHERE s.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found.");
}

/* UPDATE STUDENT */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

verify_csrf();

    $first_name = trim($_POST['first_name_en'] ?? '');
    $last_name = trim($_POST['last_name_en'] ?? '');
    $name_bn = trim($_POST['full_name_bn'] ?? '');

    $dob = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $blood_group = trim($_POST['blood_group'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $birth_registration = trim($_POST['birth_registration_no'] ?? '');

    $present_address = trim($_POST['present_address'] ?? '');
    $permanent_address = trim($_POST['permanent_address'] ?? '');

    $admission_date = $_POST['admission_date'] ?? '';
    $status = $_POST['status'] ?? '';

    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_name_bn = trim($_POST['guardian_name_bn'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $guardian_alternate_phone = trim($_POST['guardian_alternate_phone'] ?? '');
    $guardian_email = trim($_POST['guardian_email'] ?? '');
    $guardian_occupation = trim($_POST['guardian_occupation'] ?? '');
    $guardian_address = trim($_POST['guardian_address'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');

    $allowed_genders = ['male', 'female', 'other'];
    $allowed_statuses = [
        'active',
        'transferred',
        'graduated',
        'withdrawn',
        'suspended'
    ];

    if (
        $first_name === '' ||
        $dob === '' ||
        $admission_date === '' ||
        $guardian_name === '' ||
        $guardian_phone === ''
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!in_array($gender, $allowed_genders, true)) {
        $error = "Invalid gender selected.";
    } elseif (!in_array($status, $allowed_statuses, true)) {
        $error = "Invalid student status.";
    } elseif (
        $guardian_email !== '' &&
        !filter_var($guardian_email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = "Please enter a valid guardian email.";
    } else {

        try {

            $conn->begin_transaction();

            /* UPDATE STUDENT */

            $update = $conn->prepare("
                UPDATE students SET
                    first_name_en = ?,
                    last_name_en = ?,
                    full_name_bn = ?,
                    date_of_birth = ?,
                    gender = ?,
                    blood_group = ?,
                    religion = ?,
                    birth_registration_no = ?,
                    present_address = ?,
                    permanent_address = ?,
                    admission_date = ?,
                    status = ?
                WHERE id = ?
            ");

            $update->bind_param(
                "ssssssssssssi",
                $first_name,
                $last_name,
                $name_bn,
                $dob,
                $gender,
                $blood_group,
                $religion,
                $birth_registration,
                $present_address,
                $permanent_address,
                $admission_date,
                $status,
                $id
            );

            $update->execute();

            /* UPDATE PRIMARY GUARDIAN */

            $guardian_id = (int)($student['guardian_id'] ?? 0);

            if ($guardian_id > 0) {

                $guardian_update = $conn->prepare("
                    UPDATE guardians SET
                        name_en = ?,
                        name_bn = ?,
                        phone = ?,
                        alternate_phone = ?,
                        email = ?,
                        occupation = ?,
                        address = ?
                    WHERE id = ?
                ");

                $guardian_update->bind_param(
                    "sssssssi",
                    $guardian_name,
                    $guardian_name_bn,
                    $guardian_phone,
                    $guardian_alternate_phone,
                    $guardian_email,
                    $guardian_occupation,
                    $guardian_address,
                    $guardian_id
                );

                $guardian_update->execute();

                $relationship_update = $conn->prepare("
                    UPDATE student_guardians
                    SET relationship = ?
                    WHERE student_id = ?
                    AND guardian_id = ?
                    AND is_primary = 1
                ");

                $relationship_update->bind_param(
                    "sii",
                    $relationship,
                    $id,
                    $guardian_id
                );

                $relationship_update->execute();

            } else {

                /* CREATE PRIMARY GUARDIAN IF NONE EXISTS */

                $guardian_insert = $conn->prepare("
                    INSERT INTO guardians
                    (
                        name_en,
                        name_bn,
                        phone,
                        alternate_phone,
                        email,
                        occupation,
                        address
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $guardian_insert->bind_param(
                    "sssssss",
                    $guardian_name,
                    $guardian_name_bn,
                    $guardian_phone,
                    $guardian_alternate_phone,
                    $guardian_email,
                    $guardian_occupation,
                    $guardian_address
                );

                $guardian_insert->execute();

                $new_guardian_id = $conn->insert_id;

                $link = $conn->prepare("
                    INSERT INTO student_guardians
                    (
                        student_id,
                        guardian_id,
                        relationship,
                        is_primary,
                        is_emergency_contact,
                        can_receive_notifications
                    )
                    VALUES (?, ?, ?, 1, 1, 1)
                ");

                $link->bind_param(
                    "iis",
                    $id,
                    $new_guardian_id,
                    $relationship
                );

                $link->execute();
            }

            /* AUDIT LOG */

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    module_name,
                    record_table,
                    record_id,
                    new_values,
                    ip_address
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $user_id = (int)$_SESSION['user_id'];
            $action = 'UPDATE';
            $module = 'students';
            $table = 'students';

            $new_values = json_encode([
                'first_name_en' => $first_name,
                'last_name_en' => $last_name,
                'status' => $status
            ]);

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            $audit->bind_param(
                "isssiss",
                $user_id,
                $action,
                $module,
                $table,
                $id,
                $new_values,
                $ip
            );

            $audit->execute();

            $conn->commit();

            redirect('view_student.php?id=' . $id);

        } catch (Throwable $e) {

            $conn->rollback();

            $error = "Unable to update student. " . $e->getMessage();
        }
    }

    /* KEEP FORM VALUES AFTER VALIDATION ERROR */

    $student = array_merge($student, [
        'first_name_en' => $first_name,
        'last_name_en' => $last_name,
        'full_name_bn' => $name_bn,
        'date_of_birth' => $dob,
        'gender' => $gender,
        'blood_group' => $blood_group,
        'religion' => $religion,
        'birth_registration_no' => $birth_registration,
        'present_address' => $present_address,
        'permanent_address' => $permanent_address,
        'admission_date' => $admission_date,
        'status' => $status,
        'guardian_name' => $guardian_name,
        'guardian_name_bn' => $guardian_name_bn,
        'guardian_phone' => $guardian_phone,
        'guardian_alternate_phone' => $guardian_alternate_phone,
        'guardian_email' => $guardian_email,
        'guardian_occupation' => $guardian_occupation,
        'guardian_address' => $guardian_address,
        'relationship' => $relationship
    ]);
}

function fieldValue($data, $key) {
    return e($data[$key] ?? '');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Student | Sezua International School</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="assets/css/style.css" rel="stylesheet">
</head>

<body>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">Edit Student</h3>
            <p class="text-muted mb-0">
                Update student and guardian information
            </p>
        </div>

        <a href="view_student.php?id=<?= $id ?>"
           class="btn btn-outline-secondary">
            Back to Profile
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <input type="hidden"
               name="csrf_token"
               value="<?= e(csrf_token()) ?>">

        <!-- STUDENT INFORMATION -->

        <div class="card shadow-sm mb-4">

            <div class="card-header fw-bold">
                Student Information
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Admission Number</label>
                        <input class="form-control"
                               value="<?= fieldValue($student, 'admission_no') ?>"
                               readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Registration Number</label>
                        <input class="form-control"
                               value="<?= fieldValue($student, 'registration_no') ?>"
                               readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name_en"
                               class="form-control" required
                               value="<?= fieldValue($student, 'first_name_en') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name_en"
                               class="form-control"
                               value="<?= fieldValue($student, 'last_name_en') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Full Name (Bangla)</label>
                        <input type="text" name="full_name_bn"
                               class="form-control"
                               value="<?= fieldValue($student, 'full_name_bn') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Date of Birth *</label>
                        <input type="date" name="date_of_birth"
                               class="form-control" required
                               value="<?= fieldValue($student, 'date_of_birth') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Gender *</label>
                        <select name="gender" class="form-select" required>
                            <option value="male" <?= ($student['gender'] === 'male') ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= ($student['gender'] === 'female') ? 'selected' : '' ?>>Female</option>
                            <option value="other" <?= ($student['gender'] === 'other') ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Blood Group</label>
                        <input type="text" name="blood_group"
                               class="form-control"
                               value="<?= fieldValue($student, 'blood_group') ?>"
                               placeholder="Example: A+">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Religion</label>
                        <input type="text" name="religion"
                               class="form-control"
                               value="<?= fieldValue($student, 'religion') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Birth Registration Number</label>
                        <input type="text" name="birth_registration_no"
                               class="form-control"
                               value="<?= fieldValue($student, 'birth_registration_no') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Admission Date *</label>
                        <input type="date" name="admission_date"
                               class="form-control" required
                               value="<?= fieldValue($student, 'admission_date') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Present Address</label>
                        <textarea name="present_address"
                                  class="form-control"
                                  rows="3"><?= fieldValue($student, 'present_address') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Permanent Address</label>
                        <textarea name="permanent_address"
                                  class="form-control"
                                  rows="3"><?= fieldValue($student, 'permanent_address') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Student Status *</label>
                        <select name="status" class="form-select" required>
                            <?php
                            $statuses = [
                                'active' => 'Active',
                                'transferred' => 'Transferred',
                                'graduated' => 'Graduated',
                                'withdrawn' => 'Withdrawn',
                                'suspended' => 'Suspended'
                            ];

                            foreach ($statuses as $key => $label):
                            ?>
                                <option value="<?= e($key) ?>"
                                    <?= ($student['status'] === $key) ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <!-- GUARDIAN INFORMATION -->

        <div class="card shadow-sm mb-4">

            <div class="card-header fw-bold">
                Primary Guardian Information
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Guardian Name *</label>
                        <input type="text" name="guardian_name"
                               class="form-control" required
                               value="<?= fieldValue($student, 'guardian_name') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Guardian Name (Bangla)</label>
                        <input type="text" name="guardian_name_bn"
                               class="form-control"
                               value="<?= fieldValue($student, 'guardian_name_bn') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="guardian_phone"
                               class="form-control" required
                               value="<?= fieldValue($student, 'guardian_phone') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Alternate Phone</label>
                        <input type="text" name="guardian_alternate_phone"
                               class="form-control"
                               value="<?= fieldValue($student, 'guardian_alternate_phone') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="guardian_email"
                               class="form-control"
                               value="<?= fieldValue($student, 'guardian_email') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Occupation</label>
                        <input type="text" name="guardian_occupation"
                               class="form-control"
                               value="<?= fieldValue($student, 'guardian_occupation') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Relationship</label>
                        <select name="relationship" class="form-select">
                            <?php
                            $relationships = [
                                'Father', 'Mother', 'Brother',
                                'Sister', 'Uncle', 'Aunt', 'Other'
                            ];
                            foreach ($relationships as $rel):
                            ?>
                                <option value="<?= e($rel) ?>"
                                    <?= (($student['relationship'] ?? '') === $rel) ? 'selected' : '' ?>>
                                    <?= e($rel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Guardian Address</label>
                        <textarea name="guardian_address"
                                  class="form-control"
                                  rows="3"><?= fieldValue($student, 'guardian_address') ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                Update Student
            </button>

            <a href="view_student.php?id=<?= $id ?>"
               class="btn btn-secondary">
                Cancel
            </a>
        </div>

    </form>

</div>

</body>
</html>
