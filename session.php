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

if (!function_exists('application_log_directory')) {
	function application_log_directory(): string
	{
		$logDir = __DIR__ . '/storage/logs';
		if (!is_dir($logDir)) {
			@mkdir($logDir, 0775, true);
		}
		return $logDir;
	}
}

if (!function_exists('record_system_error')) {
	function record_system_error(Throwable $throwable, array $context = []): void
	{
		try {
			$logDir = application_log_directory();
			$payload = [
				'timestamp' => gmdate('c'),
				'error_class' => get_class($throwable),
				'error_message' => $throwable->getMessage(),
				'file' => $throwable->getFile(),
				'line' => $throwable->getLine(),
				'stack_trace' => $throwable->getTraceAsString(),
				'context' => $context,
			];
			$logFile = $logDir . '/app-error.log';
			$logEntry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($logEntry !== false) {
				@file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
			}
		} catch (Throwable $loggingError) {
			// As a last resort, fall back to PHP's native error log to avoid surfacing stack traces to the UI.
			error_log('Failed to persist application error: ' . $loggingError->getMessage());
		}
	}
}

if (!function_exists('render_http_error')) {
	function render_http_error(int $statusCode, ?string $userMessage = null, ?string $userAction = null): void
	{
		$allowedStatuses = [400, 403, 404, 500];
		if (!in_array($statusCode, $allowedStatuses, true)) {
			$statusCode = 500;
		}
		http_response_code($statusCode);
		$defaults = [
			400 => [
				'title' => 'We could not process that request.',
				'message' => 'Something in the request looked unusual or incomplete.',
			],
			403 => [
				'title' => 'You do not have permission to view this page.',
				'message' => 'Your account is missing one of the required privileges.',
			],
			404 => [
				'title' => 'We could not find what you were looking for.',
				'message' => 'The link may be outdated or the resource may have moved.',
			],
			500 => [
				'title' => 'Something went wrong on our side.',
				'message' => 'An unexpected error occurred while processing your request.',
			],
		];
		$title = $defaults[$statusCode]['title'];
		$message = $userMessage !== null && $userMessage !== '' ? $userMessage : $defaults[$statusCode]['message'];
		$action = $userAction !== null && $userAction !== '' ? $userAction : 'Please try again or contact support.';
		$viewPath = __DIR__ . '/errors/error-page.php';
		if (PHP_SAPI === 'cli' || !is_file($viewPath)) {
			echo $title . PHP_EOL . $message . PHP_EOL . $action;
			exit;
		}
		$errorPageData = [
			'code' => $statusCode,
			'title' => $title,
			'message' => $message,
			'action' => $action,
		];
		include $viewPath;
		exit;
	}
}

if (!function_exists('register_global_error_handlers')) {
	function register_global_error_handlers(): void
	{
		static $registered = false;
		if ($registered) {
			return;
		}
		$registered = true;
		@ini_set('display_errors', '0');
		@error_reporting(E_ALL);
		set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
			if (!(error_reporting() & $severity)) {
				return false;
			}
			throw new ErrorException($message, 0, $severity, $file, $line);
		});
		set_exception_handler(static function (Throwable $throwable): void {
			record_system_error($throwable, ['handler' => 'exception']);
			render_http_error(500);
		});
		register_shutdown_function(static function (): void {
			$error = error_get_last();
			if ($error === null) {
				return;
			}
			$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
			if (!in_array($error['type'], $fatalTypes, true)) {
				return;
			}
			$throwable = new ErrorException($error['message'], 0, $error['type'], $error['file'], (int) $error['line']);
			record_system_error($throwable, ['handler' => 'shutdown']);
			if (!headers_sent()) {
				render_http_error(500);
			}
		});
	}
}

register_global_error_handlers();

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
				render_http_error(403);
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
		if (is_https_request()) {
			header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
		}
	}
}

if (!function_exists('enforce_https_transport')) {
	function enforce_https_transport(): void
	{
		if (PHP_SAPI === 'cli' || is_https_request()) {
			return;
		}
		$host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
		if ($host === '' || stripos($host, 'localhost') !== false) {
			return;
		}
		$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
		header('Location: https://' . $host . $requestUri, true, 301);
		exit;
	}
}

