
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('attendance.view');

date_default_timezone_set('Asia/Dhaka');

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
| FILTERS
|--------------------------------------------------------------------------
*/

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$sectionId = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT) ?: 0;
$shiftId = filter_input(INPUT_GET, 'shift_id', FILTER_VALIDATE_INT) ?: 0;

$fromObject = DateTime::createFromFormat('!Y-m-d', $dateFrom);
$toObject = DateTime::createFromFormat('!Y-m-d', $dateTo);

if (!$fromObject || $fromObject->format('Y-m-d') !== $dateFrom) {
    $dateFrom = date('Y-m-01');
}

if (!$toObject || $toObject->format('Y-m-d') !== $dateTo) {
    $dateTo = date('Y-m-d');
}

if ($dateFrom > $dateTo) {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| LOAD DROPDOWN DATA
|--------------------------------------------------------------------------
*/

$classes = [];
$sections = [];
$shifts = [];

$result = $conn->query("
    SELECT id, class_name_en
    FROM classes
    WHERE status = 'active'
    ORDER BY id ASC
");

while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

$result = $conn->query("
    SELECT id, section_name_en, class_id
    FROM sections
    WHERE status = 'active'
    ORDER BY class_id, id
");

while ($row = $result->fetch_assoc()) {
    $sections[] = $row;
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

/*
|--------------------------------------------------------------------------
| VALIDATE FILTERS
|--------------------------------------------------------------------------
*/

$classValid = false;

foreach ($classes as $class) {
    if ((int)$class['id'] === $classId) {
        $classValid = true;
        break;
    }
}

if (!$classValid) {
    $classId = 0;
}

$sectionValid = false;

foreach ($sections as $section) {
    if (
        (int)$section['id'] === $sectionId &&
        (int)$section['class_id'] === $classId &&
        $classId > 0
    ) {
        $sectionValid = true;
        break;
    }
}

if (!$sectionValid) {
    $sectionId = 0;
}

$shiftValid = false;

foreach ($shifts as $shift) {
    if ((int)$shift['id'] === $shiftId) {
        $shiftValid = true;
        break;
    }
}

if (!$shiftValid) {
    $shiftId = 0;
}

/*
|--------------------------------------------------------------------------
| BUILD REPORT QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        e.id AS enrollment_id,
        e.roll_no,
        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,

        c.class_name_en,
        sec.section_name_en,
        sh.shift_name_en,

        COUNT(a.id) AS marked_days,

        COALESCE(SUM(a.attendance_status = 'present'), 0) AS present_days,

        COALESCE(SUM(a.attendance_status = 'absent'), 0) AS absent_days,

        COALESCE(SUM(a.attendance_status = 'late'), 0) AS late_days,

        COALESCE(SUM(a.attendance_status = 'half_day'), 0) AS half_day_days,

        COALESCE(SUM(a.attendance_status = 'leave'), 0) AS leave_days

    FROM enrollments e

    INNER JOIN students s
        ON s.id = e.student_id

    INNER JOIN classes c
        ON c.id = e.class_id

    INNER JOIN sections sec
        ON sec.id = e.section_id

    INNER JOIN shifts sh
        ON sh.id = e.shift_id

    LEFT JOIN attendance a
        ON a.enrollment_id = e.id
        AND a.attendance_date BETWEEN ? AND ?

    WHERE
        e.academic_year_id = ?
        AND e.enrollment_status = 'active'
        AND s.status = 'active'
";

$params = [
    $dateFrom,
    $dateTo,
    $academicYearId
];

$types = 'ssi';

if ($classId > 0) {
    $sql .= " AND e.class_id = ?";
    $params[] = $classId;
    $types .= 'i';
}

if ($sectionId > 0) {
    $sql .= " AND e.section_id = ?";
    $params[] = $sectionId;
    $types .= 'i';
}

if ($shiftId > 0) {
    $sql .= " AND e.shift_id = ?";
    $params[] = $shiftId;
    $types .= 'i';
}

$sql .= "
    GROUP BY
        e.id,
        e.roll_no,
        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        s.full_name_bn,
        c.class_name_en,
        sec.section_name_en,
        sh.shift_name_en

    ORDER BY
        c.id,
        sec.id,
        sh.id,
        CAST(e.roll_no AS UNSIGNED),
        s.first_name_en
";

$stmt = $conn->prepare($sql);

/*
|--------------------------------------------------------------------------
| DYNAMIC PARAMETER BINDING
|--------------------------------------------------------------------------
*/

$bindParams = [$types];

foreach ($params as $key => $value) {
    $bindParams[] = &$params[$key];
}

call_user_func_array(
    [$stmt, 'bind_param'],
    $bindParams
);

$stmt->execute();

$result = $stmt->get_result();

$reportRows = [];

while ($row = $result->fetch_assoc()) {
    $reportRows[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| OVERALL SUMMARY
|--------------------------------------------------------------------------
*/

$totalStudents = count($reportRows);

$totalPresent = 0;
$totalAbsent = 0;
$totalLate = 0;
$totalHalfDay = 0;
$totalLeave = 0;
$totalMarked = 0;

foreach ($reportRows as $row) {

    $totalPresent += (int)$row['present_days'];
    $totalAbsent += (int)$row['absent_days'];
    $totalLate += (int)$row['late_days'];
    $totalHalfDay += (int)$row['half_day_days'];
    $totalLeave += (int)$row['leave_days'];
    $totalMarked += (int)$row['marked_days'];
}

$overallPresentRate = $totalMarked > 0
    ? round(($totalPresent / $totalMarked) * 100, 2)
    : 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Attendance Report | Sezua International School</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f4f6f9;
        }

        .report-panel {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #e8edf2;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: #fff;
            padding: 16px;
            border-radius: 10px;
            border: 1px solid #e8edf2;
        }

        .summary-card small {
            color: #6c757d;
        }

        .summary-card h4 {
            margin: 5px 0 0;
            font-weight: 700;
        }

        .report-table th {
            background: #0b2947;
            color: #fff;
            white-space: nowrap;
        }

        .report-table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        @media print {

            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .report-panel,
            .summary-card {
                border: 1px solid #ccc;
            }

        }

    </style>

</head>

<body>

<div class="container-fluid p-3 p-md-4">

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

        <div>

            <h3 class="mb-1">
                Attendance Report
            </h3>

            <p class="text-muted mb-0">
                Academic Year: <?= e($currentYear['year_name']) ?>
            </p>

        </div>

        <div class="no-print d-flex gap-2">

            <button
                type="button"
                onclick="window.print()"
                class="btn btn-outline-primary"
            >
                Print Report
            </button>

            <a
                href="<?= BASE_URL ?>/attendance.php"
                class="btn btn-success"
            >
                Take Attendance
            </a>

            <a
                href="<?= BASE_URL ?>/dashboard.php"
                class="btn btn-outline-secondary"
            >
                Dashboard
            </a>

        </div>

    </div>


    <!-- FILTERS -->

    <div class="report-panel no-print">

        <h5 class="mb-3">
            Report Filters
        </h5>

        <form method="GET">

            <div class="row g-3">

                <div class="col-md-3">

                    <label class="form-label">
                        From Date
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        class="form-control"
                        value="<?= e($dateFrom) ?>"
                        required
                    >

                </div>


                <div class="col-md-3">

                    <label class="form-label">
                        To Date
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        class="form-control"
                        value="<?= e($dateTo) ?>"
                        required
                    >

                </div>


                <div class="col-md-2">

                    <label class="form-label">
                        Class
                    </label>

                    <select
                        name="class_id"
                        id="class_id"
                        class="form-select"
                    >

                        <option value="">
                            All Classes
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


                <div class="col-md-2">

                    <label class="form-label">
                        Section
                    </label>

                    <select
                        name="section_id"
                        id="section_id"
                        class="form-select"
                    >

                        <option value="">
                            All Sections
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


                <div class="col-md-2">

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
                                <?= $shiftId === (int)$shift['id'] ? 'selected' : '' ?>
                            >
                                <?= e($shift['shift_name_en']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>


            <div class="mt-3 d-flex gap-2">

                <button type="submit" class="btn btn-primary">
                    Generate Report
                </button>

                <a
                    href="<?= BASE_URL ?>/attendance_report.php"
                    class="btn btn-outline-secondary"
                >
                    Reset
                </a>

            </div>

        </form>

    </div>


    <!-- REPORT SUMMARY -->

    <div class="summary-grid">

        <div class="summary-card">
            <small>Total Students</small>
            <h4><?= $totalStudents ?></h4>
        </div>

        <div class="summary-card">
            <small>Present Records</small>
            <h4 class="text-success"><?= $totalPresent ?></h4>
        </div>

        <div class="summary-card">
            <small>Absent Records</small>
            <h4 class="text-danger"><?= $totalAbsent ?></h4>
        </div>

        <div class="summary-card">
            <small>Late Records</small>
            <h4 class="text-warning"><?= $totalLate ?></h4>
        </div>

        <div class="summary-card">
            <small>Half Day Records</small>
            <h4 class="text-primary"><?= $totalHalfDay ?></h4>
        </div>

        <div class="summary-card">
            <small>Leave Records</small>
            <h4 class="text-info"><?= $totalLeave ?></h4>
        </div>

        <div class="summary-card">
            <small>Present Rate</small>
            <h4><?= $overallPresentRate ?>%</h4>
        </div>

    </div>


    <!-- REPORT TABLE -->

    <div class="report-panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

            <h5 class="mb-0">
                Student Attendance Details
            </h5>

            <small class="text-muted">
                <?= e($dateFrom) ?> to <?= e($dateTo) ?>
            </small>

        </div>


        <div class="table-responsive">

            <table class="table table-bordered table-hover report-table">

                <thead>

                    <tr>

                        <th>Roll</th>
                        <th>Admission No.</th>
                        <th>Student Name</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Shift</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Half Day</th>
                        <th>Leave</th>
                        <th>Marked Days</th>
                        <th>Present Rate</th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (empty($reportRows)): ?>

                        <tr>

                            <td colspan="13" class="text-center py-4">
                                No student records found for the selected filters.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($reportRows as $row): ?>

                            <?php

                            $markedDays = (int)$row['marked_days'];

                            $presentRate = $markedDays > 0
                                ? round(
                                    ((int)$row['present_days'] / $markedDays) * 100,
                                    2
                                )
                                : 0;

                            ?>

                            <tr>

                                <td><?= e($row['roll_no']) ?></td>

                                <td><?= e($row['admission_no']) ?></td>

                                <td>

                                    <strong>
                                        <?= e(trim(
                                            $row['first_name_en'] . ' ' .
                                            $row['last_name_en']
                                        )) ?>
                                    </strong>

                                    <?php if ($row['full_name_bn']): ?>

                                        <br>

                                        <small class="text-muted">
                                            <?= e($row['full_name_bn']) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td><?= e($row['class_name_en']) ?></td>

                                <td><?= e($row['section_name_en']) ?></td>

                                <td><?= e($row['shift_name_en']) ?></td>

                                <td class="text-success">
                                    <?= (int)$row['present_days'] ?>
                                </td>

                                <td class="text-danger">
                                    <?= (int)$row['absent_days'] ?>
                                </td>

                                <td class="text-warning">
                                    <?= (int)$row['late_days'] ?>
                                </td>

                                <td>
                                    <?= (int)$row['half_day_days'] ?>
                                </td>

                                <td>
                                    <?= (int)$row['leave_days'] ?>
                                </td>

                                <td>
                                    <?= $markedDays ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= $presentRate ?>%
                                    </strong>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="text-muted small mb-0">
            Present Rate = Present records ÷ Marked attendance records × 100.
            Unmarked days are excluded.
        </p>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const classSelect = document.getElementById('class_id');
    const sectionSelect = document.getElementById('section_id');

    function filterSections(resetSelection = false) {

        const selectedClass = classSelect.value;

        if (resetSelection) {
            sectionSelect.value = '';
        }

        let validSelection = false;

        Array.from(sectionSelect.options).forEach(function (option) {

            if (option.value === '') {
                option.hidden = false;
                return;
            }

            const visible = (
                selectedClass !== '' &&
                option.dataset.classId === selectedClass
            );

            option.hidden = !visible;
            option.disabled = !visible;

            if (
                visible &&
                option.value === sectionSelect.value
            ) {
                validSelection = true;
            }

        });

        if (!validSelection) {
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
