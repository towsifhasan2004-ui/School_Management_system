
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('expenses.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function ec_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';
$editCategory = null;

/* =========================================
   HANDLE FORM SUBMISSIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        /* ADD CATEGORY */
        if ($action === 'add') {

            $nameEn = trim($_POST['category_name_en'] ?? '');
            $nameBn = trim($_POST['category_name_bn'] ?? '');

            if ($nameEn === '') {
                throw new Exception('English category name is required.');
            }

            if (mb_strlen($nameEn) > 100 || mb_strlen($nameBn) > 100) {
                throw new Exception('Category name must not exceed 100 characters.');
            }

            $stmt = $conn->prepare("
                INSERT INTO expense_categories
                    (category_name_en, category_name_bn, status)
                VALUES (?, ?, 'active')
            ");

            $stmt->bind_param("ss", $nameEn, $nameBn);
            $stmt->execute();
            $stmt->close();

            $message = 'Expense category created successfully.';
        }

        /* UPDATE CATEGORY */
        elseif ($action === 'update') {

            $id = (int)($_POST['category_id'] ?? 0);
            $nameEn = trim($_POST['category_name_en'] ?? '');
            $nameBn = trim($_POST['category_name_bn'] ?? '');

            if ($id <= 0 || $nameEn === '') {
                throw new Exception('Invalid category information.');
            }

            if (mb_strlen($nameEn) > 100 || mb_strlen($nameBn) > 100) {
                throw new Exception('Category name must not exceed 100 characters.');
            }

            $stmt = $conn->prepare("
                UPDATE expense_categories
                SET category_name_en = ?,
                    category_name_bn = ?
                WHERE id = ?
            ");

            $stmt->bind_param("ssi", $nameEn, $nameBn, $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                // Confirm the category exists even if values were unchanged.
                $stmt->close();

                $check = $conn->prepare("
                    SELECT id FROM expense_categories WHERE id = ?
                ");
                $check->bind_param("i", $id);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    throw new Exception('Category not found.');
                }
            } else {
                $stmt->close();
            }

            $message = 'Expense category updated successfully.';
        }

        /* ACTIVATE / DEACTIVATE */
        elseif ($action === 'toggle') {

            $id = (int)($_POST['category_id'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Invalid category.');
            }

            $stmt = $conn->prepare("
                UPDATE expense_categories
                SET status = CASE
                    WHEN status = 'active' THEN 'inactive'
                    ELSE 'active'
                END
                WHERE id = ?
            ");

            $stmt->bind_param("i", $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception('Category not found.');
            }

            $stmt->close();

            $message = 'Category status updated successfully.';
        }

        else {
            throw new Exception('Invalid request.');
        }

    } catch (mysqli_sql_exception $e) {

        if ((int)$e->getCode() === 1062) {
            $message = 'This expense category already exists.';
        } else {
            error_log('Expense category error: ' . $e->getMessage());
            $message = 'Database error. Please check the information and try again.';
        }

        $messageType = 'danger';

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/* =========================================
   LOAD CATEGORY FOR EDITING
========================================= */

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT id, category_name_en, category_name_bn, status
        FROM expense_categories
        WHERE id = ?
    ");

    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $editCategory = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* =========================================
   LOAD CATEGORIES
========================================= */

$categories = $conn->query("
    SELECT
        ec.id,
        ec.category_name_en,
        ec.category_name_bn,
        ec.status,
        COUNT(e.id) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_expense
    FROM expense_categories ec
    LEFT JOIN expenses e
        ON e.expense_category_id = ec.id
    GROUP BY
        ec.id,
        ec.category_name_en,
        ec.category_name_bn,
        ec.status
    ORDER BY ec.category_name_en ASC
");

/* SUMMARY */
$summary = $conn->query("
    SELECT
        COUNT(*) AS total_categories,
        SUM(status = 'active') AS active_categories,
        SUM(status = 'inactive') AS inactive_categories
    FROM expense_categories
")->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Expense Category Management | SISMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
            font-family: "Segoe UI", sans-serif;
            color: #263445;
        }

        .topbar {
            background: #102b46;
            color: white;
            padding: 17px 25px;
        }

        .topbar h4 {
            margin: 0;
            font-weight: 700;
        }

        .page-container {
            max-width: 1450px;
            margin: auto;
            padding: 28px 18px;
        }

        .page-heading {
            font-weight: 700;
            color: #102b46;
        }

        .panel {
            background: white;
            border-radius: 13px;
            padding: 23px;
            box-shadow: 0 4px 18px rgba(20, 40, 70, .06);
            margin-bottom: 22px;
        }

        .summary-card {
            border-left: 4px solid #d8ad4c;
        }

        .summary-number {
            font-size: 27px;
            font-weight: 750;
            color: #102b46;
        }

        .form-label {
            font-weight: 600;
            color: #34445a;
        }

        .btn-primary {
            background: #102b46;
            border-color: #102b46;
        }

        .btn-primary:hover {
            background: #1d466c;
            border-color: #1d466c;
        }

        .table thead th {
            background: #102b46;
            color: white;
            white-space: nowrap;
            padding: 13px;
        }

        .table td {
            vertical-align: middle;
            padding: 12px;
        }

        .category-name {
            font-weight: 650;
            color: #102b46;
        }

        .status-active {
            background: #d9f5e5;
            color: #137044;
        }

        .status-inactive {
            background: #eceff3;
            color: #657080;
        }

        .action-buttons {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        @media(max-width: 768px) {
            .page-container {
                padding: 18px 10px;
            }

            .panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="topbar">
    <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4>
            <i class="fa-solid fa-layer-group me-2"></i>
            SISMS Financial Management
        </h4>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-house me-1"></i>
            Dashboard
        </a>
    </div>
</div>

<div class="page-container">

    <div class="mb-4">
        <h3 class="page-heading">Expense Category Management</h3>
        <p class="text-muted mb-0">
            Create and manage categories used when recording school expenses.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= ec_h($messageType) ?> alert-dismissible fade show">
            <?= ec_h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="panel summary-card mb-0">
                <div class="text-muted">Total Categories</div>
                <div class="summary-number">
                    <?= (int)($summary['total_categories'] ?? 0) ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel summary-card mb-0">
                <div class="text-muted">Active Categories</div>
                <div class="summary-number text-success">
                    <?= (int)($summary['active_categories'] ?? 0) ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel summary-card mb-0">
                <div class="text-muted">Inactive Categories</div>
                <div class="summary-number text-secondary">
                    <?= (int)($summary['inactive_categories'] ?? 0) ?>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <!-- ADD / EDIT FORM -->
        <div class="col-lg-4">

            <div class="panel">

                <h5 class="fw-bold mb-3">
                    <i class="fa-solid fa-<?= $editCategory ? 'pen-to-square' : 'plus-circle' ?> me-2"></i>

                    <?= $editCategory ? 'Edit Category' : 'Create New Category' ?>
                </h5>

                <form method="POST">

                    <input type="hidden" name="csrf_token"
                           value="<?= ec_h(csrf_token()) ?>">

                    <input type="hidden" name="action"
                           value="<?= $editCategory ? 'update' : 'add' ?>">

                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id"
                               value="<?= (int)$editCategory['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">
                            Category Name (English) *
                        </label>

                        <input type="text"
                               name="category_name_en"
                               class="form-control"
                               maxlength="100"
                               required
                               value="<?= ec_h($editCategory['category_name_en'] ?? '') ?>"
                               placeholder="e.g. Electricity Bill">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Category Name (Bangla)
                        </label>

                        <input type="text"
                               name="category_name_bn"
                               class="form-control"
                               maxlength="100"
                               value="<?= ec_h($editCategory['category_name_bn'] ?? '') ?>"
                               placeholder="যেমন: বিদ্যুৎ বিল">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-save me-1"></i>

                        <?= $editCategory ? 'Update Category' : 'Save Category' ?>
                    </button>

                    <?php if ($editCategory): ?>
                        <a href="expense_categories.php"
                           class="btn btn-light border w-100 mt-2">
                            Cancel Editing
                        </a>
                    <?php endif; ?>

                </form>

            </div>

        </div>

        <!-- CATEGORY LIST -->
        <div class="col-lg-8">

            <div class="panel">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="fw-bold mb-0">
                        <i class="fa-solid fa-list me-2"></i>
                        Expense Categories
                    </h5>

                    <input type="search"
                           id="categorySearch"
                           class="form-control"
                           style="max-width:260px"
                           placeholder="Search category...">
                </div>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover" id="categoryTable">

                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Category</th>
                                <th>Bangla Name</th>
                                <th>Expenses</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if ($categories->num_rows > 0): ?>

                            <?php $i = 1; ?>

                            <?php while ($category = $categories->fetch_assoc()): ?>

                                <tr>

                                    <td><?= $i++ ?></td>

                                    <td class="category-name">
                                        <?= ec_h($category['category_name_en']) ?>
                                    </td>

                                    <td>
                                        <?= ec_h($category['category_name_bn'] ?: '-') ?>
                                    </td>

                                    <td>
                                        <?= (int)$category['expense_count'] ?>
                                    </td>

                                    <td>
                                        ৳<?= number_format((float)$category['total_expense'], 2) ?>
                                    </td>

                                    <td>
                                        <?php if ($category['status'] === 'active'): ?>
                                            <span class="badge status-active">Active</span>
                                        <?php else: ?>
                                            <span class="badge status-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="action-buttons">

                                            <a href="expense_categories.php?edit=<?= (int)$category['id'] ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>

                                            <form method="POST"
                                                  onsubmit="return confirm('Change this category status?');">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= ec_h(csrf_token()) ?>">

                                                <input type="hidden" name="action"
                                                       value="toggle">

                                                <input type="hidden" name="category_id"
                                                       value="<?= (int)$category['id'] ?>">

                                                <?php if ($category['status'] === 'active'): ?>
                                                    <button type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                            title="Deactivate">
                                                        <i class="fa-solid fa-ban"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit"
                                                            class="btn btn-sm btn-outline-success"
                                                            title="Activate">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                <?php endif; ?>

                                            </form>

                                        </div>
                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No expense categories found.
                                </td>
                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <small class="text-muted">
                    Categories already used in expenses cannot be deleted.
                    Deactivate them instead.
                </small>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('categorySearch').addEventListener('input', function () {

    const search = this.value.toLowerCase();

    document.querySelectorAll('#categoryTable tbody tr').forEach(function (row) {

        row.style.display = row.innerText.toLowerCase().includes(search)
            ? ''
            : 'none';

    });

});
</script>

</body>
</html>
