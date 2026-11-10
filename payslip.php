
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payroll.manage');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function ps_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$itemId = (int)($_GET['item_id'] ?? 0);

if ($itemId <= 0) {
    die('Invalid payslip request.');
}

/* =========================================
   LOAD EMPLOYEE PAYROLL INFORMATION
========================================= */

$stmt = $conn->prepare("
    SELECT
        pi.id AS payroll_item_id,
        pi.gross_salary,
        pi.total_deduction,
        pi.net_salary,
        pi.payment_status,
        pi.payment_date,

        pr.id AS payroll_run_id,
        pr.payroll_month,
        pr.status AS payroll_status,

        e.employee_code,
        e.first_name_en,
        e.last_name_en,
        e.phone,
        e.email,
        e.joining_date,
        e.employment_type,

        d.department_name_en AS department_name,
        des.designation_name_en AS designation_name

    FROM payroll_items pi

    INNER JOIN payroll_runs pr
        ON pr.id = pi.payroll_run_id

    INNER JOIN employees e
        ON e.id = pi.employee_id

    LEFT JOIN departments d
        ON d.id = e.department_id

    LEFT JOIN designations des
        ON des.id = e.designation_id

    WHERE pi.id = ?
      AND pr.status IN ('processed', 'paid')

    LIMIT 1
");

$stmt->bind_param("i", $itemId);
$stmt->execute();

$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee) {
    die('Payslip not found. Only processed or paid payroll records are available.');
}

/* =========================================
   LOAD SALARY COMPONENTS
========================================= */

$components = [];

