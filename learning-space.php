<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = require_login();
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Student';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'User'));
$logoutToken = generate_csrf_token('logout_form');

$certActionMessage = null;
$certActionError = null;
$currentUserId = (int) ($currentUser['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cert_action'])) {
	$certAction = trim((string) ($_POST['cert_action'] ?? ''));
	$certId = (int) ($_POST['cert_id'] ?? 0);
	$formKey = 'cert_action_' . $certId;
	$csrfToken = (string) ($_POST['csrf_token'] ?? '');

	if ($certId <= 0 || $certAction !== 'request') {
		$certActionError = 'Invalid certification request.';
	} elseif (!validate_csrf_token($formKey, $csrfToken)) {
		$certActionError = 'This action could not be verified. Please try again.';
	} elseif ($currentUserId <= 0) {
		$certActionError = 'Unable to identify your account.';
	} else {
		$certStmt = mysqli_prepare(
			$conn,
			'SELECT cert_id, name, valid_days FROM certifications WHERE cert_id = ?'
		);
		if ($certStmt === false) {
			$certActionError = 'Unable to load certification details.';
		} else {
			mysqli_stmt_bind_param($certStmt, 'i', $certId);
			mysqli_stmt_execute($certStmt);
			$certResult = mysqli_stmt_get_result($certStmt);
			$certRow = $certResult ? mysqli_fetch_assoc($certResult) : null;
			if ($certResult) {
				mysqli_free_result($certResult);
			}
			mysqli_stmt_close($certStmt);
			if (!$certRow) {
				$certActionError = 'Certification not found.';
			} else {
				$validDays = isset($certRow['valid_days']) ? (int) $certRow['valid_days'] : 0;
				$now = date('Y-m-d H:i:s');
				$expiresAt = '';
				if ($validDays > 0) {
					$expiresAt = (new DateTimeImmutable('now'))
						->modify('+' . $validDays . ' days')
						->format('Y-m-d H:i:s');
				}

				$existingStatus = null;
				$existingStmt = mysqli_prepare(
					$conn,
					'SELECT status FROM user_certifications WHERE user_id = ? AND cert_id = ?'
				);
				if ($existingStmt !== false) {
					mysqli_stmt_bind_param($existingStmt, 'ii', $currentUserId, $certId);
					mysqli_stmt_execute($existingStmt);
					$existingResult = mysqli_stmt_get_result($existingStmt);
					$existingRow = $existingResult ? mysqli_fetch_assoc($existingResult) : null;
					if ($existingResult) {
						mysqli_free_result($existingResult);
					}
					mysqli_stmt_close($existingStmt);
					if ($existingRow) {
						$existingStatus = (string) ($existingRow['status'] ?? '');
					}
				}

				if ($existingStatus === null) {
					$insertStmt = mysqli_prepare(
						$conn,
						"INSERT INTO user_certifications (user_id, cert_id, status) VALUES (?, ?, 'in_progress')"
					);
					if ($insertStmt) {
						mysqli_stmt_bind_param($insertStmt, 'ii', $currentUserId, $certId);
						mysqli_stmt_execute($insertStmt);
						mysqli_stmt_close($insertStmt);
						$certActionMessage = 'Certification request submitted.';
					} else {
						$certActionError = 'Unable to request certification.';
					}
				} elseif ($existingStatus !== 'in_progress') {
					$updateStmt = mysqli_prepare(
						$conn,
						"UPDATE user_certifications
						 SET status = 'in_progress', completed_at = NULL, expires_at = NULL, verified_by = NULL, verified_at = NULL
						 WHERE user_id = ? AND cert_id = ?"
					);
					if ($updateStmt) {
						mysqli_stmt_bind_param($updateStmt, 'ii', $currentUserId, $certId);
						mysqli_stmt_execute($updateStmt);
						mysqli_stmt_close($updateStmt);
						$certActionMessage = 'Certification request submitted.';
					} else {
						$certActionError = 'Unable to request certification.';
					}
				} else {
					$certActionMessage = 'Certification request is already in progress.';
				}

				if ($certActionMessage !== null) {
					log_audit_event(
						$conn,
						$currentUserId,
						'user_cert_action',
						'certification',
						$certId,
						['action' => 'request']
					);
				}
			}
		}
	}
}

