<?php
if (PHP_SAPI !== 'cli') {
    $requestedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    if ($requestedScript !== '' && $requestedScript === realpath(__FILE__)) {
        http_response_code(404);
        exit;
    }
}
// Database connection settings.
$host = "localhost";
$user = "root";
$password = "";
$database = "tp_amc";

// Initialize the mysqli client with timeouts.
$mysqli = mysqli_init();
if ($mysqli !== false) {
    mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    mysqli_options($mysqli, MYSQLI_OPT_READ_TIMEOUT, 8);
}
// Establish the database connection.
$conn = $mysqli ? @mysqli_real_connect($mysqli, $host, $user, $password, $database) ? $mysqli : null : null;

// Fail fast with logging and a friendly error page if connection fails.
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

// Ensure schema constraints and session enforcement hooks are applied.
require_once __DIR__ . '/schema_constraints.php';
ensure_core_database_constraints($conn);
if (function_exists('enforce_single_active_session')) {
    enforce_single_active_session($conn);
}
?>