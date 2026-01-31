<?php
if (PHP_SAPI !== 'cli') {
    $requestedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    if ($requestedScript !== '' && $requestedScript === realpath(__FILE__)) {
        http_response_code(404);
        exit;
    }
}
$host = "localhost";
$user = "root";
$password = "";
$database = "tp_amc";

$mysqli = mysqli_init();
if ($mysqli !== false) {
    mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    mysqli_options($mysqli, MYSQLI_OPT_READ_TIMEOUT, 8);
}
$conn = $mysqli ? @mysqli_real_connect($mysqli, $host, $user, $password, $database) ? $mysqli : null : null;

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
if (function_exists('enforce_single_active_session')) {
    enforce_single_active_session($conn);
}
?>