$equipment = [];
$equipmentError = null;

$equipmentResult = mysqli_query(
	$conn,
	'SELECT equipment_id, name, category, location FROM equipment ORDER BY name ASC'
);
if ($equipmentResult === false) {
	$equipmentError = 'Unable to load equipment right now.';
} else {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$equipment[] = [
			'equipment_id' => $equipmentId,
			'name' => trim((string) ($row['name'] ?? 'Unnamed equipment')),
			'category' => trim((string) ($row['category'] ?? '')),
			'location' => trim((string) ($row['location'] ?? '')),
		];
	}
	mysqli_free_result($equipmentResult);
}

$materialsByEquipment = [];
$materialsError = null;

$materialsSql = "SELECT
		etm.equipment_id,
		tm.material_id,
		tm.title,
		tm.material_type,
		tm.file_url,
		tm.file_path
	FROM equipment_training_materials etm
	INNER JOIN training_materials tm ON tm.material_id = etm.material_id
	ORDER BY tm.title ASC";
$materialsResult = mysqli_query($conn, $materialsSql);
if ($materialsResult === false) {
	$materialsError = 'Unable to load learning materials right now.';
} else {
	while ($row = mysqli_fetch_assoc($materialsResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$title = trim((string) ($row['title'] ?? 'Untitled material'));
		$materialsByEquipment[$equipmentId][] = [
			'material_id' => (int) ($row['material_id'] ?? 0),
			'title' => $title === '' ? 'Untitled material' : $title,
			'material_type' => trim((string) ($row['material_type'] ?? 'other')),
			'file_url' => trim((string) ($row['file_url'] ?? '')),
			'file_path' => trim((string) ($row['file_path'] ?? '')),
		];
	}
	mysqli_free_result($materialsResult);
}

$allCerts = [];
$allCertsError = null;

$allCertsResult = mysqli_query(
	$conn,
	'SELECT cert_id, name, description, valid_days FROM certifications ORDER BY name ASC'
);
if ($allCertsResult === false) {
	$allCertsError = 'Unable to load certifications right now.';
} else {
	while ($row = mysqli_fetch_assoc($allCertsResult)) {
		$certId = (int) ($row['cert_id'] ?? 0);
		if ($certId <= 0) {
			continue;
		}
		$certName = trim((string) ($row['name'] ?? ''));
		$allCerts[] = [
			'cert_id' => $certId,
			'name' => $certName === '' ? 'Unnamed certification' : $certName,
			'description' => trim((string) ($row['description'] ?? '')),
			'valid_days' => isset($row['valid_days']) ? (int) $row['valid_days'] : null,
		];
	}
	mysqli_free_result($allCertsResult);
}

$userCertsById = [];
$userCertsError = null;

$userCertStmt = mysqli_prepare(
	$conn,
	'SELECT cert_id, status, completed_at, expires_at FROM user_certifications WHERE user_id = ?'
);
if ($userCertStmt === false) {
	$userCertsError = 'Unable to load your certification progress.';
} else {
	mysqli_stmt_bind_param($userCertStmt, 'i', $currentUserId);
	mysqli_stmt_execute($userCertStmt);
	$userCertResult = mysqli_stmt_get_result($userCertStmt);
	if ($userCertResult) {
		while ($row = mysqli_fetch_assoc($userCertResult)) {
			$certId = (int) ($row['cert_id'] ?? 0);
			if ($certId <= 0) {
				continue;
			}
			$userCertsById[$certId] = [
				'status' => (string) ($row['status'] ?? 'in_progress'),
				'completed_at' => $row['completed_at'] ?? null,
				'expires_at' => $row['expires_at'] ?? null,
			];
		}
		mysqli_free_result($userCertResult);
	}
	mysqli_stmt_close($userCertStmt);
}

$userCertDetails = [];
$userCertDetailsError = null;

$userCertDetailsStmt = mysqli_prepare(
	$conn,
	'SELECT uc.status, uc.completed_at, uc.expires_at, c.name, c.valid_days
	 FROM user_certifications uc
	 INNER JOIN certifications c ON c.cert_id = uc.cert_id
	 WHERE uc.user_id = ?
	 ORDER BY c.name ASC'
);
if ($userCertDetailsStmt === false) {
	$userCertDetailsError = 'Unable to load your certification status.';
} else {
	mysqli_stmt_bind_param($userCertDetailsStmt, 'i', $currentUserId);
	mysqli_stmt_execute($userCertDetailsStmt);
	$userCertDetailsResult = mysqli_stmt_get_result($userCertDetailsStmt);
	if ($userCertDetailsResult) {
		while ($row = mysqli_fetch_assoc($userCertDetailsResult)) {
			$userCertDetails[] = [
				'name' => trim((string) ($row['name'] ?? '')),
				'status' => (string) ($row['status'] ?? 'in_progress'),
				'completed_at' => $row['completed_at'] ?? null,
				'expires_at' => $row['expires_at'] ?? null,
				'valid_days' => isset($row['valid_days']) ? (int) $row['valid_days'] : null,
			];
		}
		mysqli_free_result($userCertDetailsResult);
	}
	mysqli_stmt_close($userCertDetailsStmt);
}

$certsByEquipment = [];
$certsError = null;

$certsSql = "SELECT
			erc.equipment_id,
			c.cert_id,
			c.name,
			c.valid_days
		FROM equipment_required_certs erc
		INNER JOIN certifications c ON c.cert_id = erc.cert_id
		ORDER BY c.name ASC";
$certsResult = mysqli_query($conn, $certsSql);
if ($certsResult === false) {
	$certsError = 'Unable to load required certifications right now.';
} else {
	while ($row = mysqli_fetch_assoc($certsResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$certName = trim((string) ($row['name'] ?? ''));
		if ($certName === '') {
			$certName = 'Unnamed certification';
		}
		$certsByEquipment[$equipmentId][] = [
			'cert_id' => (int) ($row['cert_id'] ?? 0),
			'name' => $certName,
			'valid_days' => isset($row['valid_days']) ? (int) $row['valid_days'] : null,
		];
	}
	mysqli_free_result($certsResult);
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Learning Space</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<style>
			:root {
				--bg: #f8fbff;
				--accent: #4361ee;
				--accent-soft: #edf2ff;
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
				background: radial-gradient(circle at top, #eef2ff, var(--bg));
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

			header h1 {
				margin: 0;
				font-size: clamp(1.6rem, 3vw, 2.4rem);
				font-weight: 600;
			}

			.banner {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 2rem;
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
				background: #dfe7ff;
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
				box-shadow: 0 20px 45px rgba(67, 97, 238, 0.2);
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
				box-shadow: 0 10px 20px rgba(67, 97, 238, 0.25);
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.intro {
				max-width: 680px;
				margin-bottom: 2rem;
			}

			.intro p {
				color: var(--muted);
				line-height: 1.6;
			}

			.grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 1.5rem;
			}

			.card {
				background: var(--card);
				padding: 1.25rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(67, 97, 238, 0.12);
				display: flex;
				flex-direction: column;
			}

			.card details {
				margin: 0;
				flex: 1;
				display: flex;
				flex-direction: column;
			}

			.card summary {
				list-style: none;
				cursor: pointer;
				flex: 1;
				display: flex;
				flex-direction: column;
			}

			.card summary::-webkit-details-marker {
				display: none;
			}

			.card summary h2 {
				margin: 0 0 0.35rem;
				font-size: 1.1rem;
			}

			.card summary p {
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.material-count {
				display: inline-flex;
				align-self: flex-start;
				align-items: center;
				gap: 0.35rem;
				padding: 0.3rem 0.75rem;
				border-radius: 999px;
				background: var(--accent-soft);
				color: var(--accent);
				font-size: 0.85rem;
				font-weight: 600;
			}

			.summary-meta {
				margin-top: auto;
				display: flex;
				flex-wrap: wrap;
				gap: 0.5rem;
				align-items: center;
			}

			.machine-cert-status {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 0.3rem 0.75rem;
				border-radius: 999px;
				font-size: 0.8rem;
				font-weight: 600;
			}

			.machine-cert-status.status-certified {
				background: #dcfce7;
				color: #166534;
			}

			.machine-cert-status.status-not-certified {
				background: #fee2e2;
				color: #991b1b;
			}

			.certifications {
				margin-top: 1rem;
				padding-top: 1rem;
				border-top: 1px dashed rgba(67, 97, 238, 0.2);
			}

			.certifications h3 {
				margin: 0 0 0.75rem;
				font-size: 0.95rem;
				color: var(--muted);
				text-transform: uppercase;
				letter-spacing: 0.08em;
			}

			.cert-list {
				list-style: none;
				margin: 0;
				padding: 0;
				display: flex;
				flex-wrap: wrap;
				gap: 0.5rem;
			}

			.cert-pill {
				display: inline-flex;
				flex-direction: column;
				gap: 0.2rem;
				padding: 0.45rem 0.7rem;
				border-radius: 0.85rem;
				background: #f1f5ff;
				border: 1px solid rgba(67, 97, 238, 0.2);
				font-size: 0.85rem;
				font-weight: 600;
				color: #1f2a44;
				min-height: 3.4rem;
				width: 100%;
			}

			.cert-pill span:first-child {
				display: -webkit-box;
				-webkit-line-clamp: 2;
				-webkit-box-orient: vertical;
				overflow: hidden;
			}

			.cert-meta {
				font-size: 0.75rem;
				font-weight: 500;
				color: var(--muted);
			}

			.materials {
				margin-top: 1rem;
				padding-top: 1rem;
				border-top: 1px solid rgba(67, 97, 238, 0.18);
			}

			.materials ul {
				list-style: none;
				padding: 0;
				margin: 0;
				display: flex;
				flex-direction: column;
				gap: 0.65rem;
			}

			.material-item {
				display: flex;
				flex-direction: column;
				gap: 0.2rem;
			}

			.material-item a {
				color: var(--text);
				text-decoration: none;
				font-weight: 600;
			}

			.material-item a:hover {
				color: var(--accent);
			}

			.material-type {
				font-size: 0.8rem;
				color: var(--muted);
			}

			.cert-hub {
				margin: 2.5rem 0 3rem;
				padding: 1.5rem;
				background: #ffffff;
				border-radius: 1.2rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 18px 35px rgba(15, 23, 42, 0.08);
			}

			.cert-hub-header {
				margin-bottom: 1.2rem;
			}

			.cert-hub-header h2 {
				margin: 0 0 0.5rem;
				font-size: 1.3rem;
			}

			.cert-hub-header p {
				margin: 0;
				color: var(--muted);
			}

			.cert-hub-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
				gap: 1.25rem;
			}

			.cert-card {
				border: 1px solid #e2e8f0;
				border-radius: 1rem;
				padding: 1rem;
				display: flex;
				flex-direction: column;
				gap: 0.75rem;
				min-height: 220px;
				background: #f8faff;
			}

			.cert-card h3 {
				margin: 0;
				font-size: 1.05rem;
			}

			.cert-card p {
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
				line-height: 1.5;
			}

			.cert-card-body {
				flex: 1;
				display: flex;
				flex-direction: column;
				gap: 0.4rem;
			}

			.cert-card-footer {
				display: flex;
				flex-direction: column;
				gap: 0.6rem;
			}

			.cert-status {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				padding: 0.25rem 0.65rem;
				border-radius: 999px;
				font-size: 0.75rem;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.06em;
			}

			.status-not-started {
				background: #e2e8f0;
				color: #1f2937;
			}

			.status-in-progress {
				background: #fff7ed;
				color: #c2410c;
			}

			.status-completed {
				background: #ecfdf3;
				color: #15803d;
			}

			.status-expired,
			.status-revoked {
				background: #fee2e2;
				color: #b91c1c;
			}

			.cert-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 0.5rem;
			}

			.cert-actions form {
				margin: 0;
			}

			.cert-actions button {
				border: none;
				border-radius: 999px;
				padding: 0.45rem 0.9rem;
				font-size: 0.8rem;
				font-weight: 600;
				cursor: pointer;
				background: var(--accent);
				color: #ffffff;
				transition: transform 0.15s ease, box-shadow 0.15s ease;
			}

			.cert-actions button.secondary {
				background: #e2e8f0;
				color: #1f2937;
			}

			.cert-actions button:disabled {
				cursor: not-allowed;
				opacity: 0.6;
			}

			.notice {
				margin: 1rem 0;
				padding: 0.75rem 1rem;
				border-radius: 0.8rem;
				font-size: 0.9rem;
				font-weight: 500;
			}

			.notice-success {
				background: #ecfdf3;
				color: #166534;
				border: 1px solid #bbf7d0;
			}

			.notice-error {
				background: #fee2e2;
				color: #b91c1c;
				border: 1px solid #fecaca;
			}

			.modal-toggle {
				position: absolute;
				opacity: 0;
				pointer-events: none;
			}

			.modal-trigger {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				border: none;
				border-radius: 999px;
				padding: 0.5rem 1rem;
				font-size: 0.85rem;
				font-weight: 600;
				background: var(--accent);
				color: #fff;
				cursor: pointer;
				box-shadow: 0 10px 20px rgba(67, 97, 238, 0.2);
				margin-bottom: 1rem;
			}

			.modal-shell {
				position: fixed;
				inset: 0;
				display: none;
				align-items: center;
				justify-content: center;
				padding: 1.5rem;
				z-index: 50;
			}

			.modal-toggle:checked ~ .modal-shell {
				display: flex;
			}

			.modal-overlay {
				position: absolute;
				inset: 0;
				background: rgba(15, 23, 42, 0.45);
			}

			.modal-content {
				position: relative;
				max-width: 760px;
				width: min(90vw, 760px);
				background: #ffffff;
				border-radius: 1.2rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 30px 60px rgba(15, 23, 42, 0.2);
				padding: 1.5rem;
				z-index: 1;
				max-height: 80vh;
				overflow: hidden;
			}

			.modal-body {
				max-height: calc(80vh - 4.5rem);
				overflow: auto;
				padding-right: 0.5rem;
				padding-bottom: 0.75rem;
				scrollbar-gutter: stable;
			}

			.modal-body::-webkit-scrollbar {
				width: 10px;
			}

			.modal-body::-webkit-scrollbar-track {
				background: transparent;
			}

			.modal-body::-webkit-scrollbar-thumb {
				background: #c7d2fe;
				border-radius: 999px;
				border: 3px solid #ffffff;
			}

			.modal-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 1rem;
				margin-bottom: 1rem;
			}

			.modal-header h3 {
				margin: 0;
				font-size: 1.2rem;
			}

			.modal-close {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 36px;
				height: 36px;
				border-radius: 50%;
				border: 1px solid #e2e8f0;
				background: #f8fafc;
				cursor: pointer;
			}

			.cert-list-modal {
				list-style: none;
				padding: 0;
				margin: 0;
				display: flex;
				flex-direction: column;
				gap: 0.75rem;
			}

			.cert-row {
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				padding: 0.9rem 1rem;
				background: #f8faff;
				display: flex;
				flex-direction: column;
				gap: 0.4rem;
			}

			.empty-state {
				color: var(--muted);
				font-style: italic;
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
			}
		</style>
	</head>
	<body>
		<header>
			<div class="banner">
				<h1>Learning Space</h1>
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
					<a class="icon-button" href="index.php" aria-label="Home">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-hidden="true"
						>
							<path
								d="M12 3 2 11h2v9h6v-6h4v6h6v-9h2L12 3z"
							/>
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
			<section class="intro">
				<h2>Welcome, <?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></h2>
				<p>
					Explore equipment-specific learning materials and certification resources. Select a
					machine to view guides, SOPs, and videos aligned with your training path.
				</p>
			</section>
			<section class="cert-hub" aria-labelledby="cert-hub-title">
				<div class="cert-hub-header">
					<h2 id="cert-hub-title">Certification Hub</h2>
					<p>Request certifications to unlock equipment access and stay compliant.</p>
				</div>
				<input type="checkbox" id="cert-modal-toggle" class="modal-toggle" />
				<label class="modal-trigger" for="cert-modal-toggle">View current certificates</label>
				<div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="cert-modal-title">
					<label class="modal-overlay" for="cert-modal-toggle" aria-hidden="true"></label>
					<div class="modal-content">
						<div class="modal-header">
							<h3 id="cert-modal-title">My Certificates</h3>
							<label class="modal-close" for="cert-modal-toggle" aria-label="Close certificates popup">✕</label>
						</div>
						<div class="modal-body">
							<?php if ($userCertDetailsError !== null): ?>
								<p class="empty-state" role="alert"><?php echo htmlspecialchars($userCertDetailsError, ENT_QUOTES); ?></p>
							<?php elseif (empty($userCertDetails)): ?>
								<p class="empty-state">You do not have any certificates yet.</p>
							<?php else: ?>
								<ul class="cert-list-modal">
									<?php foreach ($userCertDetails as $certRow): ?>
										<?php
											$certName = $certRow['name'] !== '' ? $certRow['name'] : 'Unnamed certification';
											$status = $certRow['status'] !== '' ? $certRow['status'] : 'in_progress';
											$expiresAt = $certRow['expires_at'] ?? null;
											if ($expiresAt !== null && $expiresAt !== '' && strtotime($expiresAt) < time()) {
												$status = 'expired';
											}
											$statusLabelMap = [
												'in_progress' => 'In progress',
												'completed' => 'Completed',
												'expired' => 'Expired',
												'revoked' => 'Revoked',
											];
											$statusLabel = $statusLabelMap[$status] ?? 'In progress';
											$validDays = $certRow['valid_days'];
											$validityText = null;
											if (is_int($validDays) && $validDays > 0) {
												$validityText = 'Validity: ' . $validDays . ' days';
											}
										?>
									<li class="cert-row">
										<strong><?php echo htmlspecialchars($certName, ENT_QUOTES); ?></strong>
										<div>
											<span class="cert-status status-<?php echo htmlspecialchars(str_replace('_', '-', $status), ENT_QUOTES); ?>">
												<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>
											</span>
											<?php if ($validityText !== null): ?>
												<span class="cert-meta"><?php echo htmlspecialchars($validityText, ENT_QUOTES); ?></span>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php if ($certActionMessage !== null): ?>
					<p class="notice notice-success" role="status"><?php echo htmlspecialchars($certActionMessage, ENT_QUOTES); ?></p>
				<?php elseif ($certActionError !== null): ?>
					<p class="notice notice-error" role="alert"><?php echo htmlspecialchars($certActionError, ENT_QUOTES); ?></p>
				<?php endif; ?>
				<?php if ($allCertsError !== null): ?>
					<p class="empty-state" role="alert"><?php echo htmlspecialchars($allCertsError, ENT_QUOTES); ?></p>
				<?php elseif ($userCertsError !== null): ?>
					<p class="empty-state" role="alert"><?php echo htmlspecialchars($userCertsError, ENT_QUOTES); ?></p>
				<?php elseif (empty($allCerts)): ?>
					<p class="empty-state">No certifications have been added yet.</p>
				<?php else: ?>
					<div class="cert-hub-grid">
						<?php foreach ($allCerts as $cert): ?>
							<?php
								$certId = (int) $cert['cert_id'];
								$userCert = $userCertsById[$certId] ?? null;
								$status = $userCert['status'] ?? 'not_started';
								$expiresAt = $userCert['expires_at'] ?? null;
								if ($expiresAt !== null && $expiresAt !== '' && strtotime($expiresAt) < time()) {
									$status = 'expired';
								}
								$statusLabelMap = [
									'not_started' => 'Not started',
									'in_progress' => 'In progress',
									'completed' => 'Completed',
									'expired' => 'Expired',
									'revoked' => 'Revoked',
								];
								$statusLabel = $statusLabelMap[$status] ?? 'In progress';
								$validDays = $cert['valid_days'];
								$validityText = null;
								if (is_int($validDays) && $validDays > 0) {
									$validityText = 'Validity: ' . $validDays . ' days';
								}
							?>
							<article class="cert-card">
								<div class="cert-card-body">
									<h3><?php echo htmlspecialchars($cert['name'], ENT_QUOTES); ?></h3>
									<?php if ($cert['description'] !== ''): ?>
										<p><?php echo htmlspecialchars($cert['description'], ENT_QUOTES); ?></p>
									<?php endif; ?>
								</div>
								<div class="cert-card-footer">
									<div>
										<span class="cert-status status-<?php echo htmlspecialchars(str_replace('_', '-', $status), ENT_QUOTES); ?>">
											<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>
										</span>
										<?php if ($validityText !== null): ?>
											<span class="cert-meta"><?php echo htmlspecialchars($validityText, ENT_QUOTES); ?></span>
										<?php endif; ?>
									</div>
									<div class="cert-actions">
									<?php if ($status === 'completed'): ?>
										<button type="button" disabled>Completed</button>
									<?php elseif ($status === 'in_progress'): ?>
										<button type="button" disabled>Request in progress</button>
									<?php else: ?>
										<form method="post" action="">
											<input type="hidden" name="cert_id" value="<?php echo $certId; ?>" />
											<input type="hidden" name="cert_action" value="request" />
											<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token('cert_action_' . $certId), ENT_QUOTES); ?>" />
											<button type="submit">Request certificate</button>
										</form>
									<?php endif; ?>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<section class="cert-hub" aria-labelledby="learning-materials-title">
				<div class="cert-hub-header">
					<h2 id="learning-materials-title">Machine Learning Materials</h2>
					<p>Browse machines to view required certifications and related training materials.</p>
				</div>
			<?php if ($equipmentError !== null): ?>
				<p class="empty-state" role="alert"><?php echo htmlspecialchars($equipmentError, ENT_QUOTES); ?></p>
			<?php elseif (empty($equipment)): ?>
				<p class="empty-state">No equipment has been added yet.</p>
			<?php elseif ($materialsError !== null): ?>
				<p class="empty-state" role="alert"><?php echo htmlspecialchars($materialsError, ENT_QUOTES); ?></p>
			<?php elseif ($certsError !== null): ?>
				<p class="empty-state" role="alert"><?php echo htmlspecialchars($certsError, ENT_QUOTES); ?></p>
			<?php endif; ?>
			<section class="grid">
				<?php foreach ($equipment as $machine): ?>
					<?php
						$machineId = (int) $machine['equipment_id'];
						$materials = $materialsByEquipment[$machineId] ?? [];
						$requiredCerts = $certsByEquipment[$machineId] ?? [];
						$materialCount = count($materials);
						$certCount = count($requiredCerts);
						$isCertified = true;
						if ($certCount > 0) {
							foreach ($requiredCerts as $cert) {
								$certId = (int) ($cert['cert_id'] ?? 0);
								if ($certId <= 0) {
									$isCertified = false;
									break;
								}
								$userCert = $userCertsById[$certId] ?? null;
								if (!$userCert || ($userCert['status'] ?? '') !== 'completed') {
									$isCertified = false;
									break;
								}
								$expiresAt = $userCert['expires_at'] ?? null;
								if ($expiresAt !== null && $expiresAt !== '' && strtotime((string) $expiresAt) < time()) {
									$isCertified = false;
									break;
								}
							}
						}
						$certStatusLabel = $isCertified ? 'Certified to use' : 'Not certified';
						$certStatusClass = $isCertified ? 'status-certified' : 'status-not-certified';
						$locationParts = [];
						if ($machine['category'] !== '') {
							$locationParts[] = $machine['category'];
						}
						if ($machine['location'] !== '') {
							$locationParts[] = $machine['location'];
						}
						$subtitle = $locationParts !== [] ? implode(' • ', $locationParts) : 'Certification resources';
					?>
					<article class="card">
						<details>
							<summary>
								<h2><?php echo htmlspecialchars($machine['name'], ENT_QUOTES); ?></h2>
								<p><?php echo htmlspecialchars($subtitle, ENT_QUOTES); ?></p>
								<div class="summary-meta">
									<span class="material-count">
										<?php echo $materialCount; ?> material<?php echo $materialCount === 1 ? '' : 's'; ?>
									</span>
									<span class="machine-cert-status <?php echo htmlspecialchars($certStatusClass, ENT_QUOTES); ?>">
										<?php echo htmlspecialchars($certStatusLabel, ENT_QUOTES); ?>
									</span>
								</div>
							</summary>
							<div class="certifications">
								<h3>Required certifications (<?php echo $certCount; ?>)</h3>
								<?php if ($certCount === 0): ?>
									<p class="empty-state">No certifications listed for this machine yet.</p>
								<?php else: ?>
									<ul class="cert-list">
										<?php foreach ($requiredCerts as $cert): ?>
											<?php
												$validDays = $cert['valid_days'];
												$validLabel = null;
												if (is_int($validDays) && $validDays > 0) {
													$validLabel = $validDays . ' days validity';
												}
											?>
											<li class="cert-pill">
												<span><?php echo htmlspecialchars($cert['name'], ENT_QUOTES); ?></span>
												<?php if ($validLabel !== null): ?>
													<span class="cert-meta"><?php echo htmlspecialchars($validLabel, ENT_QUOTES); ?></span>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
							<div class="materials">
								<?php if ($materialCount === 0): ?>
									<p class="empty-state">No learning materials available yet.</p>
								<?php else: ?>
									<ul>
										<?php foreach ($materials as $material): ?>
											<?php
												$materialId = (int) ($material['material_id'] ?? 0);
												$label = $material['title'];
												$materialType = strtolower($material['material_type']);
												$typeLabel = strtoupper($materialType);
												$link = $material['file_url'] !== '' ? $material['file_url'] : $material['file_path'];
												$useDownloadHandler = $materialId > 0 && $link !== '';
												if ($useDownloadHandler) {
													$link = 'download-material.php?id=' . $materialId;
												}
											?>
											<li class="material-item">
												<?php if ($link !== ''): ?>
													<a href="<?php echo htmlspecialchars($link, ENT_QUOTES); ?>">
														<?php echo htmlspecialchars($label, ENT_QUOTES); ?>
													</a>
												<?php else: ?>
													<span><?php echo htmlspecialchars($label, ENT_QUOTES); ?></span>
												<?php endif; ?>
												<span class="material-type"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						</details>
					</article>
				<?php endforeach; ?>
			</section>
			</section>
		</main>
	</body>
</html>
