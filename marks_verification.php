
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('marks.verify');

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
    WHERE e.result_status IN ('marks_entry', 'under_review')
    ORDER BY e.start_date DESC, e.id DESC
");

while ($row = $result->fetch_assoc()) {
    $examinations[] = $row;
}

/* =========================================
   SELECT EXAMINATION AND SUBJECT
========================================= */

$selectedExamId = (int)(
    $_GET['exam_id'] ?? $_POST['exam_id'] ?? 0
);

$selectedExamSubjectId = (int)(
    $_GET['exam_subject_id'] ?? $_POST['exam_subject_id'] ?? 0
);

$selectedExam = null;
$examSubjects = [];
$selectedExamSubject = null;
$marks = [];

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
            ['marks_entry', 'under_review'],
            true
        )
    ) {
        $selectedExam = null;
        $selectedExamId = 0;

        $message = 'Examination not found or not available for verification.';
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

    foreach ($examSubjects as $subject) {

        if ((int)$subject['id'] === $selectedExamSubjectId) {
            $selectedExamSubject = $subject;
            break;
        }
    }
}

/* =========================================
   VERIFY SELECTED MARKS
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $selectedExam &&
    $selectedExamSubject
) {

    verify_csrf();

    $markIds = $_POST['mark_ids'] ?? [];
    $userId = (int)$_SESSION['user_id'];

    if (!is_array($markIds) || empty($markIds)) {

        $message = 'Please select at least one mark record.';
        $messageType = 'warning';

    } else {

        $verifiedCount = 0;

        try {

            $conn->begin_transaction();

            $verifyStmt = $conn->prepare("
                UPDATE marks m

                INNER JOIN exam_subjects es
                    ON es.id = m.exam_subject_id

                INNER JOIN examinations e
                    ON e.id = es.examination_id

                INNER JOIN enrollments en
                    ON en.id = m.enrollment_id

                SET
                    m.verified_by = ?,
                    m.verified_at = NOW()

                WHERE m.id = ?
                  AND es.id = ?
                  AND e.id = ?

                  AND en.academic_year_id = e.academic_year_id
                  AND en.class_id = e.class_id

                  AND (
                      e.shift_id IS NULL
                      OR e.shift_id = 0
                      OR en.shift_id = e.shift_id
                  )

                  AND m.verified_at IS NULL

                  AND m.result_status IN ('pass', 'fail', 'absent')
            ");

            foreach ($markIds as $markId) {

                $markId = (int)$markId;

                if ($markId <= 0) {
                    continue;
                }

                $verifyStmt->bind_param(
                    'iiii',
                    $userId,
                    $markId,
                    $selectedExamSubjectId,
                    $selectedExamId
                );

                $verifyStmt->execute();

                $verifiedCount += $verifyStmt->affected_rows;
            }

            $conn->commit();

            $message = $verifiedCount .
                ' mark record(s) verified successfully.';

            $messageType = 'success';

        } catch (Throwable $e) {

            $conn->rollback();

            $message = 'Verification failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

/* =========================================
   LOAD STUDENT MARKS
========================================= */

