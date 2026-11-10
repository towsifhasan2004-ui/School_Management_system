<?php

require_once __DIR__ . '/includes/auth.php';

require_permission('students.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/students.php');
}

/*
|--------------------------------------------------------------------------
| CSRF VALIDATION
|--------------------------------------------------------------------------
| Matches the working CSRF implementation in your system.
*/

verify_csrf();

/*
|--------------------------------------------------------------------------
| VALIDATE STUDENT ID
|--------------------------------------------------------------------------
*/

$studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

if (!$studentId || $studentId < 1) {
    redirect(BASE_URL . '/students.php');
}

/*
|--------------------------------------------------------------------------
| CHECK STUDENT EXISTS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id, photo_path
    FROM students
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $studentId);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    redirect(BASE_URL . '/students.php');
}

/*
|--------------------------------------------------------------------------
| CHECK FILE UPLOAD
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['student_photo'])) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=no_file');
}

$file = $_FILES['student_photo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=upload');
}

/*
|--------------------------------------------------------------------------
| FILE SIZE LIMIT: 2 MB
|--------------------------------------------------------------------------
*/

$maxSize = 2 * 1024 * 1024;

if ($file['size'] <= 0 || $file['size'] > $maxSize) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=size');
}

/*
|--------------------------------------------------------------------------
| VALIDATE REAL IMAGE TYPE
|--------------------------------------------------------------------------
*/

$tmpFile = $file['tmp_name'];

if (!is_uploaded_file($tmpFile)) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=invalid');
}

$imageInfo = @getimagesize($tmpFile);

if ($imageInfo === false) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=invalid');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpFile);

$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

if (
    !isset($allowedTypes[$mimeType]) ||
    $imageInfo['mime'] !== $mimeType
) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=type');
}

/*
|--------------------------------------------------------------------------
| CREATE UPLOAD DIRECTORY
|--------------------------------------------------------------------------
*/

$uploadDirectory = __DIR__ . '/uploads/student_photos/';

if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true)) {
        redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=folder');
    }
}

/*
|--------------------------------------------------------------------------
| GENERATE UNIQUE FILE NAME
|--------------------------------------------------------------------------
*/

$extension = $allowedTypes[$mimeType];

$fileName = 'student_' . $studentId . '_' .
    bin2hex(random_bytes(16)) . '.' . $extension;

$destination = $uploadDirectory . $fileName;

$relativePath = 'uploads/student_photos/' . $fileName;

/*
|--------------------------------------------------------------------------
| MOVE UPLOADED FILE
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpFile, $destination)) {
    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=save');
}

/*
|--------------------------------------------------------------------------
| UPDATE DATABASE
|--------------------------------------------------------------------------
*/

try {

    $conn->begin_transaction();

    $stmt = $conn->prepare("
        UPDATE students
        SET photo_path = ?
        WHERE id = ?
    ");

    $stmt->bind_param('si', $relativePath, $studentId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    if (is_file($destination)) {
        unlink($destination);
    }

    error_log('Student photo update failed: ' . $e->getMessage());

    redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_error=database');
}

/*
|--------------------------------------------------------------------------
| DELETE OLD PHOTO AFTER SUCCESSFUL DATABASE UPDATE
|--------------------------------------------------------------------------
*/

$oldPhoto = $student['photo_path'] ?? '';

if (
    $oldPhoto !== '' &&
    strpos($oldPhoto, 'uploads/student_photos/') === 0 &&
    basename($oldPhoto) === $oldPhoto ||
    (
        $oldPhoto !== '' &&
        strpos($oldPhoto, 'uploads/student_photos/') === 0 &&
        dirname($oldPhoto) === 'uploads/student_photos'
    )
) {

    $oldFile = __DIR__ . '/' . $oldPhoto;

    if (is_file($oldFile) && realpath($oldFile) !== realpath($destination)) {
        unlink($oldFile);
    }
}

redirect(BASE_URL . '/view_student.php?id=' . $studentId . '&photo_success=1');