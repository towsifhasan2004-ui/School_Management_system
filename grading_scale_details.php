
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('academics.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   VALIDATE GRADING SCALE ID
========================================= */

$scaleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$scaleId || $scaleId <= 0) {
    redirect('grading_scales.php');
}

/* =========================================
   LOAD GRADING SCALE
========================================= */

$stmt = $conn->prepare("
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
    WHERE gs.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $scaleId);
$stmt->execute();

$scale = $stmt->get_result()->fetch_assoc();

if (!$scale) {
    redirect('grading_scales.php');
}

/* =========================================
   LOAD GRADE DETAILS
========================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        minimum_percentage,
        maximum_percentage,
        letter_grade,
        grade_point,
        grade_remark_en,
        grade_remark_bn
    FROM grading_scale_details
    WHERE grading_scale_id = ?
    ORDER BY minimum_percentage DESC
");

$stmt->bind_param('i', $scaleId);
$stmt->execute();

$grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Grading Scale Details</title>

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
        }

        .page-title {
            color: #173b60;
            font-weight: 700;
        }

        .info-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 16px;
            border-radius: 10px;
            height: 100%;
        }

        .info-label {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 17px;
            font-weight: 700;
            color: #173b60;
        }

        .table thead th {
            background: #173b60;
            color: #fff;
            white-space: nowrap;
        }

        .grade-letter {
            font-size: 17px;
            font-weight: 700;
            color: #173b60;
        }
    </style>
</head>

<body>

<div class="container-fluid py-4 px-3 px-lg-4">

    <!-- PAGE HEADER -->

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

        <div>
            <h3 class="page-title mb-1">Grading Scale Details</h3>

            <p class="text-muted mb-0">
                View the complete grading structure.
            </p>
        </div>

        <a href="grading_scales.php"
           class="btn btn-outline-secondary">
            &larr; Back to Grading Scales
        </a>

    </div>

    <!-- SCALE INFORMATION -->

    <div class="page-card mb-4">

        <h5 class="mb-3">Grading Scale Information</h5>

        <div class="row g-3">

            <div class="col-md-3">
                <div class="info-box">
                    <div class="info-label">Scale Name</div>
                    <div class="info-value">
                        <?= h($scale['scale_name']) ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <div class="info-label">Academic Year</div>
                    <div class="info-value">
                        <?= h($scale['year_name'] ?? 'All Years') ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <div class="info-label">Default Scale</div>
                    <div class="info-value">
                        <?= (int)$scale['is_default'] === 1
                            ? 'Yes'
                            : 'No' ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <?= h(ucfirst($scale['status'])) ?>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- GRADE DETAILS -->

    <div class="page-card">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div>
                <h5 class="mb-1">Grade Configuration</h5>

                <small class="text-muted">
                    Total grades: <?= count($grades) ?>
                </small>
            </div>

            <button onclick="window.print()"
                    class="btn btn-outline-primary">
                Print Grades
            </button>

        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Minimum Percentage</th>
                        <th>Maximum Percentage</th>
                        <th>Letter Grade</th>
                        <th>Grade Point</th>
                        <th>Remark (English)</th>
                        <th>Remark (Bangla)</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($grades)): ?>

                    <tr>
                        <td colspan="7"
                            class="text-center text-muted py-4">
                            No grade details found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($grades as $index => $grade): ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td>
                                <?= number_format(
                                    (float)$grade['minimum_percentage'],
                                    2
                                ) ?>%
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$grade['maximum_percentage'],
                                    2
                                ) ?>%
                            </td>

                            <td class="grade-letter">
                                <?= h($grade['letter_grade']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$grade['grade_point'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= h($grade['grade_remark_en'] ?? '-') ?>
                            </td>

                            <td>
                                <?= h($grade['grade_remark_bn'] ?? '-') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="alert alert-info mt-3 mb-0">
            These grade ranges will be used when calculating student
            examination results with this grading scale.
        </div>

    </div>

</div>

</body>
</html>
