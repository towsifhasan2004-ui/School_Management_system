
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('academics.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sisms_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';
$editAssignment = null;

/* ==========================================
   LOAD ACADEMIC YEARS
========================================== */

$yearsResult = $conn->query("
    SELECT id, year_name, status, is_current
    FROM academic_years
    ORDER BY start_date DESC
");

$years = $yearsResult->fetch_all(MYSQLI_ASSOC);

if (!$years) {
    die('No academic year found. Please create an academic year first.');
}

/* ==========================================
   SELECT ACADEMIC YEAR
========================================== */

$selectedYearId = (int)($_GET['year_id'] ?? 0);

if ($selectedYearId <= 0) {
    foreach ($years as $year) {
        if ((int)$year['is_current'] === 1) {
            $selectedYearId = (int)$year['id'];
            break;
        }
    }
}

if ($selectedYearId <= 0) {
    $selectedYearId = (int)$years[0]['id'];
}

/* ==========================================
   LOAD EDIT ASSIGNMENT
========================================== */

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT *
            FROM class_subjects
            WHERE id = ?
        ");

        $stmt->bind_param('i', $editId);
        $stmt->execute();

        $editAssignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($editAssignment) {
            $selectedYearId = (int)$editAssignment['academic_year_id'];
        }
    }
}

