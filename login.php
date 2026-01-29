<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$adminNumber = '';
$errors = [];
$infoMessages = [];

$roleDestinations = [
	'admin' => 'admin.php',
	'manager' => 'manager.php',
	'technician' => 'technician.php',
	'student' => 'index.php',
	'staff' => 'index.php',
];

$roleAllowedTargets = [
	'admin' => [
		'admin.php',
		'manager.php',
		'technician.php',
		'approve-bookings.php',
	],
	'manager' => [
		'manager.php',
		'approve-bookings.php',
		'technician.php',
	],
	'technician' => [
		'technician.php',
	],
	'student' => [
		'index.php',
		'learning-space.php',
		'book-machines.php',
		'report-fault.php',
		'download-material.php',
	],
	'staff' => [
		'index.php',
		'learning-space.php',
		'book-machines.php',
		'report-fault.php',
		'download-material.php',
	],
];

$resolveDestination = static function (string $roleKey, string $redirectTarget) use ($roleDestinations, $roleAllowedTargets): string {
	$roleKey = strtolower(trim($roleKey));
	$defaultDestination = $roleDestinations[$roleKey] ?? 'index.php';
	if ($redirectTarget === '') {
		return $defaultDestination;
	}
	$path = parse_url($redirectTarget, PHP_URL_PATH);
	$normalizedTarget = strtolower(ltrim((string) $path, '/'));
	if ($normalizedTarget === '') {
		return $defaultDestination;
	}
	$allowedTargets = $roleAllowedTargets[$roleKey] ?? [];
	if (!in_array($normalizedTarget, $allowedTargets, true)) {
		return $defaultDestination;
	}
	return $redirectTarget;
};

$redirectTarget = sanitize_redirect_target($_POST['redirect_to'] ?? $_GET['redirect'] ?? '');
if (is_authenticated()) {
	$sessionUser = current_user();
	$roleKey = strtolower(trim((string) ($sessionUser['role_name'] ?? '')));
	$alreadyDestination = $resolveDestination($roleKey, $redirectTarget);
	redirect_if_authenticated($alreadyDestination);
}

if (isset($_SESSION['auth_notice'])) {
	$notice = trim((string) $_SESSION['auth_notice']);
	if ($notice !== '') {
		$infoMessages[] = $notice;
	}
	unset($_SESSION['auth_notice']);
}

$throttleKey = 'login_throttle';
$maxAttempts = 3;
$lockSeconds = 300;
$throttleState = $_SESSION[$throttleKey] ?? ['attempts' => 0, 'locked_until' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$now = time();
	$lockedUntil = (int) ($throttleState['locked_until'] ?? 0);
	$loginSuccessful = false;
	$attemptedAuth = false;

	if (!validate_csrf_token('login_form', $_POST['csrf_token'] ?? null)) {
		$errors[] = 'Your login session expired. Please refresh and try again.';
	}

	if ($lockedUntil > $now) {
		$waitSeconds = max(1, $lockedUntil - $now);
		$errors[] = 'Too many attempts. Please wait ' . ceil($waitSeconds / 60) . ' minute(s) before trying again.';
	}

	$adminNumber = trim($_POST['admin_number'] ?? '');
	$password = $_POST['password'] ?? '';

	if ($adminNumber === '') {
		$errors[] = 'Admin number is required.';
	}

	if ($password === '') {
		$errors[] = 'Password is required.';
	}

	if (empty($errors)) {
		$attemptedAuth = true;
		$sql = 'SELECT u.user_id, u.role_id, u.tp_admin_no, u.password_hash, u.full_name, r.role_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.tp_admin_no = ? LIMIT 1';
		$stmt = mysqli_prepare($conn, $sql);
		if ($stmt === false) {
			$errors[] = 'Unable to prepare login statement. Please try again later.';
		} else {
			mysqli_stmt_bind_param($stmt, 's', $adminNumber);
			mysqli_stmt_execute($stmt);
			$result = mysqli_stmt_get_result($stmt);
			$user = $result ? mysqli_fetch_assoc($result) : null;
			mysqli_stmt_close($stmt);

			if ($user && password_verify($password, (string) $user['password_hash'])) {
				$loginSuccessful = true;
				if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
					$newHash = password_hash($password, PASSWORD_DEFAULT);
					if ($newHash !== false) {
						$rehashStmt = mysqli_prepare($conn, 'UPDATE users SET password_hash = ? WHERE user_id = ?');
						if ($rehashStmt) {
							mysqli_stmt_bind_param($rehashStmt, 'si', $newHash, $user['user_id']);
							mysqli_stmt_execute($rehashStmt);
							mysqli_stmt_close($rehashStmt);
						}
					}
				}

				refresh_session_id(true);
				$_SESSION['user_id'] = $user['user_id'];
				$_SESSION['admin_number'] = $user['tp_admin_no'];
				$_SESSION['role_id'] = $user['role_id'];
				$_SESSION['role_name'] = $user['role_name'];
				$_SESSION['full_name'] = $user['full_name'];
				unset($_SESSION[$throttleKey]);

				$roleKey = strtolower(trim($user['role_name'] ?? ''));
				$destination = $resolveDestination($roleKey, $redirectTarget);

				$actorId = (int) $user['user_id'];
				$entityId = $actorId;
				$detailsPayload = [
					'event' => 'login',
					'admin_number' => $user['tp_admin_no'],
					'role' => $user['role_name'],
				];
				log_audit_event($conn, $actorId, 'login', 'authentication', $entityId, $detailsPayload);

				header('Location: ' . $destination);
				exit;
			}

			$errors[] = 'Invalid admin number or password.';
		}
	}

	if (!$loginSuccessful && $attemptedAuth) {
		$throttleState['attempts'] = (int) ($throttleState['attempts'] ?? 0) + 1;
		if ($throttleState['attempts'] >= $maxAttempts) {
			$throttleState['locked_until'] = $now + $lockSeconds;
			$throttleState['attempts'] = 0;
			log_audit_event(
				$conn,
				null,
				'login_lockout',
				'authentication',
				null,
				[
					'admin_number' => $adminNumber,
					'attempts' => $maxAttempts,
					'lock_seconds' => $lockSeconds,
				]
			);
		}
		$_SESSION[$throttleKey] = $throttleState;
		try {
			usleep(random_int(150000, 400000));
		} catch (Exception $exception) {
			usleep(200000);
		}
	}
}

