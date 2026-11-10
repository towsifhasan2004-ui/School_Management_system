
<?php
require_once __DIR__ . '/includes/auth.php';

require_permission('payments.reverse');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function rp_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rp_money($value) {
    return number_format((float)$value, 2);
}

$error = '';

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$paymentId || $paymentId < 1) {
    http_response_code(400);
    exit('Invalid payment ID.');
}

/* ==========================================
   PROCESS REVERSAL
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $postedPaymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $reason = trim($_POST['reason'] ?? '');

    if (!$postedPaymentId || $postedPaymentId !== (int)$paymentId) {
        $error = 'Invalid payment reference.';
    } elseif ($reason === '') {
        $error = 'A reversal reason is required.';
    } elseif (mb_strlen($reason) > 500) {
        $error = 'The reason cannot exceed 500 characters.';
    } else {
        $reversedBy = (int)($_SESSION['user_id'] ?? 0);

        if ($reversedBy < 1) {
            $error = 'Unable to identify the logged-in user.';
        } else {
            $conn->begin_transaction();

            try {
                /* Lock payment row. */
                $stmt = $conn->prepare("
                    SELECT
                        id,
                        receipt_no,
                        amount,
                        status
                    FROM payments
                    WHERE id = ?
                    FOR UPDATE
                ");

                $stmt->bind_param("i", $paymentId);
                $stmt->execute();

                $payment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$payment) {
                    throw new Exception('Payment not found.');
                }

                if ($payment['status'] !== 'posted') {
                    throw new Exception(
                        'Only posted payments can be reversed. Current status: ' .
                        $payment['status']
                    );
                }

                /* Get every invoice allocated to this payment. */
                $stmt = $conn->prepare("
                    SELECT invoice_id, allocated_amount
                    FROM payment_allocations
                    WHERE payment_id = ?
                    ORDER BY invoice_id
                ");

                $stmt->bind_param("i", $paymentId);
                $stmt->execute();

                $allocationResult = $stmt->get_result();
                $allocations = [];

                while ($row = $allocationResult->fetch_assoc()) {
                    $allocations[] = $row;
                }

                $stmt->close();

                if (!$allocations) {
                    throw new Exception(
                        'This payment has no invoice allocation. Please review it manually.'
                    );
                }

                /*
                 * Record the full payment reversal.
                 * Original payment and allocations remain untouched.
                 */
                $reversalDate = date('Y-m-d H:i:s');
                $reversalAmount = (float)$payment['amount'];

                $stmt = $conn->prepare("
                    INSERT INTO payment_reversals (
                        payment_id,
                        reversal_type,
                        amount,
                        reason,
                        reversed_by,
                        reversal_date
                    ) VALUES (?, 'reversal', ?, ?, ?, ?)
                ");

                $stmt->bind_param(
    "idsis",
    $paymentId,
    $reversalAmount,
    $reason,
    $reversedBy,
    $reversalDate
);

                $stmt->execute();
                $stmt->close();

                /* Mark the original payment as reversed. */
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET status = 'reversed'
                    WHERE id = ?
                      AND status = 'posted'
                ");

                $stmt->bind_param("i", $paymentId);
                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    throw new Exception('Payment status could not be updated.');
                }

                $stmt->close();

                /*
                 * Recalculate every affected invoice.
                 * Reversed payments are excluded from paid totals.
                 */
                foreach ($allocations as $allocation) {
                    $invoiceId = (int)$allocation['invoice_id'];

                    $stmt = $conn->prepare("
                        SELECT id, total_amount, due_date, status
                        FROM invoices
                        WHERE id = ?
                        FOR UPDATE
                    ");

                    $stmt->bind_param("i", $invoiceId);
                    $stmt->execute();

                    $invoice = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$invoice) {
                        throw new Exception(
                            'An allocated invoice could not be found.'
                        );
                    }

                    if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
                        continue;
                    }

                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(pa.allocated_amount), 0) AS paid_amount
                        FROM payment_allocations pa
                        INNER JOIN payments p
                            ON p.id = pa.payment_id
                        WHERE pa.invoice_id = ?
                          AND p.status = 'posted'
                    ");

                    $stmt->bind_param("i", $invoiceId);
                    $stmt->execute();

                    $paidRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $paidAmount = (float)$paidRow['paid_amount'];
                    $invoiceTotal = (float)$invoice['total_amount'];

                    if ($paidAmount >= $invoiceTotal - 0.009) {
                        $newStatus = 'paid';
                    } elseif ($paidAmount > 0.009) {
                        $newStatus = 'partially_paid';
                    } elseif (
                        strtotime($invoice['due_date']) <
                        strtotime(date('Y-m-d'))
                    ) {
                        $newStatus = 'overdue';
                    } else {
                        $newStatus = 'issued';
                    }

                    $stmt = $conn->prepare("
                        UPDATE invoices
                        SET status = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param("si", $newStatus, $invoiceId);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                header(
                    'Location: payment_history.php?status=reversed'
                );
                exit;

            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

/* ==========================================
   LOAD PAYMENT DETAILS
========================================== */

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.receipt_no,
        p.payment_date,
        p.amount,
        p.transaction_reference,
        p.status,
        pm.method_name_en,
        s.admission_no,
        s.first_name_en,
        s.last_name_en,
        u.username AS received_by

    FROM payments p

    INNER JOIN payment_methods pm
        ON pm.id = p.payment_method_id

    INNER JOIN students s
        ON s.id = p.student_id

    INNER JOIN users u
        ON u.id = p.received_by

    WHERE p.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $paymentId);