enforce_https_transport();
apply_security_headers();

if (!function_exists('get_user_equipment_access_map')) {
	/**
	 * Retrieve a cached map of equipment_ids the current user can access.
	 */
	function get_user_equipment_access_map(mysqli $conn, ?int $userId): array
	{
		static $cache = [];
		$normalizedUserId = (int) ($userId ?? 0);
		if ($normalizedUserId <= 0) {
			return [];
		}
		if (isset($cache[$normalizedUserId])) {
			return $cache[$normalizedUserId];
		}
		$allowed = [];
		$stmt = mysqli_prepare($conn, 'SELECT equipment_id FROM user_equipment_access WHERE user_id = ?');
		if ($stmt) {
			mysqli_stmt_bind_param($stmt, 'i', $normalizedUserId);
			if (mysqli_stmt_execute($stmt)) {
				$result = mysqli_stmt_get_result($stmt);
				if ($result) {
					while ($row = mysqli_fetch_assoc($result)) {
						$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
						if ($equipmentId > 0) {
							$allowed[$equipmentId] = true;
						}
					}
					mysqli_free_result($result);
				}
			}
			mysqli_stmt_close($stmt);
		}
		$cache[$normalizedUserId] = $allowed;
		return $allowed;
	}
}

if (!function_exists('user_can_access_equipment')) {
	/**
	 * Determine if the user can interact with a specific equipment row.
	 */
	function user_can_access_equipment(mysqli $conn, ?int $userId, int $equipmentId): bool
	{
		if ($equipmentId <= 0) {
			return false;
		}
		$map = get_user_equipment_access_map($conn, $userId);
		if (empty($map)) {
			return true;
		}
		return isset($map[$equipmentId]);
	}
}