$csrfToken = generate_csrf_token('login_form');
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Login</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<style>
			:root {
				--bg: #f8fbff;
				--card: #ffffff;
				--accent: #4361ee;
				--text: #0f172a;
				--muted: #64748b;
				font-size: 16px;
			}

			* {
				box-sizing: border-box;
			}

			body {
				margin: 0;
				min-height: 100vh;
				font-family: "Space Grotesk", "Segoe UI", Tahoma, sans-serif;
				background: radial-gradient(circle at top, #eef3ff, var(--bg));
				display: flex;
				align-items: center;
				justify-content: center;
				color: var(--text);
			}

			.card {
				width: min(420px, 90vw);
				background: var(--card);
				padding: 2rem;
				border-radius: 1.25rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 25px 55px rgba(15, 23, 42, 0.08);
			}

			h1 {
				margin-top: 0;
				font-size: 1.6rem;
			}

			p {
				color: var(--muted);
			}

			form {
				display: grid;
				gap: 1rem;
				margin-top: 1.5rem;
			}

			label span {
				display: block;
				font-size: 0.95rem;
				margin-bottom: 0.35rem;
				color: var(--muted);
			}

			input {
				width: 100%;
				padding: 0.75rem 0.95rem;
				border-radius: 0.85rem;
				border: 1px solid #d7def0;
				background: #fdfdff;
				font-family: inherit;
				font-size: 1rem;
			}

			button {
				padding: 0.85rem 1rem;
				border-radius: 0.9rem;
				border: none;
				font-size: 1rem;
				font-weight: 600;
				font-family: inherit;
				background: var(--accent);
				color: #fff;
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			button:hover {
				transform: translateY(-2px);
				box-shadow: 0 12px 30px rgba(67, 97, 238, 0.35);
			}

			.error-list {
				margin: 0;
				margin-bottom: 1rem;
				padding: 0.85rem 1rem;
				border-radius: 0.9rem;
				background: #fee2e2;
				border: 1px solid #fecaca;
				color: #991b1b;
				list-style: disc inside;
			}

			.info-list {
				margin: 0;
				margin-bottom: 1rem;
				padding: 0.85rem 1rem;
				border-radius: 0.9rem;
				background: #dcfce7;
				border: 1px solid #86efac;
				color: #166534;
				list-style: disc inside;
			}
		</style>
	</head>
	<body>
		<main class="card">
			<h1>Login</h1>
			<p>Enter your admin number and password to access the dashboard.</p>
			<?php if (!empty($infoMessages)): ?>
				<ul class="info-list">
					<?php foreach ($infoMessages as $message): ?>
						<li><?php echo htmlspecialchars($message, ENT_QUOTES); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if (!empty($errors)): ?>
				<ul class="error-list">
					<?php foreach ($errors as $error): ?>
						<li><?php echo htmlspecialchars($error, ENT_QUOTES); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" novalidate>
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTarget, ENT_QUOTES); ?>" />
				<label>
					<span>Admin Number</span>
					<input
						name="admin_number"
						type="text"
						value="<?php echo htmlspecialchars($adminNumber, ENT_QUOTES); ?>"
						autocomplete="username"
						inputmode="numeric"
						required
					/>
				</label>
				<label>
					<span>Password</span>
					<input name="password" type="password" autocomplete="current-password" required />
				</label>
				<button type="submit">Sign In</button>
			</form>
		</main>
	</body>
</html>