if ($selectedExam && $selectedExamSubject) {

    $stmt = $conn->prepare("
        SELECT
            m.id AS mark_id,
            m.written_obtained,
            m.mcq_obtained,
            m.practical_obtained,
            m.total_marks,
            m.percentage,
            m.letter_grade,
            m.grade_point,
            m.result_status,
            m.verified_at,

            en.roll_no,
            s.admission_no,
            s.first_name_en,
            s.last_name_en

        FROM marks m

        INNER JOIN enrollments en
            ON en.id = m.enrollment_id

        INNER JOIN students s
            ON s.id = en.student_id

        WHERE m.exam_subject_id = ?
          AND en.academic_year_id = ?
          AND en.class_id = ?
          AND en.enrollment_status = 'active'
          AND s.status = 'active'

          AND (
              ? = 0
              OR en.shift_id = ?
          )

        ORDER BY
            CAST(en.roll_no AS UNSIGNED),
            en.roll_no
    ");

    $examShiftId = (int)($selectedExam['shift_id'] ?? 0);

    $stmt->bind_param(
        'iiiii',
        $selectedExamSubjectId,
        $selectedExam['academic_year_id'],
        $selectedExam['class_id'],
        $examShiftId,
        $examShiftId
    );

    $stmt->execute();

    $marks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* =========================================
   SUMMARY
========================================= */

$totalRecords = count($marks);
$verifiedRecords = 0;
$pendingRecords = 0;

foreach ($marks as $mark) {

    if (!empty($mark['verified_at'])) {
        $verifiedRecords++;
    } else {
        $pendingRecords++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Marks Verification</title>

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
            color: #fff;
            white-space: nowrap;
        }

        .verified-row {
            background: #f0fdf4 !important;
        }

        .pending-row {
            background: #fff;
        }

        .table td {
            vertical-align: middle;
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
                Marks Verification
            </h3>

            <p class="text-muted mb-0">
                Review and verify student examination marks.
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

    <!-- EXAMINATION SELECTION -->

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

    <?php if (empty($examinations)): ?>

        <div class="alert alert-info">
            No examinations are currently available for marks verification.
            Marks must be entered before verification.
        </div>

    <?php endif; ?>

    <?php if ($selectedExam): ?>

        <!-- EXAM INFORMATION -->

        <div class="page-card">

            <h5 class="mb-3">Examination Information</h5>

            <div class="row g-3">

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Examination
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['examination_name_en']) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Class
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['class_name_en']) ?>
                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="summary-box">

                        <div class="summary-label">
                            Academic Year
                        </div>

                        <div class="fw-bold">
                            <?= h($selectedExam['year_name']) ?>
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

                        <label class="form-label">
                            Examination Subject
                        </label>

                        <select name="exam_subject_id"
                                class="form-select"
                                required>

                            <option value="">
                                Select Subject
                            </option>

                            <?php foreach ($examSubjects as $subject): ?>

                                <option
                                    value="<?= (int)$subject['id'] ?>"
                                    <?= $selectedExamSubjectId === (int)$subject['id'] ? 'selected' : '' ?>
                                >
                                    <?= h($subject['subject_name_en']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-4">

                        <button type="submit"
                                class="btn btn-primary w-100">
                            Load Marks
                        </button>

                    </div>

                </div>

            </form>

        </div>

        <?php if (empty($examSubjects)): ?>

            <div class="alert alert-warning">
                No subjects have been assigned to this examination.
            </div>

        <?php endif; ?>

    <?php endif; ?>

    <?php if ($selectedExam && $selectedExamSubject): ?>

        <!-- SUMMARY -->

        <div class="row g-3 mb-4">

            <div class="col-md-4">

                <div class="summary-box">

                    <div class="summary-label">
                        Total Mark Records
                    </div>

                    <div class="summary-value">
                        <?= $totalRecords ?>
                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="summary-box">

                    <div class="summary-label">
                        Verified Records
                    </div>

                    <div class="summary-value text-success">
                        <?= $verifiedRecords ?>
                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="summary-box">

                    <div class="summary-label">
                        Pending Verification
                    </div>

                    <div class="summary-value text-warning">
                        <?= $pendingRecords ?>
                    </div>

                </div>

            </div>

        </div>

        <!-- MARKS TABLE -->

        <div class="page-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

                <h5 class="mb-0">
                    <?= h($selectedExamSubject['subject_name_en']) ?>
                    - Marks Review
                </h5>

                <button onclick="window.print()"
                        class="btn btn-outline-primary btn-sm no-print">
                    Print
                </button>

            </div>

            <?php if (empty($marks)): ?>

                <div class="alert alert-warning">
                    No marks have been entered for this subject yet.
                </div>

            <?php else: ?>

                <form method="POST"
                      action="marks_verification.php?exam_id=<?= (int)$selectedExamId ?>&exam_subject_id=<?= (int)$selectedExamSubjectId ?>">

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

                                    <th class="no-print">
                                        <input type="checkbox"
                                               id="selectAll">
                                    </th>

                                    <th>#</th>
                                    <th>Roll</th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Written</th>
                                    <th>MCQ</th>
                                    <th>Practical</th>
                                    <th>Total</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Result</th>
                                    <th>Verification</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($marks as $index => $mark): ?>

                                    <?php
                                    $isVerified = !empty($mark['verified_at']);

                                    $isIncomplete =
                                        $mark['result_status'] === 'incomplete';
                                    ?>

                                    <tr class="<?= $isVerified ? 'verified-row' : 'pending-row' ?>">

                                        <td class="no-print text-center">

                                            <input
                                                type="checkbox"
                                                class="form-check-input mark-checkbox"
                                                name="mark_ids[]"
                                                value="<?= (int)$mark['mark_id'] ?>"
                                                <?= ($isVerified || $isIncomplete) ? 'disabled' : '' ?>
                                            >

                                        </td>

                                        <td><?= $index + 1 ?></td>

                                        <td><?= h($mark['roll_no']) ?></td>

                                        <td><?= h($mark['admission_no']) ?></td>

                                        <td class="fw-semibold">

                                            <?= h(
                                                $mark['first_name_en'] . ' ' .
                                                $mark['last_name_en']
                                            ) ?>

                                        </td>

                                        <td>
                                            <?= number_format((float)$mark['written_obtained'], 2) ?>
                                        </td>

                                        <td>
                                            <?= number_format((float)$mark['mcq_obtained'], 2) ?>
                                        </td>

                                        <td>
                                            <?= number_format((float)$mark['practical_obtained'], 2) ?>
                                        </td>

                                        <td class="fw-bold">
                                            <?= number_format((float)$mark['total_marks'], 2) ?>
                                        </td>

                                        <td>
                                            <?= $mark['percentage'] !== null
                                                ? number_format((float)$mark['percentage'], 2) . '%'
                                                : '-' ?>
                                        </td>

                                        <td>
                                            <?= h($mark['letter_grade'] ?? '-') ?>
                                        </td>

                                        <td>

                                            <?php if ($mark['result_status'] === 'pass'): ?>

                                                <span class="badge bg-success">
                                                    Pass
                                                </span>

                                            <?php elseif ($mark['result_status'] === 'fail'): ?>

                                                <span class="badge bg-danger">
                                                    Fail
                                                </span>

                                            <?php elseif ($mark['result_status'] === 'absent'): ?>

                                                <span class="badge bg-secondary">
                                                    Absent
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-warning text-dark">
                                                    Incomplete
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if ($isVerified): ?>

                                                <span class="badge bg-success">
                                                    Verified
                                                </span>

                                                <div class="small text-muted mt-1">
                                                    <?= h($mark['verified_at']) ?>
                                                </div>

                                            <?php else: ?>

                                                <span class="badge bg-warning text-dark">
                                                    Pending
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 no-print">

                        <div class="text-muted">
                            Select the records you want to verify.
                        </div>

                        <button type="submit"
                                class="btn btn-success px-4"
                                onclick="return confirm('Are you sure you want to verify the selected marks? Verified marks cannot be edited from Marks Entry.');">

                            <i class="bi bi-check-circle"></i>
                            Verify Selected Marks

                        </button>

                    </div>

                </form>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<script>

document.getElementById('selectAll')?.addEventListener('change', function () {

    document.querySelectorAll('.mark-checkbox:not(:disabled)')
        .forEach(function (checkbox) {
            checkbox.checked = document.getElementById('selectAll').checked;
        });

});

</script>

</body>
</html>
