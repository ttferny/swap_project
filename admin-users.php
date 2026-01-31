<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current admin user context and identity values.
$currentUser = enforce_capability($conn, 'admin.core');
$userFullName = trim((string) ($currentUser['full_name'] ?? 'Administrator'));
if ($userFullName === '') {
	$userFullName = 'Administrator';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));
$logoutToken = generate_csrf_token('logout_form');
$currentUserId = (int) ($currentUser['user_id'] ?? 0);

// Flash message handling for create/update/delete flows.
$messages = ['success' => [], 'error' => []];
$userFlash = flash_retrieve('admin_users');
if (is_array($userFlash) && isset($userFlash['messages']) && is_array($userFlash['messages'])) {
	foreach (['success', 'error'] as $type) {
		if (isset($userFlash['messages'][$type]) && is_array($userFlash['messages'][$type])) {
			$messages[$type] = $userFlash['messages'][$type];
		}
	}
}

// Build equipment lookup for access summaries.
$equipmentLookup = [];
$equipmentResult = mysqli_query($conn, 'SELECT equipment_id, name, category, risk_level FROM equipment ORDER BY name ASC');
if ($equipmentResult instanceof mysqli_result) {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
		if ($equipmentId <= 0) {
			continue;
		}
		$name = trim((string) ($row['name'] ?? ''));
		if ($name === '') {
			$name = 'Equipment #' . $equipmentId;
		}
		$category = trim((string) ($row['category'] ?? ''));
		$riskLevel = trim((string) ($row['risk_level'] ?? ''));
		$equipmentLookup[$equipmentId] = [
			'name' => $name,
			'category' => $category,
			'risk_level' => $riskLevel,
		];
	}
	mysqli_free_result($equipmentResult);
}

// Load role options for user creation and editing.
$roles = [];
$roleLookup = [];
$rolesResult = mysqli_query($conn, 'SELECT role_id, role_name FROM roles ORDER BY role_name ASC');
if ($rolesResult instanceof mysqli_result) {
	while ($row = mysqli_fetch_assoc($rolesResult)) {
		$roleId = isset($row['role_id']) ? (int) $row['role_id'] : 0;
		$roleName = trim((string) ($row['role_name'] ?? ''));
		if ($roleId <= 0 || $roleName === '') {
			continue;
		}
		$roles[] = ['role_id' => $roleId, 'role_name' => $roleName];
		$roleLookup[$roleId] = $roleName;
	}
	mysqli_free_result($rolesResult);
}

// Normalize role labels for comparisons.
$normalizeRoleLabel = static function (?string $value): string {
	return strtolower(trim((string) $value));
};

$getRoleKeyById = static function (int $roleId) use ($roleLookup, $normalizeRoleLabel): string {
	return $normalizeRoleLabel($roleLookup[$roleId] ?? '');
};

$isAdminRoleId = static function (int $roleId) use ($getRoleKeyById): bool {
	return $getRoleKeyById($roleId) === 'admin';
};

