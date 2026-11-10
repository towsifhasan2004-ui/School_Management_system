
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('attendance.view');

date_default_timezone_set('Asia/Dhaka');

$canTakeAttendance = has_permission('attendance.take');

/*
|--------------------------------------------------------------------------
| CURRENT ACADEMIC YEAR
|--------------------------------------------------------------------------
*/

$yearQuery = $conn->query("
    SELECT id, year_name
    FROM academic_years
    WHERE is_current = 1
    LIMIT 1
");

$currentYear = $yearQuery->fetch_assoc();

if (!$currentYear) {
    exit('No current academic year found.');
}

$academicYearId = (int)$currentYear['id'];

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$sectionId = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT) ?: 0;
$shiftId = filter_input(INPUT_GET, 'shift_id', FILTER_VALIDATE_INT) ?: 0;

$attendanceDate = $_GET['attendance_date'] ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('!Y-m-d', $attendanceDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $attendanceDate) {
    $attendanceDate = date('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| LOAD CLASSES
|--------------------------------------------------------------------------
*/

$classes = [];

$result = $conn->query("
    SELECT id, class_name_en
    FROM classes
    WHERE status = 'active'
    ORDER BY id ASC
");

while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

/*
|--------------------------------------------------------------------------
| LOAD SECTIONS
|--------------------------------------------------------------------------
*/

$sections = [];

$result = $conn->query("
    SELECT id, section_name_en, class_id
    FROM sections
    WHERE status = 'active'
    ORDER BY class_id, id
");

while ($row = $result->fetch_assoc()) {
    $sections[] = $row;
}

/*
|--------------------------------------------------------------------------
| LOAD SHIFTS
|--------------------------------------------------------------------------
*/

$shifts = [];

$result = $conn->query("
    SELECT id, shift_name_en
    FROM shifts
    WHERE status = 'active'
    ORDER BY id
");

while ($row = $result->fetch_assoc()) {
    $shifts[] = $row;
}

/*
|--------------------------------------------------------------------------
| VALIDATE CLASS AND SECTION
|--------------------------------------------------------------------------
*/

$classExists = false;

foreach ($classes as $class) {
    if ((int)$class['id'] === $classId) {
        $classExists = true;
        break;
    }
}

$sectionValid = false;

if ($classExists && $sectionId > 0) {

    foreach ($sections as $section) {

        if (
            (int)$section['id'] === $sectionId &&
            (int)$section['class_id'] === $classId
        ) {
            $sectionValid = true;
            break;
        }
    }
}

$sectionMismatch = (
    $classId > 0 &&
    $sectionId > 0 &&
    !$sectionValid
);

if (!$classExists) {
    $classId = 0;
}

if (!$sectionValid) {
    $sectionId = 0;
}

/*
|--------------------------------------------------------------------------
| LOAD STUDENTS AND ATTENDANCE
|--------------------------------------------------------------------------
*/

$students = [];
$attendanceLoaded = false;

if ($classId > 0 && $sectionId > 0 && $shiftId > 0) {

    $stmt = $conn->prepare("
        SELECT
            e.id AS enrollment_id,
            e.roll_no,
            s.id AS student_id,
            s.admission_no,
            s.first_name_en,
            s.last_name_en,
            s.full_name_bn,
            s.photo_path,
            a.attendance_status,
            a.check_in_time,
            a.remarks

        FROM enrollments e

        INNER JOIN students s
            ON s.id = e.student_id

        LEFT JOIN attendance a
            ON a.enrollment_id = e.id
            AND a.attendance_date = ?

        WHERE
            e.academic_year_id = ?
            AND e.class_id = ?
            AND e.section_id = ?
            AND e.shift_id = ?
            AND e.enrollment_status = 'active'
            AND s.status = 'active'

        ORDER BY
            CAST(e.roll_no AS UNSIGNED),
            s.first_name_en
    ");

    $stmt->bind_param(
        'siiii',
        $attendanceDate,
        $academicYearId,
        $classId,
        $sectionId,
        $shiftId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();

    $attendanceLoaded = true;
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$presentCount = 0;
$absentCount = 0;
$lateCount = 0;
$halfDayCount = 0;
$leaveCount = 0;
$notMarkedCount = 0;

foreach ($students as $student) {

    switch ($student['attendance_status']) {

        case 'present':
            $presentCount++;
            break;

        case 'absent':
            $absentCount++;
            break;

        case 'late':
            $lateCount++;
            break;

        case 'half_day':
            $halfDayCount++;
            break;

        case 'leave':
            $leaveCount++;
            break;

        default:
            $notMarkedCount++;
            break;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Student Attendance | Sezua International School</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/style.css"
    >

    <style>

        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .attendance-summary-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px;
            border: 1px solid #e8edf2;
        }

        .attendance-summary-card h4 {
            margin: 0;
            font-weight: 700;
        }

        .attendance-summary-card small {
            color: #6c757d;
        }

        .attendance-table th {
            background: #0b2947;
            color: #fff;
            white-space: nowrap;
        }

        .attendance-student-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .attendance-filter {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .attendance-status-select {
            min-width: 130px;
        }

        .attendance-time {
            min-width: 120px;
        }

        .attendance-remarks {
            min-width: 150px;
        }

        .attendance-table td {
            vertical-align: middle;
        }

        .form-panel {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
        }

        @media(max-width: 576px) {
            .form-panel {
                padding: 14px;
            }
        }

    </style>

</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>

            <h3 class="page-title mb-1">
                Student Attendance
            </h3>

            <p class="text-muted mb-0">
                Academic Year: <?= e($currentYear['year_name']) ?>
            </p>

        </div>

        <a
            href="<?= BASE_URL ?>/dashboard.php"
            class="btn btn-outline-secondary"
        >
            Back to Dashboard
        </a>

    </div>


    <!-- SUCCESS / ERROR MESSAGES -->

    <?php if (isset($_GET['saved'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= e($_GET['saved']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if (isset($_GET['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= e($_GET['error']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- FILTER FORM -->

    <div class="form-panel mb-4">

        <h5 class="mb-3">
            Select Attendance Class
        </h5>

        <form method="GET">

            <div class="attendance-filter">

                <div>

                    <label class="form-label">
                        Class
                    </label>

                    <select
                        name="class_id"
                        id="class_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select Class
                        </option>

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= (int)$class['id'] ?>"
                                <?= $classId === (int)$class['id'] ? 'selected' : '' ?>
                            >
                                <?= e($class['class_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div>

                    <label class="form-label">
                        Section
                    </label>

                    <select
                        name="section_id"
                        id="section_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select Section
                        </option>

                        <?php foreach ($sections as $section): ?>

                            <option
                                value="<?= (int)$section['id'] ?>"
                                data-class-id="<?= (int)$section['class_id'] ?>"
                                <?= $sectionId === (int)$section['id'] ? 'selected' : '' ?>
                            >
                                <?= e($section['section_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div>

                    <label class="form-label">
                        Shift
                    </label>

                    <select
                        name="shift_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select Shift
                        </option>

                        <?php foreach ($shifts as $shift): ?>

                            <option
                                value="<?= (int)$shift['id'] ?>"
                                <?= $shiftId === (int)$shift['id'] ? 'selected' : '' ?>
                            >
                                <?= e($shift['shift_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div>

                    <label class="form-label">
                        Attendance Date
                    </label>

                    <input
                        type="date"
                        name="attendance_date"
                        class="form-control"
                        value="<?= e($attendanceDate) ?>"
                        required
                    >

                </div>

            </div>

            <div class="mt-3">

                <button type="submit" class="btn btn-primary">
                    Load Students
                </button>

            </div>

        </form>

    </div>


    <?php if ($sectionMismatch): ?>

        <div class="alert alert-warning">
            The selected section does not belong to the selected class.
            Please select the correct section.
        </div>

    <?php endif; ?>


    <?php if ($attendanceLoaded): ?>


        <!-- SUMMARY -->

        <div class="attendance-summary">

            <div class="attendance-summary-card">
                <small>Total Students</small>
                <h4><?= count($students) ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Present</small>
                <h4 class="text-success"><?= $presentCount ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Absent</small>
                <h4 class="text-danger"><?= $absentCount ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Late</small>
                <h4 class="text-warning"><?= $lateCount ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Half Day</small>
                <h4 class="text-primary"><?= $halfDayCount ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Leave</small>
                <h4 class="text-info"><?= $leaveCount ?></h4>
            </div>

            <div class="attendance-summary-card">
                <small>Not Marked</small>
                <h4><?= $notMarkedCount ?></h4>
            </div>

        </div>


        <!-- STUDENT ATTENDANCE TABLE -->

        <div class="form-panel">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <h5 class="mb-0">
                    Student Attendance List
                </h5>

                <span class="text-muted">
                    Date: <?= e($attendanceDate) ?>
                </span>

            </div>


            <?php if (empty($students)): ?>

                <div class="alert alert-warning">
                    No active students found for this class, section and shift.
                </div>

            <?php else: ?>


                <?php if ($canTakeAttendance): ?>

                    <form
                        method="POST"
                        action="<?= BASE_URL ?>/save_attendance.php"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="class_id"
                            value="<?= $classId ?>"
                        >

                        <input
                            type="hidden"
                            name="section_id"
                            value="<?= $sectionId ?>"
                        >

                        <input
                            type="hidden"
                            name="shift_id"
                            value="<?= $shiftId ?>"
                        >

                        <input
                            type="hidden"
                            name="attendance_date"
                            value="<?= e($attendanceDate) ?>"
                        >

                <?php endif; ?>


                <div class="table-responsive">

                    <table class="table table-bordered table-hover attendance-table">

                        <thead>

                            <tr>

                                <th>Roll</th>

                                <th>Photo</th>

                                <th>Admission No.</th>

                                <th>Student Name</th>

                                <th>Attendance Status</th>

                                <th>Check-in Time</th>

                                <th>Remarks</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($students as $student): ?>

                                <?php

                                $enrollmentId = (int)$student['enrollment_id'];

                                $currentStatus = $student['attendance_status'] ?? '';

                                $currentTime = $student['check_in_time']
                                    ? substr($student['check_in_time'], 0, 5)
                                    : '';

                                $currentRemarks = $student['remarks'] ?? '';

                                $studentPhoto = $student['photo_path'] ?? '';

                                $validPhoto = (
                                    $studentPhoto !== '' &&
                                    strpos($studentPhoto, 'uploads/student_photos/') === 0 &&
                                    dirname($studentPhoto) === 'uploads/student_photos' &&
                                    is_file(__DIR__ . '/' . $studentPhoto)
                                );

                                ?>

                                <tr>

                                    <td>
                                        <?= e($student['roll_no']) ?>
                                    </td>


                                    <td>

                                        <?php if ($validPhoto): ?>

                                            <img
                                                src="<?= BASE_URL . '/' . e($studentPhoto) ?>"
                                                class="attendance-student-photo"
                                                alt="Student"
                                            >

                                        <?php else: ?>

                                            <span class="text-muted">—</span>

                                        <?php endif; ?>

                                    </td>


                                    <td>
                                        <?= e($student['admission_no']) ?>
                                    </td>


                                    <td>

                                        <strong>
                                            <?= e(trim(
                                                $student['first_name_en'] . ' ' .
                                                $student['last_name_en']
                                            )) ?>
                                        </strong>

                                        <?php if ($student['full_name_bn']): ?>

                                            <br>

                                            <small class="text-muted">
                                                <?= e($student['full_name_bn']) ?>
                                            </small>

                                        <?php endif; ?>

                                    </td>


                                    <!-- ATTENDANCE STATUS -->

                                    <td>

                                        <?php if ($canTakeAttendance): ?>

                                            <select
                                                name="attendance[<?= $enrollmentId ?>][status]"
                                                class="form-select attendance-status-select"
                                            >

                                                <option value="">
                                                    Not Marked
                                                </option>

                                                <option
                                                    value="present"
                                                    <?= $currentStatus === 'present' ? 'selected' : '' ?>
                                                >
                                                    Present
                                                </option>

                                                <option
                                                    value="absent"
                                                    <?= $currentStatus === 'absent' ? 'selected' : '' ?>
                                                >
                                                    Absent
                                                </option>

                                                <option
                                                    value="late"
                                                    <?= $currentStatus === 'late' ? 'selected' : '' ?>
                                                >
                                                    Late
                                                </option>

                                                <option
                                                    value="half_day"
                                                    <?= $currentStatus === 'half_day' ? 'selected' : '' ?>
                                                >
                                                    Half Day
                                                </option>

                                                <option
                                                    value="leave"
                                                    <?= $currentStatus === 'leave' ? 'selected' : '' ?>
                                                >
                                                    Leave
                                                </option>

                                            </select>

                                        <?php else: ?>

                                            <?= e(ucfirst(str_replace('_', ' ', $currentStatus ?: 'Not Marked'))) ?>

                                        <?php endif; ?>

                                    </td>


                                    <!-- CHECK-IN TIME -->

                                    <td>

                                        <?php if ($canTakeAttendance): ?>

                                            <input
                                                type="time"
                                                name="attendance[<?= $enrollmentId ?>][check_in_time]"
                                                class="form-control attendance-time"
                                                value="<?= e($currentTime) ?>"
                                            >

                                        <?php else: ?>

                                            <?= e($currentTime ?: '—') ?>

                                        <?php endif; ?>

                                    </td>


                                    <!-- REMARKS -->

                                    <td>

                                        <?php if ($canTakeAttendance): ?>

                                            <input
                                                type="text"
                                                name="attendance[<?= $enrollmentId ?>][remarks]"
                                                class="form-control attendance-remarks"
                                                maxlength="500"
                                                placeholder="Optional remarks"
                                                value="<?= e($currentRemarks) ?>"
                                            >

                                        <?php else: ?>

                                            <?= e($currentRemarks ?: '—') ?>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <?php if ($canTakeAttendance): ?>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">

                        <small class="text-muted">
                            Previously saved attendance can be edited and saved again.
                        </small>

                        <button
                            type="submit"
                            class="btn btn-success"
                            onclick="return confirm('Save attendance for this class?');"
                        >
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Attendance
                        </button>

                    </div>

                    </form>

                <?php endif; ?>


            <?php endif; ?>

        </div>


    <?php elseif (!$sectionMismatch): ?>

        <div class="form-panel text-center py-5">

            <h5>Select Class, Section and Shift</h5>

            <p class="text-muted mb-0">
                Choose the attendance date and load students.
            </p>

        </div>

    <?php endif; ?>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const classSelect = document.getElementById('class_id');
    const sectionSelect = document.getElementById('section_id');

    function filterSections(resetSelection = false) {

        const selectedClass = classSelect.value;

        if (resetSelection) {
            sectionSelect.value = '';
        }

        let selectedSectionIsValid = false;

        Array.from(sectionSelect.options).forEach(function (option) {

            if (option.value === '') {
                option.hidden = false;
                return;
            }

            const shouldShow = (
                selectedClass !== '' &&
                option.dataset.classId === selectedClass
            );

            option.hidden = !shouldShow;
            option.disabled = !shouldShow;

            if (
                shouldShow &&
                option.value === sectionSelect.value
            ) {
                selectedSectionIsValid = true;
            }

        });

        if (!selectedSectionIsValid) {
            sectionSelect.value = '';
        }

    }

    filterSections(false);

    classSelect.addEventListener('change', function () {
        filterSections(true);
    });

});

</script>

</body>
</html>
