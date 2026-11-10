
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('academics.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$message = '';
$messageType = 'success';

/* =========================================
   LOAD ACADEMIC YEARS
========================================= */

$years = [];
$result = $conn->query("
    SELECT id, year_name, is_current
    FROM academic_years
    ORDER BY start_date DESC
");

while ($row = $result->fetch_assoc()) {
    $years[] = $row;
}

$currentYearId = 0;

foreach ($years as $year) {
    if ((int)$year['is_current'] === 1) {
        $currentYearId = (int)$year['id'];
        break;
    }
}

/* =========================================
   SAVE GRADING SCALE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $scaleName = trim($_POST['scale_name'] ?? '');
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    $minimums = $_POST['minimum_percentage'] ?? [];
    $maximums = $_POST['maximum_percentage'] ?? [];
    $letters = $_POST['letter_grade'] ?? [];
    $points = $_POST['grade_point'] ?? [];
    $remarksEn = $_POST['grade_remark_en'] ?? [];
    $remarksBn = $_POST['grade_remark_bn'] ?? [];

    $grades = [];

    if ($scaleName === '') {
        $message = 'Please enter a grading scale name.';
        $messageType = 'danger';
    } elseif ($yearId > 0) {

        $checkYear = $conn->prepare("
            SELECT id FROM academic_years WHERE id = ?
        ");
        $checkYear->bind_param('i', $yearId);
        $checkYear->execute();

        if (!$checkYear->get_result()->fetch_assoc()) {
            $message = 'Invalid academic year selected.';
            $messageType = 'danger';
        }
    }

    if ($message === '') {

        for ($i = 0; $i < count($letters); $i++) {

            $letter = strtoupper(trim($letters[$i] ?? ''));

            if ($letter === '') {
                continue;
            }

            $min = (float)($minimums[$i] ?? 0);
            $max = (float)($maximums[$i] ?? 0);
            $point = (float)($points[$i] ?? 0);

            if (
                $min < 0 || $max > 100 ||
                $max < $min || $point < 0 ||
                $point > 5
            ) {
                $message = 'Please check the percentage ranges and grade points.';
                $messageType = 'danger';
                break;
            }

            $grades[] = [
                'min' => $min,
                'max' => $max,
                'letter' => $letter,
                'point' => $point,
                'en' => trim($remarksEn[$i] ?? ''),
                'bn' => trim($remarksBn[$i] ?? '')
            ];
        }

        if ($message === '' && count($grades) === 0) {
            $message = 'Add at least one grade.';
            $messageType = 'danger';
        }

        // Check overlapping percentage ranges.
        if ($message === '') {

            for ($i = 0; $i < count($grades); $i++) {
                for ($j = $i + 1; $j < count($grades); $j++) {

                    $overlap =
                        $grades[$i]['min'] <= $grades[$j]['max'] &&
                        $grades[$j]['min'] <= $grades[$i]['max'];

                    if ($overlap) {
                        $message = 'Grade percentage ranges must not overlap.';
                        $messageType = 'danger';
                        break 2;
                    }
                }
            }
        }

        // Check duplicate letter grades.
        if ($message === '') {
            $lettersUsed = [];

            foreach ($grades as $grade) {
                if (in_array($grade['letter'], $lettersUsed, true)) {
                    $message = 'Duplicate letter grade found.';
                    $messageType = 'danger';
                    break;
                }

                $lettersUsed[] = $grade['letter'];
            }
        }
    }

    if ($message === '') {

        $conn->begin_transaction();

        try {

            if ($isDefault === 1) {

                if ($yearId > 0) {
                    $reset = $conn->prepare("
                        UPDATE grading_scales
                        SET is_default = 0
                        WHERE academic_year_id = ?
                    ");
                    $reset->bind_param('i', $yearId);
                    $reset->execute();
                } else {
                    $conn->query("
                        UPDATE grading_scales
                        SET is_default = 0
                        WHERE academic_year_id IS NULL
                    ");
                }
            }

            $insertScale = $conn->prepare("
                INSERT INTO grading_scales
                    (scale_name, academic_year_id, is_default, status)
                VALUES (?, NULLIF(?, 0), ?, 'active')
            ");

            $insertScale->bind_param(
                'sii',
                $scaleName,
                $yearId,
                $isDefault
            );

            $insertScale->execute();

            $scaleId = $conn->insert_id;

            $insertGrade = $conn->prepare("
                INSERT INTO grading_scale_details (
                    grading_scale_id,
                    minimum_percentage,
                    maximum_percentage,
                    letter_grade,
                    grade_point,
                    grade_remark_en,
                    grade_remark_bn
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($grades as $grade) {

                $insertGrade->bind_param(
                    'iddsdss',
                    $scaleId,
                    $grade['min'],
                    $grade['max'],
                    $grade['letter'],
                    $grade['point'],
                    $grade['en'],
                    $grade['bn']
                );

                $insertGrade->execute();
            }

            $conn->commit();

            $message = 'Grading scale created successfully.';
            $messageType = 'success';

        } catch (Throwable $e) {

            $conn->rollback();

            $message = 'Could not save grading scale: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

/* =========================================
   LOAD GRADING SCALES
========================================= */

$scales = [];

$result = $conn->query("
    SELECT
        gs.id,
        gs.scale_name,
        gs.academic_year_id,
        gs.is_default,
        gs.status,
        ay.year_name
    FROM grading_scales gs
    LEFT JOIN academic_years ay
        ON ay.id = gs.academic_year_id
    ORDER BY gs.id DESC
");

while ($row = $result->fetch_assoc()) {
    $scales[] = $row;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Grading Scale Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
            font-family: Arial, sans-serif;
        }

        .page-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
            margin-bottom: 22px;
        }

        .page-title {
            color: #173b60;
            font-weight: 700;
        }

        .grade-table input {
            min-width: 90px;
        }

        .grade-table .remark-input {
            min-width: 140px;
        }

        .table thead th {
            background: #173b60;
            color: white;
            white-space: nowrap;
        }
    </style>
</head>

<body>

<div class="container-fluid py-4 px-3 px-lg-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h3 class="page-title mb-1">Grading Scale Management</h3>
            <p class="text-muted mb-0">
                Configure letter grades, percentage ranges and grade points.
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-outline-secondary">
            Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- CREATE GRADING SCALE -->

    <div class="page-card">

        <h5 class="mb-3">Create New Grading Scale</h5>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= h(csrf_token()) ?>">

            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">Scale Name *</label>
                    <input type="text" name="scale_name"
                           class="form-control"
                           placeholder="Example: School Standard Grading"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Academic Year</label>
                    <select name="academic_year_id" class="form-select">
                        <option value="0">All Academic Years</option>

                        <?php foreach ($years as $year): ?>
                            <option value="<?= (int)$year['id'] ?>"
                                <?= (int)$year['id'] === $currentYearId ? 'selected' : '' ?>>
                                <?= h($year['year_name']) ?>
                                <?= (int)$year['is_current'] === 1 ? '(Current)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox"
                               name="is_default"
                               value="1"
                               class="form-check-input"
                               id="isDefault">

                        <label class="form-check-label" for="isDefault">
                            Set as default grading scale
                        </label>
                    </div>
                </div>

            </div>

            <h6 class="mb-3">Grade Details</h6>

            <div class="table-responsive">

                <table class="table table-bordered align-middle grade-table">

                    <thead>
                        <tr>
                            <th>Minimum %</th>
                            <th>Maximum %</th>
                            <th>Letter Grade</th>
                            <th>Grade Point</th>
                            <th>Remark (English)</th>
                            <th>Remark (Bangla)</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php
                        $defaultGrades = [
                            [80,100,'A+',5.00,'Excellent','অতি উত্তম'],
                            [70,79.99,'A',4.00,'Very Good','খুব ভালো'],
                            [60,69.99,'A-',3.50,'Good','ভালো'],
                            [50,59.99,'B',3.00,'Satisfactory','সন্তোষজনক'],
                            [40,49.99,'C',2.00,'Average','গড়'],
                            [33,39.99,'D',1.00,'Pass','উত্তীর্ণ'],
                            [0,32.99,'F',0.00,'Fail','অনুত্তীর্ণ']
                        ];
                        ?>

                        <?php foreach ($defaultGrades as $grade): ?>

                            <tr>
                                <td>
                                    <input type="number" step="0.01"
                                           name="minimum_percentage[]"
                                           class="form-control"
                                           value="<?= $grade[0] ?>">
                                </td>

                                <td>
                                    <input type="number" step="0.01"
                                           name="maximum_percentage[]"
                                           class="form-control"
                                           value="<?= $grade[1] ?>">
                                </td>

                                <td>
                                    <input type="text"
                                           name="letter_grade[]"
                                           class="form-control"
                                           value="<?= h($grade[2]) ?>">
                                </td>

                                <td>
                                    <input type="number" step="0.01"
                                           name="grade_point[]"
                                           class="form-control"
                                           value="<?= $grade[3] ?>">
                                </td>

                                <td>
                                    <input type="text"
                                           name="grade_remark_en[]"
                                           class="form-control remark-input"
                                           value="<?= h($grade[4]) ?>">
                                </td>

                                <td>
                                    <input type="text"
                                           name="grade_remark_bn[]"
                                           class="form-control remark-input"
                                           value="<?= h($grade[5]) ?>">
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

            <div class="alert alert-info small">
                You can change the default values before saving.
                Percentage ranges must not overlap.
            </div>

            <button type="submit" class="btn btn-primary px-4">
                Save Grading Scale
            </button>

        </form>

    </div>

    <!-- EXISTING GRADING SCALES -->

    <div class="page-card">

        <h5 class="mb-3">Existing Grading Scales</h5>

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Scale Name</th>
                        <th>Academic Year</th>
                        <th>Default</th>
                        <th>Status</th>
                        <th>Grade Details</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($scales)): ?>

                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No grading scales created yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($scales as $index => $scale): ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td><?= h($scale['scale_name']) ?></td>

                            <td>
                                <?= h($scale['year_name'] ?? 'All Years') ?>
                            </td>

                            <td>
                                <?= $scale['is_default']
                                    ? '<span class="badge bg-success">Default</span>'
                                    : '<span class="badge bg-secondary">No</span>' ?>
                            </td>

                            <td><?= h(ucfirst($scale['status'])) ?></td>

                            <td>
                                <a href="grading_scale_details.php?id=<?= (int)$scale['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    View Grades
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
