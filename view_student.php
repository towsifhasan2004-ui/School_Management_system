
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('students.view');

/*
|--------------------------------------------------------------------------
| GET STUDENT ID
|--------------------------------------------------------------------------
*/

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    redirect(BASE_URL . '/students.php');
}

/*
|--------------------------------------------------------------------------
| FETCH STUDENT INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        s.*,
        g.name_en AS guardian_name,
        g.phone AS guardian_phone,
        g.email AS guardian_email,
        g.occupation AS guardian_occupation,
        sg.relationship,
        e.roll_no,
        e.enrollment_date,
        c.class_name_en,
        sec.section_name_en,
        sh.shift_name_en,
        ay.year_name

    FROM students s

    LEFT JOIN student_guardians sg
        ON sg.student_id = s.id
        AND sg.is_primary = 1

    LEFT JOIN guardians g
        ON g.id = sg.guardian_id

    LEFT JOIN enrollments e
        ON e.student_id = s.id
        AND e.academic_year_id = (
            SELECT id
            FROM academic_years
            WHERE is_current = 1
            LIMIT 1
        )
        AND e.enrollment_status = 'active'

    LEFT JOIN classes c
        ON c.id = e.class_id

    LEFT JOIN sections sec
        ON sec.id = e.section_id

    LEFT JOIN shifts sh
        ON sh.id = e.shift_id

    LEFT JOIN academic_years ay
        ON ay.id = e.academic_year_id

    WHERE s.id = ?

    LIMIT 1
");

$stmt->bind_param('i', $id);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| CHECK STUDENT EXISTS
|--------------------------------------------------------------------------
*/

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

/*
|--------------------------------------------------------------------------
| STUDENT NAME
|--------------------------------------------------------------------------
*/

$studentName = trim(
    $student['first_name_en'] . ' ' . $student['last_name_en']
);

/*
|--------------------------------------------------------------------------
| STUDENT PHOTO VALIDATION
|--------------------------------------------------------------------------
*/

$photoPath = $student['photo_path'] ?? '';

$hasPhoto = (
    $photoPath !== '' &&
    strpos($photoPath, 'uploads/student_photos/') === 0 &&
    dirname($photoPath) === 'uploads/student_photos' &&
    is_file(__DIR__ . '/' . $photoPath)
);

/*
|--------------------------------------------------------------------------
| PHOTO ERROR MESSAGES
|--------------------------------------------------------------------------
*/

$photoErrors = [
    'no_file'  => 'Please select a student photo.',
    'upload'   => 'Photo upload failed. Please try again.',
    'size'     => 'Photo size must not exceed 2 MB.',
    'invalid'  => 'The uploaded file is not a valid image.',
    'type'     => 'Only JPG, PNG and WEBP images are allowed.',
    'folder'   => 'Unable to create the photo upload folder.',
    'save'     => 'Unable to save the photo.',
    'database' => 'Unable to update the student record.'
];

