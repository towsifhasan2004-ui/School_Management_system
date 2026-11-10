
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('academics.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$message = '';
$messageType = 'success';
$editSubject = null;

/* Escape output */
function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================
   SAVE / UPDATE SUBJECT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    $subjectCode = strtoupper(trim($_POST['subject_code'] ?? ''));
    $subjectNameEn = trim($_POST['subject_name_en'] ?? '');
    $subjectNameBn = trim($_POST['subject_name_bn'] ?? '');
    $subjectType = $_POST['subject_type'] ?? 'compulsory';
    $status = $_POST['status'] ?? 'active';

    $allowedTypes = ['compulsory', 'optional', 'co_curricular'];
    $allowedStatus = ['active', 'inactive'];

    if (
        $subjectCode === '' ||
        $subjectNameEn === '' ||
        !in_array($subjectType, $allowedTypes, true) ||
        !in_array($status, $allowedStatus, true)
    ) {
        $message = 'Please provide valid subject information.';
        $messageType = 'danger';

    } else {

        try {

            if ($action === 'add') {

                $stmt = $conn->prepare("
                    INSERT INTO subjects
                    (
                        subject_code,
                        subject_name_en,
                        subject_name_bn,
                        subject_type,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    'sssss',
                    $subjectCode,
                    $subjectNameEn,
                    $subjectNameBn,
                    $subjectType,
                    $status
                );

                $stmt->execute();
                $stmt->close();

                $message = 'Subject added successfully.';

            } elseif ($action === 'update') {

                $subjectId = (int)($_POST['subject_id'] ?? 0);

                if ($subjectId <= 0) {
                    throw new Exception('Invalid subject ID.');
                }

                $stmt = $conn->prepare("
                    UPDATE subjects
                    SET
                        subject_code = ?,
                        subject_name_en = ?,
                        subject_name_bn = ?,
                        subject_type = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'sssssi',
                    $subjectCode,
                    $subjectNameEn,
                    $subjectNameBn,
                    $subjectType,
                    $status,
                    $subjectId
                );

                $stmt->execute();
                $stmt->close();

                $message = 'Subject updated successfully.';

            } else {
                throw new Exception('Invalid action.');
            }

        } catch (mysqli_sql_exception $e) {

            if ((int)$e->getCode() === 1062) {
                $message = 'This subject code already exists. Please use another code.';
            } else {
                $message = 'Database error: ' . $e->getMessage();
            }

            $messageType = 'danger';

        } catch (Exception $e) {

            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

/* =========================================
   LOAD SUBJECT FOR EDITING
========================================= */

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                subject_code,
                subject_name_en,
                subject_name_bn,
                subject_type,
                status
            FROM subjects
            WHERE id = ?
        ");

        $stmt->bind_param('i', $editId);
        $stmt->execute();

        $result = $stmt->get_result();
        $editSubject = $result->fetch_assoc();

        $stmt->close();
    }
}

/* =========================================
   SEARCH SUBJECTS
========================================= */

$search = trim($_GET['search'] ?? '');

if ($search !== '') {

    $keyword = "%{$search}%";

    $stmt = $conn->prepare("
        SELECT
            id,
            subject_code,
            subject_name_en,
            subject_name_bn,
            subject_type,
            status
        FROM subjects
        WHERE
            subject_code LIKE ?
            OR subject_name_en LIKE ?
            OR subject_name_bn LIKE ?
        ORDER BY subject_name_en ASC
    ");

    $stmt->bind_param('sss', $keyword, $keyword, $keyword);

} else {

    $stmt = $conn->prepare("
        SELECT
            id,
            subject_code,
            subject_name_en,
            subject_name_bn,
            subject_type,
            status
        FROM subjects
        ORDER BY subject_name_en ASC
    ");
}

$stmt->execute();
$subjects = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Subject Management | Sezua SISMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

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
    background: #ffffff;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.btn-school {
    background: #0b2947;
    color: #ffffff;
}

.btn-school:hover {
    background: #173b60;
    color: #ffffff;
}

.table thead {
    background: #0b2947;
    color: #ffffff;
}

.table th {
    background: #0b2947;
    color: #ffffff;
    white-space: nowrap;
}

.form-label {
    font-weight: 600;
    color: #243b53;
}

.badge-active {
    background: #198754;
}

.badge-inactive {
    background: #dc3545;
}
</style>
</head>

<body>

<div class="container-fluid main-content">

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">

        <div>
            <h2 class="page-title">Subject Management</h2>
            <p class="text-muted mb-0">
                Manage school subjects in English and Bangla.
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-outline-secondary">
            Back to Dashboard
        </a>

    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= esc($messageType) ?> alert-dismissible fade show">
            <?= esc($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUBJECT FORM -->

    <div class="panel">

        <h5 class="mb-4">
            <?= $editSubject ? 'Edit Subject' : 'Add New Subject' ?>
        </h5>

        <form method="POST" action="subjects.php<?= $editSubject ? '?edit=' . (int)$editSubject['id'] : '' ?>">

            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">

            <input
                type="hidden"
                name="action"
                value="<?= $editSubject ? 'update' : 'add' ?>"
            >

            <?php if ($editSubject): ?>
                <input
                    type="hidden"
                    name="subject_id"
                    value="<?= (int)$editSubject['id'] ?>"
                >
            <?php endif; ?>

            <div class="row">

                <div class="col-md-4 mb-3">
                    <label class="form-label">Subject Code *</label>

                    <input
                        type="text"
                        name="subject_code"
                        class="form-control"
                        placeholder="Example: ENG-01"
                        value="<?= esc($editSubject['subject_code'] ?? '') ?>"
                        maxlength="30"
                        required
                    >
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Subject Name (English) *</label>

                    <input
                        type="text"
                        name="subject_name_en"
                        class="form-control"
                        placeholder="Example: English"
                        value="<?= esc($editSubject['subject_name_en'] ?? '') ?>"
                        maxlength="120"
                        required
                    >
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Subject Name (Bangla)</label>

                    <input
                        type="text"
                        name="subject_name_bn"
                        class="form-control"
                        placeholder="উদাহরণ: ইংরেজি"
                        value="<?= esc($editSubject['subject_name_bn'] ?? '') ?>"
                        maxlength="120"
                    >
                </div>

                <div class="col-md-6 mb-3">

                    <label class="form-label">Subject Type *</label>

                    <select name="subject_type" class="form-select" required>

                        <option value="compulsory"
                            <?= ($editSubject['subject_type'] ?? '') === 'compulsory' ? 'selected' : '' ?>>
                            Compulsory
                        </option>

                        <option value="optional"
                            <?= ($editSubject['subject_type'] ?? '') === 'optional' ? 'selected' : '' ?>>
                            Optional
                        </option>

                        <option value="co_curricular"
                            <?= ($editSubject['subject_type'] ?? '') === 'co_curricular' ? 'selected' : '' ?>>
                            Co-curricular
                        </option>

                    </select>

                </div>

                <div class="col-md-6 mb-3">

                    <label class="form-label">Status *</label>

                    <select name="status" class="form-select" required>

                        <option value="active"
                            <?= ($editSubject['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                            Active
                        </option>

                        <option value="inactive"
                            <?= ($editSubject['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                            Inactive
                        </option>

                    </select>

                </div>

            </div>

            <button type="submit" class="btn btn-school px-4">
                <?= $editSubject ? 'Update Subject' : 'Save Subject' ?>
            </button>

            <?php if ($editSubject): ?>
                <a href="subjects.php" class="btn btn-secondary ms-2">
                    Cancel
                </a>
            <?php endif; ?>

        </form>

    </div>

    <!-- SUBJECT LIST -->

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">

            <h5 class="mb-2">Subject List</h5>

            <form method="GET" class="d-flex gap-2">

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search subject..."
                    value="<?= esc($search) ?>"
                >

                <button type="submit" class="btn btn-school">
                    Search
                </button>

                <a href="subjects.php" class="btn btn-outline-secondary">
                    Reset
                </a>

            </form>

        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Subject Name</th>
                        <th>Bangla Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($subjects->num_rows > 0): ?>

                    <?php $i = 1; ?>

                    <?php while ($row = $subjects->fetch_assoc()): ?>

                        <tr>

                            <td><?= $i++ ?></td>

                            <td><?= esc($row['subject_code']) ?></td>

                            <td><?= esc($row['subject_name_en']) ?></td>

                            <td><?= esc($row['subject_name_bn'] ?? '') ?></td>

                            <td>
                                <?= esc(ucwords(str_replace('_', ' ', $row['subject_type']))) ?>
                            </td>

                            <td>

                                <?php if ($row['status'] === 'active'): ?>

                                    <span class="badge badge-active">Active</span>

                                <?php else: ?>

                                    <span class="badge badge-inactive">Inactive</span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <a
                                    href="subjects.php?edit=<?= (int)$row['id'] ?>"
                                    class="btn btn-sm btn-warning"
                                >
                                    Edit
                                </a>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No subjects found. Add your first subject above.
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
