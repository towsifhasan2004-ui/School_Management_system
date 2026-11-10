
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('marks.enter');

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
        e.class_id,
        e.academic_year_id,
        e.shift_id,
        e.result_status,
        c.class_name_en,
        ay.year_name
    FROM examinations e
    INNER JOIN classes c ON c.id = e.class_id
    INNER JOIN academic_years ay ON ay.id = e.academic_year_id
    WHERE e.result_status IN ('draft', 'marks_entry')
    ORDER BY e.start_date DESC, e.id DESC
");

while ($row = $result->fetch_assoc()) {
    $examinations[] = $row;
}

/* =========================================
   SELECTED EXAMINATION
========================================= */

$selectedExamId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$selectedExamSubjectId = (int)($_GET['exam_subject_id'] ?? $_POST['exam_subject_id'] ?? 0);

$selectedExam = null;
$examSubjects = [];
$selectedExamSubject = null;
$students = [];

/* =========================================
   LOAD SELECTED EXAMINATION
========================================= */

if ($selectedExamId > 0) {

    $stmt = $conn->prepare("
        SELECT
            e.*,
            c.class_name_en,
            ay.year_name
        FROM examinations e
        INNER JOIN classes c ON c.id = e.class_id
        INNER JOIN academic_years ay ON ay.id = e.academic_year_id
        WHERE e.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $selectedExamId);
    $stmt->execute();

    $selectedExam = $stmt->get_result()->fetch_assoc();

    if (!$selectedExam) {
        $message = 'Selected examination was not found.';
        $messageType = 'danger';
        $selectedExamId = 0;
    } elseif (!in_array($selectedExam['result_status'], ['draft', 'marks_entry'], true)) {
        $message = 'Marks entry is not allowed for this examination.';
        $messageType = 'danger';
        $selectedExamId = 0;
        $selectedExam = null;
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
            es.written_full_marks,
            es.mcq_full_marks,
            es.practical_full_marks,
            s.subject_name_en,
            s.subject_name_bn
        FROM exam_subjects es
        INNER JOIN subjects s ON s.id = es.subject_id
        WHERE es.examination_id = ?
        ORDER BY s.subject_name_en
    ");

    $stmt->bind_param('i', $selectedExamId);
    $stmt->execute();

    $examSubjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($examSubjects as $subject) {
        if ((int)$subject['id'] === $selectedExamSubjectId) {
            $selectedExamSubject = $subject;
            break;
        }
    }
}

/* =========================================
   SAVE STUDENT MARKS
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $selectedExam &&
    $selectedExamSubject
) {

    verify_csrf();

    $writtenInput = $_POST['written'] ?? [];
    $mcqInput = $_POST['mcq'] ?? [];
    $practicalInput = $_POST['practical'] ?? [];
    $absentInput = $_POST['absent'] ?? [];

    $userId = (int)$_SESSION['user_id'];

    $fullMarks = (float)$selectedExamSubject['full_marks'];
    $passMarks = (float)$selectedExamSubject['pass_marks'];

    $writtenMax = (float)$selectedExamSubject['written_full_marks'];
    $mcqMax = (float)$selectedExamSubject['mcq_full_marks'];
    $practicalMax = (float)$selectedExamSubject['practical_full_marks'];

    $gradingScaleId = (int)($selectedExam['grading_scale_id'] ?? 0);

    $gradeQuery = null;

    if ($gradingScaleId > 0) {
        $gradeQuery = $conn->prepare("
            SELECT letter_grade, grade_point
            FROM grading_scale_details
            WHERE grading_scale_id = ?
              AND ? BETWEEN minimum_percentage AND maximum_percentage
            LIMIT 1
        ");
    }

    $savedCount = 0;

    try {

        $conn->begin_transaction();

        /*
         * Check that this subject belongs to this examination.
         */
        $verifySubject = $conn->prepare("
            SELECT id
            FROM exam_subjects
            WHERE id = ?
              AND examination_id = ?
            LIMIT 1
        ");

        $verifySubject->bind_param(
            'ii',
            $selectedExamSubjectId,
            $selectedExamId
        );

        $verifySubject->execute();

        if (!$verifySubject->get_result()->fetch_assoc()) {
            throw new Exception('Invalid examination subject.');
        }

        /*
         * Prepare enrollment validation.
         */
        $checkEnrollment = $conn->prepare("
            SELECT en.id
            FROM enrollments en
            WHERE en.id = ?
              AND en.academic_year_id = ?
              AND en.class_id = ?
              AND en.enrollment_status = 'active'
              AND (
                    ? = 0
                    OR en.shift_id = ?
                  )
            LIMIT 1
        ");

        /*
         * Do not overwrite verified marks.
         */
        $checkVerified = $conn->prepare("
            SELECT id
            FROM marks
            WHERE exam_subject_id = ?
              AND enrollment_id = ?
              AND verified_at IS NOT NULL
            LIMIT 1
        ");

        /*
         * Insert or update marks.
         */
        $saveMark = $conn->prepare("
            INSERT INTO marks (
                exam_subject_id,
                enrollment_id,
                written_obtained,
                mcq_obtained,
                practical_obtained,
                total_marks,
                percentage,
                letter_grade,
                grade_point,
                result_status,
                entered_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                written_obtained = VALUES(written_obtained),
                mcq_obtained = VALUES(mcq_obtained),
                practical_obtained = VALUES(practical_obtained),
                total_marks = VALUES(total_marks),
                percentage = VALUES(percentage),
                letter_grade = VALUES(letter_grade),
                grade_point = VALUES(grade_point),
                result_status = VALUES(result_status),
                entered_by = VALUES(entered_by),
                verified_by = NULL,
                verified_at = NULL
        ");

        foreach ($writtenInput as $enrollmentKey => $writtenValue) {

            $enrollmentId = (int)$enrollmentKey;

            if ($enrollmentId <= 0) {
                continue;
            }

            $checkEnrollment->bind_param(
                'iiiii',
                $enrollmentId,
                $selectedExam['academic_year_id'],
                $selectedExam['class_id'],
                $selectedExam['shift_id'],
                $selectedExam['shift_id']
            );

            $checkEnrollment->execute();

            if (!$checkEnrollment->get_result()->fetch_assoc()) {
                throw new Exception('Invalid student enrollment found.');
            }

            $checkVerified->bind_param(
                'ii',
                $selectedExamSubjectId,
                $enrollmentId
            );

            $checkVerified->execute();

            if ($checkVerified->get_result()->fetch_assoc()) {
                throw new Exception(
                    'A student already has verified marks. Verified marks cannot be changed from this page.'
                );
            }

            $isAbsent = isset($absentInput[$enrollmentId]);

            if ($isAbsent) {
                $written = 0;
                $mcq = 0;
                $practical = 0;
                $total = 0;
                $percentage = 0;
                $letter = null;
                $gradePoint = null;
                $resultStatus = 'absent';

            } else {

                $written = (float)($writtenValue ?? 0);
                $mcq = (float)($mcqInput[$enrollmentId] ?? 0);
                $practical = (float)($practicalInput[$enrollmentId] ?? 0);

                if (
                    $written < 0 ||
                    $mcq < 0 ||
                    $practical < 0 ||
                    $written > $writtenMax ||
                    $mcq > $mcqMax ||
                    $practical > $practicalMax
                ) {
                    throw new Exception(
                        'Marks exceed the allowed component marks for enrollment ID ' . $enrollmentId
                    );
                }

                $total = $written + $mcq + $practical;

                if ($total > $fullMarks) {
                    throw new Exception(
                        'Total marks exceed full marks for enrollment ID ' . $enrollmentId
                    );
                }

                $percentage = $fullMarks > 0
                    ? round(($total / $fullMarks) * 100, 2)
                    : 0;

                $letter = null;
                $gradePoint = null;

                if ($gradeQuery) {

                    $gradeQuery->bind_param(
                        'id',
                        $gradingScaleId,
                        $percentage
                    );

                    $gradeQuery->execute();

                    $grade = $gradeQuery->get_result()->fetch_assoc();

                    if ($grade) {
                        $letter = $grade['letter_grade'];
                        $gradePoint = (float)$grade['grade_point'];
                    }
                }

                $resultStatus = ($total >= $passMarks) ? 'pass' : 'fail';
            }

            $saveMark->bind_param(
                'iidddddsdsi',
                $selectedExamSubjectId,
                $enrollmentId,
                $written,
                $mcq,
                $practical,
                $total,
                $percentage,
                $letter,
                $gradePoint,
                $resultStatus,
                $userId
            );

            $saveMark->execute();

            $savedCount++;
        }

        /*
         * Update examination status after successful entry.
         */
        $updateExam = $conn->prepare("
            UPDATE examinations
            SET result_status = 'marks_entry'
            WHERE id = ?
              AND result_status = 'draft'
        ");

        $updateExam->bind_param('i', $selectedExamId);
        $updateExam->execute();

        $conn->commit();

        $message = $savedCount . ' student mark records saved successfully.';
        $messageType = 'success';

    } catch (Throwable $e) {

        $conn->rollback();

        $message = 'Could not save marks: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD STUDENTS AND EXISTING MARKS
========================================= */

if ($selectedExam && $selectedExamSubject) {

    $sql = "
        SELECT
            en.id AS enrollment_id,
            en.roll_no,
            s.admission_no,
            s.first_name_en,
            s.last_name_en,
            m.written_obtained,
            m.mcq_obtained,
            m.practical_obtained,
            m.total_marks,
            m.result_status,
            m.verified_at
        FROM enrollments en

        INNER JOIN students s
            ON s.id = en.student_id

        LEFT JOIN marks m
            ON m.enrollment_id = en.id
            AND m.exam_subject_id = ?

        WHERE en.academic_year_id = ?
          AND en.class_id = ?
          AND en.enrollment_status = 'active'
          AND s.status = 'active'
          AND (
                ? = 0
                OR en.shift_id = ?
              )

        ORDER BY CAST(en.roll_no AS UNSIGNED), en.roll_no
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        'iiiii',
        $selectedExamSubjectId,
        $selectedExam['academic_year_id'],
        $selectedExam['class_id'],
        $selectedExam['shift_id'],
        $selectedExam['shift_id']
    );

    $stmt->execute();

    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Examination Marks Entry</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

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

        .info-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            height: 100%;
        }

        .info-label {
            color: #64748b;
            font-size: 13px;
        }

        .info-value {
            color: #173b60;
            font-weight: 700;
            font-size: 17px;
        }

        .table thead th {
            background: #173b60;
            color: #fff;
            white-space: nowrap;
        }

        .mark-input {
            min-width: 90px;
        }

        .student-name {
            font-weight: 600;
        }

        .verified-row {
            background: #f0fdf4;
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

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

        <div>
            <h3 class="page-title mb-1">Examination Marks Entry</h3>

            <p class="text-muted mb-0">
                Enter and manage student examination marks.
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

    <!-- EXAMINATION FILTER -->

    <div class="page-card no-print">

        <h5 class="mb-3">Select Examination</h5>

        <form method="GET">

            <div class="row g-3 align-items-end">

                <div class="col-md-8">

                    <label class="form-label">Examination *</label>

                    <select name="exam_id"
                            class="form-select"
                            required>

                        <option value="">Select Examination</option>

                        <?php foreach ($examinations as $exam): ?>

                            <option value="<?= (int)$exam['id'] ?>"
                                <?= $selectedExamId === (int)$exam['id'] ? 'selected' : '' ?>>

                                <?= h($exam['examination_name_en']) ?>
                                -
                                <?= h($exam['class_name_en']) ?>
                                -
                                <?= h($exam['year_name']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <button type="submit"
                            class="btn btn-primary w-100">
                        Load Examination
                    </button>

                </div>

            </div>

        </form>

    </div>

    <?php if ($selectedExam): ?>

        <!-- EXAM INFORMATION -->

        <div class="page-card">

            <h5 class="mb-3">Examination Information</h5>

            <div class="row g-3">

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Examination</div>
                        <div class="info-value">
                            <?= h($selectedExam['examination_name_en']) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Class</div>
                        <div class="info-value">
                            <?= h($selectedExam['class_name_en']) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Academic Year</div>
                        <div class="info-value">
                            <?= h($selectedExam['year_name']) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Result Status</div>
                        <div class="info-value">
                            <?= h(ucwords(str_replace('_', ' ', $selectedExam['result_status']))) ?>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- SUBJECT SELECTION -->

        <div class="page-card no-print">

            <h5 class="mb-3">Select Subject</h5>

            <form method="GET">

                <input type="hidden"
                       name="exam_id"
                       value="<?= (int)$selectedExamId ?>">

                <div class="row g-3 align-items-end">

                    <div class="col-md-8">

                        <label class="form-label">Examination Subject *</label>

                        <select name="exam_subject_id"
                                class="form-select"
                                required>

                            <option value="">Select Subject</option>

                            <?php foreach ($examSubjects as $subject): ?>

                                <option value="<?= (int)$subject['id'] ?>"
                                    <?= $selectedExamSubjectId === (int)$subject['id'] ? 'selected' : '' ?>>

                                    <?= h($subject['subject_name_en']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-4">

                        <button type="submit"
                                class="btn btn-primary w-100">
                            Load Students
                        </button>

                    </div>

                </div>

            </form>

        </div>

        <?php if (empty($examSubjects)): ?>

            <div class="alert alert-warning">
                No subjects have been assigned to this examination yet.
                Please add subjects through Exam Subject Assignment.
            </div>

        <?php endif; ?>

    <?php endif; ?>

    <?php if ($selectedExam && $selectedExamSubject): ?>

        <!-- MARKS INFORMATION -->

        <div class="page-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <h5 class="mb-0">
                    <?= h($selectedExamSubject['subject_name_en']) ?>
                    - Student Marks
                </h5>

                <button onclick="window.print()"
                        class="btn btn-outline-primary btn-sm no-print">
                    Print List
                </button>

            </div>

            <div class="row g-3 mb-4">

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Full Marks</div>
                        <div class="info-value">
                            <?= number_format((float)$selectedExamSubject['full_marks'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="info-box">
                        <div class="info-label">Pass Marks</div>
                        <div class="info-value">
                            <?= number_format((float)$selectedExamSubject['pass_marks'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="info-box">
                        <div class="info-label">Written</div>
                        <div class="info-value">
                            <?= number_format((float)$selectedExamSubject['written_full_marks'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="info-box">
                        <div class="info-label">MCQ</div>
                        <div class="info-value">
                            <?= number_format((float)$selectedExamSubject['mcq_full_marks'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="info-box">
                        <div class="info-label">Practical</div>
                        <div class="info-value">
                            <?= number_format((float)$selectedExamSubject['practical_full_marks'], 2) ?>
                        </div>
                    </div>
                </div>

            </div>

            <?php if (empty($students)): ?>

                <div class="alert alert-warning">
                    No active enrolled students found for this examination.
                </div>

            <?php else: ?>

                <form method="POST"
                      action="marks_entry.php?exam_id=<?= (int)$selectedExamId ?>&exam_subject_id=<?= (int)$selectedExamSubjectId ?>">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= h(csrf_token()) ?>">

                    <input type="hidden"
                           name="exam_id"
                           value="<?= (int)$selectedExamId ?>">

                    <input type="hidden"
                           name="exam_subject_id"
                           value="<?= (int)$selectedExamSubjectId ?>">

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Roll</th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Written</th>
                                    <th>MCQ</th>
                                    <th>Practical</th>
                                    <th>Previous Total</th>
                                    <th>Absent</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($students as $index => $student): ?>

                                <?php
                                $enrollmentId = (int)$student['enrollment_id'];
                                $isVerified = !empty($student['verified_at']);
                                $isAbsent = ($student['result_status'] ?? '') === 'absent';
                                ?>

                                <tr class="<?= $isVerified ? 'verified-row' : '' ?>">

                                    <td><?= $index + 1 ?></td>

                                    <td><?= h($student['roll_no']) ?></td>

                                    <td><?= h($student['admission_no']) ?></td>

                                    <td class="student-name">
                                        <?= h($student['first_name_en'] . ' ' . $student['last_name_en']) ?>

                                        <?php if ($isVerified): ?>
                                            <span class="badge bg-success">
                                                Verified
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               max="<?= h($selectedExamSubject['written_full_marks']) ?>"
                                               name="written[<?= $enrollmentId ?>]"
                                               class="form-control mark-input"
                                               value="<?= h($student['written_obtained'] ?? 0) ?>"
                                               <?= $isVerified ? 'disabled' : '' ?>>
                                    </td>

                                    <td>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               max="<?= h($selectedExamSubject['mcq_full_marks']) ?>"
                                               name="mcq[<?= $enrollmentId ?>]"
                                               class="form-control mark-input"
                                               value="<?= h($student['mcq_obtained'] ?? 0) ?>"
                                               <?= $isVerified ? 'disabled' : '' ?>>
                                    </td>

                                    <td>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               max="<?= h($selectedExamSubject['practical_full_marks']) ?>"
                                               name="practical[<?= $enrollmentId ?>]"
                                               class="form-control mark-input"
                                               value="<?= h($student['practical_obtained'] ?? 0) ?>"
                                               <?= $isVerified ? 'disabled' : '' ?>>
                                    </td>

                                    <td class="fw-bold">
                                        <?= $student['total_marks'] !== null
                                            ? number_format((float)$student['total_marks'], 2)
                                            : '-' ?>
                                    </td>

                                    <td class="text-center">
                                        <input type="checkbox"
                                               name="absent[<?= $enrollmentId ?>]"
                                               value="1"
                                               class="form-check-input"
                                               <?= $isAbsent ? 'checked' : '' ?>
                                               <?= $isVerified ? 'disabled' : '' ?>>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <div class="alert alert-info small">
                        Enter marks for each component. Select Absent if the
                        student did not attend the examination. Verified
                        records cannot be changed from this page.
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">

                        <span class="text-muted">
                            Total students: <?= count($students) ?>
                        </span>

                        <button type="submit"
                                class="btn btn-success px-4">
                            Save Student Marks
                        </button>

                    </div>

                </form>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