// Handle create, update, and delete user actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = trim((string) ($_POST['action'] ?? ''));
	if ($action === 'create_user') {
		$csrfToken = (string) ($_POST['csrf_token'] ?? '');
		if (!validate_csrf_token('admin_user_create', $csrfToken)) {
			$messages['error'][] = 'Your create request expired. Please refresh and try again.';
		} else {
			$adminNumber = trim((string) ($_POST['tp_admin_no'] ?? ''));
			$fullName = trim((string) ($_POST['full_name'] ?? ''));
			$roleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
			$password = (string) ($_POST['password'] ?? '');

			if ($adminNumber === '') {
				$messages['error'][] = 'Admin number is required.';
			}
			if ($fullName === '') {
				$messages['error'][] = 'Full name is required.';
			}
			if ($password === '') {
				$messages['error'][] = 'Password is required.';
			}
			if (!isset($roleLookup[$roleId])) {
				$messages['error'][] = 'Select a valid role.';
			}

			if (empty($messages['error'])) {
				$dupStmt = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE tp_admin_no = ? LIMIT 1');
				if ($dupStmt === false) {
					$messages['error'][] = 'Unable to verify admin number uniqueness.';
				} else {
					mysqli_stmt_bind_param($dupStmt, 's', $adminNumber);
					mysqli_stmt_execute($dupStmt);
					mysqli_stmt_store_result($dupStmt);
					if (mysqli_stmt_num_rows($dupStmt) > 0) {
						$messages['error'][] = 'Admin number already exists. Choose another one.';
					}
					mysqli_stmt_close($dupStmt);
				}
			}

			if (empty($messages['error'])) {
				$passwordHash = password_hash($password, PASSWORD_DEFAULT);
				$insertStmt = mysqli_prepare(
					$conn,
					'INSERT INTO users (tp_admin_no, full_name, role_id, password_hash) VALUES (?, ?, ?, ?)'
				);
				if ($insertStmt === false) {
					$messages['error'][] = 'Unable to create the user account right now.';
				} else {
					mysqli_stmt_bind_param($insertStmt, 'ssis', $adminNumber, $fullName, $roleId, $passwordHash);
					if (mysqli_stmt_execute($insertStmt)) {
						$newUserId = (int) mysqli_insert_id($conn);
						if ($newUserId > 0) {
							record_data_modification_audit(
								$conn,
								$currentUser,
								'user',
								$newUserId,
								[
									'action' => 'create',
									'tp_admin_no' => $adminNumber,
									'role_id' => $roleId,
								]
							);
						} else {
							$messages['error'][] = 'Failed to save the new user account.';
						}
						if ($newUserId > 0) {
							$messages['success'][] = 'User account created successfully.';
						}
					} else {
						$messages['error'][] = 'Failed to save the new user account.';
					}
					mysqli_stmt_close($insertStmt);
				}
			}
		}
	} elseif ($action === 'update_user') {
		$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$csrfToken = (string) ($_POST['csrf_token'] ?? '');
		if ($userId <= 0 || !validate_csrf_token('admin_user_update_' . $userId, $csrfToken)) {
			$messages['error'][] = 'Unable to verify that update request.';
		} else {
			$adminNumber = trim((string) ($_POST['tp_admin_no'] ?? ''));
			$fullName = trim((string) ($_POST['full_name'] ?? ''));
			$roleId = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
			$newPassword = trim((string) ($_POST['password'] ?? ''));
			$targetRow = null;
			$targetIsAdmin = false;

			$targetStmt = mysqli_prepare($conn, 'SELECT role_id FROM users WHERE user_id = ? LIMIT 1');
			if ($targetStmt === false) {
				$messages['error'][] = 'Unable to load that account right now.';
			} else {
				mysqli_stmt_bind_param($targetStmt, 'i', $userId);
				mysqli_stmt_execute($targetStmt);
				$targetResult = mysqli_stmt_get_result($targetStmt);
				$targetRow = $targetResult ? mysqli_fetch_assoc($targetResult) : null;
				if ($targetResult) {
					mysqli_free_result($targetResult);
				}
				mysqli_stmt_close($targetStmt);
				if (!$targetRow) {
					$messages['error'][] = 'That user record no longer exists.';
				} else {
					$targetRoleId = isset($targetRow['role_id']) ? (int) $targetRow['role_id'] : 0;
					$targetIsAdmin = $isAdminRoleId($targetRoleId);
				}
			}

			if ($adminNumber === '' || $fullName === '' || !isset($roleLookup[$roleId])) {
				$messages['error'][] = 'Provide a valid admin number, name, and role before saving.';
			} else {
				$dupStmt = mysqli_prepare(
					$conn,
					'SELECT user_id FROM users WHERE tp_admin_no = ? AND user_id <> ? LIMIT 1'
				);
				if ($dupStmt === false) {
					$messages['error'][] = 'Unable to validate uniqueness for that admin number.';
				} else {
					mysqli_stmt_bind_param($dupStmt, 'si', $adminNumber, $userId);
					mysqli_stmt_execute($dupStmt);
					mysqli_stmt_store_result($dupStmt);
					if (mysqli_stmt_num_rows($dupStmt) > 0) {
						$messages['error'][] = 'Another account already uses that admin number.';
					}
					mysqli_stmt_close($dupStmt);
				}
			}

			if (empty($messages['error']) && $targetIsAdmin) {
				if ($userId !== $currentUserId) {
					$messages['error'][] = 'Administrator accounts can only be modified by the account owner.';
				}
				if (!$isAdminRoleId($roleId)) {
					$messages['error'][] = 'Administrator accounts must retain the Admin role.';
				}
			}

			if (empty($messages['error'])) {
				if ($newPassword !== '') {
					$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
					$updateStmt = mysqli_prepare(
						$conn,
						'UPDATE users SET tp_admin_no = ?, full_name = ?, role_id = ?, password_hash = ? WHERE user_id = ?'
					);
					if ($updateStmt) {
						mysqli_stmt_bind_param($updateStmt, 'ssisi', $adminNumber, $fullName, $roleId, $passwordHash, $userId);
					}
				} else {
					$updateStmt = mysqli_prepare(
						$conn,
						'UPDATE users SET tp_admin_no = ?, full_name = ?, role_id = ? WHERE user_id = ?'
					);
					if ($updateStmt) {
						mysqli_stmt_bind_param($updateStmt, 'ssii', $adminNumber, $fullName, $roleId, $userId);
					}
				}

				$accountUpdated = false;
				if ($updateStmt === false) {
					$messages['error'][] = 'Unable to update that account right now.';
				} else {
					if (mysqli_stmt_execute($updateStmt)) {
						$accountUpdated = true;
					} else {
						$messages['error'][] = 'Failed to save the user changes.';
					}
					mysqli_stmt_close($updateStmt);
				}

				if ($accountUpdated) {
					$messages['success'][] = 'User account updated.';
					record_data_modification_audit(
						$conn,
						$currentUser,
						'user',
						$userId,
						[
							'action' => 'update',
							'tp_admin_no' => $adminNumber,
							'role_id' => $roleId,
							'password_rotated' => $newPassword !== '',
						]
					);
				}
			}
		}
	} elseif ($action === 'delete_user') {
		$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$csrfToken = (string) ($_POST['csrf_token'] ?? '');
		if ($userId <= 0 || !validate_csrf_token('admin_user_delete_' . $userId, $csrfToken)) {
			$messages['error'][] = 'Unable to verify the delete request.';
		} else {
			$targetRow = null;
			$targetStmt = mysqli_prepare($conn, 'SELECT role_id, tp_admin_no FROM users WHERE user_id = ? LIMIT 1');
			if ($targetStmt === false) {
				$messages['error'][] = 'Unable to load that account right now.';
			} else {
				mysqli_stmt_bind_param($targetStmt, 'i', $userId);
				mysqli_stmt_execute($targetStmt);
				$targetResult = mysqli_stmt_get_result($targetStmt);
				$targetRow = $targetResult ? mysqli_fetch_assoc($targetResult) : null;
				if ($targetResult) {
					mysqli_free_result($targetResult);
				}
				mysqli_stmt_close($targetStmt);
				if (!$targetRow) {
					$messages['error'][] = 'No matching account was found to delete.';
				} elseif ($isAdminRoleId((int) ($targetRow['role_id'] ?? 0))) {
					$messages['error'][] = 'Administrator accounts cannot be deleted.';
				}
			}

			if (empty($messages['error'])) {
				$deleteStmt = mysqli_prepare($conn, 'DELETE FROM users WHERE user_id = ? LIMIT 1');
				if ($deleteStmt === false) {
					$messages['error'][] = 'Unable to delete that account right now.';
				} else {
					mysqli_stmt_bind_param($deleteStmt, 'i', $userId);
					if (mysqli_stmt_execute($deleteStmt) && mysqli_stmt_affected_rows($deleteStmt) === 1) {
						$messages['success'][] = 'User account removed.';
						record_data_modification_audit(
							$conn,
							$currentUser,
							'user',
							$userId,
							[
								'action' => 'delete',
								'tp_admin_no' => trim((string) ($targetRow['tp_admin_no'] ?? '')),
							]
						);
					} else {
						$messages['error'][] = 'No account was removed. It may have already been deleted.';
					}
					mysqli_stmt_close($deleteStmt);
				}
			}
		}
	}

	flash_store('admin_users', ['messages' => $messages]);
	redirect_to_current_uri('admin-users.php');
}

