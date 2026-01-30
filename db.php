<?php
$host = "localhost";
$user = "root";
$password = "";
$database = "tp_amc";

$conn = @mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    $errorMessage = 'Database connection failed: ' . mysqli_connect_error();
    if (function_exists('record_system_error')) {
        record_system_error(new RuntimeException($errorMessage), ['stage' => 'db_connection']);
    }
    if (function_exists('render_http_error')) {
        render_http_error(500, 'We could not reach the database server just now.');
    }
    die('A fatal error occurred.');
}

require_once __DIR__ . '/schema_constraints.php';
ensure_core_database_constraints($conn);
?>