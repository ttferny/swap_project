<?php
session_start();
require_once __DIR__ . '/db.php';

$adminNumber = '';
$errors = [];

$roleDestinations = [
	'admin' => 'admin.php',
	'manager' => 'manager.php',
	'technician' => 'technician.php',
	'user' => 'index.php',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$adminNumber = trim($_POST['admin_number'] ?? '');
	$password = $_POST['password'] ?? '';

	if ($adminNumber === '') {
		$errors[] = 'Admin number is required.';
	}

	if ($password === '') {
		$errors[] = 'Password is required.';
	}

	if (empty($errors)) {
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

			if ($user && password_verify($password, $user['password_hash'])) {
				$_SESSION['user_id'] = $user['user_id'];
				$_SESSION['admin_number'] = $user['tp_admin_no'];
				$_SESSION['role_id'] = $user['role_id'];
				$_SESSION['role_name'] = $user['role_name'];
				$_SESSION['full_name'] = $user['full_name'];

				$roleKey = strtolower(trim($user['role_name'] ?? ''));
				$destination = null;

				if ($roleKey !== '' && isset($roleDestinations[$roleKey])) {
					$destination = $roleDestinations[$roleKey];
				}

				if ($destination === null) {
					$destination = 'index.php';
				}

				$actorId = (int) $user['user_id'];
				$entityId = $actorId;
				$action = 'login';
				$entityType = 'authentication';
				$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
				$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
				$detailsPayload = [
					'event' => 'login',
					'admin_number' => $user['tp_admin_no'],
					'role' => $user['role_name'],
				];
				$details = json_encode($detailsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($details === false) {
					$details = '{"event":"login"}';
				}

				$logStmt = mysqli_prepare(
					$conn,
					'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
				);
				if ($logStmt) {
					mysqli_stmt_bind_param(
						$logStmt,
						'ississs',
						$actorId,
						$action,
						$entityType,
						$entityId,
						$ipAddress,
						$userAgent,
						$details
					);
					if (!mysqli_stmt_execute($logStmt)) {
						error_log('Audit log execute failed: ' . mysqli_stmt_error($logStmt));
					}
					mysqli_stmt_close($logStmt);
				} else {
					error_log('Audit log prepare failed: ' . mysqli_error($conn));
				}

				header('Location: ' . $destination);
				exit;
			}

			$errors[] = 'Invalid admin number or password.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Admin Login</title>
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
		</style>
	</head>
	<body>
		<main class="card">
			<h1>Login</h1>
			<p>Enter your admin number and password to access the dashboard.</p>
			<?php if (!empty($errors)): ?>
				<ul class="error-list">
					<?php foreach ($errors as $error): ?>
						<li><?php echo htmlspecialchars($error, ENT_QUOTES); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" novalidate>
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
