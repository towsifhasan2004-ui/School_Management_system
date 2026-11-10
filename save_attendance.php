
<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('attendance.take');

date_default_timezone_set('Asia/Dhaka');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/attendance.php');
    exit;
}

// Verify CSRF token
verify_csrf();

// Logged-in user
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    exit('Invalid user session.');
}

// Get submitted filters
$classId = (int)($_POST['class_id'] ?? 0);
$sectionId = (int)($_POST['section_id'] ?? 0);
$shiftId = (int)($_POST['shift_id'] ?? 0);

$attendanceDate = $_POST['attendance_date'] ?? '';

$attendanceData = $_POST['attendance'] ?? [];

// Redirect helper
function attendanceRedirect($classId, $sectionId, $shiftId, $date, $message, $type = 'error')
{
    $params = [
        'class_id' => $classId,
        'section_id' => $sectionId,
        'shift_id' => $shiftId,
        'attendance_date' => $date,
        $type => $message
    ];

    header('Location: ' . BASE_URL . '/attendance.php?' . http_build_query($params));
    exit;
}

// Validate filters
if ($classId <= 0 || $sectionId <= 0 || $shiftId <= 0) {
    attendanceRedirect($classId, $sectionId, $shiftId, date('Y-m-d'), 'Invalid class, section or shift.');
}

// Validate date
$dateObject = DateTime::createFromFormat('!Y-m-d', $attendanceDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $attendanceDate) {
    attendanceRedirect($classId, $sectionId, $shiftId, date('Y-m-d'), 'Invalid attendance date.');
}

// Get current academic year
$yearQuery = $conn->query("
    SELECT id
    FROM academic_years
    WHERE is_current = 1
    LIMIT 1
");

$currentYear = $yearQuery->fetch_assoc();

if (!$currentYear) {
    attendanceRedirect($classId, $sectionId, $shiftId, $attendanceDate, 'No current academic year found.');
}

$academicYearId = (int)$currentYear['id'];

// Validate class, section and shift
$filterCheck = $conn->prepare("
    SELECT
        c.id AS class_id,
        sec.id AS section_id,
        sh.id AS shift_id
    FROM classes c
    INNER JOIN sections sec
        ON sec.class_id = c.id
    INNER JOIN shifts sh
        ON sh.id = ?
    WHERE c.id = ?
      AND sec.id = ?
      AND c.status = 'active'
      AND sec.status = 'active'
      AND sh.status = 'active'
    LIMIT 1
");

$filterCheck->bind_param(
    'iii',
    $shiftId,
    $classId,
    $sectionId
);

$filterCheck->execute();

$filterResult = $filterCheck->get_result();
$validFilters = $filterResult->num_rows === 1;

$filterCheck->close();

if (!$validFilters) {
    attendanceRedirect($classId, $sectionId, $shiftId, $attendanceDate, 'Invalid attendance filters.');
}

// Validate attendance data
if (!is_array($attendanceData) || empty($attendanceData)) {
    attendanceRedirect($classId, $sectionId, $shiftId, $attendanceDate, 'No attendance data received.');
}

$allowedStatuses = [
    'present',
    'absent',
    'late',
    'half_day',
    'leave'
];

$savedCount = 0;

try {

    $conn->begin_transaction();

    // Check whether each enrollment belongs to this class and filter.
    $checkEnrollment = $conn->prepare("
        SELECT e.id
        FROM enrollments e
        INNER JOIN students s
            ON s.id = e.student_id
        WHERE e.id = ?
          AND e.academic_year_id = ?
          AND e.class_id = ?
          AND e.section_id = ?
          AND e.shift_id = ?
          AND e.enrollment_status = 'active'
          AND s.status = 'active'
        LIMIT 1
    ");

    // Insert new attendance or update existing attendance.
    $saveAttendance = $conn->prepare("
        INSERT INTO attendance (
            enrollment_id,
            attendance_date,
            attendance_status,
            check_in_time,
            remarks,
            recorded_by
        )
        VALUES (?, ?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            attendance_status = VALUES(attendance_status),
            check_in_time = VALUES(check_in_time),
            remarks = VALUES(remarks),
            updated_by = VALUES(recorded_by)
    ");

    foreach ($attendanceData as $enrollmentId => $data) {

        $enrollmentId = filter_var(
            $enrollmentId,
            FILTER_VALIDATE_INT
        );

        if (!$enrollmentId || !is_array($data)) {
            throw new Exception('Invalid student attendance information.');
        }

        $status = trim($data['status'] ?? '');

        // Blank means Not Marked. Do not save blank attendance.
        if ($status === '') {
            continue;
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new Exception('Invalid attendance status selected.');
        }

        // Validate check-in time
        $checkInTime = trim($data['check_in_time'] ?? '');

        if ($checkInTime !== '') {

            $timeObject = DateTime::createFromFormat('!H:i', $checkInTime);

            if (!$timeObject || $timeObject->format('H:i') !== $checkInTime) {
                throw new Exception('Invalid check-in time.');
            }

            $checkInTime .= ':00';

        } else {
            $checkInTime = null;
        }

        // Validate remarks
        $remarks = trim($data['remarks'] ?? '');

        if (strlen($remarks) > 500) {
            throw new Exception('Remarks cannot exceed 500 characters.');
        }

        // Confirm that this enrollment belongs to the selected class.
        $checkEnrollment->bind_param(
            'iiiii',
            $enrollmentId,
            $academicYearId,
            $classId,
            $sectionId,
            $shiftId
        );

        $checkEnrollment->execute();

        $enrollmentResult = $checkEnrollment->get_result();

        if ($enrollmentResult->num_rows !== 1) {
            throw new Exception('An invalid student enrollment was submitted.');
        }

        // Save attendance
        $saveAttendance->bind_param(
            'issssi',
            $enrollmentId,
            $attendanceDate,
            $status,
            $checkInTime,
            $remarks,
            $userId
        );

        $saveAttendance->execute();

        $savedCount++;
    }

    $checkEnrollment->close();
    $saveAttendance->close();

    if ($savedCount === 0) {
        $conn->rollback();

        attendanceRedirect(
            $classId,
            $sectionId,
            $shiftId,
            $attendanceDate,
            'Please select an attendance status for at least one student.'
        );
    }

    $conn->commit();

    attendanceRedirect(
        $classId,
        $sectionId,
        $shiftId,
        $attendanceDate,
        $savedCount . ' student attendance record(s) saved successfully.',
        'saved'
    );

} catch (Throwable $e) {

    $conn->rollback();

    error_log('Attendance Save Error: ' . $e->getMessage());

    attendanceRedirect(
        $classId,
        $sectionId,
        $shiftId,
        $attendanceDate,
        'Attendance could not be saved. Please check the submitted information.'
    );
}
