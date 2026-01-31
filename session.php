<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	$requestedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
	if ($requestedScript !== '' && $requestedScript === realpath(__FILE__)) {
		http_response_code(404);
		exit;
	}
}

if (!defined('APP_REQUEST_START')) {
	define('APP_REQUEST_START', microtime(true));
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
		if ($stmt) {
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
			@mysqli_stmt_execute($stmt);
			mysqli_stmt_close($stmt);
		}
		try {
			$logDir = application_log_directory();
			@chmod($logDir, 0700);
			$payload = [
				'timestamp' => gmdate('c'),
				'actor_user_id' => $actorId,
				'action' => $action,
				'entity_type' => $entityType,
				'entity_id' => $entityId,
				'ip_address' => $ipAddress,
				'user_agent' => $userAgent,
				'context' => $details,
			];
			$logFile = $logDir . '/app-audit.log';
			$hashFile = $logDir . '/app-audit.log.sha256';
			$logEntry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($logEntry !== false) {
				@file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
				$hash = @hash_file('sha256', $logFile);
				if ($hash !== false) {
					@file_put_contents($hashFile, $hash);
				}
			}
		} catch (Throwable $loggingError) {
			// Keep failures silent to avoid cascading errors on audit writes.
		}
	}
}

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

if (!function_exists('apply_runtime_security_baseline')) {
	/**
	 * Harden common PHP/runtime misconfigurations (OWASP A6) to avoid unsafe defaults.
	 */
	function apply_runtime_security_baseline(): void
	{
		// Suppress verbose errors to clients but keep logs on.
		@ini_set('display_errors', '0');
		@ini_set('log_errors', '1');
		@ini_set('expose_php', '0');
		// Defensive session defaults even before session_start.
		@ini_set('session.use_strict_mode', '1');
		@ini_set('session.use_only_cookies', '1');
		@ini_set('session.cookie_httponly', '1');
		@ini_set('session.cookie_samesite', 'Lax');
		@ini_set('session.cookie_secure', is_https_request() ? '1' : '0');
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

if (!function_exists('sanitize_request_input')) {
	/**
	 * Strip control characters and enforce max length to reduce injection risk (OWASP A1).
	 */
	function sanitize_request_input(): void
	{
		$sanitize = static function (&$value) use (&$sanitize): void {
			if (is_array($value)) {
				foreach ($value as &$v) {
					$sanitize($v);
				}
				return;
			}
			$value = (string) $value;
			$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
			if ((function_exists('mb_substr') ? mb_strlen($value) : strlen($value)) > 4000) {
				$value = function_exists('mb_substr') ? mb_substr($value, 0, 4000) : substr($value, 0, 4000);
			}
		};
		foreach (['_GET', '_POST', '_COOKIE'] as $super) {
			if (isset($GLOBALS[$super]) && is_array($GLOBALS[$super])) {
				foreach ($GLOBALS[$super] as &$v) {
					$sanitize($v);
				}
			}
		}
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

if (!function_exists('enforce_role_access')) {
	/**
	 * Ensure the authenticated user has one of the allowed roles (server-side RBAC).
	 */
	function enforce_role_access(array $allowedRoles, ?array $user = null): void
	{
		if ($user === null) {
			$user = current_user();
		}
		$role = strtolower(trim((string) ($user['role_name'] ?? '')));
		$allowed = array_map(static fn($r) => strtolower(trim((string) $r)), $allowedRoles);
		if (!in_array($role, $allowed, true)) {
			render_http_error(403, 'You do not have permission to access this area.');
			exit;
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

if (!function_exists('enforce_session_timeout')) {
	/**
	 * Enforce idle timeout to reduce stolen-session impact (OWASP A3).
	 */
	function enforce_session_timeout(int $maxIdleSeconds = 1200): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}
		$now = time();
		$lastSeen = isset($_SESSION['last_seen_at']) ? (int) $_SESSION['last_seen_at'] : $now;
		if (($now - $lastSeen) > $maxIdleSeconds) {
			reset_session_state();
			return;
		}
		$_SESSION['last_seen_at'] = $now;
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

apply_runtime_security_baseline();
bootstrap_session();
sanitize_request_input();

if (!function_exists('get_csp_nonce')) {
	function get_csp_nonce(): string
	{
		static $nonce = null;
		if ($nonce !== null) {
			return $nonce;
		}
		try {
			$bytes = random_bytes(16);
		} catch (Throwable $exception) {
			$bytes = hash('sha256', microtime(true) . '|' . mt_rand(), true);
		}
		$nonce = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		return $nonce;
	}
}

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

if (!function_exists('static_cache_directory')) {
	function static_cache_directory(): string
	{
		$cacheDir = __DIR__ . '/storage/cache';
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0775, true);
		}
		return $cacheDir;
	}
}

if (!function_exists('static_cache_remember')) {
	/**
	 * Simple filesystem-backed cache for non-dynamic lookups to reduce DB pressure.
	 */
	function static_cache_remember(string $key, int $ttlSeconds, callable $builder)
	{
		$cacheDir = static_cache_directory();
		$path = $cacheDir . '/' . substr(hash('sha256', $key), 0, 32) . '.cache';
		$now = time();
		if (is_file($path)) {
			$raw = @file_get_contents($path);
			if ($raw !== false && $raw !== '') {
				$data = json_decode($raw, true);
				if (is_array($data) && isset($data['expires'], $data['payload']) && (int) $data['expires'] >= $now) {
					return $data['payload'];
				}
			}
		}
		$value = $builder();
		@file_put_contents($path, json_encode(['expires' => $now + $ttlSeconds, 'payload' => $value]));
		return $value;
	}
}

if (!function_exists('record_system_error')) {
	function record_system_error(Throwable $throwable, array $context = []): void
	{
		try {
			$logDir = application_log_directory();
			@chmod($logDir, 0700);
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
			$hashFile = $logDir . '/app-error.log.sha256';
			$logEntry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($logEntry !== false) {
				@file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
				$hash = @hash_file('sha256', $logFile);
				if ($hash !== false) {
					@file_put_contents($hashFile, $hash);
				}
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
		$allowedStatuses = [400, 403, 404, 429, 500];
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
					429 => [
						'title' => 'You are sending requests too quickly.',
						'message' => 'Please wait a moment before trying again.',
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
			'dashboard_href' => dashboard_home_path(),
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

if (!function_exists('flash_store')) {
	function flash_store(string $key, $value): void
	{
		bootstrap_session();
		if (!isset($_SESSION['__flash']) || !is_array($_SESSION['__flash'])) {
			$_SESSION['__flash'] = [];
		}
		$_SESSION['__flash'][$key] = $value;
	}
}

if (!function_exists('flash_retrieve')) {
	function flash_retrieve(string $key, $default = null)
	{
		bootstrap_session();
		if (!isset($_SESSION['__flash'][$key])) {
			return $default;
		}
		$value = $_SESSION['__flash'][$key];
		unset($_SESSION['__flash'][$key]);
		return $value;
	}
}

if (!function_exists('redirect_to_current_uri')) {
	function redirect_to_current_uri(?string $override = null): void
	{
		$target = sanitize_redirect_target($override ?? ($_SERVER['REQUEST_URI'] ?? '')) ?: '/swap_project/index.php';
		header('Location: ' . $target);
		exit;
	}
}

if (!function_exists('dashboard_home_path')) {
	function dashboard_home_path(?array $user = null): string
	{
		$user = $user ?? current_user();
		if ($user === null) {
			return 'login.php';
		}
		$role = strtolower(trim((string) ($user['role_name'] ?? '')));
		$routes = [
			'admin' => 'admin.php',
			'manager' => 'manager.php',
			'technician' => 'technician.php',
			'staff' => 'index.php',
			'student' => 'index.php',
		];
		return $routes[$role] ?? 'index.php';
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
if (!function_exists('logAuditEntry')) {
	/**
	 * Backwards-compatible helper used throughout older booking flows.
	 */
	function logAuditEntry(mysqli $conn, ?int $actorId, string $action, string $entityType, ?int $entityId, array $details = []): void
	{
		log_audit_event($conn, $actorId, $action, $entityType, $entityId, $details);
	}
}

if (!function_exists('register_performance_budget')) {
	function register_performance_budget(float $ttfbBudgetMs, float $renderBudgetMs, string $label = 'request'): void
	{
		static $shutdownHooked = false;
		$GLOBALS['__performance_budget'] = [
			'label' => $label,
			'ttfb' => max(1.0, $ttfbBudgetMs),
			'render' => max(1.0, $renderBudgetMs),
		];
		if (function_exists('header_register_callback')) {
			header_register_callback(static function (): void {
				if (!isset($GLOBALS['__ttfb_snapshot_ms'])) {
					$GLOBALS['__ttfb_snapshot_ms'] = (microtime(true) - APP_REQUEST_START) * 1000;
				}
			});
		}
		if (!$shutdownHooked) {
			$shutdownHooked = true;
			register_shutdown_function(static function (): void {
				$budget = $GLOBALS['__performance_budget'] ?? null;
				if ($budget === null) {
					return;
				}
				$totalMs = (microtime(true) - APP_REQUEST_START) * 1000;
				$ttfbMs = $GLOBALS['__ttfb_snapshot_ms'] ?? $totalMs;
				$logPayload = [
					'label' => $budget['label'],
					'performance' => [
						'actual_ttfb_ms' => round($ttfbMs, 2),
						'actual_render_ms' => round($totalMs, 2),
						'budget_ttfb_ms' => $budget['ttfb'],
						'budget_render_ms' => $budget['render'],
					],
				];
				$logLine = json_encode($logPayload, JSON_UNESCAPED_SLASHES);
				if ($logLine !== false) {
					$dir = application_log_directory();
					@file_put_contents($dir . '/performance.log', $logLine . PHP_EOL, FILE_APPEND);
				}
				$ttfbExceeded = $ttfbMs > $budget['ttfb'];
				$renderExceeded = $totalMs > $budget['render'];
				if (($ttfbExceeded || $renderExceeded) && function_exists('record_system_error')) {
					$exception = new RuntimeException('Performance budget exceeded for ' . $budget['label']);
					record_system_error($exception, $logPayload['performance']);
				}
				if (!headers_sent()) {
					$timingValue = round($totalMs, 2);
					header('Server-Timing: app;dur=' . $timingValue);
					header('X-Performance-Metrics: ttfb=' . round($ttfbMs, 2) . 'ms; render=' . $timingValue . 'ms');
				}
			});
		}
		if (!headers_sent()) {
			header('X-Performance-Budget: TTFB<=' . $ttfbBudgetMs . 'ms; Render<=' . $renderBudgetMs . 'ms; Label=' . $label);
		}
	}
}

if (!function_exists('record_data_modification_audit')) {
	function record_data_modification_audit(mysqli $conn, array $user, string $entityType, ?int $entityId, array $payload): void
	{
		$actorId = isset($user['user_id']) ? (int) $user['user_id'] : null;
		$auditPayload = [
			'path' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
			'payload' => $payload,
		];
		log_audit_event(
			$conn,
			$actorId,
			'data_mutation',
			$entityType,
			$entityId,
			$auditPayload
		);
	}
}

if (!function_exists('apply_security_headers')) {
	function apply_security_headers(): void
	{
		if (headers_sent()) {
			return;
		}
		$scriptNonce = get_csp_nonce();
		$cspDirectives = [
			"default-src 'self'",
			"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
			"font-src 'self' https://fonts.gstatic.com",
			"img-src 'self' data:",
			"script-src 'self' 'nonce-" . $scriptNonce . "'",
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
		header('Cache-Control: private, max-age=60, must-revalidate');
		header('Pragma: private');
		header('Expires: 0');
		if (is_https_request()) {
			header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
		}
	}
}

if (!function_exists('basic_waf_guard')) {
	/**
	 * Minimal WAF-style guardrail against obvious bad traffic.
	 */
	function basic_waf_guard(): void
	{
		if (PHP_SAPI === 'cli') {
			return;
		}
		$requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
		$userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
		$blockedSignatures = ['sqlmap', 'acunetix', 'nessus', 'nikto', 'curl', 'wget'];
		foreach ($blockedSignatures as $sig) {
			if ($userAgent !== '' && strpos($userAgent, $sig) !== false) {
				if (function_exists('record_system_error')) {
					record_system_error(new RuntimeException('Blocked by WAF signature'), ['signature' => $sig, 'ua' => $userAgent]);
				}
				render_http_error(403, 'Request blocked.');
				exit;
			}
		}
		$payload = $_SERVER['QUERY_STRING'] ?? '';
		if ((function_exists('strlen') ? strlen($payload) : 0) > 4000) {
			if (function_exists('record_system_error')) {
				record_system_error(new RuntimeException('Blocked large query string'), ['len' => strlen($payload)]);
			}
			render_http_error(413, 'Request too large.');
			exit;
		}
		$badFragments = ['union select', '<script', '../', '%00', 'sleep('];
		foreach ($badFragments as $frag) {
			if ($payload !== '' && stripos($payload, $frag) !== false) {
				if (function_exists('record_system_error')) {
					record_system_error(new RuntimeException('Blocked malformed payload'), ['fragment' => $frag]);
				}
				render_http_error(400, 'Malformed request.');
				exit;
			}
		}
	}
}

if (!function_exists('enforce_basic_rate_limit')) {
	/**
	 * Lightweight IP + token rate limiter to reduce DoS surface (OWASP A9).
	 */
	function enforce_basic_rate_limit(string $bucket = 'global', int $limit = 120, int $windowSeconds = 60, ?string $token = null): void
	{
		if (PHP_SAPI === 'cli') {
			return;
		}
		$ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
		$keys = [];
		if ($ip !== '') {
			$keys[] = substr(hash('sha256', $ip . '|' . $bucket), 0, 32);
		}
		if ($token !== null && $token !== '') {
			$keys[] = substr(hash('sha256', $token . '|' . $bucket), 0, 32);
		}
		if (empty($keys)) {
			return;
		}
		$now = time();
		$storagePath = application_log_directory() . '/rate-limit.json';
		$records = [];
		$fp = @fopen($storagePath, 'c+');
		if ($fp) {
			@flock($fp, LOCK_EX);
			$raw = stream_get_contents($fp);
			if ($raw !== false && $raw !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$records = $decoded;
				}
			}
			// prune stale
			foreach ($records as $key => $entry) {
				$ts = isset($entry['ts']) ? (int) $entry['ts'] : 0;
				if (($now - $ts) > $windowSeconds) {
					unset($records[$key]);
				}
			}
			$maxSeen = 0;
			foreach ($keys as $bucketKey) {
				$entry = $records[$bucketKey]['count'] ?? 0;
				$entryTs = $records[$bucketKey]['ts'] ?? $now;
				if (($now - (int) $entryTs) > $windowSeconds) {
					$entry = 0;
					$entryTs = $now;
				}
				$entry++;
				$records[$bucketKey] = ['count' => $entry, 'ts' => $entryTs];
				$maxSeen = max($maxSeen, $entry);
			}
			rewind($fp);
			ftruncate($fp, 0);
			fwrite($fp, json_encode($records));
			@flock($fp, LOCK_UN);
			fclose($fp);
			$warnThreshold = (int) ceil($limit * 0.8);
			if ($maxSeen >= $warnThreshold && function_exists('record_system_error')) {
				record_system_error(new RuntimeException('Rate limit near capacity for ' . $bucket), [
					'bucket' => $bucket,
					'limit' => $limit,
					'count' => $maxSeen,
				]);
			}
			if ($maxSeen > $limit) {
				if (function_exists('record_system_error')) {
					record_system_error(new RuntimeException('Rate limit exceeded for ' . $bucket), [
						'bucket' => $bucket,
						'limit' => $limit,
						'count' => $maxSeen,
					]);
				}
				http_response_code(429);
				header('Retry-After: ' . $windowSeconds);
				exit('Too many requests. Please retry in a minute.');
			}
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
$userToken = isset($_SESSION['user_id']) ? 'user_' . (int) $_SESSION['user_id'] : null;
basic_waf_guard();
enforce_basic_rate_limit((string) ($_SERVER['SCRIPT_NAME'] ?? 'global'), 120, 60, $userToken);
enforce_session_timeout();

if (!function_exists('get_equipment_requirement_metadata')) {
	/**
	 * Cache equipment-to-certification requirements for the current request.
	 */
	function get_equipment_requirement_metadata(mysqli $conn): array
	{
		static $metadata = null;
		if ($metadata !== null) {
			return $metadata;
		}
		$metadata = [
			'matrix' => [],
			'has_requirements' => false,
		];
		$sql = 'SELECT e.equipment_id, erc.cert_id
			FROM equipment e
			LEFT JOIN equipment_required_certs erc ON erc.equipment_id = e.equipment_id
			ORDER BY e.equipment_id ASC';
		$result = mysqli_query($conn, $sql);
		if ($result instanceof mysqli_result) {
			while ($row = mysqli_fetch_assoc($result)) {
				$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
				if ($equipmentId <= 0) {
					continue;
				}
				if (!isset($metadata['matrix'][$equipmentId])) {
					$metadata['matrix'][$equipmentId] = [];
				}
				$certId = isset($row['cert_id']) ? (int) $row['cert_id'] : 0;
				if ($certId > 0) {
					$metadata['matrix'][$equipmentId][$certId] = true;
					$metadata['has_requirements'] = true;
				}
			}
			mysqli_free_result($result);
		}
		return $metadata;
	}
}

if (!function_exists('should_limit_equipment_scope')) {
	/**
	 * Determine whether a user's equipment visibility should be restricted.
	 */
	function should_limit_equipment_scope(mysqli $conn, ?int $userId): bool
	{
		static $roleCache = [];
		$normalizedUserId = (int) ($userId ?? 0);
		if ($normalizedUserId <= 0) {
			return false;
		}
		$metadata = get_equipment_requirement_metadata($conn);
		if (empty($metadata['matrix']) || !$metadata['has_requirements']) {
			return false;
		}
		if (!isset($roleCache[$normalizedUserId])) {
			$roleName = 'user';
			$roleStmt = mysqli_prepare(
				$conn,
				' SELECT LOWER(r.role_name) AS role_name
					FROM users u
					INNER JOIN roles r ON r.role_id = u.role_id
					WHERE u.user_id = ?
					LIMIT 1'
			);
			if ($roleStmt) {
				mysqli_stmt_bind_param($roleStmt, 'i', $normalizedUserId);
				if (mysqli_stmt_execute($roleStmt)) {
					$result = mysqli_stmt_get_result($roleStmt);
					if ($result instanceof mysqli_result) {
						$row = mysqli_fetch_assoc($result);
						if ($row) {
							$resolvedRole = strtolower(trim((string) ($row['role_name'] ?? '')));
							if ($resolvedRole !== '') {
								$roleName = $resolvedRole;
							}
						}
						mysqli_free_result($result);
					}
				}
				mysqli_stmt_close($roleStmt);
			}
			$roleCache[$normalizedUserId] = $roleName;
		}
		$privilegedRoles = ['admin', 'manager', 'technician'];
		return !in_array($roleCache[$normalizedUserId], $privilegedRoles, true);
	}
}

if (!function_exists('get_user_equipment_access_map')) {
	/**
	 * Derive the list of equipment the user can access based on certifications.
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
		if (!should_limit_equipment_scope($conn, $normalizedUserId)) {
			$cache[$normalizedUserId] = [];
			return $cache[$normalizedUserId];
		}
		$metadata = get_equipment_requirement_metadata($conn);
		$matrix = $metadata['matrix'];
		if (empty($matrix)) {
			$cache[$normalizedUserId] = [];
			return $cache[$normalizedUserId];
		}
		$userCerts = [];
		$stmt = mysqli_prepare(
			$conn,
			'SELECT cert_id, status, expires_at FROM user_certifications WHERE user_id = ?'
		);
		if ($stmt) {
			mysqli_stmt_bind_param($stmt, 'i', $normalizedUserId);
			if (mysqli_stmt_execute($stmt)) {
				$result = mysqli_stmt_get_result($stmt);
				if ($result instanceof mysqli_result) {
					$nowTs = time();
					while ($row = mysqli_fetch_assoc($result)) {
						$certId = isset($row['cert_id']) ? (int) $row['cert_id'] : 0;
						if ($certId <= 0) {
							continue;
						}
						$status = strtolower(trim((string) ($row['status'] ?? '')));
						if ($status !== 'completed') {
							continue;
						}
						$expiresAt = trim((string) ($row['expires_at'] ?? ''));
						if ($expiresAt !== '') {
							$expiresTs = strtotime($expiresAt);
							if ($expiresTs !== false && $expiresTs < $nowTs) {
								continue;
							}
						}
						$userCerts[$certId] = true;
					}
					mysqli_free_result($result);
				}
			}
			mysqli_stmt_close($stmt);
		}
		$allowed = [];
		foreach ($matrix as $equipmentId => $requirements) {
			if (empty($requirements)) {
				$allowed[$equipmentId] = true;
				continue;
			}
			$missingRequirement = false;
			foreach ($requirements as $certId => $_) {
				if (!isset($userCerts[$certId])) {
					$missingRequirement = true;
					break;
				}
			}
			if (!$missingRequirement) {
				$allowed[$equipmentId] = true;
			}
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
		if (!should_limit_equipment_scope($conn, $userId)) {
			return true;
		}
		$map = get_user_equipment_access_map($conn, $userId);
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
		if (!should_limit_equipment_scope($conn, $userId)) {
			return true;
		}
		$map = get_user_equipment_access_map($conn, $userId);
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

if (!function_exists('mask_sensitive_identifier')) {
	function mask_sensitive_identifier(?string $value, int $visible = 4): string
	{
		$normalized = preg_replace('/\s+/', '', (string) $value);
		if ($normalized === null) {
			$normalized = '';
		}
		if ($normalized === '') {
			return '';
		}
		$lengthFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
		$substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
		$totalLength = (int) $lengthFn($normalized);
		$visible = max(0, min($visible, $totalLength));
		$maskedLength = max(0, $totalLength - $visible);
		$mask = $maskedLength > 0 ? str_repeat('*', $maskedLength) : '';
		$suffix = $visible > 0 ? $substrFn($normalized, $totalLength - $visible) : '';
		return $mask . $suffix;
	}
}

if (!function_exists('derive_device_fingerprint')) {
	function derive_device_fingerprint(): string
	{
		$remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
		$addressSegments = explode('.', $remoteAddress);
		if (count($addressSegments) >= 3) {
			$remoteAddress = $addressSegments[0] . '.' . $addressSegments[1] . '.' . $addressSegments[2];
		}
		$segments = [
			strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'))),
			strtolower(trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))),
			strtolower(trim((string) ($_SERVER['HTTP_SEC_CH_UA'] ?? ''))),
			$remoteAddress,
		];
		return hash('sha256', implode('|', $segments));
	}
}

if (!function_exists('current_device_fingerprint')) {
	function current_device_fingerprint(): string
	{
		$fingerprint = isset($_SESSION['device_fingerprint']) ? (string) $_SESSION['device_fingerprint'] : '';
		if ($fingerprint !== '') {
			return $fingerprint;
		}
		$fingerprint = derive_device_fingerprint();
		$_SESSION['device_fingerprint'] = $fingerprint;
		return $fingerprint;
	}
}

if (!function_exists('summarize_device_label')) {
	function summarize_device_label(): string
	{
		$agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'));
		if ($agent === '') {
			return 'Unknown device';
		}
		return substr($agent, 0, 120);
	}
}

if (!function_exists('ensure_user_session_registry')) {
	function ensure_user_session_registry(mysqli $conn): void
	{
		static $ensured = false;
		if ($ensured) {
			return;
		}
		$sql = <<<SQL
		CREATE TABLE IF NOT EXISTS user_active_sessions (
			user_id BIGINT(20) NOT NULL,
			device_fingerprint CHAR(64) NOT NULL DEFAULT '',
			session_token CHAR(64) NOT NULL,
			device_label VARCHAR(120) DEFAULT NULL,
			issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id, device_fingerprint),
			KEY idx_user_active_sessions_token (session_token),
			KEY idx_user_active_sessions_device (device_fingerprint)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
		SQL;
		@mysqli_query($conn, $sql);
		$columns = [];
		$result = mysqli_query($conn, 'SHOW COLUMNS FROM user_active_sessions');
		if ($result instanceof mysqli_result) {
			while ($row = mysqli_fetch_assoc($result)) {
				$field = $row['Field'] ?? '';
				if ($field !== '') {
					$columns[$field] = true;
				}
			}
			mysqli_free_result($result);
		}
		if (!isset($columns['device_fingerprint'])) {
			@mysqli_query($conn, "ALTER TABLE user_active_sessions ADD COLUMN device_fingerprint CHAR(64) NOT NULL DEFAULT '' AFTER session_token");
		}
		if (!isset($columns['device_label'])) {
			@mysqli_query($conn, 'ALTER TABLE user_active_sessions ADD COLUMN device_label VARCHAR(120) DEFAULT NULL AFTER device_fingerprint');
		}
		$primaryColumns = [];
		$primaryResult = mysqli_query($conn, "SHOW KEYS FROM user_active_sessions WHERE Key_name = 'PRIMARY'");
		if ($primaryResult instanceof mysqli_result) {
			while ($row = mysqli_fetch_assoc($primaryResult)) {
				$seq = (int) ($row['Seq_in_index'] ?? 0);
				$primaryColumns[$seq] = $row['Column_name'] ?? '';
			}
			mysqli_free_result($primaryResult);
		}
		ksort($primaryColumns);
		$normalizedPk = array_values(array_filter($primaryColumns, static function ($column): bool {
			return $column !== '';
		}));
		if ($normalizedPk !== ['user_id', 'device_fingerprint'] && isset($columns['device_fingerprint'])) {
			@mysqli_query($conn, 'ALTER TABLE user_active_sessions DROP PRIMARY KEY');
			@mysqli_query($conn, 'ALTER TABLE user_active_sessions ADD PRIMARY KEY (user_id, device_fingerprint)');
		}
		$deviceIndexExists = false;
		$indexResult = mysqli_query($conn, 'SHOW INDEX FROM user_active_sessions');
		if ($indexResult instanceof mysqli_result) {
			while ($row = mysqli_fetch_assoc($indexResult)) {
				if (($row['Key_name'] ?? '') === 'idx_user_active_sessions_device') {
					$deviceIndexExists = true;
					break;
				}
			}
			mysqli_free_result($indexResult);
		}
		if (!$deviceIndexExists) {
			@mysqli_query($conn, 'ALTER TABLE user_active_sessions ADD KEY idx_user_active_sessions_device (device_fingerprint)');
		}
		$ensured = true;
	}
}

if (!function_exists('register_active_user_session')) {
	function register_active_user_session(mysqli $conn, int $userId, string $token): void
	{
		if ($userId <= 0 || $token === '') {
			return;
		}
		ensure_user_session_registry($conn);
		$fingerprint = current_device_fingerprint();
		if ($fingerprint === '') {
			$fingerprint = derive_device_fingerprint();
		}
		$deviceLabel = summarize_device_label();
		$evictStmt = mysqli_prepare($conn, 'DELETE FROM user_active_sessions WHERE device_fingerprint = ? AND user_id <> ?');
		if ($evictStmt) {
			mysqli_stmt_bind_param($evictStmt, 'si', $fingerprint, $userId);
			mysqli_stmt_execute($evictStmt);
			mysqli_stmt_close($evictStmt);
		}
		$sql = 'INSERT INTO user_active_sessions (user_id, device_fingerprint, session_token, device_label, issued_at, last_seen_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE session_token = VALUES(session_token), issued_at = VALUES(issued_at), last_seen_at = VALUES(last_seen_at), device_label = VALUES(device_label)';
		$stmt = mysqli_prepare($conn, $sql);
		if ($stmt === false) {
			return;
		}
		mysqli_stmt_bind_param($stmt, 'isss', $userId, $fingerprint, $token, $deviceLabel);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
		$_SESSION['active_session_token'] = $token;
		$_SESSION['device_fingerprint'] = $fingerprint;
	}
}

if (!function_exists('touch_active_user_session')) {
	function touch_active_user_session(mysqli $conn, int $userId, ?string $deviceFingerprint = null): void
	{
		if ($userId <= 0) {
			return;
		}
		ensure_user_session_registry($conn);
		$fingerprint = $deviceFingerprint ?? (isset($_SESSION['device_fingerprint']) ? (string) $_SESSION['device_fingerprint'] : '');
		if ($fingerprint === '') {
			$fingerprint = derive_device_fingerprint();
		}
		$stmt = mysqli_prepare($conn, 'UPDATE user_active_sessions SET last_seen_at = NOW() WHERE user_id = ? AND device_fingerprint = ?');
		if ($stmt) {
			mysqli_stmt_bind_param($stmt, 'is', $userId, $fingerprint);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_close($stmt);
		}
	}
}

if (!function_exists('clear_active_user_session')) {
	function clear_active_user_session(mysqli $conn, ?int $userId, ?string $deviceFingerprint = null): void
	{
		$normalizedId = (int) ($userId ?? 0);
		if ($normalizedId <= 0) {
			return;
		}
		ensure_user_session_registry($conn);
		$fingerprint = $deviceFingerprint ?? (isset($_SESSION['device_fingerprint']) ? (string) $_SESSION['device_fingerprint'] : '');
		if ($fingerprint !== '') {
			$stmt = mysqli_prepare($conn, 'DELETE FROM user_active_sessions WHERE user_id = ? AND device_fingerprint = ?');
			if ($stmt) {
				mysqli_stmt_bind_param($stmt, 'is', $normalizedId, $fingerprint);
				mysqli_stmt_execute($stmt);
				mysqli_stmt_close($stmt);
				return;
			}
		}
		$stmt = mysqli_prepare($conn, 'DELETE FROM user_active_sessions WHERE user_id = ?');
		if ($stmt) {
			mysqli_stmt_bind_param($stmt, 'i', $normalizedId);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_close($stmt);
		}
	}
}

if (!function_exists('purge_stale_active_sessions')) {
	function purge_stale_active_sessions(mysqli $conn, int $maxAgeSeconds = 1800): void
	{
		$age = max(60, $maxAgeSeconds);
		ensure_user_session_registry($conn);
		$sql = 'DELETE FROM user_active_sessions WHERE last_seen_at < (NOW() - INTERVAL ' . (int) $age . ' SECOND)';
		@mysqli_query($conn, $sql);
	}
}

if (!function_exists('another_user_active_session_exists')) {
	function another_user_active_session_exists(mysqli $conn, int $currentUserId = 0, int $activeWindowSeconds = 60): bool
	{
		purge_stale_active_sessions($conn);
		ensure_user_session_registry($conn);
		$window = max(10, min(900, $activeWindowSeconds));
		$sql = 'SELECT user_id FROM user_active_sessions WHERE user_id <> ? AND last_seen_at >= (NOW() - INTERVAL ' . (int) $window . ' SECOND) LIMIT 1';
		$stmt = mysqli_prepare($conn, $sql);
		if ($stmt === false) {
			return false;
		}
		mysqli_stmt_bind_param($stmt, 'i', $currentUserId);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_store_result($stmt);
		$hasOther = mysqli_stmt_num_rows($stmt) > 0;
		mysqli_stmt_close($stmt);
		return $hasOther;
	}
}

if (!function_exists('enforce_single_active_session')) {
	function enforce_single_active_session(mysqli $conn): void
	{
		if (!isset($_SESSION['user_id'])) {
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		if ($userId <= 0) {
			return;
		}
		purge_stale_active_sessions($conn);
		$sessionToken = isset($_SESSION['active_session_token']) ? (string) $_SESSION['active_session_token'] : '';
		if ($sessionToken === '') {
			reset_session_state();
			$_SESSION['auth_notice'] = 'Please sign in again to continue.';
			header('Location: login.php');
			exit;
		}
		$deviceFingerprint = current_device_fingerprint();
		ensure_user_session_registry($conn);
		$stmt = mysqli_prepare($conn, 'SELECT session_token FROM user_active_sessions WHERE user_id = ? AND device_fingerprint = ? LIMIT 1');
		if ($stmt === false) {
			return;
		}
		mysqli_stmt_bind_param($stmt, 'is', $userId, $deviceFingerprint);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_bind_result($stmt, $dbToken);
		$hasRow = mysqli_stmt_fetch($stmt);
		mysqli_stmt_close($stmt);
		if (!$hasRow) {
			register_active_user_session($conn, $userId, $sessionToken);
			return;
		}
		if (!hash_equals((string) $dbToken, $sessionToken)) {
			reset_session_state();
			$_SESSION['auth_notice'] = 'You were signed out because this account was accessed from another device.';
			header('Location: login.php');
			exit;
		}
		touch_active_user_session($conn, $userId, $deviceFingerprint);
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

if (!function_exists('access_control_matrix')) {
	function access_control_matrix(): array
	{
		static $matrix = null;
		if ($matrix !== null) {
			return $matrix;
		}
		$matrix = [
			'portal.home' => [
				'roles' => ['student', 'staff'],
			],
			'portal.booking' => [
				'roles' => ['student', 'staff', 'technician', 'manager', 'admin'],
			],
			'portal.learning' => [
				'roles' => ['student', 'staff', 'technician', 'manager', 'admin'],
			],
			'portal.report_fault' => [
				'roles' => ['student', 'staff', 'technician', 'manager', 'admin'],
			],
			'portal.downloads' => [
				'roles' => ['student', 'staff', 'technician', 'manager', 'admin'],
			],
			'manager.console' => [
				'roles' => ['manager', 'admin'],
			],
			'technician.console' => [
				'roles' => ['technician', 'manager', 'admin'],
			],
			'approvals.bookings' => [
				'roles' => ['manager', 'admin'],
			],
			'approvals.maintenance' => [
				'roles' => ['manager', 'admin'],
			],
			'incidents.review' => [
				'roles' => ['manager', 'admin'],
			],
			'admin.core' => [
				'roles' => ['admin'],
				'sensitive' => true,
			],
			'analytics.dashboard' => [
				'roles' => ['manager', 'admin'],
				'log_access' => true,
			],
		];
		return $matrix;
	}
}

if (!function_exists('enforce_capability')) {
	function enforce_capability(mysqli $conn, string $capabilityKey): array
	{
		$matrix = access_control_matrix();
		if (!isset($matrix[$capabilityKey])) {
			render_http_error(500, 'Access control policy missing for this resource.');
		}
		$policy = $matrix[$capabilityKey];
		$allowedRoles = array_map(static function ($role): string {
			return strtolower(trim((string) $role));
		}, $policy['roles'] ?? []);
		if (empty($allowedRoles)) {
			render_http_error(500, 'Access control policy is misconfigured.');
		}
		$user = require_login();
		$currentRole = strtolower(trim((string) ($user['role_name'] ?? '')));
		if (!in_array($currentRole, $allowedRoles, true)) {
			$actorId = isset($user['user_id']) ? (int) $user['user_id'] : null;
			log_audit_event(
				$conn,
				$actorId,
				'access_denied',
				'capability',
				null,
				[
					'capability' => $capabilityKey,
					'role' => $currentRole,
					'path' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
				]
			);
			render_http_error(403);
		}
		if (!empty($policy['sensitive'])) {
			if (!validate_jwt_for_user($user)) {
				force_reauthentication('Your administrator session expired. Please sign in again.');
			}
			log_sensitive_route_access($conn, $user);
		} elseif (!empty($policy['log_access'])) {
			log_sensitive_route_access($conn, $user);
		}
		return $user;
	}
}

if (!function_exists('enforce_sensitive_route_guard')) {
	function enforce_sensitive_route_guard(mysqli $conn, array $requiredRoles = ['admin']): array
	{
		if (count($requiredRoles) === 1 && strtolower((string) $requiredRoles[0]) === 'admin') {
			return enforce_capability($conn, 'admin.core');
		}
		$user = require_login($requiredRoles);
		if (!validate_jwt_for_user($user)) {
			force_reauthentication('Your administrator session expired. Please sign in again.');
		}
		log_sensitive_route_access($conn, $user);
		return $user;
	}
}

if (php_sapi_name() !== 'cli' && function_exists('register_performance_budget')) {
	$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
	$normalizedLabel = trim($scriptName, '/');
	if ($normalizedLabel === '') {
		$normalizedLabel = 'index.php';
	}
	register_performance_budget(1500.0, 3000.0, $normalizedLabel);
}