/* ==========================================
   SAVE ASSIGNMENT
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);

    $fullMarks = (float)($_POST['full_marks'] ?? 0);
    $passMarks = (float)($_POST['pass_marks'] ?? 0);

    $weeklyInput = trim($_POST['weekly_periods'] ?? '');
    $weeklyPeriods = ($weeklyInput === '') ? null : (int)$weeklyInput;

    $isOptional = isset($_POST['is_optional']) ? 1 : 0;

    $assignmentId = (int)($_POST['assignment_id'] ?? 0);

    $selectedYearId = $yearId;

    try {

        if (
            $yearId <= 0 ||
            $classId <= 0 ||
            $subjectId <= 0 ||
            $fullMarks <= 0 ||
            $passMarks < 0 ||
            $passMarks > $fullMarks
        ) {
            throw new Exception('Please enter valid assignment information.');
        }

        if (
            $weeklyInput !== '' &&
            ($weeklyPeriods < 1 || $weeklyPeriods > 50)
        ) {
            throw new Exception('Weekly periods must be between 1 and 50.');
        }

        /* Check academic year */

        $stmt = $conn->prepare("
            SELECT id
            FROM academic_years
            WHERE id = ?
        ");

        $stmt->bind_param('i', $yearId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Selected academic year does not exist.');
        }

        $stmt->close();

        /* Check active class */

        $stmt = $conn->prepare("
            SELECT id
            FROM classes
            WHERE id = ? AND status = 'active'
        ");

        $stmt->bind_param('i', $classId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Selected class is not available.');
        }

        $stmt->close();

        /* Check active subject */

        $stmt = $conn->prepare("
            SELECT id
            FROM subjects
            WHERE id = ? AND status = 'active'
        ");

        $stmt->bind_param('i', $subjectId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new Exception('Selected subject is not active or does not exist.');
        }

        $stmt->close();

        /* INSERT */

        if ($action === 'add') {

            $stmt = $conn->prepare("
                INSERT INTO class_subjects
                (
                    academic_year_id,
                    class_id,
                    subject_id,
                    full_marks,
                    pass_marks,
                    weekly_periods,
                    is_optional
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                'iiiddii',
                $yearId,
                $classId,
                $subjectId,
                $fullMarks,
                $passMarks,
                $weeklyPeriods,
                $isOptional
            );

            $stmt->execute();
            $stmt->close();

        /* UPDATE */

        } elseif ($action === 'update') {

            if ($assignmentId <= 0) {
                throw new Exception('Invalid assignment ID.');
            }

            $stmt = $conn->prepare("
                UPDATE class_subjects
                SET
                    academic_year_id = ?,
                    class_id = ?,
                    subject_id = ?,
                    full_marks = ?,
                    pass_marks = ?,
                    weekly_periods = ?,
                    is_optional = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'iiiddiii',
                $yearId,
                $classId,
                $subjectId,
                $fullMarks,
                $passMarks,
                $weeklyPeriods,
                $isOptional,
                $assignmentId
            );

            $stmt->execute();
            $stmt->close();

        } else {
            throw new Exception('Invalid request.');
        }

        header(
            'Location: class_subject_assignment.php?year_id=' .
            $yearId . '&saved=1'
        );
        exit;

    } catch (mysqli_sql_exception $e) {

        if ((int)$e->getCode() === 1062) {
            $message = 'This subject is already assigned to this class for the selected academic year.';
        } else {
            $message = 'Database error: ' . $e->getMessage();
        }

        $messageType = 'danger';

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* ==========================================
   SUCCESS MESSAGE
========================================== */

if (isset($_GET['saved'])) {
    $message = 'Class subject assignment saved successfully.';
}

/* ==========================================
   LOAD DROPDOWN DATA
========================================== */

$classesResult = $conn->query("
    SELECT id, class_name_en, class_name_bn
    FROM classes
    WHERE status = 'active'
    ORDER BY class_order ASC
");

$classes = $classesResult->fetch_all(MYSQLI_ASSOC);

$subjectsResult = $conn->query("
    SELECT id, subject_code, subject_name_en,
           subject_name_bn, status
    FROM subjects
    ORDER BY subject_name_en ASC
");

$subjects = $subjectsResult->fetch_all(MYSQLI_ASSOC);

/* ==========================================
   LOAD ASSIGNED SUBJECTS
========================================== */

$stmt = $conn->prepare("
    SELECT
        cs.id,
        cs.full_marks,
        cs.pass_marks,
        cs.weekly_periods,
        cs.is_optional,

        c.class_name_en,
        s.subject_code,
        s.subject_name_en,
        s.subject_name_bn

    FROM class_subjects cs

    JOIN classes c
        ON c.id = cs.class_id

    JOIN subjects s
        ON s.id = cs.subject_id

    WHERE cs.academic_year_id = ?

    ORDER BY c.class_order ASC, s.subject_name_en ASC
");

$stmt->bind_param('i', $selectedYearId);
$stmt->execute();

$assignments = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Class Subject Assignment | Sezua SISMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
rel="stylesheet">

<style>
body {
    background: #f3f6fa;
    font-family: Arial, sans-serif;
}

.main-content {
    padding: 25px;
}

.page-title {
    color: #0b2947;
    font-weight: 700;
}

.panel {
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 25px;
    box-shadow: 0 3px 15px rgba(0,0,0,.06);
}

.btn-school {
    background: #0b2947;
    color: #fff;
}

.btn-school:hover {
    background: #173b60;
    color: #fff;
}

.table th {
    background: #0b2947;
    color: #fff;
    white-space: nowrap;
}

.form-label {
    font-weight: 600;
    color: #243b53;
}
</style>

</head>

<body>

<div class="container-fluid main-content">

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">

        <div>
            <h2 class="page-title">Class Subject Assignment</h2>
            <p class="text-muted mb-0">
                Assign subjects to classes for each academic year.
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-outline-secondary">
            Back to Dashboard
        </a>

    </div>

    <?php if ($message !== ''): ?>

        <div class="alert alert-<?= sisms_h($messageType) ?> alert-dismissible fade show">

            <?= sisms_h($message) ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>

    <?php if (!$subjects): ?>

        <div class="alert alert-warning">
            No subjects have been created yet.
            Please add subjects from
            <a href="subjects.php">Subject Management</a>
            first.
        </div>

    <?php else: ?>

    <!-- ASSIGNMENT FORM -->

    <div class="panel">

        <h5 class="mb-4">
            <?= $editAssignment ? 'Edit Class Subject Assignment' : 'Assign Subject to Class' ?>
        </h5>

        <form method="POST" action="class_subject_assignment.php">

            <input type="hidden" name="csrf_token"
                   value="<?= sisms_h(csrf_token()) ?>">

            <input type="hidden" name="action"
                   value="<?= $editAssignment ? 'update' : 'add' ?>">

            <?php if ($editAssignment): ?>

                <input type="hidden" name="assignment_id"
                       value="<?= (int)$editAssignment['id'] ?>">

            <?php endif; ?>

            <div class="row">

                <div class="col-md-4 mb-3">

                    <label class="form-label">Academic Year *</label>

                    <select name="academic_year_id" class="form-select" required>

                        <?php foreach ($years as $year): ?>

                            <option value="<?= (int)$year['id'] ?>"
                                <?= $selectedYearId === (int)$year['id'] ? 'selected' : '' ?>>

                                <?= sisms_h($year['year_name']) ?>
                                <?= (int)$year['is_current'] === 1 ? '(Current)' : '' ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4 mb-3">

                    <label class="form-label">Class *</label>

                    <select name="class_id" class="form-select" required>

                        <option value="">Select Class</option>

                        <?php foreach ($classes as $class): ?>

                            <option value="<?= (int)$class['id'] ?>"
                                <?= isset($editAssignment['class_id']) &&
                                    (int)$editAssignment['class_id'] === (int)$class['id']
                                    ? 'selected' : '' ?>>

                                <?= sisms_h($class['class_name_en']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4 mb-3">

                    <label class="form-label">Subject *</label>

                    <select name="subject_id" class="form-select" required>

                        <option value="">Select Subject</option>

                        <?php foreach ($subjects as $subject): ?>

                            <option
                                value="<?= (int)$subject['id'] ?>"
                                <?= isset($editAssignment['subject_id']) &&
                                    (int)$editAssignment['subject_id'] === (int)$subject['id']
                                    ? 'selected' : '' ?>
                                <?= $subject['status'] !== 'active' ? 'disabled' : '' ?>>

                                <?= sisms_h($subject['subject_code']) ?>
                                -
                                <?= sisms_h($subject['subject_name_en']) ?>

                                <?= $subject['status'] !== 'active' ? '(Inactive)' : '' ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4 mb-3">

                    <label class="form-label">Full Marks *</label>

                    <input type="number"
                           name="full_marks"
                           class="form-control"
                           min="0.01"
                           step="0.01"
                           value="<?= sisms_h($editAssignment['full_marks'] ?? '100') ?>"
                           required>

                </div>

                <div class="col-md-4 mb-3">

                    <label class="form-label">Pass Marks *</label>

                    <input type="number"
                           name="pass_marks"
                           class="form-control"
                           min="0"
                           step="0.01"
                           value="<?= sisms_h($editAssignment['pass_marks'] ?? '33') ?>"
                           required>

                </div>

                <div class="col-md-4 mb-3">

                    <label class="form-label">Weekly Periods</label>

                    <input type="number"
                           name="weekly_periods"
                           class="form-control"
                           min="1"
                           max="50"
                           value="<?= sisms_h($editAssignment['weekly_periods'] ?? '') ?>"
                           placeholder="Example: 5">

                </div>

                <div class="col-md-12 mb-3">

                    <div class="form-check">

                        <input type="checkbox"
                               name="is_optional"
                               value="1"
                               class="form-check-input"
                               id="is_optional"
                               <?= !empty($editAssignment['is_optional']) ? 'checked' : '' ?>>

                        <label class="form-check-label" for="is_optional">
                            This is an optional subject
                        </label>

                    </div>

                </div>

            </div>

            <button type="submit" class="btn btn-school px-4">

                <?= $editAssignment ? 'Update Assignment' : 'Save Assignment' ?>

            </button>

            <?php if ($editAssignment): ?>

                <a href="class_subject_assignment.php?year_id=<?= $selectedYearId ?>"
                   class="btn btn-secondary ms-2">

                    Cancel

                </a>

            <?php endif; ?>

        </form>

    </div>

    <?php endif; ?>

    <!-- ASSIGNED SUBJECT LIST -->

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">

            <h5 class="mb-2">Assigned Subjects</h5>

            <form method="GET" class="d-flex gap-2">

                <select name="year_id" class="form-select">

                    <?php foreach ($years as $year): ?>

                        <option value="<?= (int)$year['id'] ?>"
                            <?= $selectedYearId === (int)$year['id'] ? 'selected' : '' ?>>

                            <?= sisms_h($year['year_name']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <button type="submit" class="btn btn-school">
                    Filter
                </button>

            </form>

        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Class</th>
                        <th>Subject Code</th>
                        <th>Subject</th>
                        <th>Full Marks</th>
                        <th>Pass Marks</th>
                        <th>Weekly Periods</th>
                        <th>Type</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($assignments->num_rows > 0): ?>

                    <?php $i = 1; ?>

                    <?php while ($row = $assignments->fetch_assoc()): ?>

                        <tr>

                            <td><?= $i++ ?></td>

                            <td><?= sisms_h($row['class_name_en']) ?></td>

                            <td><?= sisms_h($row['subject_code']) ?></td>

                            <td><?= sisms_h($row['subject_name_en']) ?></td>

                            <td><?= number_format((float)$row['full_marks'], 2) ?></td>

                            <td><?= number_format((float)$row['pass_marks'], 2) ?></td>

                            <td><?= $row['weekly_periods'] === null
                                ? '-' : (int)$row['weekly_periods'] ?></td>

                            <td>
                                <?= (int)$row['is_optional'] === 1
                                    ? 'Optional' : 'Compulsory' ?>
                            </td>

                            <td>

                                <a href="class_subject_assignment.php?edit=<?= (int)$row['id'] ?>"
                                   class="btn btn-sm btn-warning">

                                    Edit

                                </a>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="9" class="text-center text-muted py-4">

                            No subjects assigned for this academic year yet.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
$stmt->close();
?>
