
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('reports.academic');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

/* =========================================
   HELPER FUNCTIONS
========================================= */

function rc_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rc_date($date) {
    return $date ? date('d M Y', strtotime($date)) : '-';
}

/* =========================================
   SCHOOL PROFILE
========================================= */

$schoolResult = $conn->query("
    SELECT school_name_en, school_name_bn, short_name,
           address_en, address_bn, phone, email, website, logo_path
    FROM school_profile
    ORDER BY id ASC
    LIMIT 1
");

$school = $schoolResult->fetch_assoc() ?: [];

/* =========================================
   LOAD PUBLISHED EXAMINATIONS
========================================= */

$examResult = $conn->query("
    SELECT
        e.id,
        e.examination_name_en,
        e.examination_name_bn,
        e.academic_year_id,
        e.class_id,
        e.shift_id,
        e.start_date,
        e.end_date,
        ay.year_name,
        c.class_name_en,
        c.class_name_bn
    FROM examinations e
    INNER JOIN academic_years ay
        ON ay.id = e.academic_year_id
    INNER JOIN classes c
        ON c.id = e.class_id
    WHERE e.result_status = 'published'
    ORDER BY e.start_date DESC, e.id DESC
");

$examinations = $examResult->fetch_all(MYSQLI_ASSOC);

/* =========================================
   SELECTED VALUES
========================================= */

$examId = (int)($_GET['exam_id'] ?? 0);
$studentId = (int)($_GET['student_id'] ?? 0);

$selectedExam = null;
$students = [];
$selectedStudent = null;
$studentProfile = [];
$subjects = [];

$totalObtained = 0;
$totalFullMarks = 0;
$totalGpa = 0;
$subjectCount = 0;

$allPassed = true;
$allComplete = true;

$finalGpa = 0;
$finalResult = 'INCOMPLETE';
$percentage = 0;

$message = '';

/* =========================================
   FIND SELECTED EXAM
========================================= */

foreach ($examinations as $exam) {
    if ((int)$exam['id'] === $examId) {
        $selectedExam = $exam;
        break;
    }
}

/* =========================================
   LOAD STUDENTS FOR SELECTED EXAM
========================================= */

if ($selectedExam) {

    $examYearId = (int)$selectedExam['academic_year_id'];
    $examClassId = (int)$selectedExam['class_id'];
    $examShiftId = (int)($selectedExam['shift_id'] ?? 0);

    $studentSql = "
        SELECT
            s.id,
            s.admission_no,
            s.first_name_en,
            s.last_name_en,
            s.full_name_bn,
            en.id AS enrollment_id,
            en.roll_no,
            sec.section_name_en,
            sh.shift_name_en
        FROM enrollments en
        INNER JOIN students s
            ON s.id = en.student_id
        INNER JOIN sections sec
            ON sec.id = en.section_id
        INNER JOIN shifts sh
            ON sh.id = en.shift_id
        WHERE en.academic_year_id = ?
          AND en.class_id = ?
          AND en.enrollment_status = 'active'
          AND s.status = 'active'
    ";

    // NULL examination shift means all shifts.
    if ($examShiftId > 0) {
        $studentSql .= " AND en.shift_id = ? ";
    }

    $studentSql .= "
        ORDER BY CAST(en.roll_no AS UNSIGNED), s.first_name_en
    ";

    $studentStmt = $conn->prepare($studentSql);

    if ($examShiftId > 0) {

        $studentStmt->bind_param(
            'iii',
            $examYearId,
            $examClassId,
            $examShiftId
        );

    } else {

        $studentStmt->bind_param(
            'ii',
            $examYearId,
            $examClassId
        );
    }

    $studentStmt->execute();

    $students = $studentStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $studentStmt->close();

    /* =====================================
       LOAD SELECTED STUDENT
    ===================================== */

    foreach ($students as $student) {

        if ((int)$student['id'] === $studentId) {
            $selectedStudent = $student;
            break;
        }
    }

    if ($selectedStudent) {

        $enrollmentId = (int)$selectedStudent['enrollment_id'];

        /* =================================
           STUDENT PROFILE
        ================================= */

        $profileStmt = $conn->prepare("
            SELECT
                s.date_of_birth,
                s.gender,
                s.blood_group,
                s.photo_path,
                s.present_address,
                g.name_en AS guardian_name,
                g.phone AS guardian_phone
            FROM students s
            LEFT JOIN student_guardians sg
                ON sg.student_id = s.id
               AND sg.is_primary = 1
            LEFT JOIN guardians g
                ON g.id = sg.guardian_id
            WHERE s.id = ?
            LIMIT 1
        ");

        $profileStmt->bind_param('i', $studentId);
        $profileStmt->execute();

        $studentProfile = $profileStmt
            ->get_result()
            ->fetch_assoc() ?: [];

        $profileStmt->close();

        /* =================================
           LOAD SUBJECT MARKS
        ================================= */

        $marksStmt = $conn->prepare("
            SELECT
                es.id AS exam_subject_id,
                es.full_marks,
                es.pass_marks,
                es.written_full_marks,
                es.mcq_full_marks,
                es.practical_full_marks,

                sub.subject_name_en,
                sub.subject_name_bn,

                m.written_obtained,
                m.mcq_obtained,
                m.practical_obtained,
                m.total_marks,
                m.percentage,
                m.letter_grade,
                m.grade_point,
                m.result_status,
                m.verified_at

            FROM exam_subjects es

            INNER JOIN subjects sub
                ON sub.id = es.subject_id

            LEFT JOIN marks m
                ON m.exam_subject_id = es.id
               AND m.enrollment_id = ?

            WHERE es.examination_id = ?

            ORDER BY sub.subject_name_en
        ");

        $marksStmt->bind_param(
            'ii',
            $enrollmentId,
            $examId
        );

        $marksStmt->execute();

        $subjects = $marksStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $marksStmt->close();

        /* =================================
           CALCULATE RESULT SUMMARY
        ================================= */

        foreach ($subjects as $subject) {

            $status = $subject['result_status'] ?? 'incomplete';

            if (
                $status === 'incomplete' ||
                $subject['verified_at'] === null
            ) {
                $allComplete = false;
            }

            if ($status !== 'pass') {
                $allPassed = false;
            }

            if ($status === 'incomplete' || $status === null) {
                continue;
            }

            $totalObtained += (float)($subject['total_marks'] ?? 0);

            $totalFullMarks += (float)$subject['full_marks'];

            $totalGpa += (float)($subject['grade_point'] ?? 0);

            $subjectCount++;
        }

        if ($allComplete && $subjectCount > 0) {

            $finalResult = $allPassed ? 'PASS' : 'FAIL';

            $finalGpa = $allPassed
                ? $totalGpa / $subjectCount
                : 0;

        } else {

            $finalResult = 'INCOMPLETE';
        }

        $percentage = $totalFullMarks > 0
            ? ($totalObtained / $totalFullMarks) * 100
            : 0;

    } elseif ($studentId > 0) {

        $message = 'The selected student does not belong to this examination.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Student Report Card | Sezua International School
    </title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
        rel="stylesheet">

    <style>

        :root {
            --navy: #0b2947;
            --deep: #071b30;
            --blue: #173b60;
            --gold: #e8c66f;
            --light: #f4f6fa;
            --border: #dce3eb;
        }

        body {
            margin: 0;
            background: var(--light);
            font-family: "Segoe UI", Arial, sans-serif;
            color: #243447;
        }

        /* =================================
           NAVIGATION
        ================================= */

        .report-navbar {
            background: var(--navy);
            padding: 14px 25px;
            color: #fff;

            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;
            flex-wrap: wrap;

            box-shadow: 0 3px 12px rgba(0,0,0,.12);
        }

        .report-navbar .school-title {
            color: #fff;
            text-decoration: none;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .4px;
        }

        .report-navbar .school-title i {
            color: var(--gold);
        }

        .report-navbar .nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .report-navbar .nav-links a {
            color: #e5edf5;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: .2s;
        }

        .report-navbar .nav-links a:hover {
            color: var(--gold);
        }

        /* =================================
           MAIN PAGE
        ================================= */

        .report-page {
            padding: 25px;
            min-height: 100vh;
        }

        .page-heading {
            margin-bottom: 22px;
        }

        .page-heading h3 {
            color: var(--navy);
            font-weight: 800;
        }

        .page-heading p {
            color: #64748b;
        }

        .filter-card {
            background: #fff;
            border: 1px solid #e6ebf1;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,.04);
        }

        .form-label {
            color: #334155;
            font-size: 13px;
        }

        .form-select {
            min-height: 43px;
        }

        .btn-primary {
            background: var(--blue);
            border-color: var(--blue);
        }

        .btn-primary:hover {
            background: var(--navy);
            border-color: var(--navy);
        }

        /* =================================
           REPORT CARD
        ================================= */

        .report-card {
            background: #fff;
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            padding: 30px;

            max-width: 1050px;
            margin: 25px auto;

            box-shadow: 0 5px 20px rgba(0,0,0,.05);
        }

        .report-header {
            text-align: center;
            border-bottom: 3px double var(--blue);
            padding-bottom: 18px;
            margin-bottom: 22px;
        }

        .report-header h2 {
            color: var(--blue);
            font-weight: 800;
            margin-bottom: 5px;
            font-size: 25px;
        }

        .report-header h4 {
            color: #b28b35;
            font-weight: 800;
            margin-top: 15px;
            letter-spacing: 1px;
        }

        .report-header h5 {
            margin-top: 5px;
        }

        .school-logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            margin-bottom: 8px;
        }

        /* =================================
           STUDENT INFORMATION
        ================================= */

        .student-information {
            display: grid;
            grid-template-columns: 1fr 120px;
            gap: 20px;
            margin-bottom: 25px;
        }

        .info-table {
            margin-bottom: 0;
        }

        .info-table td {
            padding: 7px 9px;
            border: 0;
            font-size: 13px;
        }

        .info-table td:first-child {
            font-weight: 700;
            width: 150px;
            color: #334155;
        }

        .student-photo {
            width: 105px;
            height: 120px;
            object-fit: cover;
            border: 2px solid #d5dce5;
            padding: 3px;
            border-radius: 6px;
        }

        /* =================================
           MARKS TABLE
        ================================= */

        .marks-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .marks-table th {
            background: var(--blue);
            color: #fff;
            padding: 11px 8px;
            font-size: 12px;
            white-space: nowrap;
            border: 1px solid var(--blue);
        }

        .marks-table td {
            padding: 10px 8px;
            border: 1px solid var(--border);
            font-size: 12px;
            text-align: center;
        }

        .marks-table td.subject-name {
            text-align: left;
            font-weight: 600;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        /* =================================
           SUMMARY
        ================================= */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 22px;
        }

        .summary-box {
            border: 1px solid var(--border);
            padding: 14px;
            text-align: center;
            border-radius: 8px;
            background: #fff;
        }

        .summary-box small {
            display: block;
            color: #64748b;
            margin-bottom: 7px;
            font-size: 12px;
        }

        .summary-box strong {
            color: var(--blue);
            font-size: 19px;
        }

        .result-pass {
            color: #15803d !important;
        }

        .result-fail {
            color: #dc2626 !important;
        }

        .result-incomplete {
            color: #b45309 !important;
        }

        /* =================================
           SIGNATURES
        ================================= */

        .signature-area {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 75px;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #333;
            padding-top: 8px;
            font-weight: 600;
            font-size: 13px;
        }

        .report-footer {
            text-align: center;
            border-top: 1px solid #ddd;
            margin-top: 35px;
            padding-top: 12px;
            color: #64748b;
            font-size: 12px;
        }

        /* =================================
           MOBILE RESPONSIVE
        ================================= */

        @media(max-width:768px) {

            .report-navbar {
                padding: 13px 15px;
            }

            .report-navbar .school-title {
                font-size: 15px;
            }

            .report-navbar .nav-links {
                gap: 12px;
            }

            .report-navbar .nav-links a {
                font-size: 12px;
            }

            .report-page {
                padding: 12px;
            }

            .report-card {
                padding: 14px;
            }

            .student-information {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .marks-table {
                min-width: 750px;
            }

            .signature-area {
                gap: 12px;
            }
        }

        /* =================================
           PRINT
        ================================= */

        @page {
            size: A4 portrait;
            margin: 12mm;
        }

        @media print {

            body {
                background: #fff !important;
            }

            .no-print,
            .report-navbar {
                display: none !important;
            }

            .report-page {
                padding: 0 !important;
                background: #fff !important;
                min-height: auto;
            }

            .report-card {
                max-width: 100%;
                margin: 0;
                padding: 0;
                border: 0;
                box-shadow: none;
            }

            .marks-table {
                width: 100%;
            }

            .marks-table th {
                background: var(--blue) !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-box {
                break-inside: avoid;
            }

            .signature-area {
                break-inside: avoid;
            }
        }

    </style>

</head>

<body>

<!-- =====================================
     STANDALONE NAVIGATION
===================================== -->

<nav class="report-navbar no-print">

    <a href="dashboard.php" class="school-title">
        <i class="fas fa-graduation-cap me-2"></i>
        SEZUA INTERNATIONAL SCHOOL
    </a>

    <div class="nav-links">

        <a href="dashboard.php">
            <i class="fas fa-home me-1"></i>
            Dashboard
        </a>

        <a href="result_processing.php">
            <i class="fas fa-chart-line me-1"></i>
            Results
        </a>

        <a href="attendance_report.php">
            <i class="fas fa-calendar-check me-1"></i>
            Attendance
        </a>

    </div>

</nav>

<!-- =====================================
     MAIN PAGE
===================================== -->

<div class="report-page">

    <!-- PAGE HEADING -->

    <div class="no-print">

        <div class="page-heading d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div>
                <h3 class="mb-1">
                    <i class="fas fa-file-alt me-2"></i>
                    Student Report Card
                </h3>

                <p class="mb-0">
                    View and print published examination results.
                </p>
            </div>

            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>
                Dashboard
            </a>

        </div>

        <!-- =================================
             EXAMINATION AND STUDENT FILTER
        ================================== -->

        <div class="filter-card mb-4">

            <div class="card-body p-4">

                <form method="GET" class="row g-3">

                    <div class="col-md-5">

                        <label class="form-label fw-bold">
                            Select Examination
                        </label>

                        <select
                            name="exam_id"
                            class="form-select"
                            required
                            onchange="this.form.student_id.value=''; this.form.submit();">

                            <option value="">
                                Select Examination
                            </option>

                            <?php foreach ($examinations as $exam): ?>

                                <option
                                    value="<?= (int)$exam['id'] ?>"
                                    <?= $examId === (int)$exam['id'] ? 'selected' : '' ?>>

                                    <?= rc_h($exam['examination_name_en']) ?>
                                    -
                                    <?= rc_h($exam['class_name_en']) ?>
                                    (<?= rc_h($exam['year_name']) ?>)

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-5">

                        <label class="form-label fw-bold">
                            Select Student
                        </label>

                        <select
                            name="student_id"
                            class="form-select"
                            <?= !$selectedExam ? 'disabled' : '' ?>
                            required>

                            <option value="">
                                Select Student
                            </option>

                            <?php foreach ($students as $student): ?>

                                <option
                                    value="<?= (int)$student['id'] ?>"
                                    <?= $studentId === (int)$student['id'] ? 'selected' : '' ?>>

                                    <?= rc_h($student['roll_no']) ?>
                                    -
                                    <?= rc_h($student['first_name_en'] . ' ' . $student['last_name_en']) ?>
                                    (<?= rc_h($student['admission_no']) ?>)

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-2 d-flex align-items-end">

                        <button type="submit" class="btn btn-primary w-100">

                            <i class="fas fa-search me-1"></i>
                            View

                        </button>

                    </div>

                </form>

            </div>

        </div>

        <!-- MESSAGES -->

        <?php if (!$examinations): ?>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No published examinations are available yet.
            </div>

        <?php endif; ?>

        <?php if ($message): ?>

            <div class="alert alert-warning">
                <?= rc_h($message) ?>
            </div>

        <?php endif; ?>

        <?php if ($selectedExam && !$students): ?>

            <div class="alert alert-warning">
                No active students found for this examination.
            </div>

        <?php endif; ?>

    </div>

    <!-- =================================
         REPORT CARD
    ================================== -->

    <?php if ($selectedExam && $selectedStudent && !empty($subjects)): ?>

        <div class="text-end mb-3 no-print">

            <button
                onclick="window.print()"
                class="btn btn-success">

                <i class="fas fa-print me-2"></i>
                Print / Save PDF

            </button>

        </div>

        <div class="report-card">

            <!-- SCHOOL HEADER -->

            <div class="report-header">

                <?php if (!empty($school['logo_path'])): ?>

                    <img
                        src="<?= rc_h($school['logo_path']) ?>"
                        class="school-logo"
                        alt="School Logo"
                        onerror="this.style.display='none'">

                <?php endif; ?>

                <h2>
                    <?= rc_h($school['school_name_en'] ?? 'Sezua International School') ?>
                </h2>

                <?php if (!empty($school['school_name_bn'])): ?>

                    <h5>
                        <?= rc_h($school['school_name_bn']) ?>
                    </h5>

                <?php endif; ?>

                <div>
                    <?= rc_h($school['address_en'] ?? '') ?>
                </div>

                <div>
                    <?= rc_h($school['phone'] ?? '') ?>

                    <?= !empty($school['email'])
                        ? ' | ' . rc_h($school['email'])
                        : '' ?>
                </div>

                <h4>
                    ACADEMIC REPORT CARD
                </h4>

                <strong>
                    <?= rc_h($selectedExam['examination_name_en']) ?>
                </strong>

                <div>
                    Academic Year:
                    <?= rc_h($selectedExam['year_name']) ?>
                </div>

            </div>

            <!-- STUDENT INFORMATION -->

            <div class="student-information">

                <div>

                    <table class="table info-table">

                        <tr>
                            <td>Student Name</td>

                            <td>
                                <?= rc_h(
                                    $selectedStudent['first_name_en'] . ' ' .
                                    $selectedStudent['last_name_en']
                                ) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>বাংলা নাম</td>

                            <td>
                                <?= rc_h($selectedStudent['full_name_bn']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Admission No.</td>

                            <td>
                                <?= rc_h($selectedStudent['admission_no']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Class</td>

                            <td>
                                <?= rc_h($selectedExam['class_name_en']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Section</td>

                            <td>
                                <?= rc_h($selectedStudent['section_name_en']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Roll No.</td>

                            <td>
                                <?= rc_h($selectedStudent['roll_no']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Shift</td>

                            <td>
                                <?= rc_h($selectedStudent['shift_name_en']) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Date of Birth</td>

                            <td>
                                <?= rc_date($studentProfile['date_of_birth'] ?? null) ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Guardian</td>

                            <td>
                                <?= rc_h($studentProfile['guardian_name'] ?? '-') ?>
                            </td>
                        </tr>

                        <tr>
                            <td>Guardian Phone</td>

                            <td>
                                <?= rc_h($studentProfile['guardian_phone'] ?? '-') ?>
                            </td>
                        </tr>

                    </table>

                </div>

                <!-- STUDENT PHOTO -->

                <div class="text-center">

                    <?php

                    $photo = $studentProfile['photo_path'] ?? '';
                    $photoExists = false;

                    if (
                        $photo !== '' &&
                        strpos($photo, 'uploads/student_photos/') === 0
                    ) {

                        $photoFullPath = __DIR__ . '/' . $photo;

                        $photoExists = is_file($photoFullPath);
                    }

                    ?>

                    <?php if ($photoExists): ?>

                        <img
                            src="<?= rc_h($photo) ?>"
                            class="student-photo"
                            alt="Student Photo">

                    <?php else: ?>

                        <div class="student-photo d-flex align-items-center justify-content-center">

                            <i class="fas fa-user fa-3x text-secondary"></i>

                        </div>

                    <?php endif; ?>

                    <small class="d-block mt-2">
                        Student Photo
                    </small>

                </div>

            </div>

            <!-- SUBJECT MARKS -->

            <h5 class="fw-bold mb-2">
                Subject-wise Academic Performance
            </h5>

            <div class="table-wrapper">

                <table class="marks-table">

                    <thead>

                        <tr>
                            <th>SL</th>
                            <th>Subject</th>
                            <th>Full Marks</th>
                            <th>Written</th>
                            <th>MCQ</th>
                            <th>Practical</th>
                            <th>Obtained</th>
                            <th>Grade</th>
                            <th>GPA</th>
                            <th>Result</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($subjects as $index => $subject): ?>

                            <?php
                            $status = $subject['result_status'] ?? 'incomplete';
                            ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td class="subject-name">
                                    <?= rc_h($subject['subject_name_en']) ?>
                                </td>

                                <td>
                                    <?= number_format((float)$subject['full_marks'], 2) ?>
                                </td>

                                <td>
                                    <?= number_format((float)($subject['written_obtained'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    <?= number_format((float)($subject['mcq_obtained'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    <?= number_format((float)($subject['practical_obtained'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    <?= number_format((float)($subject['total_marks'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    <?= rc_h($subject['letter_grade'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= number_format((float)($subject['grade_point'] ?? 0), 2) ?>
                                </td>

                                <td>

                                    <?php if ($status === 'pass'): ?>

                                        <span class="text-success fw-bold">
                                            PASS
                                        </span>

                                    <?php elseif ($status === 'absent'): ?>

                                        <span class="text-danger fw-bold">
                                            ABSENT
                                        </span>

                                    <?php elseif ($status === 'fail'): ?>

                                        <span class="text-danger fw-bold">
                                            FAIL
                                        </span>

                                    <?php else: ?>

                                        <span class="text-warning fw-bold">
                                            INCOMPLETE
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <!-- RESULT SUMMARY -->

            <div class="summary-grid">

                <div class="summary-box">

                    <small>Total Obtained</small>

                    <strong>
                        <?= number_format($totalObtained, 2) ?>
                    </strong>

                </div>

                <div class="summary-box">

                    <small>Total Full Marks</small>

                    <strong>
                        <?= number_format($totalFullMarks, 2) ?>
                    </strong>

                </div>

                <div class="summary-box">

                    <small>Percentage</small>

                    <strong>
                        <?= number_format($percentage, 2) ?>%
                    </strong>

                </div>

                <div class="summary-box">

                    <small>GPA</small>

                    <strong>

                        <?= $finalResult === 'INCOMPLETE'
                            ? '-'
                            : number_format($finalGpa, 2) ?>

                    </strong>

                </div>

            </div>

            <!-- FINAL RESULT -->

            <div class="summary-box mt-3">

                <small>FINAL RESULT</small>

                <strong class="<?=
                    $finalResult === 'PASS'
                        ? 'result-pass'
                        : (
                            $finalResult === 'FAIL'
                                ? 'result-fail'
                                : 'result-incomplete'
                        )
                ?>">

                    <?= rc_h($finalResult) ?>

                </strong>

            </div>

            <!-- SIGNATURES -->

            <div class="signature-area">

                <div class="signature-line">
                    Class Teacher
                </div>

                <div class="signature-line">
                    Examination Controller
                </div>

                <div class="signature-line">
                    Headmaster / Principal
                </div>

            </div>

            <!-- REPORT FOOTER -->

            <div class="report-footer">

                This is a computer-generated academic report card.

                <br>

                Generated on
                <?= date('d M Y, h:i A') ?>

            </div>

        </div>

    <?php elseif ($selectedExam && $selectedStudent && empty($subjects)): ?>

        <div class="alert alert-warning mt-3">

            <i class="fas fa-exclamation-triangle me-2"></i>

            No subjects have been assigned to this examination.

        </div>

    <?php endif; ?>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>
