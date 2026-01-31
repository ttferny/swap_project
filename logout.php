<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$redirectTarget = sanitize_redirect_target($_POST['redirect_to'] ?? $_GET['redirect'] ?? '') ?: 'login.php';
$csrfToken = $_POST['csrf_token'] ?? null;
$logoutMessage = 'You have been signed out.';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf_token('logout_form', $csrfToken)) {
	$logoutMessage = 'We could not verify your logout request.';
	$_SESSION['auth_notice'] = $logoutMessage;
	header('Location: ' . $redirectTarget);
	exit;
}

$actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$entityId = $actorId;
$details = [
	'event' => 'logout',
	'admin_number' => $_SESSION['admin_number'] ?? null,
];
if (isset($conn) && $conn instanceof mysqli) {
	log_audit_event($conn, $actorId, 'logout', 'authentication', $entityId, $details);
	if (function_exists('clear_active_user_session')) {
		$fingerprint = isset($_SESSION['device_fingerprint']) ? (string) $_SESSION['device_fingerprint'] : null;
		clear_active_user_session($conn, $actorId, $fingerprint);
	}
}

unset($_SESSION['active_session_token']);

clear_jwt_cookie();
reset_session_state();
$_SESSION['auth_notice'] = $logoutMessage;

header('Location: ' . $redirectTarget);
exit;
