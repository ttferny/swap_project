<?php
declare(strict_types=1);

if (!function_exists('is_https_request')) {
	function is_https_request(): bool
	{
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
			return true;
		}
		$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
		if (is_string($forwardedProto) && stripos($forwardedProto, 'https') !== false) {
			return true;
		}
		return ($_SERVER['SERVER_PORT'] ?? null) === '443';
	}
}

if (!function_exists('session_fingerprint_seed')) {
	function session_fingerprint_seed(): string
	{
		$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
		$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
		if ($remoteAddr !== '') {
			$segments = explode('.', $remoteAddr);
			$remoteAddr = implode('.', array_slice($segments, 0, 2));
		}
		return $userAgent . '|' . $remoteAddr;
	}
}

if (!function_exists('refresh_session_id')) {
	function refresh_session_id(bool $force = false): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}
		$now = time();
		$last = (int) ($_SESSION['session_regenerated_at'] ?? 0);
		if ($force || ($now - $last) >= 900) {
			session_regenerate_id(true);
			$_SESSION['session_regenerated_at'] = $now;
		}
	}
}

if (!function_exists('reset_session_state')) {
	function reset_session_state(): void
	{
		$_SESSION = [];
		if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			if (PHP_VERSION_ID >= 70300) {
				setcookie(session_name(), '', [
					'expires' => time() - 42000,
					'path' => $params['path'] ?? '/',
					'domain' => $params['domain'] ?? '',
					'httponly' => true,
					'samesite' => $params['samesite'] ?? 'Lax',
					'secure' => $params['secure'] ?? false,
				]);
			} else {
				setcookie(
					session_name(),
					'',
					time() - 42000,
					$params['path'] ?? '/',
					$params['domain'] ?? '',
					$params['secure'] ?? false,
					true
				);
			}
		}
		refresh_session_id(true);
	}
}

if (!function_exists('enforce_session_integrity')) {
	function enforce_session_integrity(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}
		$fingerprint = hash('sha256', session_fingerprint_seed());
		$existing = $_SESSION['session_fingerprint'] ?? null;
		if ($existing === null) {
			$_SESSION['session_fingerprint'] = $fingerprint;
			return;
		}
		if (!hash_equals((string) $existing, $fingerprint)) {
			$hadUser = isset($_SESSION['user_id']);
			reset_session_state();
			$_SESSION['session_fingerprint'] = $fingerprint;
			if ($hadUser) {
				$_SESSION['auth_notice'] = 'Please sign in again.';
			}
		}
	}
}

if (!function_exists('bootstrap_session')) {
	function bootstrap_session(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			$defaults = session_get_cookie_params();
			$options = [
				'lifetime' => 0,
				'path' => $defaults['path'] ?? '/',
				'domain' => $defaults['domain'] ?? '',
				'httponly' => true,
				'samesite' => 'Lax',
				'secure' => is_https_request(),
			];
			if (PHP_VERSION_ID >= 70300) {
				session_set_cookie_params($options);
			} else {
				session_set_cookie_params(
					$options['lifetime'],
					$options['path'],
					$options['domain'],
					$options['secure'],
					$options['httponly']
				);
			}
			if (session_status() !== PHP_SESSION_ACTIVE) {
				if (session_name() !== 'swap_session') {
					session_name('swap_session');
				}
				session_start();
			}
		}
		enforce_session_integrity();
		refresh_session_id();
	}
}

bootstrap_session();

if (!function_exists('current_user')) {
	function current_user(): ?array
	{
		if (!isset($_SESSION['user_id'])) {
			return null;
		}
		return [
			'user_id' => (int) ($_SESSION['user_id'] ?? 0),
			'admin_number' => (string) ($_SESSION['admin_number'] ?? ''),
			'role_id' => (int) ($_SESSION['role_id'] ?? 0),
			'role_name' => (string) ($_SESSION['role_name'] ?? ''),
			'full_name' => (string) ($_SESSION['full_name'] ?? ''),
		];
	}
}

if (!function_exists('is_authenticated')) {
	function is_authenticated(): bool
	{
		return current_user() !== null;
	}
}

if (!function_exists('sanitize_redirect_target')) {
	function sanitize_redirect_target(?string $value): string
	{
		$target = trim((string) $value);
		if ($target === '') {
			return '';
		}
		if (strpbrk($target, "\r\n")) {
			return '';
		}
		if (preg_match('#^(?:[a-z]+:)?//#i', $target)) {
			return '';
		}
			$target = preg_replace('#/+#', '/', $target);
		if ($target === null) {
			return '';
		}
		if (strpos($target, '..') !== false) {
			return '';
		}
		return $target;
	}
}