$stmt = $conn->prepare("
    SELECT
        component_name,
        component_type,
        amount

    FROM payroll_item_components

    WHERE payroll_item_id = ?

    ORDER BY
        CASE
            WHEN component_type = 'earning' THEN 1
            ELSE 2
        END,
        component_name
");

$stmt->bind_param("i", $itemId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $components[] = $row;
}

$stmt->close();

/* =========================================
   SEPARATE EARNINGS AND DEDUCTIONS
========================================= */

$earnings = [];
$deductions = [];

$earningTotal = 0;
$deductionTotal = 0;

foreach ($components as $component) {

    if ($component['component_type'] === 'earning') {

        $earnings[] = $component;
        $earningTotal += (float)$component['amount'];

    } elseif ($component['component_type'] === 'deduction') {

        $deductions[] = $component;
        $deductionTotal += (float)$component['amount'];
    }
}

/* =========================================
   VERIFY PAYSLIP TOTALS
========================================= */

if (
    abs($earningTotal - (float)$employee['gross_salary']) > 0.01 ||
    abs($deductionTotal - (float)$employee['total_deduction']) > 0.01 ||
    abs(
        (float)$employee['net_salary'] -
        ($earningTotal - $deductionTotal)
    ) > 0.01
) {
    die('Payslip validation failed. Please check the employee payroll components.');
}

$employeeName = trim(
    $employee['first_name_en'] . ' ' . $employee['last_name_en']
);

$payrollMonth = date(
    'F Y',
    strtotime($employee['payroll_month'])
);

$paymentStatus = ucfirst($employee['payment_status']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payslip - <?= ps_h($employeeName) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
      rel="stylesheet">

<style>

body {
    background: #eef2f6;
    font-family: Arial, sans-serif;
    color: #243247;
}

.payslip-container {
    max-width: 900px;
    margin: 30px auto;
    background: white;
    padding: 40px;
    border: 1px solid #dfe5ec;
    border-radius: 10px;
}

.school-header {
    text-align: center;
    border-bottom: 3px solid #173b60;
    padding-bottom: 22px;
    margin-bottom: 25px;
}

.school-name {
    color: #173b60;
    font-size: 27px;
    font-weight: 800;
    letter-spacing: 1px;
}

.school-subtitle {
    color: #718096;
    font-size: 13px;
    margin-top: 5px;
}

.document-title {
    display: inline-block;
    background: #173b60;
    color: white;
    padding: 9px 28px;
    border-radius: 5px;
    font-weight: 700;
    letter-spacing: 1px;
    margin-top: 18px;
}

.section-heading {
    color: #173b60;
    font-size: 15px;
    font-weight: 700;
    border-bottom: 1px solid #dfe5ec;
    padding-bottom: 9px;
    margin-bottom: 15px;
}

.info-table td {
    padding: 7px 5px;
    font-size: 13px;
}

.info-label {
    color: #68788b;
    width: 145px;
}

.salary-table th {
    background: #173b60;
    color: white;
    font-size: 13px;
    padding: 11px;
}

.salary-table td {
    padding: 10px;
    font-size: 13px;
}

.total-row {
    background: #edf2f7;
    font-weight: 700;
}

.net-box {
    background: #102d4b;
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-top: 25px;
}

.net-amount {
    font-size: 27px;
    font-weight: 800;
}

.payment-badge {
    display: inline-block;
    padding: 6px 13px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.paid {
    background: #d9f4e5;
    color: #147442;
}

.unpaid {
    background: #fff0ce;
    color: #805900;
}

.signature-area {
    display: flex;
    justify-content: space-between;
    gap: 40px;
    margin-top: 65px;
}

.signature {
    width: 220px;
    text-align: center;
    border-top: 1px solid #68788b;
    padding-top: 10px;
    font-size: 12px;
}

.footer-note {
    text-align: center;
    border-top: 1px solid #dfe5ec;
    margin-top: 40px;
    padding-top: 15px;
    color: #718096;
    font-size: 11px;
}

@media print {

    body {
        background: white;
    }

    .no-print {
        display: none !important;
    }

    .payslip-container {
        max-width: 100%;
        margin: 0;
        padding: 20px;
        border: none;
        border-radius: 0;
    }

    @page {
        size: A4;
        margin: 12mm;
    }

    .salary-table th {
        background: #173b60 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .net-box {
        background: #102d4b !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

}

</style>

</head>

<body>

<div class="container payslip-container">

    <!-- ACTION BUTTONS -->

    <div class="no-print d-flex justify-content-between flex-wrap gap-2 mb-4">

        <a href="payroll_payment.php?run_id=<?= (int)$employee['payroll_run_id'] ?>"
           class="btn btn-outline-secondary">

            <i class="fa-solid fa-arrow-left me-1"></i>
            Back to Payments

        </a>

        <button type="button"
                onclick="window.print()"
                class="btn btn-primary">

            <i class="fa-solid fa-print me-1"></i>
            Print / Save PDF

        </button>

    </div>

    <!-- SCHOOL HEADER -->

    <div class="school-header">

        <div class="school-name">
            SEZUA INTERNATIONAL SCHOOL
        </div>

        <div class="school-subtitle">
            Employee Salary Statement
        </div>

        <div class="document-title">
            MONTHLY PAYSLIP
        </div>

    </div>

    <!-- PAYSLIP PERIOD -->

    <div class="row mb-4">

        <div class="col-6">

            <small class="text-muted">Payroll Month</small>

            <h5 class="fw-bold text-primary">
                <?= ps_h($payrollMonth) ?>
            </h5>

        </div>

        <div class="col-6 text-end">

            <small class="text-muted">Payroll Reference</small>

            <h5 class="fw-bold">
                PR-<?= (int)$employee['payroll_run_id'] ?>
            </h5>

        </div>

    </div>

    <!-- EMPLOYEE INFORMATION -->

    <h6 class="section-heading">
        EMPLOYEE INFORMATION
    </h6>

    <div class="row mb-4">

        <div class="col-md-6">

            <table class="info-table w-100">

                <tr>
                    <td class="info-label">Employee Name</td>
                    <td><strong><?= ps_h($employeeName) ?></strong></td>
                </tr>

                <tr>
                    <td class="info-label">Employee Code</td>
                    <td><?= ps_h($employee['employee_code']) ?></td>
                </tr>

                <tr>
                    <td class="info-label">Department</td>
                    <td><?= ps_h($employee['department_name'] ?? 'Not assigned') ?></td>
                </tr>

                <tr>
                    <td class="info-label">Designation</td>
                    <td><?= ps_h($employee['designation_name'] ?? 'Not assigned') ?></td>
                </tr>

            </table>

        </div>

        <div class="col-md-6">

            <table class="info-table w-100">

                <tr>
                    <td class="info-label">Phone</td>
                    <td><?= ps_h($employee['phone']) ?></td>
                </tr>

                <tr>
                    <td class="info-label">Email</td>
                    <td><?= ps_h($employee['email'] ?? 'Not provided') ?></td>
                </tr>

                <tr>
                    <td class="info-label">Joining Date</td>
                    <td><?= ps_h($employee['joining_date']) ?></td>
                </tr>

                <tr>
                    <td class="info-label">Employment Type</td>
                    <td><?= ps_h(ucwords(str_replace('_', ' ', $employee['employment_type']))) ?></td>
                </tr>

            </table>

        </div>

    </div>

    <!-- SALARY BREAKDOWN -->

    <h6 class="section-heading">
        SALARY BREAKDOWN
    </h6>

    <div class="table-responsive">

        <table class="table table-bordered salary-table">

            <thead>

                <tr>
                    <th style="width:10%">SL</th>
                    <th>Salary Component</th>
                    <th class="text-end" style="width:30%">Amount (BDT)</th>
                </tr>

            </thead>

            <tbody>

                <?php $sl = 1; ?>

                <?php foreach ($earnings as $earning): ?>

                    <tr>

                        <td><?= $sl++ ?></td>

                        <td>
                            <?= ps_h($earning['component_name']) ?>
                        </td>

                        <td class="text-end">
                            <?= number_format((float)$earning['amount'], 2) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (empty($earnings)): ?>

                    <tr>
                        <td colspan="3" class="text-center">
                            No earning components.
                        </td>
                    </tr>

                <?php endif; ?>

                <tr class="total-row">

                    <td colspan="2">
                        TOTAL GROSS SALARY
                    </td>

                    <td class="text-end">
                        <?= number_format($earningTotal, 2) ?>
                    </td>

                </tr>

            </tbody>

        </table>

    </div>

    <div class="table-responsive mt-4">

        <table class="table table-bordered salary-table">

            <thead>

                <tr>
                    <th style="width:10%">SL</th>
                    <th>Deduction Component</th>
                    <th class="text-end" style="width:30%">Amount (BDT)</th>
                </tr>

            </thead>

            <tbody>

                <?php $sl = 1; ?>

                <?php foreach ($deductions as $deduction): ?>

                    <tr>

                        <td><?= $sl++ ?></td>

                        <td>
                            <?= ps_h($deduction['component_name']) ?>
                        </td>

                        <td class="text-end">
                            <?= number_format((float)$deduction['amount'], 2) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (empty($deductions)): ?>

                    <tr>
                        <td colspan="3" class="text-center">
                            No deductions.
                        </td>
                    </tr>

                <?php endif; ?>

                <tr class="total-row">

                    <td colspan="2">
                        TOTAL DEDUCTIONS
                    </td>

                    <td class="text-end">
                        <?= number_format($deductionTotal, 2) ?>
                    </td>

                </tr>

            </tbody>

        </table>

    </div>

    <!-- NET SALARY -->

    <div class="net-box">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div>

                <div class="small opacity-75">
                    NET SALARY PAYABLE
                </div>

                <div class="net-amount">
                    ৳<?= number_format((float)$employee['net_salary'], 2) ?>
                </div>

            </div>

            <div class="text-end">

                <div class="small opacity-75">
                    Payment Status
                </div>

                <?php if ($employee['payment_status'] === 'paid'): ?>

                    <span class="payment-badge paid">
                        <i class="fa-solid fa-check me-1"></i>
                        PAID
                    </span>

                <?php else: ?>

                    <span class="payment-badge unpaid">
                        UNPAID
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- PAYMENT DETAILS -->

    <div class="mt-4">

        <h6 class="section-heading">
            PAYMENT INFORMATION
        </h6>

        <table class="info-table w-100">

            <tr>

                <td class="info-label">
                    Payment Status
                </td>

                <td>
                    <strong><?= ps_h($paymentStatus) ?></strong>
                </td>

            </tr>

            <tr>

                <td class="info-label">
                    Payment Date
                </td>

                <td>
                    <?= $employee['payment_date']
                        ? ps_h($employee['payment_date'])
                        : 'Not paid yet' ?>
                </td>

            </tr>

        </table>

    </div>

    <!-- SIGNATURES -->

    <div class="signature-area">

        <div class="signature">
            Prepared By
        </div>

        <div class="signature">
            Authorized Signature
        </div>

        <div class="signature">
            Employee Signature
        </div>

    </div>

    <!-- FOOTER -->

    <div class="footer-note">

        This is a computer-generated salary statement.

        <br>

        SEZUA INTERNATIONAL SCHOOL

    </div>

</div>

</body>
</html>