if (!function_exists('user_can_access_any_equipment')) {
	/**
	 * Determine if the user can access at least one equipment in the provided list.
	 */
	function user_can_access_any_equipment(mysqli $conn, ?int $userId, array $equipmentIds): bool
	{
		if (empty($equipmentIds)) {
			return true;
		}
		$map = get_user_equipment_access_map($conn, $userId);
		if (empty($map)) {
			return true;
		}
		foreach ($equipmentIds as $equipmentId) {
			$normalizedId = (int) $equipmentId;
			if ($normalizedId > 0 && isset($map[$normalizedId])) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('get_application_encryption_key')) {
	/**
	 * Resolve a stable 256-bit key for encrypting sensitive fields.
	 */
	function get_application_encryption_key(): string
	{
		static $cachedKey = null;
		if ($cachedKey !== null) {
			return $cachedKey;
		}
		$keyMaterial = '';
		$sources = [
			getenv('SWAP_APP_ENC_KEY') ?: null,
			$_SERVER['SWAP_APP_ENC_KEY'] ?? null,
		];
		foreach ($sources as $source) {
			if (!is_string($source) || $source === '') {
				continue;
			}
			$decoded = base64_decode($source, true);
			if ($decoded !== false && strlen($decoded) >= 32) {
				$keyMaterial = substr($decoded, 0, 32);
				break;
			}
			$keyMaterial = substr(hash('sha256', $source, true), 0, 32);
			break;
		}
		if ($keyMaterial === '') {
			$keyMaterial = substr(hash('sha256', __DIR__ . '|' . php_uname('n'), true), 0, 32);
		}
		$cachedKey = $keyMaterial;
		return $cachedKey;
	}
}

if (!function_exists('encrypt_sensitive_value')) {
	/**
	 * Encrypt sensitive text with AES-256-GCM.
	 */
	function encrypt_sensitive_value(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$normalized = trim((string) $value);
		if ($normalized === '') {
			return null;
		}
		$key = get_application_encryption_key();
		try {
			$iv = random_bytes(12);
		} catch (Throwable $e) {
			return $normalized;
		}
		$tag = '';
		$ciphertext = openssl_encrypt($normalized, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($ciphertext === false || $tag === '') {
			return $normalized;
		}
		$segments = [
			'v1',
			rtrim(strtr(base64_encode($iv), '+/', '-_'), '='),
			rtrim(strtr(base64_encode($tag), '+/', '-_'), '='),
			rtrim(strtr(base64_encode($ciphertext), '+/', '-_'), '='),
		];
		return implode(':', $segments);
	}
}

if (!function_exists('decrypt_sensitive_value')) {
	/**
	 * Decrypt sensitive text previously encrypted via encrypt_sensitive_value().
	 */
	function decrypt_sensitive_value(?string $value): string
	{
		if ($value === null) {
			return '';
		}
		$normalized = trim((string) $value);
		if ($normalized === '') {
			return '';
		}
		$parts = explode(':', $normalized);
		if (count($parts) !== 4 || $parts[0] !== 'v1') {
			return $normalized;
		}
		$decodeSegment = static function (string $segment): string {
			$base = strtr($segment, '-_', '+/');
			$padding = strlen($base) % 4;
			if ($padding > 0) {
				$base .= str_repeat('=', 4 - $padding);
			}
			$decoded = base64_decode($base, true);
			return $decoded === false ? '' : $decoded;
		};
		$iv = $decodeSegment($parts[1]);
		$tag = $decodeSegment($parts[2]);
		$ciphertext = $decodeSegment($parts[3]);
		if ($iv === '' || $tag === '' || $ciphertext === '') {
			return '';
		}
		$plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', get_application_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($plaintext === false) {
			return '';
		}
		return $plaintext;
	}
}

if (!function_exists('base64url_encode_string')) {
	function base64url_encode_string(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}

if (!function_exists('base64url_decode_string')) {
	function base64url_decode_string(string $value): string
	{
		$normalized = strtr($value, '-_', '+/');
		$padding = strlen($normalized) % 4;
		if ($padding > 0) {
			$normalized .= str_repeat('=', 4 - $padding);
		}
		$decoded = base64_decode($normalized, true);
		return $decoded === false ? '' : $decoded;
	}
}

if (!function_exists('get_jwt_secret')) {
	function get_jwt_secret(): string
	{
		static $secret = null;
		if ($secret !== null) {
			return $secret;
		}
		$sources = [
			getenv('SWAP_JWT_SECRET') ?: null,
			$_SERVER['SWAP_JWT_SECRET'] ?? null,
		];
		foreach ($sources as $candidate) {
			if (!is_string($candidate) || $candidate === '') {
				continue;
			}
			$decoded = base64_decode($candidate, true);
			if ($decoded !== false && strlen($decoded) >= 32) {
				$secret = substr($decoded, 0, 32);
				return $secret;
			}
			$secret = substr(hash('sha256', $candidate, true), 0, 32);
			return $secret;
		}
		$secret = substr(hash('sha256', __DIR__ . '|jwt|' . php_uname('n'), true), 0, 32);
		return $secret;
	}
}

if (!function_exists('issue_user_jwt')) {
	function issue_user_jwt(array $user, int $ttlSeconds = 1800): ?string
	{
		$userId = isset($user['user_id']) ? (int) $user['user_id'] : 0;
		if ($userId <= 0) {
			return null;
		}
		$role = strtolower(trim((string) ($user['role_name'] ?? '')));
		$issuedAt = time();
		$expires = $issuedAt + max(300, $ttlSeconds);
		$header = ['alg' => 'HS256', 'typ' => 'JWT'];
		$payload = [
			'uid' => $userId,
			'role' => $role,
			'iat' => $issuedAt,
			'exp' => $expires,
		];
		$headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
		$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
		if ($headerJson === false || $payloadJson === false) {
			return null;
		}
		$segments = [
			base64url_encode_string($headerJson),
			base64url_encode_string($payloadJson),
		];
		$signingInput = implode('.', $segments);
		$signature = hash_hmac('sha256', $signingInput, get_jwt_secret(), true);
		$segments[] = base64url_encode_string($signature);
		$token = implode('.', $segments);
		$cookieOptions = [
			'expires' => $expires,
			'path' => '/',
			'httponly' => true,
			'samesite' => 'Strict',
			'secure' => is_https_request(),
		];
		if (PHP_VERSION_ID >= 70300) {
			setcookie('swap_jwt', $token, $cookieOptions);
		} else {
			setcookie('swap_jwt', $token, $expires, '/', '', $cookieOptions['secure'], true);
		}
		return $token;
	}
}

if (!function_exists('clear_jwt_cookie')) {
	function clear_jwt_cookie(): void
	{
		$cookieOptions = [
			'expires' => time() - 3600,
			'path' => '/',
			'httponly' => true,
			'samesite' => 'Strict',
			'secure' => is_https_request(),
		];
		if (PHP_VERSION_ID >= 70300) {
			setcookie('swap_jwt', '', $cookieOptions);
		} else {
			setcookie('swap_jwt', '', $cookieOptions['expires'], '/', '', $cookieOptions['secure'], true);
		}
	}
}

if (!function_exists('get_request_jwt_token')) {
	function get_request_jwt_token(): ?string
	{
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		if (is_string($header) && stripos($header, 'bearer ') === 0) {
			return trim(substr($header, 7));
		}
		if (isset($_COOKIE['swap_jwt']) && is_string($_COOKIE['swap_jwt']) && $_COOKIE['swap_jwt'] !== '') {
			return $_COOKIE['swap_jwt'];
		}
		return null;
	}
}

if (!function_exists('decode_jwt_token')) {
	function decode_jwt_token(string $token): ?array
	{
		$segments = explode('.', $token);
		if (count($segments) !== 3) {
			return null;
		}
		[$headerSegment, $payloadSegment, $signatureSegment] = $segments;
		$headerRaw = base64url_decode_string($headerSegment);
		$payloadRaw = base64url_decode_string($payloadSegment);
		$signature = base64url_decode_string($signatureSegment);
		if ($headerRaw === '' || $payloadRaw === '' || $signature === '') {
			return null;
		}
		$header = json_decode($headerRaw, true);
		$payload = json_decode($payloadRaw, true);
		if (!is_array($header) || !is_array($payload)) {
			return null;
		}
		if (($header['alg'] ?? '') !== 'HS256') {
			return null;
		}
		$expected = hash_hmac('sha256', $headerSegment . '.' . $payloadSegment, get_jwt_secret(), true);
		if (!hash_equals($expected, $signature)) {
			return null;
		}
		return $payload;
	}
}

if (!function_exists('validate_jwt_for_user')) {
	function validate_jwt_for_user(?array $user): bool
	{
		if ($user === null) {
			return false;
		}
		$token = get_request_jwt_token();
		if ($token === null) {
			return false;
		}
		$payload = decode_jwt_token($token);
		if ($payload === null) {
			return false;
		}
		$userId = isset($user['user_id']) ? (int) $user['user_id'] : 0;
		$role = strtolower(trim((string) ($user['role_name'] ?? '')));
		if ($userId <= 0 || $role === '') {
			return false;
		}
		if ((int) ($payload['uid'] ?? 0) !== $userId) {
			return false;
		}
		if (strtolower((string) ($payload['role'] ?? '')) !== $role) {
			return false;
		}
		$expiresAt = (int) ($payload['exp'] ?? 0);
		if ($expiresAt < time()) {
			return false;
		}
		return true;
	}
}

if (!function_exists('force_reauthentication')) {
	function force_reauthentication(string $message = 'Please sign in again.'): void
	{
		clear_jwt_cookie();
		reset_session_state();
		$_SESSION['auth_notice'] = $message;
		header('Location: login.php');
		exit;
	}
}

if (!function_exists('log_sensitive_route_access')) {
	function log_sensitive_route_access(mysqli $conn, array $user): void
	{
		static $logged = false;
		if ($logged) {
			return;
		}
		$logged = true;
		$details = [
			'path' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
			'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
		];
		$actorId = isset($user['user_id']) ? (int) $user['user_id'] : null;
		log_audit_event($conn, $actorId, 'sensitive_route_access', 'route', null, $details);
	}
}

if (!function_exists('enforce_sensitive_route_guard')) {
	function enforce_sensitive_route_guard(mysqli $conn, array $requiredRoles = ['admin']): array
	{
		$user = require_login($requiredRoles);
		if (!validate_jwt_for_user($user)) {
			force_reauthentication('Your administrator session expired. Please sign in again.');
		}
		log_sensitive_route_access($conn, $user);
		return $user;
	}
}
