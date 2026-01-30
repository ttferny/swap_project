<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = require_login(['manager', 'admin']);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Manager';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Manager'));
$logoutToken = generate_csrf_token('logout_form');
$assignToken = generate_csrf_token('assign_incident');
$investigationToken = generate_csrf_token('update_investigation');

$assignmentError = null;
$assignmentNotice = null;
$investigationUpdateError = null;
$investigationUpdateNotice = null;
$staffMembers = [];
$staffLookup = [];

$staffSql = "SELECT u.user_id, u.full_name, r.role_name
	FROM users u
	INNER JOIN roles r ON r.role_id = u.role_id
	WHERE r.role_name IN ('Staff', 'User')
	AND u.status = 'active'
	ORDER BY u.full_name";
$staffResult = mysqli_query($conn, $staffSql);
if ($staffResult !== false) {
	while ($row = mysqli_fetch_assoc($staffResult)) {
		$staffMembers[] = $row;
		$staffLookup[(int) $row['user_id']] = $row;
	}
	mysqli_free_result($staffResult);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_incident'])) {
	$csrfToken = (string) ($_POST['csrf_token'] ?? '');
	$incidentId = (int) ($_POST['incident_id'] ?? 0);
	$assignedTo = (int) ($_POST['assigned_to'] ?? 0);

	if (!validate_csrf_token('assign_incident', $csrfToken)) {
		$assignmentError = 'Your session expired. Please try again.';
	} elseif ($incidentId <= 0) {
		$assignmentError = 'Invalid incident selected.';
	} elseif ($assignedTo <= 0 || !isset($staffLookup[$assignedTo])) {
		$assignmentError = 'Please select a valid staff member.';
	} else {
		$success = false;
		mysqli_begin_transaction($conn);
		try {
			$existingId = null;
			$checkStmt = mysqli_prepare($conn, 'SELECT investigation_id FROM incident_investigations WHERE incident_id = ? LIMIT 1');
			if ($checkStmt) {
				mysqli_stmt_bind_param($checkStmt, 'i', $incidentId);
				mysqli_stmt_execute($checkStmt);
				mysqli_stmt_bind_result($checkStmt, $existingId);
				mysqli_stmt_fetch($checkStmt);
				mysqli_stmt_close($checkStmt);
			}

			if ($existingId) {
				$updateStmt = mysqli_prepare($conn, 'UPDATE incident_investigations SET assigned_to = ?, updated_at = CURRENT_TIMESTAMP WHERE investigation_id = ?');
				if ($updateStmt) {
					mysqli_stmt_bind_param($updateStmt, 'ii', $assignedTo, $existingId);
					$success = mysqli_stmt_execute($updateStmt);
					mysqli_stmt_close($updateStmt);
				}
			} else {
				$insertStmt = mysqli_prepare($conn, 'INSERT INTO incident_investigations (incident_id, assigned_to) VALUES (?, ?)');
				if ($insertStmt) {
					mysqli_stmt_bind_param($insertStmt, 'ii', $incidentId, $assignedTo);
					$success = mysqli_stmt_execute($insertStmt);
					mysqli_stmt_close($insertStmt);
				}
			}

			if ($success) {
				$statusStmt = mysqli_prepare($conn, "UPDATE incidents SET status = 'under_review', updated_at = CURRENT_TIMESTAMP WHERE incident_id = ?");
				if ($statusStmt) {
					mysqli_stmt_bind_param($statusStmt, 'i', $incidentId);
					$success = mysqli_stmt_execute($statusStmt);
					mysqli_stmt_close($statusStmt);
				}
			}

			if ($success) {
				mysqli_commit($conn);
				$staffName = trim((string) ($staffLookup[$assignedTo]['full_name'] ?? ''));
				$assignmentNotice = $staffName !== ''
					? 'Incident assigned to ' . $staffName . '.'
					: 'Incident assigned successfully.';
			} else {
				mysqli_rollback($conn);
				$assignmentError = 'Unable to assign the incident right now.';
			}
		} catch (Throwable $e) {
			mysqli_rollback($conn);
			$assignmentError = 'Unable to assign the incident right now.';
		}
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_investigation'])) {
	$csrfToken = (string) ($_POST['csrf_token'] ?? '');
	$investigationId = (int) ($_POST['investigation_id'] ?? 0);
	$findings = trim((string) ($_POST['findings'] ?? ''));
	$actionsTaken = trim((string) ($_POST['actions_taken'] ?? ''));

	if (!validate_csrf_token('update_investigation', $csrfToken)) {
		$investigationUpdateError = 'Your session expired. Please try again.';
	} elseif ($investigationId <= 0) {
		$investigationUpdateError = 'Invalid investigation selected.';
	} elseif ($findings === '' || $actionsTaken === '') {
		$investigationUpdateError = 'Findings and actions taken are required to close the investigation.';
	} else {
		$success = false;
		mysqli_begin_transaction($conn);
		try {
			$incidentId = null;
			$lookupStmt = mysqli_prepare(
				$conn,
				'SELECT incident_id FROM incident_investigations WHERE investigation_id = ? LIMIT 1'
			);
			if ($lookupStmt) {
				mysqli_stmt_bind_param($lookupStmt, 'i', $investigationId);
				mysqli_stmt_execute($lookupStmt);
				mysqli_stmt_bind_result($lookupStmt, $incidentId);
				mysqli_stmt_fetch($lookupStmt);
				mysqli_stmt_close($lookupStmt);
			}

			$encryptedFindings = encrypt_sensitive_value($findings);
			$encryptedActions = encrypt_sensitive_value($actionsTaken);
			$updateStmt = mysqli_prepare(
				$conn,
				'UPDATE incident_investigations SET findings = ?, actions_taken = ?, closed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE investigation_id = ?'
			);
			if ($updateStmt) {
				mysqli_stmt_bind_param($updateStmt, 'ssi', $encryptedFindings, $encryptedActions, $investigationId);
				$success = mysqli_stmt_execute($updateStmt);
				mysqli_stmt_close($updateStmt);
			}

			if ($success && $incidentId) {
				$statusStmt = mysqli_prepare(
					$conn,
					"UPDATE incidents SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE incident_id = ?"
				);
				if ($statusStmt) {
					mysqli_stmt_bind_param($statusStmt, 'i', $incidentId);
					$success = mysqli_stmt_execute($statusStmt);
					mysqli_stmt_close($statusStmt);
				}
			}

			if ($success) {
				mysqli_commit($conn);
				$investigationUpdateNotice = 'Investigation closed.';
			} else {
				mysqli_rollback($conn);
				$investigationUpdateError = 'Unable to close the investigation right now.';
			}
		} catch (Throwable $e) {
			mysqli_rollback($conn);
			$investigationUpdateError = 'Unable to close the investigation right now.';
		}
	}
}

$incidents = [];
$incidentsError = null;

$investigations = [];
$investigationsError = null;

$incidentSql = 'SELECT i.incident_id, i.severity, i.category, i.location, i.description, i.status, i.created_at, i.updated_at,
		u.full_name, u.tp_admin_no, r.role_name AS reporter_role,
		e.name AS equipment_name, e.location AS equipment_location
	FROM incidents i
	LEFT JOIN users u ON u.user_id = i.reported_by
	LEFT JOIN roles r ON r.role_id = u.role_id
	LEFT JOIN equipment e ON e.equipment_id = i.equipment_id
	LEFT JOIN incident_investigations ii ON ii.incident_id = i.incident_id AND ii.assigned_to IS NOT NULL
	WHERE ii.investigation_id IS NULL
	ORDER BY i.created_at DESC';

$incidentResult = mysqli_query($conn, $incidentSql);
if ($incidentResult === false) {
	$incidentsError = 'Unable to load incident reports right now.';
} else {
	while ($row = mysqli_fetch_assoc($incidentResult)) {
		$row['location'] = decrypt_sensitive_value($row['location'] ?? null);
		$row['description'] = decrypt_sensitive_value($row['description'] ?? null);
		$incidents[] = $row;
	}
	mysqli_free_result($incidentResult);
}

	$investigationSql = 'SELECT ii.investigation_id, ii.incident_id, ii.assigned_to, ii.findings, ii.actions_taken, ii.closed_at, ii.created_at, ii.updated_at,
		i.severity, i.category, i.status, i.description AS incident_description, i.location,
		e.name AS equipment_name, e.location AS equipment_location,
		u.full_name AS assigned_name, r.role_name AS assigned_role
	FROM incident_investigations ii
	LEFT JOIN incidents i ON i.incident_id = ii.incident_id
	LEFT JOIN equipment e ON e.equipment_id = i.equipment_id
	LEFT JOIN users u ON u.user_id = ii.assigned_to
	LEFT JOIN roles r ON r.role_id = u.role_id
		WHERE ii.assigned_to IS NOT NULL AND ii.closed_at IS NULL
	ORDER BY ii.created_at DESC';

$investigationResult = mysqli_query($conn, $investigationSql);
if ($investigationResult === false) {
	$investigationsError = 'Unable to load incident investigations right now.';
} else {
	while ($row = mysqli_fetch_assoc($investigationResult)) {
		$row['location'] = decrypt_sensitive_value($row['location'] ?? null);
		$row['incident_description'] = decrypt_sensitive_value($row['incident_description'] ?? null);
		$row['findings'] = decrypt_sensitive_value($row['findings'] ?? null);
		$row['actions_taken'] = decrypt_sensitive_value($row['actions_taken'] ?? null);
		$investigations[] = $row;
	}
	mysqli_free_result($investigationResult);
}

$severityLabels = [
	'low' => 'Low',
	'medium' => 'Medium',
	'high' => 'High',
	'critical' => 'Critical',
];

$categoryLabels = [
	'near_miss' => 'Near miss',
	'injury' => 'Injury',
	'hazard' => 'Hazard',
	'damage' => 'Damage',
	'security' => 'Security',
	'other' => 'Other',
];

$statusLabels = [
	'submitted' => 'Submitted',
	'under_review' => 'Under review',
	'action_required' => 'Action required',
	'closed' => 'Closed',
];

function format_incident_location(string $incidentLocation, string $equipmentName, string $equipmentLocation): string
{
	$location = trim($incidentLocation);
	if ($location === '') {
		return '';
	}
	$equipmentName = trim($equipmentName);
	$equipmentLocation = trim($equipmentLocation);
	if ($equipmentName !== '' && strcasecmp($location, $equipmentName) === 0) {
		if ($equipmentLocation !== '') {
			return $equipmentName . ' · ' . $equipmentLocation;
		}
		return $equipmentName;
	}
	return $location;
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Incident Reports</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<style>
			:root {
				--bg: #f8fbff;
				--accent: #10b981;
				--accent-soft: #e6fff5;
				--text: #0f172a;
				--muted: #64748b;
				--card: #ffffff;
				font-size: 16px;
			}

			* {
				box-sizing: border-box;
			}

			body {
				margin: 0;
				font-family: "Space Grotesk", "Segoe UI", Tahoma, sans-serif;
				color: var(--text);
				background: radial-gradient(circle at top, #eefdf6, var(--bg));
				min-height: 100vh;
			}

			header {
				padding: 1.5rem clamp(1.5rem, 5vw, 4rem);
				background: var(--card);
				border-bottom: 1px solid #e2e8f0;
				box-shadow: 0 30px 60px rgba(15, 23, 42, 0.05);
				position: sticky;
				top: 0;
				z-index: 10;
			}

			.banner {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 2rem;
			}

			.banner h1 {
				margin: 0;
				font-size: clamp(1.5rem, 3vw, 2.2rem);
				font-weight: 600;
			}

			.banner-actions {
				display: flex;
				align-items: center;
				gap: 0.75rem;
			}

			.search-bar {
				display: flex;
				align-items: center;
				gap: 0.5rem;
				padding: 0.35rem 0.75rem;
				border-radius: 999px;
				border: 1px solid #d7def0;
				background: var(--accent-soft);
			}

			.search-bar input {
				border: none;
				background: transparent;
				font-family: inherit;
				font-size: 0.95rem;
				min-width: 11rem;
				color: var(--text);
			}

			.search-bar input:focus {
				outline: none;
			}

			.icon-button {
				width: 42px;
				height: 42px;
				border-radius: 50%;
				border: none;
				background: var(--accent-soft);
				display: grid;
				place-items: center;
				cursor: pointer;
				transition: background 0.2s ease, transform 0.2s ease;
			}

			.icon-button:hover {
				background: #c4f7df;
				transform: translateY(-1px);
			}

			.icon-button svg {
				width: 20px;
				height: 20px;
				fill: var(--accent);
			}

			.profile-menu {
				position: relative;
			}

			.profile-menu summary {
				list-style: none;
				cursor: pointer;
			}

			.profile-menu summary::-webkit-details-marker {
				display: none;
			}

			.profile-dropdown {
				position: absolute;
				top: calc(100% + 0.5rem);
				right: 0;
				min-width: 200px;
				background: var(--card);
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				box-shadow: 0 20px 45px rgba(15, 23, 42, 0.15);
				padding: 1rem;
				opacity: 0;
				transform: translateY(-6px);
				pointer-events: none;
				transition: opacity 0.2s ease, transform 0.2s ease;
				z-index: 15;
			}

			.profile-menu[open] .profile-dropdown {
				opacity: 1;
				transform: translateY(0);
				pointer-events: auto;
			}

			.profile-name {
				margin: 0;
				font-weight: 600;
			}

			.profile-role {
				margin: 0.15rem 0 0.75rem;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.logout-form {
				margin: 0;
			}

			.logout-form button {
				width: 100%;
				border: none;
				border-radius: 0.75rem;
				padding: 0.65rem 1rem;
				font-size: 0.95rem;
				font-weight: 600;
				color: #fff;
				background: var(--accent);
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.logout-form button:hover {
				transform: translateY(-1px);
				box-shadow: 0 10px 20px rgba(16, 185, 129, 0.25);
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.intro {
				max-width: 640px;
				margin-bottom: 2rem;
			}

			.intro p {
				color: var(--muted);
				line-height: 1.6;
			}

			.card {
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(16, 185, 129, 0.12);
			}

			.card + .card {
				margin-top: 2rem;
			}

			.incident-list.scrollable,
			.investigation-list.scrollable {
				max-height: 460px;
				overflow: auto;
				padding-right: 0.35rem;
				scrollbar-width: thin;
				scrollbar-color: rgba(148, 163, 184, 0.6) transparent;
			}

			.incident-list.scrollable::-webkit-scrollbar,
			.investigation-list.scrollable::-webkit-scrollbar {
				width: 8px;
			}

			.incident-list.scrollable::-webkit-scrollbar-track,
			.investigation-list.scrollable::-webkit-scrollbar-track {
				background: transparent;
			}

			.incident-list.scrollable::-webkit-scrollbar-thumb,
			.investigation-list.scrollable::-webkit-scrollbar-thumb {
				background-color: rgba(148, 163, 184, 0.5);
				border-radius: 999px;
				border: 2px solid transparent;
				background-clip: content-box;
			}

			.card h2 {
				margin-top: 0;
				font-size: 1.25rem;
			}

			.card p {
				color: var(--muted);
			}

			.alert {
				padding: 0.85rem 1rem;
				border-radius: 0.8rem;
				border: 1px solid #fecaca;
				background: #fee2e2;
				color: #991b1b;
				margin-bottom: 1rem;
			}

			.notice {
				padding: 0.85rem 1rem;
				border-radius: 0.8rem;
				border: 1px solid #bbf7d0;
				background: #ecfdf3;
				color: #166534;
				margin-bottom: 1rem;
			}

			.incident-list {
				display: grid;
				gap: 1rem;
				margin-top: 1.5rem;
			}

			.incident-item {
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				padding: 1rem 1.2rem;
				background: #f8faff;
				display: grid;
				gap: 0.6rem;
			}

			.investigation-list {
				display: grid;
				gap: 1rem;
				margin-top: 1.5rem;
			}

			.investigation-item {
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				padding: 1rem 1.2rem;
				background: #f8faff;
				display: grid;
				gap: 0.6rem;
			}

			.incident-header {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				justify-content: space-between;
				gap: 0.6rem;
			}

			.incident-meta {
				color: var(--muted);
				font-size: 0.9rem;
			}

			.incident-label {
				font-weight: 600;
				color: var(--text);
			}

			.incident-body {
				display: grid;
				grid-template-columns: 1fr;
				gap: 1rem;
				align-items: start;
			}

			.incident-details {
				display: grid;
				gap: 0.5rem;
			}

			.incident-detail {
				display: flex;
				gap: 0.35rem;
				flex-wrap: wrap;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.incident-description {
				border: 1px solid #e2e8f0;
				border-radius: 0.8rem;
				padding: 0.85rem 0.95rem;
				background: #ffffff;
				color: var(--muted);
				line-height: 1.5;
			}

			.badges {
				display: flex;
				gap: 0.5rem;
				flex-wrap: wrap;
			}

			.incident-actions {
				display: flex;
				justify-content: flex-end;
			}

			.assign-button {
				border: none;
				border-radius: 0.75rem;
				padding: 0.5rem 0.9rem;
				font-size: 0.85rem;
				font-weight: 600;
				color: #fff;
				background: var(--accent);
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.assign-form {
				display: flex;
				gap: 0.6rem;
				align-items: center;
				flex-wrap: wrap;
				justify-content: flex-end;
			}

			.assign-select {
				border: 1px solid #d7def0;
				border-radius: 0.7rem;
				padding: 0.45rem 0.75rem;
				background: #f8faff;
				font-family: inherit;
				font-size: 0.9rem;
				color: var(--text);
			}

			.assign-select:focus {
				outline: 2px solid rgba(16, 185, 129, 0.35);
				outline-offset: 2px;
			}

			.assign-button:disabled,
			.assign-select:disabled {
				opacity: 0.6;
				cursor: not-allowed;
			}

			.assign-button:hover {
				transform: translateY(-1px);
				box-shadow: 0 10px 20px rgba(16, 185, 129, 0.25);
			}

			.investigation-button {
				padding: 0.5rem 0.65rem;
			}

			.investigation-form {
				display: grid;
				gap: 0.75rem;
			}

			.investigation-form button {
				justify-self: start;
				width: auto;
			}

			.investigation-form textarea {
				border: 1px solid #d7def0;
				border-radius: 0.8rem;
				padding: 0.65rem 0.75rem;
				font-family: inherit;
				font-size: 0.9rem;
				min-height: 90px;
				resize: vertical;
				background: #ffffff;
			}

			.investigation-form textarea:focus {
				outline: 2px solid rgba(16, 185, 129, 0.35);
				outline-offset: 2px;
			}

			.badge {
				display: inline-flex;
				align-items: center;
				padding: 0.2rem 0.6rem;
				border-radius: 999px;
				background: #e0e7ff;
				color: #3730a3;
				font-size: 0.8rem;
				font-weight: 600;
			}

			.badge.severity-low {
				background: #dcfce7;
				color: #166534;
			}

			.badge.severity-medium {
				background: #fef9c3;
				color: #854d0e;
			}

			.badge.severity-high {
				background: #fee2e2;
				color: #991b1b;
			}

			.badge.severity-critical {
				background: #ffe4e6;
				color: #9f1239;
			}

			.badge.status {
				background: #e2e8f0;
				color: #475569;
			}

			.back-link {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				margin-top: 2rem;
				padding: 0.75rem 1.25rem;
				border-radius: 999px;
				background: var(--accent);
				color: #fff;
				text-decoration: none;
				font-weight: 600;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.back-link:hover {
				transform: translateY(-2px);
				box-shadow: 0 15px 35px rgba(16, 185, 129, 0.35);
			}

			@media (max-width: 640px) {
				.banner {
					flex-direction: column;
					align-items: flex-start;
				}

				.banner-actions {
					width: 100%;
					justify-content: space-between;
					flex-wrap: wrap;
				}

				.search-bar {
					flex: 1;
				}

				.search-bar input {
					min-width: 0;
					width: 100%;
				}

				.incident-body {
					grid-template-columns: 1fr;
				}
			}
		</style>
	</head>
	<body>
		<header>
			<div class="banner">
				<h1>Safety Incident Reports</h1>
				<div class="banner-actions">
					<label class="search-bar" aria-label="Search the platform">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-hidden="true"
						>
							<path
								d="M11 4a7 7 0 1 1 0 14 7 7 0 0 1 0-14zm0-2a9 9 0 1 0 5.9 15.7l4.2 4.2 1.4-1.4-4.2-4.2A9 9 0 0 0 11 2z"
							/>
						</svg>
						<input type="search" placeholder="Search" />
					</label>
					<a class="icon-button" href="manager.php" aria-label="Manager home">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-hidden="true"
						>
							<path d="M12 3 2 11h2v9h6v-6h4v6h6v-9h2L12 3z" />
						</svg>
					</a>
					<button class="icon-button" aria-label="Notifications">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-hidden="true"
						>
							<path
								d="M12 3a6 6 0 0 0-6 6v3.6l-1.6 2.7A1 1 0 0 0 5.3 17H18.7a1 1 0 0 0 .9-1.7L18 12.6V9a6 6 0 0 0-6-6zm0 19a3 3 0 0 0 3-3H9a3 3 0 0 0 3 3z"
							/>
						</svg>
					</button>
					<details class="profile-menu">
						<summary class="icon-button" aria-label="Profile menu" role="button">
							<svg
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
								role="img"
								aria-hidden="true"
							>
								<path
									d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-3.3 0-9 1.7-9 5v2h18v-2c0-3.3-5.7-5-9-5z"
								/>
							</svg>
						</summary>
						<div class="profile-dropdown">
							<p class="profile-name"><?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></p>
							<p class="profile-role"><?php echo htmlspecialchars($roleDisplay, ENT_QUOTES); ?></p>
							<form class="logout-form" method="post" action="logout.php">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutToken, ENT_QUOTES); ?>" />
								<input type="hidden" name="redirect_to" value="login.php" />
								<button type="submit">Log Out</button>
							</form>
						</div>
					</details>
				</div>
			</div>
		</header>
		<main>
			<section class="intro" aria-labelledby="incident-intro-title">
				<h2 id="incident-intro-title">Safety Incidents and Investigations</h2>
				<p>Review reported safety incidents, assign investigations, and document findings and follow-up actions for the AMC team.</p>
			</section>
			<section class="card" aria-labelledby="incident-list-title">
				<h2 id="incident-list-title">All Incident Reports</h2>
				<p>Review the full history of submitted incident reports across the AMC.</p>
				<?php if ($assignmentError !== null): ?>
					<div class="alert" role="alert"><?php echo htmlspecialchars($assignmentError, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if ($assignmentNotice !== null): ?>
					<div class="notice" role="status"><?php echo htmlspecialchars($assignmentNotice, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if ($incidentsError !== null): ?>
					<div class="alert" role="alert"><?php echo htmlspecialchars($incidentsError, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if (empty($incidents) && $incidentsError === null): ?>
					<p class="incident-meta">No incidents have been submitted yet.</p>
				<?php endif; ?>
				<?php if (!empty($incidents)): ?>
					<div class="incident-list scrollable">
						<?php foreach ($incidents as $incident): ?>
							<?php
								$severityKey = (string) ($incident['severity'] ?? 'low');
								$categoryKey = (string) ($incident['category'] ?? 'other');
								$statusKey = (string) ($incident['status'] ?? 'submitted');
								$equipmentName = trim((string) ($incident['equipment_name'] ?? ''));
								$equipmentLocation = trim((string) ($incident['equipment_location'] ?? ''));
								$reporterName = trim((string) ($incident['full_name'] ?? ''));
								$reporterLabel = $reporterName !== '' ? $reporterName : 'Unknown reporter';
								$adminNo = trim((string) ($incident['tp_admin_no'] ?? ''));
								if ($adminNo !== '') {
									$reporterLabel .= ' · ' . $adminNo;
								}
								$reporterRole = trim((string) ($incident['reporter_role'] ?? ''));
								$location = format_incident_location(
									(string) ($incident['location'] ?? ''),
									$equipmentName,
									$equipmentLocation
								);
								$createdAt = trim((string) ($incident['created_at'] ?? ''));
								$updatedAt = trim((string) ($incident['updated_at'] ?? ''));
								if ($reporterRole !== '') {
									$reporterLabel .= ' · ' . $reporterRole;
								}
									$isUnderReview = $statusKey === 'under_review';
							?>
							<div class="incident-item">
								<div class="incident-header">
									<div>
										<strong>Incident #<?php echo htmlspecialchars((string) $incident['incident_id'], ENT_QUOTES); ?></strong>
									</div>
									<div class="badges">
										<span class="badge severity-<?php echo htmlspecialchars($severityKey, ENT_QUOTES); ?>"><?php echo htmlspecialchars($severityLabels[$severityKey] ?? ucfirst($severityKey), ENT_QUOTES); ?></span>
										<span class="badge"><?php echo htmlspecialchars($categoryLabels[$categoryKey] ?? ucfirst($categoryKey), ENT_QUOTES); ?></span>
										<?php if ($isUnderReview): ?>
											<span class="badge status">Under review</span>
										<?php endif; ?>
									</div>
								</div>
								<div class="incident-body">
									<div class="incident-details">
										<div class="incident-detail">
											<span class="incident-label">Reporter:</span>
											<span><?php echo htmlspecialchars($reporterLabel, ENT_QUOTES); ?></span>
										</div>
										<div class="incident-detail">
											<span class="incident-label">Location:</span>
											<span>
												<?php if ($equipmentName !== ''): ?>
													<?php echo htmlspecialchars($equipmentName, ENT_QUOTES); ?><?php echo $equipmentLocation !== '' ? ' · ' . htmlspecialchars($equipmentLocation, ENT_QUOTES) : ''; ?>
											<?php else: ?>
												General area / not applicable
											<?php endif; ?>
											</span>
										</div>
										<?php if ($location !== ''): ?>
											<div class="incident-detail">
												<span class="incident-label">Location:</span>
												<span><?php echo htmlspecialchars($location, ENT_QUOTES); ?></span>
											</div>
										<?php endif; ?>
										<?php if ($updatedAt !== '' && $updatedAt !== $createdAt): ?>
											<div class="incident-detail">
												<span class="incident-label">Updated:</span>
												<span><?php echo htmlspecialchars($updatedAt, ENT_QUOTES); ?></span>
											</div>
										<?php endif; ?>
										<div class="incident-actions">
											<form class="assign-form" method="post" action="">
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($assignToken, ENT_QUOTES); ?>" />
												<input type="hidden" name="incident_id" value="<?php echo htmlspecialchars((string) $incident['incident_id'], ENT_QUOTES); ?>" />
												<select class="assign-select" name="assigned_to" aria-label="Assign staff" <?php echo empty($staffMembers) || $isUnderReview ? 'disabled' : ''; ?>>
													<option value="">Select staff</option>
													<?php foreach ($staffMembers as $staff): ?>
														<option value="<?php echo htmlspecialchars((string) $staff['user_id'], ENT_QUOTES); ?>">
															<?php echo htmlspecialchars((string) ($staff['full_name'] ?? 'Staff'), ENT_QUOTES); ?>
															(Staff)
														</option>
													<?php endforeach; ?>
												</select>
												<button class="assign-button" type="submit" name="assign_incident" <?php echo empty($staffMembers) || $isUnderReview ? 'disabled' : ''; ?>>Assign to Staff</button>
											</form>
										</div>
									</div>
									<div class="incident-description">
										<span class="incident-label">Description:</span>
										<?php echo nl2br(htmlspecialchars((string) ($incident['description'] ?? ''), ENT_QUOTES)); ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<section class="card" aria-labelledby="investigation-list-title">
				<h2 id="investigation-list-title">Incident Investigations</h2>
				<p>Track assigned investigations, findings, and follow-up actions.</p>
				<?php if ($investigationUpdateError !== null): ?>
					<div class="alert" role="alert"><?php echo htmlspecialchars($investigationUpdateError, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if ($investigationUpdateNotice !== null): ?>
					<div class="notice" role="status"><?php echo htmlspecialchars($investigationUpdateNotice, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if ($investigationsError !== null): ?>
					<div class="alert" role="alert"><?php echo htmlspecialchars($investigationsError, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<?php if (empty($investigations) && $investigationsError === null): ?>
					<p class="incident-meta">No investigations have been created yet.</p>
				<?php endif; ?>
				<?php if (!empty($investigations)): ?>
					<div class="investigation-list scrollable">
						<?php foreach ($investigations as $investigation): ?>
							<?php
								$invSeverity = (string) ($investigation['severity'] ?? 'low');
								$invCategory = (string) ($investigation['category'] ?? 'other');
								$invStatus = (string) ($investigation['status'] ?? 'submitted');
								$assignedName = trim((string) ($investigation['assigned_name'] ?? ''));
								$assignedRole = trim((string) ($investigation['assigned_role'] ?? ''));
								$assignedLabel = $assignedName !== '' ? $assignedName : 'Unassigned';
								if ($assignedRole !== '') {
									$assignedLabel .= ' · ' . $assignedRole;
								}
								$invCreated = trim((string) ($investigation['created_at'] ?? ''));
								$invUpdated = trim((string) ($investigation['updated_at'] ?? ''));
								$invDescription = trim((string) ($investigation['incident_description'] ?? ''));
								$invFindings = trim((string) ($investigation['findings'] ?? ''));
								$invActions = trim((string) ($investigation['actions_taken'] ?? ''));
								$invEquipmentName = trim((string) ($investigation['equipment_name'] ?? ''));
								$invEquipmentLocation = trim((string) ($investigation['equipment_location'] ?? ''));
								$invLocation = format_incident_location(
									(string) ($investigation['location'] ?? ''),
									$invEquipmentName,
									$invEquipmentLocation
								);
							?>
							<div class="investigation-item">
								<div class="incident-header">
									<div>
										<strong>Investigation #<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?></strong>
										<div class="incident-meta"><span class="incident-label">Incident:</span> #<?php echo htmlspecialchars((string) $investigation['incident_id'], ENT_QUOTES); ?></div>
									</div>
									<div class="badges">
										<span class="badge severity-<?php echo htmlspecialchars($invSeverity, ENT_QUOTES); ?>"><?php echo htmlspecialchars($severityLabels[$invSeverity] ?? ucfirst($invSeverity), ENT_QUOTES); ?></span>
										<span class="badge"><?php echo htmlspecialchars($categoryLabels[$invCategory] ?? ucfirst($invCategory), ENT_QUOTES); ?></span>
										<?php if ($invStatus === 'under_review'): ?>
											<span class="badge status">Under review</span>
										<?php elseif ($invStatus !== ''): ?>
											<span class="badge status"><?php echo htmlspecialchars($statusLabels[$invStatus] ?? ucfirst($invStatus), ENT_QUOTES); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<div class="incident-details">
									<div class="incident-detail">
										<span class="incident-label">Assigned to:</span>
										<span><?php echo htmlspecialchars($assignedLabel, ENT_QUOTES); ?></span>
									</div>
									<div class="incident-detail">
										<span class="incident-label">Created:</span>
										<span><?php echo htmlspecialchars($invCreated !== '' ? $invCreated : 'N/A', ENT_QUOTES); ?></span>
									</div>
									<?php if ($invLocation !== ''): ?>
										<div class="incident-detail">
											<span class="incident-label">Location:</span>
											<span><?php echo htmlspecialchars($invLocation, ENT_QUOTES); ?></span>
										</div>
									<?php endif; ?>
									<?php if ($invUpdated !== '' && $invUpdated !== $invCreated): ?>
										<div class="incident-detail">
											<span class="incident-label">Updated:</span>
											<span><?php echo htmlspecialchars($invUpdated, ENT_QUOTES); ?></span>
										</div>
									<?php endif; ?>
								</div>
								<?php if ($invDescription !== ''): ?>
									<div class="incident-description">
										<div><span class="incident-label">Description:</span> <?php echo nl2br(htmlspecialchars($invDescription, ENT_QUOTES)); ?></div>
									</div>
								<?php endif; ?>
								<form class="investigation-form" method="post" action="">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($investigationToken, ENT_QUOTES); ?>" />
									<input type="hidden" name="investigation_id" value="<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?>" />
									<label class="incident-label" for="findings-<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?>">Findings (by assigned staff)</label>
									<textarea id="findings-<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?>" name="findings" placeholder="Enter findings..." required><?php echo htmlspecialchars($invFindings, ENT_QUOTES); ?></textarea>
									<label class="incident-label" for="actions-<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?>">Actions taken</label>
									<textarea id="actions-<?php echo htmlspecialchars((string) $investigation['investigation_id'], ENT_QUOTES); ?>" name="actions_taken" placeholder="Enter actions taken..." required><?php echo htmlspecialchars($invActions, ENT_QUOTES); ?></textarea>
									<button class="assign-button investigation-button" type="submit" name="update_investigation">Close Investigation</button>
								</form>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</main>
	</body>
</html>