$photoError = $_GET['photo_error'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        Student Profile | Sezua International School
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <style>

        /* PROFILE PHOTO */

        .student-profile-photo {
            width: 115px;
            height: 115px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #e8c66f;
            background: #f4f6f9;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        /* DEFAULT STUDENT AVATAR */

        .student-avatar {
            width: 115px;
            height: 115px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #0b2947;
            color: #e8c66f;
            font-size: 42px;
            font-weight: 700;
            border: 4px solid #e8c66f;
        }

        /* PROFILE HEADER */

        .student-profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .student-photo-form {
            border-top: 1px solid #e9ecef;
            margin-top: 22px;
            padding-top: 20px;
        }

        .student-photo-form .form-control {
            border-radius: 8px;
        }

        .profile-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 11px 0;
            border-bottom: 1px solid #edf0f3;
        }

        .profile-row span {
            color: #6c757d;
            min-width: 130px;
        }

        .profile-row strong {
            text-align: right;
            overflow-wrap: anywhere;
        }

        @media(max-width: 576px) {

            .student-profile-photo,
            .student-avatar {
                width: 90px;
                height: 90px;
            }

            .student-avatar {
                font-size: 34px;
            }

            .profile-row {
                flex-direction: column;
                gap: 4px;
            }

            .profile-row strong {
                text-align: left;
            }

        }

    </style>

</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <!-- PAGE HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>

            <h3 class="page-title mb-1">
                Student Profile
            </h3>

            <p class="text-muted mb-0">
                Admission Number:
                <?= e($student['admission_no']) ?>
            </p>

        </div>

        <a
            href="<?= BASE_URL ?>/students.php"
            class="btn btn-outline-secondary"
        >
            <i class="fa-solid fa-arrow-left"></i>
            Back to Students
        </a>

    </div>


    <!-- SUCCESS MESSAGES -->

    <?php if (isset($_GET['created'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="fa-solid fa-circle-check"></i>

            Student admission completed successfully.

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if (isset($_GET['photo_success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="fa-solid fa-circle-check"></i>

            Student photo uploaded successfully.

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- PHOTO ERROR -->

    <?php if ($photoError !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="fa-solid fa-triangle-exclamation"></i>

            <?= e($photoErrors[$photoError] ?? 'An unexpected error occurred.') ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ACTION BUTTONS -->

    <div class="d-flex gap-2 flex-wrap mb-3">

        <?php if (has_permission('students.update')): ?>

            <a
                href="<?= BASE_URL ?>/edit_student.php?id=<?= (int)$student['id'] ?>"
                class="btn btn-primary"
            >

                <i class="fa-solid fa-pen-to-square"></i>

                Edit Student

            </a>

        <?php endif; ?>

    </div>


    <!-- STUDENT PROFILE CARD -->

    <div class="form-panel mb-4">

        <div class="student-profile-header">

            <!-- STUDENT PHOTO -->

            <?php if ($hasPhoto): ?>

                <img
                    src="<?= BASE_URL . '/' . e($photoPath) ?>"
                    alt="Student Photo"
                    class="student-profile-photo"
                >

            <?php else: ?>

                <div class="student-avatar">

                    <?= e(
                        strtoupper(
                            substr($student['first_name_en'], 0, 1)
                        )
                    ) ?>

                </div>

            <?php endif; ?>


            <!-- STUDENT NAME -->

            <div>

                <h4 class="mb-1">

                    <?= e($studentName) ?>

                </h4>

                <?php if ($student['full_name_bn']): ?>

                    <div class="text-muted mb-2">

                        <?= e($student['full_name_bn']) ?>

                    </div>

                <?php endif; ?>


                <span class="status-pill status-<?= e($student['status']) ?>">

                    <?= e(ucfirst($student['status'])) ?>

                </span>

            </div>

        </div>


        <!-- PHOTO UPLOAD FORM -->

        <?php if (has_permission('students.update')): ?>

            <div class="student-photo-form">

                <form
                    action="<?= BASE_URL ?>/upload_student_photo.php"
                    method="POST"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="student_id"
                        value="<?= (int)$student['id'] ?>"
                    >


                    <label class="form-label fw-semibold">

                        <i class="fa-solid fa-camera"></i>

                        <?= $hasPhoto ? 'Change Student Photo' : 'Upload Student Photo' ?>

                    </label>


                    <div class="row g-2 align-items-end">

                        <div class="col-md-8">

                            <input
                                type="file"
                                name="student_photo"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                                required
                            >

                            <small class="text-muted">

                                Allowed formats: JPG, PNG, WEBP.
                                Maximum size: 2 MB.

                            </small>

                        </div>


                        <div class="col-md-4">

                            <button
                                type="submit"
                                class="btn btn-success w-100"
                            >

                                <i class="fa-solid fa-cloud-arrow-up"></i>

                                <?= $hasPhoto ? 'Replace Photo' : 'Upload Photo' ?>

                            </button>

                        </div>

                    </div>

                </form>

            </div>

        <?php endif; ?>

    </div>


    <!-- INFORMATION SECTIONS -->

    <div class="row g-4">


        <!-- PERSONAL INFORMATION -->

        <div class="col-lg-6">

            <div class="form-panel h-100">

                <h5 class="mb-3">

                    <i class="fa-solid fa-user text-primary"></i>

                    Personal Information

                </h5>


                <div class="profile-row">

                    <span>Admission Number</span>

                    <strong>
                        <?= e($student['admission_no']) ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Registration Number</span>

                    <strong>
                        <?= e($student['registration_no'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Date of Birth</span>

                    <strong>
                        <?= e($student['date_of_birth'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Gender</span>

                    <strong>
                        <?= e(ucfirst($student['gender'] ?? '—')) ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Blood Group</span>

                    <strong>
                        <?= e($student['blood_group'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Birth Registration</span>

                    <strong>
                        <?= e($student['birth_registration_no'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Admission Date</span>

                    <strong>
                        <?= e($student['admission_date'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Present Address</span>

                    <strong>
                        <?= nl2br(e($student['present_address'] ?: '—')) ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Permanent Address</span>

                    <strong>
                        <?= nl2br(e($student['permanent_address'] ?: '—')) ?>
                    </strong>

                </div>

            </div>

        </div>


        <!-- GUARDIAN AND ACADEMIC INFORMATION -->

        <div class="col-lg-6">


            <!-- GUARDIAN INFORMATION -->

            <div class="form-panel mb-4">

                <h5 class="mb-3">

                    <i class="fa-solid fa-people-roof text-primary"></i>

                    Guardian Information

                </h5>


                <div class="profile-row">

                    <span>Guardian Name</span>

                    <strong>
                        <?= e($student['guardian_name'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Relationship</span>

                    <strong>
                        <?= e($student['relationship'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Phone</span>

                    <strong>
                        <?= e($student['guardian_phone'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Email</span>

                    <strong>
                        <?= e($student['guardian_email'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Occupation</span>

                    <strong>
                        <?= e($student['guardian_occupation'] ?: '—') ?>
                    </strong>

                </div>

            </div>


            <!-- ACADEMIC ENROLLMENT -->

            <div class="form-panel">

                <h5 class="mb-3">

                    <i class="fa-solid fa-graduation-cap text-primary"></i>

                    Current Academic Enrollment

                </h5>


                <div class="profile-row">

                    <span>Academic Year</span>

                    <strong>
                        <?= e($student['year_name'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Class</span>

                    <strong>
                        <?= e($student['class_name_en'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Section</span>

                    <strong>
                        <?= e($student['section_name_en'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Roll Number</span>

                    <strong>
                        <?= e($student['roll_no'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Shift</span>

                    <strong>
                        <?= e($student['shift_name_en'] ?: '—') ?>
                    </strong>

                </div>


                <div class="profile-row">

                    <span>Enrollment Date</span>

                    <strong>
                        <?= e($student['enrollment_date'] ?: '—') ?>
                    </strong>

                </div>

            </div>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
