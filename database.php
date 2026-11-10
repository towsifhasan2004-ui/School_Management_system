<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$port = 3306;
$username = "root";
$password = "";
$database = "sezua_sisms";

try {
    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    $conn->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {

    error_log($e->getMessage());

    die("Database connection failed. Please contact the administrator.");
}