
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('results.publish');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$message = '';
$messageType = 'success';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   LOAD EXAMINATIONS
========================================= */

$examinations = [];

$result = $conn->query("
    SELECT
        e.id,
        e.examination_name_en,
        e.result_status,
        c.class_name_en,
        ay.year_name
    FROM examinations e
    INNER JOIN classes c ON c.id = e.class_id
    INNER JOIN academic_years ay
        ON ay.id = e.academic_year_id
    WHERE e.result_status IN (
        'marks_entry',
        'under_review',
        'published'
    )
    ORDER BY e.start_date DESC, e.id DESC
");

while ($row = $result->fetch_assoc()) {
    $examinations[] = $row;
}

/* =========================================
   SELECT EXAMINATION
========================================= */

$selectedExamId = (int)(
    $_GET['exam_id'] ?? $_POST['exam_id'] ?? 0
);

$selectedExam = null;
$examSubjects = [];
$studentResults = [];

$totalStudents = 0;
$completeStudents = 0;
$pendingStudents = 0;
$canPublish = false;

/* =========================================
   LOAD EXAMINATION DETAILS
========================================= */

if ($selectedExamId > 0) {

    $stmt = $conn->prepare("
        SELECT
            e.*,
            c.class_name_en,
            ay.year_name
        FROM examinations e
        INNER JOIN classes c ON c.id = e.class_id
        INNER JOIN academic_years ay
            ON ay.id = e.academic_year_id
        WHERE e.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $selectedExamId);
    $stmt->execute();

    $selectedExam = $stmt->get_result()->fetch_assoc();

    if (
        !$selectedExam ||
        !in_array(
            $selectedExam['result_status'],
            ['marks_entry', 'under_review', 'published'],
            true
        )
    ) {
        $selectedExam = null;
        $selectedExamId = 0;

        $message = 'Selected examination is not available.';
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD EXAM SUBJECTS
========================================= */

if ($selectedExam) {

    $stmt = $conn->prepare("
        SELECT
            es.id,
            es.subject_id,
            es.full_marks,
            es.pass_marks,
            s.subject_name_en
        FROM exam_subjects es
        INNER JOIN subjects s
            ON s.id = es.subject_id
        WHERE es.examination_id = ?
        ORDER BY s.subject_name_en
    ");

    $stmt->bind_param('i', $selectedExamId);
    $stmt->execute();

    $examSubjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* =========================================
   LOAD STUDENTS AND SUBJECT MARKS
========================================= */

if ($selectedExam && !empty($examSubjects)) {

    $examShiftId = (int)($selectedExam['shift_id'] ?? 0);

    $sql = "
        SELECT
            en.id AS enrollment_id,
            en.roll_no,

            s.admission_no,
            s.first_name_en,
            s.last_name_en,

            es.id AS exam_subject_id,
            es.full_marks,
            es.pass_marks,
            sub.subject_name_en,

            m.id AS mark_id,
            m.total_marks,
            m.percentage,
            m.letter_grade,
            m.grade_point,
            m.result_status,
            m.verified_at

        FROM enrollments en

        INNER JOIN students s
            ON s.id = en.student_id

        INNER JOIN exam_subjects es
            ON es.examination_id = ?

        INNER JOIN subjects sub
            ON sub.id = es.subject_id

        LEFT JOIN marks m
            ON m.exam_subject_id = es.id
            AND m.enrollment_id = en.id

        WHERE en.academic_year_id = ?
          AND en.class_id = ?
          AND en.enrollment_status = 'active'
          AND s.status = 'active'
    ";

    if ($examShiftId > 0) {
        $sql .= " AND en.shift_id = ?";
    }

    $sql .= "
        ORDER BY
            CAST(en.roll_no AS UNSIGNED),
            en.roll_no,
            sub.subject_name_en
    ";

    $stmt = $conn->prepare($sql);

    if ($examShiftId > 0) {

        $stmt->bind_param(
            'iiii',
            $selectedExamId,
            $selectedExam['academic_year_id'],
            $selectedExam['class_id'],
            $examShiftId
        );

    } else {

        $stmt->bind_param(
            'iii',
            $selectedExamId,
            $selectedExam['academic_year_id'],
            $selectedExam['class_id']
        );
    }

    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* =========================================
       CALCULATE STUDENT RESULTS
    ========================================= */

    foreach ($rows as $row) {

        $enrollmentId = (int)$row['enrollment_id'];

        if (!isset($studentResults[$enrollmentId])) {

            $studentResults[$enrollmentId] = [
                'enrollment_id' => $enrollmentId,
                'roll_no' => $row['roll_no'],
                'admission_no' => $row['admission_no'],

                'student_name' =>
                    $row['first_name_en'] . ' ' .
                    $row['last_name_en'],

                'subjects' => [],

                'subject_count' => 0,
                'entered_count' => 0,
                'verified_count' => 0,

                'total_marks' => 0,
                'full_marks' => 0,

                'grade_point_total' => 0,
                'grade_point_count' => 0,

                'all_passed' => true,
                'complete' => true
            ];
        }

        $student = &$studentResults[$enrollmentId];

        $student['subject_count']++;

        $fullMarks = (float)$row['full_marks'];

        $student['full_marks'] += $fullMarks;

        $subjectStatus = 'Not Entered';
        $subjectVerified = false;

        $obtainedMarks = 0;
        $letterGrade = '-';
        $gradePoint = null;

        if ($row['mark_id'] !== null) {

            $student['entered_count']++;

            $obtainedMarks = (float)$row['total_marks'];

            $student['total_marks'] += $obtainedMarks;

            $letterGrade = $row['letter_grade'] ?? '-';

            $gradePoint = $row['grade_point'] !== null
                ? (float)$row['grade_point']
                : null;

            $subjectStatus = ucfirst($row['result_status']);

            $subjectVerified = !empty($row['verified_at']);

            if ($subjectVerified) {
                $student['verified_count']++;
            } else {
                $student['complete'] = false;
            }

            if ($row['result_status'] === 'incomplete') {
                $student['complete'] = false;
            }

            if ($row['result_status'] !== 'pass') {
                $student['all_passed'] = false;
            }

            if ($gradePoint !== null) {
                $student['grade_point_total'] += $gradePoint;
                $student['grade_point_count']++;
            }

        } else {

            $student['complete'] = false;
            $student['all_passed'] = false;
        }

        $student['subjects'][] = [
            'subject_name' => $row['subject_name_en'],
            'full_marks' => $fullMarks,
            'obtained_marks' => $obtainedMarks,
            'letter_grade' => $letterGrade,
            'grade_point' => $gradePoint,
            'status' => $subjectStatus,
            'verified' => $subjectVerified
        ];

        unset($student);
    }

    /* =========================================
       FINAL RESULT CALCULATION
    ========================================= */

    $totalStudents = count($studentResults);
    $completeStudents = 0;
    $pendingStudents = 0;

    foreach ($studentResults as &$student) {

        $student['percentage'] =
            $student['full_marks'] > 0
            ? round(
                ($student['total_marks'] / $student['full_marks']) * 100,
                2
            )
            : 0;

        $student['gpa'] = 0;

        if (
            $student['all_passed'] &&
            $student['grade_point_count'] > 0
        ) {

            $student['gpa'] = round(
                $student['grade_point_total'] /
                $student['grade_point_count'],
                2
            );
        }

        $student['final_result'] =
            $student['all_passed'] &&
            $student['subject_count'] > 0
            ? 'PASS'
            : 'FAIL';

        if (
            $student['complete'] &&
            $student['verified_count'] === $student['subject_count']
        ) {
            $completeStudents++;
        } else {
            $pendingStudents++;
        }
    }

    unset($student);

    $canPublish =
        $totalStudents > 0 &&
        $completeStudents === $totalStudents &&
        count($examSubjects) > 0;
}

/* =========================================
   PUBLISH RESULTS
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['publish_results']) &&
    $selectedExam
) {

    verify_csrf();

    if ($selectedExam['result_status'] === 'published') {

        $message = 'This examination has already been published.';
        $messageType = 'warning';

    } elseif (!$canPublish) {

        $message = 'Cannot publish. All student subject marks must be entered and verified first.';
        $messageType = 'danger';

    } else {

        $userId = (int)$_SESSION['user_id'];

        try {

            $conn->begin_transaction();

            /* Lock examination row */

            $lockExam = $conn->prepare("
                SELECT id, result_status
                FROM examinations
                WHERE id = ?
                FOR UPDATE
            ");

            $lockExam->bind_param('i', $selectedExamId);
            $lockExam->execute();

            $lockedExam = $lockExam->get_result()->fetch_assoc();

            if (
                !$lockedExam ||
                !in_array(
                    $lockedExam['result_status'],
                    ['marks_entry', 'under_review'],
                    true
                )
            ) {
                throw new Exception(
                    'Examination cannot be published in its current status.'
                );
            }

            /*
             * Recheck every expected student-subject mark
             * inside the transaction.
             */

            $examShiftId = (int)($selectedExam['shift_id'] ?? 0);

            $checkSql = "
                SELECT
                    COUNT(*) AS expected_records,

                    SUM(
                        CASE
                            WHEN m.id IS NOT NULL THEN 1
                            ELSE 0
                        END
                    ) AS entered_records,

                    SUM(
                        CASE
                            WHEN m.verified_at IS NOT NULL
                             AND m.result_status IN ('pass','fail','absent')
                            THEN 1
                            ELSE 0
                        END
                    ) AS verified_records

                FROM enrollments en

                INNER JOIN students s
                    ON s.id = en.student_id

                INNER JOIN exam_subjects es
                    ON es.examination_id = ?

                LEFT JOIN marks m
                    ON m.exam_subject_id = es.id
                    AND m.enrollment_id = en.id

                WHERE en.academic_year_id = ?
                  AND en.class_id = ?
                  AND en.enrollment_status = 'active'
                  AND s.status = 'active'
            ";

            if ($examShiftId > 0) {
                $checkSql .= " AND en.shift_id = ?";
            }

            $checkStmt = $conn->prepare($checkSql);

            if ($examShiftId > 0) {

                $checkStmt->bind_param(
                    'iiii',
                    $selectedExamId,
                    $selectedExam['academic_year_id'],
                    $selectedExam['class_id'],
                    $examShiftId
                );

            } else {

                $checkStmt->bind_param(
                    'iii',
                    $selectedExamId,
                    $selectedExam['academic_year_id'],
                    $selectedExam['class_id']
                );
            }

            $checkStmt->execute();

            $check = $checkStmt->get_result()->fetch_assoc();

            $expected = (int)($check['expected_records'] ?? 0);
            $entered = (int)($check['entered_records'] ?? 0);
            $verified = (int)($check['verified_records'] ?? 0);

            if (
                $expected <= 0 ||
                $entered !== $expected ||
                $verified !== $expected
            ) {
                throw new Exception(
                    'Some marks are missing or not verified. Please complete verification first.'
                );
            }

            /* Publish examination */

            $publishStmt = $conn->prepare("
                UPDATE examinations
                SET
                    result_status = 'published',
                    published_by = ?,
                    published_at = NOW()
                WHERE id = ?
                  AND result_status IN ('marks_entry','under_review')
            ");

            $publishStmt->bind_param(
                'ii',
                $userId,
                $selectedExamId
            );

            $publishStmt->execute();

            if ($publishStmt->affected_rows !== 1) {
                throw new Exception('Result publication could not be completed.');
            }

            $conn->commit();

            $selectedExam['result_status'] = 'published';
            $selectedExam['published_by'] = $userId;

            $message = 'Examination results published successfully.';
            $messageType = 'success';

        } catch (Throwable $e) {

            $conn->rollback();

            $message = 'Publication failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Result Processing & Publication</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f4f6fa;
            font-family: Arial, sans-serif;
        }

        .page-title {
            color: #173b60;
            font-weight: 700;
        }

        .page-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
            margin-bottom: 22px;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
            height: 100%;
        }

        .summary-label {
            color: #64748b;
            font-size: 13px;
        }

        .summary-value {
            color: #173b60;
            font-size: 25px;
            font-weight: 700;
        }

        .table thead th {
            background: #173b60;
            color: white;
            white-space: nowrap;
        }

        .result-pass {
            color: #15803d;
            font-weight: 700;
        }

        .result-fail {
            color: #dc2626;
            font-weight: 700;
        }

        .subject-details {
            min-width: 250px;
        }

        @media print {

            .no-print {
                display: none !important;
            }

            body {
                background: white;
            }

            .page-card {
                box-shadow: none;
            }
        }

    </style>

</head>

<body>

<div class="container-fluid py-4 px-3 px-lg-4">

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">

        <div>

            <h3 class="page-title mb-1">
                Result Processing & Publication
            </h3>

            <p class="text-muted mb-0">
                Calculate, review and publish examination results.
            </p>

        </div>

        <a href="dashboard.php"
           class="btn btn-outline-secondary no-print">
            Back to Dashboard
        </a>

    </div>

    <!-- MESSAGE -->

    <?php if ($message !== ''): ?>

        <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show">

            <?= h($message) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- EXAMINATION SELECT -->

    <div class="page-card no-print">

        <h5 class="mb-3">Select Examination</h5>

        <form method="GET">

            <div class="row g-3 align-items-end">

                <div class="col-md-8">

                    <label class="form-label">
                        Examination
                    </label>

                    <select name="exam_id"
                            class="form-select"
                            required>

                        <option value="">
                            Select Examination
                        </option>

                        <?php foreach ($examinations as $exam): ?>

                            <option
                                value="<?= (int)$exam['id'] ?>"
                                <?= $selectedExamId === (int)$exam['id'] ? 'selected' : '' ?>
                            >

                                <?= h($exam['examination_name_en']) ?>
                                -
                                <?= h($exam['class_name_en']) ?>
                                -
                                <?= h($exam['year_name']) ?>
                                (<?= h(ucwords(str_replace('_', ' ', $exam['result_status']))) ?>)

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <button type="submit"
                            class="btn btn-primary w-100">
                        Load Results
                    </button>

                </div>

            </div>

        </form>

    </div>

    <?php if (empty($examinations)): ?>

        <div class="alert alert-info">
            No examinations are available for result processing.
        </div>

    <?php endif; ?>

    <?php if ($selectedExam): ?>

        <!-- EXAM INFORMATION -->

        <div class="page-card">

            <h5 class="mb-3">
                Examination Information
            </h5>

            <div class="row g-3">

                <div class="col-md-3">

                    <div class="summary-box">

                        <div class="summary-label">
                            Examination
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['examination_name_en']) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <div class="summary-label">
                            Class
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['class_name_en']) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <div class="summary-label">
                            Academic Year
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['year_name']) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="summary-box">

                        <div class="summary-label">
                            Result Status
                        </div>

                        <div class="fw-bold">
                            <?= h(ucwords(str_replace('_', ' ', $selectedExam['result_status']))) ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <?php if (empty($examSubjects)): ?>

            <div class="alert alert-warning">
                No subjects have been assigned to this examination.
            </div>

        <?php elseif (empty($studentResults)): ?>

            <div class="alert alert-warning">
                No active enrolled students were found for this examination.
            </div>

        <?php else: ?>

            <!-- SUMMARY -->

            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Total Students
                        </div>

                        <div class="summary-value">
                            <?= $totalStudents ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Complete & Verified
                        </div>

                        <div class="summary-value text-success">
                            <?= $completeStudents ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Pending Students
                        </div>

                        <div class="summary-value text-warning">
                            <?= $pendingStudents ?>
                        </div>

                    </div>

                </div>

            </div>

            <!-- RESULT TABLE -->

            <div class="page-card">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                    <h5 class="mb-0">
                        Student Final Results
                    </h5>

                    <button onclick="window.print()"
                            class="btn btn-outline-primary btn-sm no-print">
                        Print Result
                    </button>

                </div>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead>

                            <tr>
                                <th>#</th>
                                <th>Roll</th>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Subjects Verified</th>
                                <th>Total Marks</th>
                                <th>Percentage</th>
                                <th>GPA</th>
                                <th>Final Result</th>
                                <th>Subject Details</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($studentResults as $index => $student): ?>

                                <tr>

                                    <td><?= $index + 1 ?></td>

                                    <td><?= h($student['roll_no']) ?></td>

                                    <td><?= h($student['admission_no']) ?></td>

                                    <td class="fw-semibold">
                                        <?= h($student['student_name']) ?>
                                    </td>

                                    <td>

                                        <?= (int)$student['verified_count'] ?>
                                        /
                                        <?= (int)$student['subject_count'] ?>

                                        <?php if ($student['complete']): ?>

                                            <span class="badge bg-success">
                                                Complete
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-warning text-dark">
                                                Pending
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="fw-bold">

                                        <?= number_format(
                                            $student['total_marks'],
                                            2
                                        ) ?>

                                        /

                                        <?= number_format(
                                            $student['full_marks'],
                                            2
                                        ) ?>

                                    </td>

                                    <td>
                                        <?= number_format(
                                            $student['percentage'],
                                            2
                                        ) ?>%
                                    </td>

                                    <td class="fw-bold">
                                        <?= number_format($student['gpa'], 2) ?>
                                    </td>

                                    <td>

                                        <?php if ($student['final_result'] === 'PASS'): ?>

                                            <span class="result-pass">
                                                PASS
                                            </span>

                                        <?php else: ?>

                                            <span class="result-fail">
                                                FAIL
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="subject-details">

                                        <details>

                                            <summary class="text-primary"
                                                     style="cursor:pointer">

                                                View Subjects
                                            </summary>

                                            <div class="mt-2">

                                                <?php foreach ($student['subjects'] as $subject): ?>

                                                    <div class="border rounded p-2 mb-2">

                                                        <strong>
                                                            <?= h($subject['subject_name']) ?>
                                                        </strong>

                                                        <div class="small text-muted">

                                                            Marks:
                                                            <?= number_format($subject['obtained_marks'], 2) ?>
                                                            /
                                                            <?= number_format($subject['full_marks'], 2) ?>

                                                            <br>

                                                            Grade:
                                                            <?= h($subject['letter_grade']) ?>

                                                            <br>

                                                            Status:
                                                            <?= h($subject['status']) ?>

                                                            <br>

                                                            Verification:
                                                            <?= $subject['verified']
                                                                ? 'Verified'
                                                                : 'Pending' ?>

                                                        </div>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        </details>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- PUBLICATION -->

                <?php if ($selectedExam['result_status'] === 'published'): ?>

                    <div class="alert alert-success mt-3">

                        <strong>Results Published</strong>

                        <p class="mb-0">
                            This examination's results have already been published.
                        </p>

                        <?php if (!empty($selectedExam['published_at'])): ?>

                            <small>
                                Published at:
                                <?= h($selectedExam['published_at']) ?>
                            </small>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div class="mt-4">

                        <?php if ($canPublish): ?>

                            <div class="alert alert-success">
                                All student subject marks have been entered
                                and verified. The examination is ready for publication.
                            </div>

                            <form method="POST"
                                  onsubmit="return confirm('Publish the final results for this examination? This action will change the examination status to Published.');">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= h(csrf_token()) ?>">

                                <input type="hidden"
                                       name="exam_id"
                                       value="<?= (int)$selectedExamId ?>">

                                <button type="submit"
                                        name="publish_results"
                                        value="1"
                                        class="btn btn-success px-4">

                                    Publish Examination Results

                                </button>

                            </form>

                        <?php else: ?>

                            <div class="alert alert-warning mb-0">

                                <strong>Publication is not available yet.</strong>

                                <p class="mb-0">
                                    Complete marks entry and verification
                                    for every subject before publishing results.
                                </p>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>