if (!function_exists('require_login')) {
	function require_login(array $allowedRoles = []): array
	{
		$user = current_user();
		if ($user === null) {
			$redirectTarget = sanitize_redirect_target($_SERVER['REQUEST_URI'] ?? '') ?: '/swap_project/index.php';
			header('Location: login.php?redirect=' . rawurlencode($redirectTarget));
			exit;
		}
		if (!empty($allowedRoles)) {
			$normalized = array_map(static function ($role): string {
				return strtolower(trim((string) $role));
			}, $allowedRoles);
			$currentRole = strtolower(trim((string) ($user['role_name'] ?? '')));
			if (!in_array($currentRole, $normalized, true)) {
				http_response_code(403);
				exit('Access denied.');
			}
		}
		return $user;
	}
}

if (!function_exists('redirect_if_authenticated')) {
	function redirect_if_authenticated(string $destination = 'index.php'): void
	{
		if (!is_authenticated()) {
			return;
		}
		$target = sanitize_redirect_target($destination);
		if ($target === '') {
			$target = '/swap_project/index.php';
		}
		header('Location: ' . $target);
		exit;
	}
}

if (!function_exists('generate_csrf_token')) {
	function generate_csrf_token(string $formKey): string
	{
		bootstrap_session();
		if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
			$_SESSION['csrf_tokens'] = [];
		}
		if (
			isset($_SESSION['csrf_tokens'][$formKey])
			&& is_array($_SESSION['csrf_tokens'][$formKey])
			&& isset($_SESSION['csrf_tokens'][$formKey]['value'], $_SESSION['csrf_tokens'][$formKey]['expires_at'])
			&& (int) $_SESSION['csrf_tokens'][$formKey]['expires_at'] >= time()
		) {
			return (string) $_SESSION['csrf_tokens'][$formKey]['value'];
		}
		$token = bin2hex(random_bytes(32));
		$_SESSION['csrf_tokens'][$formKey] = [
			'value' => $token,
			'expires_at' => time() + 900,
		];
		return $token;
	}
}

if (!function_exists('validate_csrf_token')) {
	function validate_csrf_token(string $formKey, ?string $token): bool
	{
		bootstrap_session();
		if (!isset($_SESSION['csrf_tokens'][$formKey])) {
			return false;
		}
		$record = $_SESSION['csrf_tokens'][$formKey];
		unset($_SESSION['csrf_tokens'][$formKey]);
		if (!is_array($record) || !isset($record['value'], $record['expires_at'])) {
			return false;
		}
		if ((int) $record['expires_at'] < time()) {
			return false;
		}
		return hash_equals((string) $record['value'], (string) $token);
	}
}

if (!function_exists('log_audit_event')) {
	function log_audit_event(mysqli $conn, ?int $actorId, string $action, string $entityType, ?int $entityId, array $details = []): void
	{
		$ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
		if ($ipAddress === '') {
			$ipAddress = null;
		}
		$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
		if ($userAgent !== '') {
			$userAgent = function_exists('mb_substr') ? mb_substr($userAgent, 0, 255) : substr($userAgent, 0, 255);
		} else {
			$userAgent = null;
		}
		$detailsJson = null;
		if (!empty($details)) {
			$detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($detailsJson === false) {
				$detailsJson = null;
			}
		}
		$stmt = mysqli_prepare(
			$conn,
			'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, user_agent, details) VALUES (NULLIF(?, 0), ?, ?, NULLIF(?, 0), ?, ?, ?)'
		);
		if (!$stmt) {
			return;
		}
		$actorParam = $actorId ?? 0;
		$entityParam = $entityId ?? 0;
		mysqli_stmt_bind_param(
			$stmt,
			'ississs',
			$actorParam,
			$action,
			$entityType,
			$entityParam,
			$ipAddress,
			$userAgent,
			$detailsJson
		);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
	}
}

if (!function_exists('apply_security_headers')) {
	function apply_security_headers(): void
	{
		if (headers_sent()) {
			return;
		}
		$cspDirectives = [
			"default-src 'self'",
			"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
			"font-src 'self' https://fonts.gstatic.com",
			"img-src 'self' data:",
			"script-src 'self'",
			"connect-src 'self'",
			"form-action 'self'",
			"frame-ancestors 'none'",
			"base-uri 'self'",
		];
		header('Content-Security-Policy: ' . implode('; ', $cspDirectives));
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: DENY');
		header('Referrer-Policy: same-origin');
		header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
	}
}

apply_security_headers();