// Load user list for the admin table.
$users = [];
$usersResult = mysqli_query(
	$conn,
	'  SELECT u.user_id, u.tp_admin_no, u.full_name, u.role_id, r.role_name
	   FROM users u
	   INNER JOIN roles r ON r.role_id = u.role_id
	   ORDER BY u.user_id ASC'
);
if ($usersResult instanceof mysqli_result) {
	while ($row = mysqli_fetch_assoc($usersResult)) {
		$users[] = $row;
	}
	mysqli_free_result($usersResult);
}

// CSRF token for the create-user form.
$createToken = generate_csrf_token('admin_user_create');
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>User Accounts | Admin Control</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<!-- Base styles for the user management page. -->
		<style>
			:root {
				--bg: #f8fbff;
				--card: #ffffff;
				--text: #0f172a;
				--muted: #64748b;
				--accent: #4361ee;
				--danger: #dc2626;
				--border: #e2e8f0;
				font-size: 16px;
			}

			* {
				box-sizing: border-box;
			}

			body {
				margin: 0;
				font-family: "Space Grotesk", "Segoe UI", Tahoma, sans-serif;
				color: var(--text);
				background: radial-gradient(circle at top, #eef3ff, var(--bg));
				min-height: 100vh;
			}

			header {
				padding: 1.5rem clamp(1.5rem, 5vw, 4rem);
				background: var(--card);
				border-bottom: 1px solid var(--border);
				box-shadow: 0 24px 45px rgba(67, 97, 238, 0.12);
				position: sticky;
				top: 0;
				z-index: 10;
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.grid-two {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
				gap: 1.5rem;
				margin-bottom: 2rem;
			}

			.card {
				background: var(--card);
				border: 1px solid var(--border);
				border-radius: 1rem;
				padding: 1.5rem;
				box-shadow: 0 18px 35px rgba(15, 23, 42, 0.08);
			}

			.card h2 {
				margin-top: 0;
			}

			label {
				display: flex;
				flex-direction: column;
				gap: 0.35rem;
				font-size: 0.9rem;
				font-weight: 600;
				color: var(--muted);
			}

			input,
			select {
				border: 1px solid var(--border);
				border-radius: 0.75rem;
				padding: 0.55rem 0.75rem;
				font-family: inherit;
				font-size: 0.95rem;
			}

			button {
				border: none;
				border-radius: 0.75rem;
				padding: 0.6rem 1.2rem;
				font-weight: 600;
				cursor: pointer;
			}

			button.primary {
				background: var(--accent);
				color: #fff;
			}

			button.danger {
				background: var(--danger);
				color: #fff;
			}

			.muted-text {
				color: var(--muted);
				font-size: 0.9rem;
			}

			.equip-meta {
				display: block;
				font-weight: 500;
				font-size: 0.8rem;
				color: var(--muted);
			}

			.access-manager details {
				border: 1px solid var(--border);
				border-radius: 0.9rem;
				padding: 0.85rem;
				background: #f8fafc;
				transition: box-shadow 0.2s ease;
			}

			.access-manager summary {
				cursor: pointer;
				font-weight: 600;
				color: var(--accent);
				outline: none;
			}

			.access-manager summary::-webkit-details-marker {
				color: var(--accent);
			}

			.access-manager details[open] {
				background: #fff;
				box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
			}

			.equipment-taglist {
				display: flex;
				flex-wrap: wrap;
				gap: 0.4rem;
				margin-top: 0.6rem;
			}

			.equipment-tag {
				padding: 0.18rem 0.6rem;
				border-radius: 999px;
				background: rgba(67, 97, 238, 0.12);
				font-size: 0.78rem;
				font-weight: 600;
				color: var(--accent);
			}

			.equipment-tag.muted-tag {
				background: rgba(100, 116, 139, 0.18);
				color: var(--muted);
			}

			table {
				width: 100%;
				border-collapse: collapse;
				background: var(--card);
				border: 1px solid var(--border);
				border-radius: 1rem;
				overflow: hidden;
				box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
			}

			thead {
				background: rgba(67, 97, 238, 0.08);
			}

			th,
			td {
				padding: 0.85rem 1rem;
				text-align: left;
				border-bottom: 1px solid var(--border);
			}

			tbody tr:last-child td {
				border-bottom: none;
			}

			.actions {
				display: flex;
				gap: 0.5rem;
				flex-wrap: wrap;
			}

			.notice {
				margin-bottom: 1rem;
				padding: 0.85rem 1rem;
				border-radius: 0.75rem;
				font-weight: 600;
			}

			.notice.success {
				background: #ecfdf5;
				color: #065f46;
			}

			.notice.error {
				background: #fef2f2;
				color: #991b1b;
			}

			@media (max-width: 768px) {
				table,
				thead,
				tbody,
				th,
				td,
				tr {
					display: block;
				}

				thead {
					display: none;
				}

				td {
					border-bottom: 1px solid rgba(226, 232, 240, 0.7);
				}

				td::before {
					content: attr(data-label);
					display: block;
					font-size: 0.8rem;
					color: var(--muted);
					text-transform: uppercase;
					letter-spacing: 0.05em;
					margin-bottom: 0.2rem;
				}
			}
		</style>
	</head>
	<body>
		<!-- Header showing the signed-in admin identity. -->
		<header>
			<h1 style="margin: 0; font-size: clamp(1.5rem, 3vw, 2.4rem);">User Accounts Control</h1>
			<p style="margin: 0.35rem 0 0; color: var(--muted);">
				Signed in as <?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?> (<?php echo htmlspecialchars($roleDisplay, ENT_QUOTES); ?>)
			</p>
		</header>
		<main>
			<!-- Flash messages for recent actions. -->
			<?php foreach (['success', 'error'] as $type): ?>
				<?php foreach ($messages[$type] as $message): ?>
					<div class="notice <?php echo $type; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<!-- Create user form and quick navigation panel. -->
			<div class="grid-two">
				<div class="card">
					<!-- User creation form for adding new accounts. -->
					<h2>Create New User</h2>
					<form method="post">
						<input type="hidden" name="action" value="create_user" />
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($createToken, ENT_QUOTES); ?>" />
						<!-- Core identity fields for a new account. -->
						<label>
							Admin Number
							<input type="text" name="tp_admin_no" required />
						</label>
						<label>
							Full Name
							<input type="text" name="full_name" required />
						</label>
						<label>
							Role
							<select name="role_id" required>
								<option value="">Select a role</option>
								<?php foreach ($roles as $role): ?>
									<option value="<?php echo (int) $role['role_id']; ?>">
										<?php echo htmlspecialchars($role['role_name'], ENT_QUOTES); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<!-- Temporary password for first login. -->
						<label>
							Temporary Password
							<input type="password" name="password" required />
						</label>
						<p class="muted-text">Equipment access is unlocked automatically when the user completes the required certifications.</p>
						<button type="submit" class="primary">Create User</button>
					</form>
				</div>
				<div class="card">
					<!-- Quick links for cross-role testing and navigation. -->
					<h2>Platform Shortcuts</h2>
					<p>Need to impersonate a role or verify user journeys? Use the quick links below to jump directly into each workspace.</p>
					<ul style="margin: 1rem 0 0; padding-left: 1.2rem; color: var(--muted); line-height: 1.6;">
						<li><a href="manager.php">Manager Workspace</a></li>
						<li><a href="technician.php">Technician Console</a></li>
						<li><a href="book-machines.php">Learner Booking Portal</a></li>
						<li><a href="learning-space.php">Learning Space</a></li>
					</ul>
				</div>
			</div>

			<!-- User table listing and inline edit/delete controls. -->
			<?php if (empty($users)): ?>
				<p style="color: var(--muted);">No users found in the system.</p>
			<?php else: ?>
				<table>
					<thead>
						<tr>
							<th>ID</th>
							<th>Admin Number</th>
							<th>Full Name</th>
							<th>Role</th>
							<th>Equipment Access</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($users as $user): ?>
							<?php
								// Prepare per-user tokens and access summaries.
								$userId = (int) ($user['user_id'] ?? 0);
								$updateToken = generate_csrf_token('admin_user_update_' . $userId);
								$deleteToken = generate_csrf_token('admin_user_delete_' . $userId);
								$updateFormId = 'update-user-' . $userId;
								$deleteFormId = 'delete-user-' . $userId;
								$scopeLimited = should_limit_equipment_scope($conn, $userId);
								$accessibleIds = $scopeLimited ? array_keys(get_user_equipment_access_map($conn, $userId)) : [];
								$accessibleNames = [];
								foreach ($accessibleIds as $accessibleEquipmentId) {
									$accessibleNames[] = $equipmentLookup[$accessibleEquipmentId]['name'] ?? ('Equipment #' . $accessibleEquipmentId);
								}
								$accessSummary = $scopeLimited
									? (empty($accessibleNames) ? 'No equipment unlocked' : count($accessibleNames) . ' unlocked')
									: 'Full access';
								$previewTags = array_slice($accessibleNames, 0, 3);
								$remainingTagCount = max(0, count($accessibleNames) - count($previewTags));
								$rowRoleKey = $normalizeRoleLabel($user['role_name'] ?? '');
								$rowIsAdmin = $rowRoleKey === 'admin';
								$isSelfRow = $userId === $currentUserId;
								$lockAdminRow = $rowIsAdmin && !$isSelfRow;
							?>
							<!-- Hidden forms used for per-row update and delete actions. -->
							<form id="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" method="post">
								<input type="hidden" name="action" value="update_user" />
								<input type="hidden" name="user_id" value="<?php echo $userId; ?>" />
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($updateToken, ENT_QUOTES); ?>" />
							</form>
							<form id="<?php echo htmlspecialchars($deleteFormId, ENT_QUOTES); ?>" method="post">
								<input type="hidden" name="action" value="delete_user" />
								<input type="hidden" name="user_id" value="<?php echo $userId; ?>" />
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteToken, ENT_QUOTES); ?>" />
							</form>
							<tr>
								<td data-label="ID"><?php echo $userId; ?></td>
								<td data-label="Admin Number">
									<input type="text" name="tp_admin_no" form="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars((string) ($user['tp_admin_no'] ?? ''), ENT_QUOTES); ?>" required <?php echo $lockAdminRow ? 'disabled="disabled"' : ''; ?> />
								</td>
								<td data-label="Full Name">
									<input type="text" name="full_name" form="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars((string) ($user['full_name'] ?? ''), ENT_QUOTES); ?>" required <?php echo $lockAdminRow ? 'disabled="disabled"' : ''; ?> />
								</td>
								<td data-label="Role">
									<select name="role_id" form="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" required <?php echo $lockAdminRow ? 'disabled="disabled"' : ''; ?>>
										<?php foreach ($roles as $role): ?>
											<option value="<?php echo (int) $role['role_id']; ?>" <?php echo (int) ($user['role_id'] ?? 0) === (int) $role['role_id'] ? 'selected' : ''; ?>>
												<?php echo htmlspecialchars($role['role_name'], ENT_QUOTES); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td data-label="Equipment Access">
									<div class="access-manager">
										<p class="muted-text" style="margin: 0; font-weight: 600;">Access summary: <?php echo htmlspecialchars($accessSummary, ENT_QUOTES); ?></p>
										<?php if ($scopeLimited): ?>
											<?php if (empty($previewTags)): ?>
												<p class="muted-text" style="margin: 0.5rem 0 0;">No equipment unlocked yet.</p>
											<?php else: ?>
												<div class="equipment-taglist" style="margin-top: 0.6rem;">
													<?php foreach ($previewTags as $tagName): ?>
														<span class="equipment-tag"><?php echo htmlspecialchars($tagName, ENT_QUOTES); ?></span>
													<?php endforeach; ?>
													<?php if ($remainingTagCount > 0): ?>
														<span class="equipment-tag">+<?php echo $remainingTagCount; ?> more</span>
													<?php endif; ?>
												</div>
											<?php endif; ?>
											<p class="muted-text" style="margin: 0.5rem 0 0;">Equipment access follows the user's completed certifications.</p>
										<?php else: ?>
											<p class="muted-text" style="margin: 0.5rem 0 0;">Privileged role — full equipment catalogue available.</p>
										<?php endif; ?>
									</div>
								</td>
								<td data-label="Actions">
									<div class="actions">
										<label style="margin: 0; width: 220px;">
											<span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted);">New Password (optional)</span>
											<input type="password" name="password" form="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" placeholder="Leave blank to keep" <?php echo $lockAdminRow ? 'disabled="disabled"' : ''; ?> />
										</label>
										<button type="submit" form="<?php echo htmlspecialchars($updateFormId, ENT_QUOTES); ?>" class="primary" <?php echo $lockAdminRow ? 'disabled="disabled" title="Administrator accounts can only be changed by the account owner."' : ''; ?>>Save</button>
										<button type="submit" form="<?php echo htmlspecialchars($deleteFormId, ENT_QUOTES); ?>" class="danger" onclick="return confirm('Delete this user? This cannot be undone.');" <?php echo $rowIsAdmin ? 'disabled="disabled" title="Administrator accounts cannot be deleted."' : ''; ?>>Delete</button>
									</div>
									<?php if ($lockAdminRow): ?>
										<p class="muted-text" style="margin: 0.4rem 0 0;">Administrator accounts can only be changed by the account owner.</p>
									<?php endif; ?>
									<?php if ($rowIsAdmin): ?>
										<p class="muted-text" style="margin: 0.2rem 0 0;">Administrator accounts cannot be deleted.</p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</main>
	</body>
</html>