$stmt->execute();

$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    http_response_code(404);
    exit('Payment not found.');
}

/* ==========================================
   LOAD ALLOCATIONS
========================================== */

$stmt = $conn->prepare("
    SELECT
        i.invoice_no,
        pa.allocated_amount
    FROM payment_allocations pa
    INNER JOIN invoices i
        ON i.id = pa.invoice_id
    WHERE pa.payment_id = ?
    ORDER BY pa.id
");

$stmt->bind_param("i", $paymentId);
$stmt->execute();

$allocationResult = $stmt->get_result();
$allocations = [];

while ($row = $allocationResult->fetch_assoc()) {
    $allocations[] = $row;
}

$stmt->close();

$canReverse = $payment['status'] === 'posted';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Reverse Payment | Sezua International School</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
      rel="stylesheet">

<style>
body {
    background: #f3f6fa;
    font-family: Arial, sans-serif;
    color: #263445;
}

.topbar {
    background: #102c4a;
    color: white;
    padding: 17px 24px;
}

.container-main {
    max-width: 850px;
    margin: 35px auto;
    padding: 0 15px;
}

.panel {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
}

.page-title {
    color: #102c4a;
    font-weight: 800;
}

.warning-panel {
    background: #fff4e5;
    border: 1px solid #f0c36d;
    padding: 15px;
    border-radius: 8px;
}

.info-label {
    color: #758294;
    font-size: 12px;
}

.info-value {
    font-weight: 700;
    margin-bottom: 15px;
}

.amount {
    font-size: 25px;
    font-weight: 800;
    color: #b42318;
}
</style>
</head>

<body>

<div class="topbar">
    <strong>SEZUA INTERNATIONAL SCHOOL</strong>
    <span class="float-end">Payment Reversal</span>
</div>

<div class="container-main">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="page-title mb-0">Payment Reversal</h3>

        <a href="payment_history.php" class="btn btn-outline-secondary">
            Back to History
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= rp_h($error) ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <div class="warning-panel mb-4">
            <strong>Important warning</strong>
            <p class="mb-0 mt-2 small">
                Reversing a payment will remove its contribution from the
                allocated invoice balances. This action is intended for
                incorrect or invalid transactions and cannot be undone
                through this page.
            </p>
        </div>

        <div class="row">

            <div class="col-md-6">
                <div class="info-label">Receipt Number</div>
                <div class="info-value">
                    <?= rp_h($payment['receipt_no']) ?>
                </div>

                <div class="info-label">Student</div>
                <div class="info-value">
                    <?= rp_h(trim(
                        $payment['first_name_en'] . ' ' .
                        $payment['last_name_en']
                    )) ?>
                </div>

                <div class="info-label">Admission Number</div>
                <div class="info-value">
                    <?= rp_h($payment['admission_no']) ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="info-label">Payment Date</div>
                <div class="info-value">
                    <?= date(
                        'd M Y h:i A',
                        strtotime($payment['payment_date'])
                    ) ?>
                </div>

                <div class="info-label">Payment Method</div>
                <div class="info-value">
                    <?= rp_h($payment['method_name_en']) ?>
                </div>

                <div class="info-label">Received By</div>
                <div class="info-value">
                    <?= rp_h($payment['received_by']) ?>
                </div>
            </div>

        </div>

        <div class="mb-4">
            <div class="info-label">Payment Amount</div>
            <div class="amount">
                ৳ <?= rp_money($payment['amount']) ?>
            </div>
        </div>

        <div class="info-label mb-2">Allocated Invoices</div>

        <div class="table-responsive mb-4">
            <table class="table table-bordered">

                <thead>
                    <tr>
                        <th>Invoice Number</th>
                        <th class="text-end">Allocated Amount</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($allocations as $allocation): ?>
                        <tr>
                            <td>
                                <?= rp_h($allocation['invoice_no']) ?>
                            </td>

                            <td class="text-end">
                                ৳ <?= rp_money($allocation['allocated_amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$allocations): ?>
                        <tr>
                            <td colspan="2" class="text-center text-muted">
                                No invoice allocations found.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>
        </div>

        <?php if ($canReverse && $allocations): ?>

            <form method="POST" id="reverseForm">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= rp_h(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="payment_id"
                    value="<?= (int)$payment['id'] ?>"
                >

                <div class="mb-3">
                    <label class="form-label fw-bold">
                        Reason for Reversal *
                    </label>

                    <textarea
                        name="reason"
                        class="form-control"
                        rows="4"
                        maxlength="500"
                        required
                        placeholder="Example: Payment was recorded against the wrong student."
                    ></textarea>

                    <div class="form-text">
                        Maximum 500 characters. This reason will be permanently
                        recorded in the reversal history.
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-danger px-4"
                    onclick="return confirm('Are you sure you want to reverse this entire payment?');"
                >
                    Confirm Payment Reversal
                </button>

            </form>

        <?php else: ?>

            <div class="alert alert-secondary mb-0">
                This payment cannot be reversed from this page.
                Current status:
                <strong><?= rp_h(strtoupper($payment['status'])) ?></strong>.
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
