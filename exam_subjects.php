
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('exams.manage');

date_default_timezone_set('Asia/Dhaka');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$academicYearId = 0;
$userId = (int)($_SESSION['user_id'] ?? 0);

$yearResult = $conn->query("
    SELECT id
    FROM academic_years
    WHERE is_current = 1
    LIMIT 1
");

$currentYear = $yearResult->fetch_assoc();

if (!$currentYear) {
    exit('No current academic year found.');
}

$academicYearId = (int)$currentYear['id'];

$error = '';

/*
|--------------------------------------------------------------------------
| SELECTED EXAMINATION
|--------------------------------------------------------------------------
*/

$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);

$exam = null;

if ($examId > 0) {

    $stmt = $conn->prepare("
        SELECT
            ex.id,
            ex.academic_year_id,
            ex.class_id,
            ex.examination_name_en,
            ex.start_date,
            ex.end_date,
            ex.result_status,
            c.class_name_en

        FROM examinations ex

        INNER JOIN classes c
            ON c.id = ex.class_id

        WHERE ex.id = ?
          AND ex.academic_year_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('ii', $examId, $academicYearId);
    $stmt->execute();

    $exam = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| LOAD EXAMINATIONS
|--------------------------------------------------------------------------
*/

$examinations = [];

$stmt = $conn->prepare("
    SELECT
        ex.id,
        ex.examination_name_en,
        ex.result_status,
        c.class_name_en

    FROM examinations ex

    INNER JOIN classes c
        ON c.id = ex.class_id

    WHERE ex.academic_year_id = ?

    ORDER BY ex.start_date DESC, ex.id DESC
");

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $examinations[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| LOAD SUBJECTS ASSIGNED TO SELECTED CLASS
|--------------------------------------------------------------------------
*/

$availableSubjects = [];

if ($exam) {

    $stmt = $conn->prepare("
        SELECT
            cs.subject_id,
            s.subject_code,
            s.subject_name_en,
            s.subject_name_bn,
            cs.full_marks AS default_full_marks,
            cs.pass_marks AS default_pass_marks

        FROM class_subjects cs

        INNER JOIN subjects s
            ON s.id = cs.subject_id

        WHERE cs.academic_year_id = ?
          AND cs.class_id = ?
          AND s.status = 'active'

          AND NOT EXISTS (
              SELECT 1
              FROM exam_subjects es
              WHERE es.examination_id = ?
                AND es.subject_id = cs.subject_id
          )

        ORDER BY s.subject_name_en
    ");

    $stmt->bind_param(
        'iii',
        $academicYearId,
        $exam['class_id'],
        $examId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $availableSubjects[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| ADD SUBJECT TO EXAMINATION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        verify_csrf();

        if (!$exam) {
            throw new Exception('Please select a valid examination.');
        }

        if ($exam['result_status'] !== 'draft') {
            throw new Exception(
                'Subjects can only be added while the examination is in Draft status.'
            );
        }

        $subjectId = (int)($_POST['subject_id'] ?? 0);

        $fullMarks = (float)($_POST['full_marks'] ?? 0);
        $passMarks = (float)($_POST['pass_marks'] ?? 0);

        $writtenMarks = (float)($_POST['written_full_marks'] ?? 0);
        $mcqMarks = (float)($_POST['mcq_full_marks'] ?? 0);
        $practicalMarks = (float)($_POST['practical_full_marks'] ?? 0);

        $examDate = trim($_POST['exam_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');

        /*
        | Validate subject assignment
        */

        $check = $conn->prepare("
            SELECT id
            FROM class_subjects
            WHERE academic_year_id = ?
              AND class_id = ?
              AND subject_id = ?
            LIMIT 1
        ");

        $check->bind_param(
            'iii',
            $academicYearId,
            $exam['class_id'],
            $subjectId
        );

        $check->execute();

        if ($check->get_result()->num_rows !== 1) {
            throw new Exception(
                'This subject is not assigned to the selected class for this academic year.'
            );
        }

        $check->close();

        /*
        | Validate marks
        */

        if (
            !is_finite($fullMarks) ||
            !is_finite($passMarks) ||
            !is_finite($writtenMarks) ||
            !is_finite($mcqMarks) ||
            !is_finite($practicalMarks)
        ) {
            throw new Exception('Invalid marks entered.');
        }

        if ($fullMarks <= 0) {
            throw new Exception('Full marks must be greater than zero.');
        }

        if ($passMarks < 0 || $passMarks > $fullMarks) {
            throw new Exception('Pass marks must be between zero and full marks.');
        }

        if (
            $writtenMarks < 0 ||
            $mcqMarks < 0 ||
            $practicalMarks < 0
        ) {
            throw new Exception('Component marks cannot be negative.');
        }

        $componentTotal = $writtenMarks + $mcqMarks + $practicalMarks;

        if ($componentTotal > $fullMarks) {
            throw new Exception(
                'Written, MCQ and practical full marks cannot exceed total full marks.'
            );
        }

        /*
        | Validate optional exam date
        */

        if ($examDate !== '') {

            $dateObject = DateTime::createFromFormat('!Y-m-d', $examDate);

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $examDate
            ) {
                throw new Exception('Invalid subject examination date.');
            }

            if (
                $examDate < $exam['start_date'] ||
                $examDate > $exam['end_date']
            ) {
                throw new Exception(
                    'Subject examination date must fall within the examination date range.'
                );
            }

        } else {
            $examDate = null;
        }

        /*
        | Validate optional times
        */

        foreach (['startTime', 'endTime'] as $timeVariable) {

            if ($$timeVariable !== '') {

                $timeObject = DateTime::createFromFormat(
                    '!H:i',
                    $$timeVariable
                );

                if (
                    !$timeObject ||
                    $timeObject->format('H:i') !== $$timeVariable
                ) {
                    throw new Exception('Invalid examination time.');
                }

                $$timeVariable .= ':00';

            } else {
                $$timeVariable = null;
            }
        }

        if (
            $startTime !== null &&
            $endTime !== null &&
            $endTime <= $startTime
        ) {
            throw new Exception('End time must be later than start time.');
        }

        /*
        | Insert examination subject
        */

        $stmt = $conn->prepare("
            INSERT INTO exam_subjects (
                examination_id,
                subject_id,
                full_marks,
                pass_marks,
                written_full_marks,
                mcq_full_marks,
                practical_full_marks,
                exam_date,
                start_time,
                end_time
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iidddddsss',
            $examId,
            $subjectId,
            $fullMarks,
            $passMarks,
            $writtenMarks,
            $mcqMarks,
            $practicalMarks,
            $examDate,
            $startTime,
            $endTime
        );

        $stmt->execute();
        $stmt->close();

        header(
            'Location: ' . BASE_URL .
            '/exam_subjects.php?exam_id=' . $examId .
            '&saved=1'
        );

        exit;

    } catch (Throwable $e) {

        error_log('Exam Subject Setup Error: ' . $e->getMessage());

        $error = $e instanceof mysqli_sql_exception
            ? 'Could not add the subject. It may already exist in this examination.'
            : $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ADDED SUBJECTS
|--------------------------------------------------------------------------
*/

$examSubjects = [];

if ($exam) {

    $stmt = $conn->prepare("
        SELECT
            es.id,
            es.subject_id,
            es.full_marks,
            es.pass_marks,
            es.written_full_marks,
            es.mcq_full_marks,
            es.practical_full_marks,
            es.exam_date,
            es.start_time,
            es.end_time,
            s.subject_code,
            s.subject_name_en,
            s.subject_name_bn

        FROM exam_subjects es

        INNER JOIN subjects s
            ON s.id = es.subject_id

        WHERE es.examination_id = ?

        ORDER BY s.subject_name_en
    ");

    $stmt->bind_param('i', $examId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $examSubjects[] = $row;
    }

    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Exam Subject Setup | Sezua International School</title>

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
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #e9ecef;
            color: #495057;
            font-size: 12px;
        }

    </style>

</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>

            <h3 class="page-heading mb-1">
                Exam Subject Setup
            </h3>

            <p class="text-muted mb-0">
                Configure subjects and marks for examinations.
            </p>

        </div>

        <a
            href="<?= BASE_URL ?>/examinations.php"
            class="btn btn-outline-secondary"
        >
            Back to Examinations
        </a>

    </div>


    <?php if (isset($_GET['saved'])): ?>

        <div class="alert alert-success">
            Subject added to the examination successfully.
        </div>

    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <!-- SELECT EXAMINATION -->

    <div class="page-panel">

        <h5 class="mb-3">
            Select Examination
        </h5>

        <form method="GET">

            <div class="row g-3 align-items-end">

                <div class="col-md-8">

                    <label class="form-label">
                        Examination
                    </label>

                    <select
                        name="exam_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select Examination
                        </option>

                        <?php foreach ($examinations as $item): ?>

                            <option
                                value="<?= (int)$item['id'] ?>"
                                <?= $examId === (int)$item['id'] ? 'selected' : '' ?>
                            >
                                <?= e($item['examination_name_en']) ?>
                                — <?= e($item['class_name_en']) ?>
                                (<?= e(ucwords(str_replace('_', ' ', $item['result_status']))) ?>)
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <button type="submit" class="btn btn-primary">
                        Load Examination
                    </button>

                </div>

            </div>

        </form>

    </div>


    <?php if ($exam): ?>


        <!-- EXAM DETAILS -->

        <div class="page-panel">

            <h5>
                <?= e($exam['examination_name_en']) ?>
            </h5>

            <p class="mb-1">
                <strong>Class:</strong>
                <?= e($exam['class_name_en']) ?>
            </p>

            <p class="mb-1">
                <strong>Exam Period:</strong>
                <?= e($exam['start_date']) ?>
                to
                <?= e($exam['end_date']) ?>
            </p>

            <p class="mb-0">
                <strong>Status:</strong>
                <span class="status-badge">
                    <?= e(ucwords(str_replace('_', ' ', $exam['result_status']))) ?>
                </span>
            </p>

        </div>


        <?php if ($exam['result_status'] === 'draft'): ?>


            <!-- ADD SUBJECT -->

            <div class="page-panel">

                <h5 class="mb-3">
                    Add Subject and Configure Marks
                </h5>

                <?php if (empty($availableSubjects)): ?>

                    <div class="alert alert-warning">

                        No available subjects found.

                        Please check that subjects have been assigned to this class
                        for the current academic year in `class_subjects`.

                    </div>

                <?php else: ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="exam_id"
                            value="<?= $examId ?>"
                        >

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Subject *
                                </label>

                                <select
                                    name="subject_id"
                                    id="subject_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Subject
                                    </option>

                                    <?php foreach ($availableSubjects as $subject): ?>

                                        <option
                                            value="<?= (int)$subject['subject_id'] ?>"
                                            data-full="<?= e($subject['default_full_marks']) ?>"
                                            data-pass="<?= e($subject['default_pass_marks']) ?>"
                                        >
                                            <?= e($subject['subject_code']) ?>
                                            -
                                            <?= e($subject['subject_name_en']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <div class="col-md-3">

                                <label class="form-label">
                                    Full Marks *
                                </label>

                                <input
                                    type="number"
                                    name="full_marks"
                                    id="full_marks"
                                    class="form-control"
                                    min="0.01"
                                    step="0.01"
                                    required
                                >

                            </div>


                            <div class="col-md-3">

                                <label class="form-label">
                                    Pass Marks *
                                </label>

                                <input
                                    type="number"
                                    name="pass_marks"
                                    id="pass_marks"
                                    class="form-control"
                                    min="0"
                                    step="0.01"
                                    required
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    Written Full Marks
                                </label>

                                <input
                                    type="number"
                                    name="written_full_marks"
                                    class="form-control"
                                    value="0"
                                    min="0"
                                    step="0.01"
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    MCQ Full Marks
                                </label>

                                <input
                                    type="number"
                                    name="mcq_full_marks"
                                    class="form-control"
                                    value="0"
                                    min="0"
                                    step="0.01"
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    Practical Full Marks
                                </label>

                                <input
                                    type="number"
                                    name="practical_full_marks"
                                    class="form-control"
                                    value="0"
                                    min="0"
                                    step="0.01"
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    Subject Exam Date
                                </label>

                                <input
                                    type="date"
                                    name="exam_date"
                                    class="form-control"
                                    min="<?= e($exam['start_date']) ?>"
                                    max="<?= e($exam['end_date']) ?>"
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    Start Time
                                </label>

                                <input
                                    type="time"
                                    name="start_time"
                                    class="form-control"
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label">
                                    End Time
                                </label>

                                <input
                                    type="time"
                                    name="end_time"
                                    class="form-control"
                                >

                            </div>

                        </div>


                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                Add Subject to Examination
                            </button>

                        </div>

                    </form>

                <?php endif; ?>

            </div>


        <?php else: ?>

            <div class="alert alert-info">
                This examination is no longer in Draft status.
                Subject configuration is locked on this page.
            </div>

        <?php endif; ?>


        <!-- ADDED SUBJECTS -->

        <div class="page-panel">

            <h5 class="mb-3">
                Added Examination Subjects
            </h5>

            <div class="table-responsive">

                <table class="table table-bordered table-hover exam-table">

                    <thead>

                        <tr>

                            <th>#</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Full Marks</th>
                            <th>Pass Marks</th>
                            <th>Written</th>
                            <th>MCQ</th>
                            <th>Practical</th>
                            <th>Exam Date</th>
                            <th>Time</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($examSubjects)): ?>

                            <tr>

                                <td colspan="10" class="text-center py-4">
                                    No subjects added to this examination yet.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($examSubjects as $index => $subject): ?>

                                <tr>

                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <?= e($subject['subject_code']) ?>
                                    </td>

                                    <td>

                                        <strong>
                                            <?= e($subject['subject_name_en']) ?>
                                        </strong>

                                        <?php if ($subject['subject_name_bn']): ?>

                                            <br>

                                            <small class="text-muted">
                                                <?= e($subject['subject_name_bn']) ?>
                                            </small>

                                        <?php endif; ?>

                                    </td>

                                    <td><?= number_format((float)$subject['full_marks'], 2) ?></td>

                                    <td><?= number_format((float)$subject['pass_marks'], 2) ?></td>

                                    <td><?= number_format((float)$subject['written_full_marks'], 2) ?></td>

                                    <td><?= number_format((float)$subject['mcq_full_marks'], 2) ?></td>

                                    <td><?= number_format((float)$subject['practical_full_marks'], 2) ?></td>

                                    <td><?= e($subject['exam_date'] ?? 'Not set') ?></td>

                                    <td>

                                        <?php if ($subject['start_time'] && $subject['end_time']): ?>

                                            <?= e(substr($subject['start_time'], 0, 5)) ?>
                                            -
                                            <?= e(substr($subject['end_time'], 0, 5)) ?>

                                        <?php else: ?>

                                            Not set

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


    <?php elseif ($examId > 0): ?>

        <div class="alert alert-danger">
            Examination not found in the current academic year.
        </div>

    <?php endif; ?>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const subjectSelect = document.getElementById('subject_id');
    const fullMarksInput = document.getElementById('full_marks');
    const passMarksInput = document.getElementById('pass_marks');

    if (!subjectSelect) {
        return;
    }

    subjectSelect.addEventListener('change', function () {

        const option = this.options[this.selectedIndex];

        if (option.dataset.full) {
            fullMarksInput.value = option.dataset.full;
            passMarksInput.value = option.dataset.pass;
        }

    });

});

</script>

</body>
</html>
