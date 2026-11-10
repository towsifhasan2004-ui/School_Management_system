
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('exams.manage');

date_default_timezone_set('Asia/Dhaka');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int)($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| CURRENT ACADEMIC YEAR
|--------------------------------------------------------------------------
*/

$yearResult = $conn->query("
    SELECT id, year_name
    FROM academic_years
    WHERE is_current = 1
    LIMIT 1
");

$currentYear = $yearResult->fetch_assoc();

if (!$currentYear) {
    exit('No current academic year found.');
}

$academicYearId = (int)$currentYear['id'];

/*
|--------------------------------------------------------------------------
| LOAD DROPDOWN DATA
|--------------------------------------------------------------------------
*/

$examTypes = [];
$classes = [];
$shifts = [];
$gradingScales = [];

$result = $conn->query("
    SELECT id, type_name_en
    FROM examination_types
    WHERE status = 'active'
    ORDER BY id
");

while ($row = $result->fetch_assoc()) {
    $examTypes[] = $row;
}

$result = $conn->query("
    SELECT id, class_name_en
    FROM classes
    WHERE status = 'active'
    ORDER BY class_order
");

while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

$result = $conn->query("
    SELECT id, shift_name_en
    FROM shifts
    WHERE status = 'active'
    ORDER BY id
");

while ($row = $result->fetch_assoc()) {
    $shifts[] = $row;
}

$stmt = $conn->prepare("
    SELECT id, scale_name
    FROM grading_scales
    WHERE status = 'active'
      AND (academic_year_id = ? OR academic_year_id IS NULL)
    ORDER BY is_default DESC, id
");

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $gradingScales[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| CREATE EXAMINATION
|--------------------------------------------------------------------------
*/

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        verify_csrf();

        $examName = trim($_POST['examination_name_en'] ?? '');
        $examNameBn = trim($_POST['examination_name_bn'] ?? '');

        $examTypeId = (int)($_POST['examination_type_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $shiftId = (int)($_POST['shift_id'] ?? 0);
        $gradingScaleId = (int)($_POST['grading_scale_id'] ?? 0);

        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';

        /*
        | Validate required fields
        */

        if ($examName === '' || strlen($examName) > 150) {
            throw new Exception('Enter a valid examination name (maximum 150 characters).');
        }

        if (strlen($examNameBn) > 150) {
            throw new Exception('Bangla examination name is too long.');
        }

        $startObject = DateTime::createFromFormat('!Y-m-d', $startDate);
        $endObject = DateTime::createFromFormat('!Y-m-d', $endDate);

        if (
            !$startObject ||
            $startObject->format('Y-m-d') !== $startDate ||
            !$endObject ||
            $endObject->format('Y-m-d') !== $endDate
        ) {
            throw new Exception('Please select valid examination dates.');
        }

        if ($endDate < $startDate) {
            throw new Exception('End date cannot be earlier than start date.');
        }

        /*
        | Validate examination type
        */

        if ($examTypeId > 0) {

            $check = $conn->prepare("
                SELECT id
                FROM examination_types
                WHERE id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $check->bind_param('i', $examTypeId);
            $check->execute();

            if ($check->get_result()->num_rows !== 1) {
                throw new Exception('Invalid examination type.');
            }

            $check->close();

        } else {
            $examTypeId = null;
        }

        /*
        | Validate class
        */

        $check = $conn->prepare("
            SELECT id
            FROM classes
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $check->bind_param('i', $classId);
        $check->execute();

        if ($check->get_result()->num_rows !== 1) {
            throw new Exception('Please select a valid class.');
        }

        $check->close();

        /*
        | Validate shift
        */

        if ($shiftId > 0) {

            $check = $conn->prepare("
                SELECT id
                FROM shifts
                WHERE id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $check->bind_param('i', $shiftId);
            $check->execute();

            if ($check->get_result()->num_rows !== 1) {
                throw new Exception('Invalid shift selected.');
            }

            $check->close();

        } else {
            $shiftId = null;
        }

        /*
        | Validate grading scale
        */

        if ($gradingScaleId > 0) {

            $check = $conn->prepare("
                SELECT id
                FROM grading_scales
                WHERE id = ?
                  AND status = 'active'
                  AND (academic_year_id = ? OR academic_year_id IS NULL)
                LIMIT 1
            ");

            $check->bind_param(
                'ii',
                $gradingScaleId,
                $academicYearId
            );

            $check->execute();

            if ($check->get_result()->num_rows !== 1) {
                throw new Exception('Invalid grading scale selected.');
            }

            $check->close();

        } else {
            $gradingScaleId = null;
        }

        /*
        | Save examination
        */

        $examNameBn = $examNameBn !== '' ? $examNameBn : null;

        $stmt = $conn->prepare("
            INSERT INTO examinations (
                academic_year_id,
                examination_type_id,
                class_id,
                shift_id,
                examination_name_en,
                examination_name_bn,
                start_date,
                end_date,
                grading_scale_id,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iiiissssii',
            $academicYearId,
            $examTypeId,
            $classId,
            $shiftId,
            $examName,
            $examNameBn,
            $startDate,
            $endDate,
            $gradingScaleId,
            $userId
        );

        $stmt->execute();

        $newExamId = $conn->insert_id;

        $stmt->close();

        header(
            'Location: ' . BASE_URL .
            '/examinations.php?saved=1'
        );

        exit;

    } catch (Throwable $e) {

        error_log('Examination Creation Error: ' . $e->getMessage());

        $error = $e instanceof mysqli_sql_exception
            ? 'Could not create the examination. Please check the database and selected information.'
            : $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| LOAD EXAMINATION LIST
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        ex.id,
        ex.examination_name_en,
        ex.examination_name_bn,
        ex.start_date,
        ex.end_date,
        ex.result_status,

        et.type_name_en,
        c.class_name_en,
        sh.shift_name_en,
        gs.scale_name

    FROM examinations ex

    INNER JOIN classes c
        ON c.id = ex.class_id

    LEFT JOIN examination_types et
        ON et.id = ex.examination_type_id

    LEFT JOIN shifts sh
        ON sh.id = ex.shift_id

    LEFT JOIN grading_scales gs
        ON gs.id = ex.grading_scale_id

    WHERE ex.academic_year_id = ?

    ORDER BY ex.start_date DESC, ex.id DESC
");

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();

$examinations = [];

while ($row = $result->fetch_assoc()) {
    $examinations[] = $row;
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Examination Management | Sezua International School</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f4f6f9;
        }

        .page-panel {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            border: 1px solid #e8edf2;
            margin-bottom: 22px;
        }

        .page-heading {
            color: #0b2947;
            font-weight: 700;
        }

        .exam-table th {
            background: #0b2947;
            color: #fff;
            white-space: nowrap;
        }

        .exam-table td {
            vertical-align: middle;
        }

        .status-badge {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-draft {
            background: #e9ecef;
            color: #495057;
        }

        .status-marks_entry {
            background: #cff4fc;
            color: #055160;
        }

        .status-under_review {
            background: #fff3cd;
            color: #664d03;
        }

        .status-published {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-archived {
            background: #f8d7da;
            color: #842029;
        }

    </style>

</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>

            <h3 class="page-heading mb-1">
                Examination Management
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


    <!-- SUCCESS MESSAGE -->

    <?php if (isset($_GET['saved'])): ?>

        <div class="alert alert-success">
            Examination created successfully.
        </div>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->

    <?php if ($error !== ''): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <!-- CREATE EXAMINATION -->

    <div class="page-panel">

        <h5 class="mb-3">
            Create New Examination
        </h5>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label">
                        Examination Name (English) *
                    </label>

                    <input
                        type="text"
                        name="examination_name_en"
                        class="form-control"
                        maxlength="150"
                        placeholder="Example: Annual Examination 2026"
                        value="<?= e($_POST['examination_name_en'] ?? '') ?>"
                        required
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">
                        Examination Name (Bangla)
                    </label>

                    <input
                        type="text"
                        name="examination_name_bn"
                        class="form-control"
                        maxlength="150"
                        placeholder="পরীক্ষার নাম"
                        value="<?= e($_POST['examination_name_bn'] ?? '') ?>"
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Examination Type
                    </label>

                    <select
                        name="examination_type_id"
                        class="form-select"
                    >

                        <option value="">
                            Select Type
                        </option>

                        <?php foreach ($examTypes as $type): ?>

                            <option
                                value="<?= (int)$type['id'] ?>"
                                <?= (int)($_POST['examination_type_id'] ?? 0) === (int)$type['id'] ? 'selected' : '' ?>
                            >
                                <?= e($type['type_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Class *
                    </label>

                    <select
                        name="class_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select Class
                        </option>

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= (int)$class['id'] ?>"
                                <?= (int)($_POST['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>
                            >
                                <?= e($class['class_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Shift
                    </label>

                    <select
                        name="shift_id"
                        class="form-select"
                    >

                        <option value="">
                            All Shifts
                        </option>

                        <?php foreach ($shifts as $shift): ?>

                            <option
                                value="<?= (int)$shift['id'] ?>"
                                <?= (int)($_POST['shift_id'] ?? 0) === (int)$shift['id'] ? 'selected' : '' ?>
                            >
                                <?= e($shift['shift_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Grading Scale
                    </label>

                    <select
                        name="grading_scale_id"
                        class="form-select"
                    >

                        <option value="">
                            Select Grading Scale
                        </option>

                        <?php foreach ($gradingScales as $scale): ?>

                            <option
                                value="<?= (int)$scale['id'] ?>"
                                <?= (int)($_POST['grading_scale_id'] ?? 0) === (int)$scale['id'] ? 'selected' : '' ?>
                            >
                                <?= e($scale['scale_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        Start Date *
                    </label>

                    <input
                        type="date"
                        name="start_date"
                        class="form-control"
                        value="<?= e($_POST['start_date'] ?? '') ?>"
                        required
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        End Date *
                    </label>

                    <input
                        type="date"
                        name="end_date"
                        class="form-control"
                        value="<?= e($_POST['end_date'] ?? '') ?>"
                        required
                    >

                </div>

            </div>


            <div class="mt-4">

                <button
                    type="submit"
                    class="btn btn-success"
                >
                    Create Examination
                </button>

            </div>

        </form>

    </div>


    <!-- EXAMINATION LIST -->

    <div class="page-panel">

        <h5 class="mb-3">
            Examination List
        </h5>

        <div class="table-responsive">

            <table class="table table-bordered table-hover exam-table">

                <thead>

                    <tr>

                        <th>#</th>
                        <th>Examination</th>
                        <th>Type</th>
                        <th>Class</th>
                        <th>Shift</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Grading Scale</th>
                        <th>Status</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (empty($examinations)): ?>

                        <tr>

                            <td colspan="9" class="text-center py-4">
                                No examinations created yet.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($examinations as $index => $exam): ?>

                            <?php
                            $statusClass = 'status-' . $exam['result_status'];
                            ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= e($exam['examination_name_en']) ?>
                                    </strong>

                                    <?php if ($exam['examination_name_bn']): ?>

                                        <br>

                                        <small class="text-muted">
                                            <?= e($exam['examination_name_bn']) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= e($exam['type_name_en'] ?? 'Not specified') ?>
                                </td>

                                <td>
                                    <?= e($exam['class_name_en']) ?>
                                </td>

                                <td>
                                    <?= e($exam['shift_name_en'] ?? 'All Shifts') ?>
                                </td>

                                <td>
                                    <?= e($exam['start_date']) ?>
                                </td>

                                <td>
                                    <?= e($exam['end_date']) ?>
                                </td>

                                <td>
                                    <?= e($exam['scale_name'] ?? 'Not assigned') ?>
                                </td>

                                <td>

                                    <span class="status-badge <?= e($statusClass) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $exam['result_status']))) ?>
                                    </span>

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
