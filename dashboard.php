<?php

require_once __DIR__ . '/includes/auth.php';

require_login();
require_permission('dashboard.view');

$username = $_SESSION['username'];

$roles = get_user_roles();

$roleNames = array_column($roles, 'role_name_en');

$school = $conn->query("
    SELECT school_name_en, school_name_bn
    FROM school_profile
    ORDER BY id ASC
    LIMIT 1
")->fetch_assoc();

$schoolName = $school['school_name_en']
    ?? 'Sezua International School';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dashboard | Sezua International School</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="<?= BASE_URL ?>/assets/css/style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="dashboard-wrapper">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="sidebar-brand">

            <div class="brand-logo">S</div>

            <div>
                <h5>SEZUA</h5>
                <small>International School</small>
            </div>

        </div>

        <div class="sidebar-menu">

            <small class="menu-heading">MAIN MENU</small>

            <a href="dashboard.php" class="menu-link active">
                <i class="fa-solid fa-gauge-high"></i>
                Dashboard
            </a>

            <small class="menu-heading">SCHOOL MANAGEMENT</small>

<?php if (has_permission('students.view')): ?>
    <a href="students.php" class="menu-link">
        <i class="fa-solid fa-user-graduate"></i>
        Students
    </a>
<?php endif; ?>

            <div class="menu-link disabled-link">
                <i class="fa-solid fa-chalkboard-user"></i>
                Teachers
                <span class="coming-soon">Soon</span>
            </div>
            <a href="employees.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Employee & Teacher Management
</a>
<a href="employee_attendance.php">
    Employee Attendance
</a>
<a href="employee_attendance_report.php">
    Employee Attendance Report
</a>
<a href="employee_attendance_summary.php">
    Monthly Attendance Summary
</a>
<a href="employee_leave.php">Employee Leave Management</a>
<a href="employee_leave_balance.php">Employee Leave Balance</a>
<a href="employee_leave_report.php">Employee Leave Reports</a>
<a href="employee_leave_calendar.php">Employee Leave Calendar</a>
            <a href="salary_components.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Salary Components
</a>
         <a href="employee_salary_structure.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Employee Salary Structure
</a>
         <a href="monthly_payroll.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Payroll Generation
</a>
        <a href="payroll_review.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Payroll Review
</a>
</a>
        <a href="payroll_payment.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Payroll Payment
</a>
</a>
        <a href="payroll_reports.php" class="nav-link">
    <i class="fa-solid fa-users me-2"></i>
    Payroll Reports
</a>

            <div class="menu-link disabled-link">
                <i class="fa-solid fa-book-open"></i>
                Academics
                <span class="coming-soon">Soon</span>
            </div>
                           <a href="result_processing.php" class="nav-link">
    Result Processing & Publication
</a>
            <a href="subjects.php" class="nav-link">
    Subject Management
</a>

<a href="class_subject_assignment.php" class="nav-link">
    Class Subject Assignment
</a>

<?php if (has_permission('attendance.view')): ?>

<li class="nav-item">
    <a href="<?= BASE_URL ?>/attendance.php" class="nav-link">
        <i class="fa-solid fa-calendar-check"></i>
        Student Attendance
    </a>
</li>

<?php endif; ?>
<a href="<?= BASE_URL ?>/attendance_report.php">
    <i class="fa-solid fa-chart-column"></i>
    Attendance Report
</a>

<a href="<?= BASE_URL ?>/examinations.php">
    <i class="fa-solid fa-file-pen"></i>
    Examination Management
</a>
<a href="<?= BASE_URL ?>/exam_subjects.php">
    <i class="fa-solid fa-book-open"></i>
    Exam Subject Setup
</a>
<a href="marks_entry.php" class="nav-link">
    Marks Entry
</a>
<a href="marks_verification.php" class="nav-link">
    Marks Verification
</a>
<a href="grading_scales.php" class="nav-link">
    Grading Scale Management
</a>
<a href="student_report_card.php" class="nav-link">
    <i class="fas fa-file-alt"></i>
    <span>Student Report Card</span>
</a>

            <small class="menu-heading">FINANCE</small>

            <div class="menu-link disabled-link">
                <i class="fa-solid fa-money-bill-wave"></i>
                Fees & Payments
                <span class="coming-soon">Soon</span>
            </div>
            <div>
            <a href="fee_structures.php" class="nav-link">
    <i class="fas fa-money-bill-wave"></i>
    <span>Fee Structure Management</span>
</a>
</div>
<div>
<a href="generate_invoices.php" class="nav-link">
    <i class="fa-solid fa-file-invoice-dollar me-2"></i>
    Generate Fee Invoices
</a>
<a href="invoices.php" class="nav-link">
    <i class="fa-solid fa-file-invoice me-2"></i>
    Invoice Management
</a>
<a href="collect_payment.php" class="nav-link">
    <i class="fa-solid fa-money-bill-wave me-2"></i>
    Payment Collection
</a>
<a href="payment_history.php" class="nav-link">
    <i class="fa-solid fa-clock-rotate-left me-2"></i>
    Payment History
</a>
</div>
            <div class="menu-link disabled-link">
                <i class="fa-solid fa-wallet"></i>
                Expenses
                <span class="coming-soon">Soon</span>
                
            </div>
            <a href="expense_categories.php" class="nav-link">
    <i class="fa-solid fa-layer-group me-2"></i>
    Expense Categories
</a>
            <a href="expenses.php" class="nav-link">
    <i class="fa-solid fa-wallet me-2"></i>
    Expense Management
</a>
<a href="expense_approval.php" class="nav-link">
    <i class="fa-solid fa-clipboard-check me-2"></i>
    Expense Approval
</a>
<a href="expense_reports.php" class="nav-link">
    <i class="fa-solid fa-chart-column me-2"></i>
    Expense Reports
</a>


            <small class="menu-heading">SYSTEM</small>

            <?php if (has_permission('users.view')): ?>

                <div class="menu-link disabled-link">
                    <i class="fa-solid fa-users-gear"></i>
                    User Management
                    <span class="coming-soon">Soon</span>
                </div>
                <a href="user_management.php" class="nav-link">
    <i class="fa-solid fa-chart-column me-2"></i>
    User Management
</a>

            <?php endif; ?>

            <?php if (has_permission('settings.manage')): ?>

                <div class="menu-link disabled-link">
                    <i class="fa-solid fa-gear"></i>
                    Settings
                    <span class="coming-soon">Soon</span>
                </div>

            <?php endif; ?>

        </div>

        <div class="sidebar-footer">

            <div class="sidebar-footer-text">
                SISMS Version 1.0
            </div>

        </div>

    </aside>


    <!-- MAIN CONTENT -->

    <main class="main-content">

        <!-- TOP NAVBAR -->

        <nav class="top-navbar">

            <div>
                <h6 class="mb-0"><?= e($schoolName) ?></h6>
                <small class="text-muted">
                    School Management System
                </small>
            </div>

            <div class="user-profile">

                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>

                <div class="user-details">

                    <strong><?= e($username) ?></strong>

                    <small>
                        <?= e(implode(', ', $roleNames)) ?>
                    </small>

                </div>

                <a href="logout.php" class="logout-button"
                   onclick="return confirm('Are you sure you want to logout?')">

                    <i class="fa-solid fa-right-from-bracket"></i>

                </a>

            </div>

        </nav>


        <!-- DASHBOARD BODY -->

        <div class="dashboard-body">

            <div class="welcome-section">

                <div>

                    <span class="welcome-label">
                        SCHOOL MANAGEMENT OVERVIEW
                    </span>

                    <h2>
                        Welcome back, <?= e($username) ?>!
                    </h2>

                    <p>
                        Manage your school's daily activities from one place.
                    </p>

                </div>

                <div class="today-date">

                    <i class="fa-regular fa-calendar"></i>

                    <?= date('l, d F Y') ?>

                </div>

            </div>


            <!-- SUMMARY CARDS -->

            <div class="row g-4 mt-2">

                <div class="col-xl-3 col-md-6">

                    <div class="summary-card">

                        <div class="summary-icon blue">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>

                        <div>
                            <p>Total Students</p>
                            <h3>—</h3>
                            <small>Student module coming soon</small>
                        </div>

                    </div>

                </div>


                <div class="col-xl-3 col-md-6">

                    <div class="summary-card">

                        <div class="summary-icon green">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>

                        <div>
                            <p>Total Teachers</p>
                            <h3>—</h3>
                            <small>Teacher module coming soon</small>
                        </div>

                    </div>

                </div>


                <div class="col-xl-3 col-md-6">

                    <div class="summary-card">

                        <div class="summary-icon orange">
                            <i class="fa-solid fa-wallet"></i>
                        </div>

                        <div>
                            <p>Fee Collection</p>
                            <h3>—</h3>
                            <small>Finance module coming soon</small>
                        </div>

                    </div>

                </div>


                <div class="col-xl-3 col-md-6">

                    <div class="summary-card">

                        <div class="summary-icon purple">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>

                        <div>
                            <p>Attendance</p>
                            <h3>—</h3>
                            <small>Attendance module coming soon</small>
                        </div>

                    </div>

                </div>

            </div>


            <!-- QUICK ACCESS -->

            <div class="section-heading">

                <h4>System Overview</h4>

                <p>
                    Your school management modules will appear here.
                </p>

            </div>


            <div class="row g-4">

                <div class="col-lg-8">

                    <div class="content-card">

                        <h5>Development Progress</h5>

                        <div class="progress-item">

                            <div class="d-flex justify-content-between">
                                <span>Authentication & Security</span>
                                <strong>Phase 1</strong>
                            </div>

                            <div class="progress mt-2">
                                <div class="progress-bar"
                                     style="width:100%">
                                </div>
                            </div>

                        </div>

                        <div class="progress-item mt-4">

                            <div class="d-flex justify-content-between">
                                <span>Student Management</span>
                                <strong>Upcoming</strong>
                            </div>

                            <div class="progress mt-2">
                                <div class="progress-bar bg-secondary"
                                     style="width:0%">
                                </div>
                            </div>

                        </div>

                        <div class="progress-item mt-4">

                            <div class="d-flex justify-content-between">
                                <span>Academic Management</span>
                                <strong>Upcoming</strong>
                            </div>

                            <div class="progress mt-2">
                                <div class="progress-bar bg-secondary"
                                     style="width:0%">
                                </div>
                            </div>

                        </div>

                    </div>

                </div>


                <div class="col-lg-4">

                    <div class="content-card">

                        <h5>System Information</h5>

                        <div class="system-info">

                            <span>School</span>
                            <strong><?= e($schoolName) ?></strong>

                        </div>

                        <div class="system-info">

                            <span>Academic Medium</span>
                            <strong>Bangla Medium</strong>

                        </div>

                        <div class="system-info">

                            <span>Campus</span>
                            <strong>Single Campus</strong>

                        </div>

                        <div class="system-info">

                            <span>Language Support</span>
                            <strong>English / বাংলা</strong>

                        </div>

                        <div class="system-info">

                            <span>System Version</span>
                            <strong>1.0</strong>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

</body>
</html>