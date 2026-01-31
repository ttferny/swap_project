<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current user and enforce incident reporting access.
$currentUser = enforce_capability($conn, 'portal.report_fault');
$dashboardHref = dashboard_home_path($currentUser);
$historyFallback = $dashboardHref;
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : 0;
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Student';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'User'));
// CSRF token for logout action.
$logoutToken = generate_csrf_token('logout_form');
// Flash message buckets for report submissions.
$reportMessages = [
	'success' => [],
	'error' => [],
];
// Access scope to restrict equipment list.
$userEquipmentAccessMap = [];
$limitEquipmentScope = false;
if ($currentUserId > 0) {
	$limitEquipmentScope = should_limit_equipment_scope($conn, $currentUserId);
	if ($limitEquipmentScope) {
		$userEquipmentAccessMap = get_user_equipment_access_map($conn, $currentUserId);
	}
}
// Default form values for the incident report.
$formValues = [
	'equipment_id' => '',
	'severity' => 'low',
	'category' => 'other',
	'location' => '',
	'description' => '',
];

$savedFormState = flash_retrieve('report_fault.form');
if (is_array($savedFormState)) {
	$formValues = array_merge($formValues, array_intersect_key($savedFormState, $formValues));
}
$savedMessages = flash_retrieve('report_fault.messages');
if (is_array($savedMessages)) {
	foreach (['success', 'error'] as $type) {
		if (isset($savedMessages[$type]) && is_array($savedMessages[$type])) {
			$reportMessages[$type] = $savedMessages[$type];
		}
	}
}

// Load equipment list for the select control.
$equipment = [];
$equipmentById = [];
$equipmentError = null;
$cachedEquipment = static_cache_remember('equipment.list.v1', 300, function () use ($conn) {
	$data = [];
	$result = mysqli_query($conn, 'SELECT equipment_id, name, location FROM equipment ORDER BY name ASC');
	if ($result === false) {
		return ['error' => 'Unable to load equipment right now.', 'rows' => []];
	}
	while ($row = mysqli_fetch_assoc($result)) {
		$data[] = $row;
	}
	mysqli_free_result($result);
	return ['error' => null, 'rows' => $data];
});

if (isset($cachedEquipment['error']) && $cachedEquipment['error'] !== null) {
	$equipmentError = $cachedEquipment['error'];
} else {
	foreach ($cachedEquipment['rows'] as $row) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$name = trim((string) ($row['name'] ?? ''));
		$location = trim((string) ($row['location'] ?? ''));
		$isUnlocked = !$limitEquipmentScope || isset($userEquipmentAccessMap[$equipmentId]);
		$displayName = $name === '' ? 'Unnamed equipment' : $name;
		$equipment[] = [
			'equipment_id' => $equipmentId,
			'name' => $displayName,
			'location' => $location,
			'is_unlocked' => $isUnlocked,
		];
		$equipmentById[$equipmentId] = [
			'name' => $displayName,
			'location' => $location,
			'is_unlocked' => $isUnlocked,
		];
	}
}
 
// Provide a fallback message when no equipment exists.
if ($equipmentError === null && empty($equipment)) {
	$equipmentError = 'No machines are currently available to report yet.';
}

// Label sets for form select options.
$severityOptions = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
$categoryOptions = [
	'near_miss' => 'Near miss',
	'injury' => 'Injury',
	'hazard' => 'Hazard',
	'damage' => 'Damage',
	'security' => 'Security',
	'other' => 'Other',
];

// Handle incident report submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'report_incident') {
	$formValues['equipment_id'] = trim((string) ($_POST['equipment_id'] ?? ''));
	$formValues['severity'] = trim((string) ($_POST['severity'] ?? $formValues['severity']));
	$formValues['category'] = trim((string) ($_POST['category'] ?? $formValues['category']));
	$formValues['location'] = trim((string) ($_POST['location'] ?? ''));
	$formValues['description'] = trim((string) ($_POST['description'] ?? ''));
	$csrfToken = (string) ($_POST['csrf_token'] ?? '');

	if (!validate_csrf_token('report_incident', $csrfToken)) {
		$reportMessages['error'][] = 'This action could not be verified. Please try again.';
	}

	if ($currentUserId <= 0) {
		$reportMessages['error'][] = 'Unable to identify your account.';
	}

	if (!isset($severityOptions[$formValues['severity']])) {
		$reportMessages['error'][] = 'Select a valid severity level.';
	}

	if (!isset($categoryOptions[$formValues['category']])) {
		$reportMessages['error'][] = 'Select a valid category.';
	}

	$equipmentIdValue = 0;
	$resolvedLocation = '';
	if ($formValues['equipment_id'] === '') {
		$reportMessages['error'][] = 'Select a machine or choose general area.';
	} elseif ($formValues['equipment_id'] === 'general') {
		if ($formValues['location'] === '') {
			$reportMessages['error'][] = 'Provide a location for the general area.';
		}
		$resolvedLocation = $formValues['location'];
	} elseif (!ctype_digit($formValues['equipment_id'])) {
		$reportMessages['error'][] = 'Select a valid machine.';
	} else {
		$equipmentIdValue = (int) $formValues['equipment_id'];
		if ($equipmentIdValue <= 0 || !isset($equipmentById[$equipmentIdValue])) {
			$reportMessages['error'][] = 'Select a valid machine.';
		} else {
			$resolvedLocation = (string) ($equipmentById[$equipmentIdValue]['name'] ?? '');
		}
	}

	if ($formValues['description'] === '') {
		$reportMessages['error'][] = 'Provide a description of the incident.';
	} elseif ((function_exists('mb_strlen') ? mb_strlen($formValues['description']) : strlen($formValues['description'])) > 2000) {
		$reportMessages['error'][] = 'Description must be 2000 characters or fewer.';
	}

	if ($resolvedLocation !== '' && (function_exists('mb_strlen') ? mb_strlen($resolvedLocation) : strlen($resolvedLocation)) > 80) {
		$reportMessages['error'][] = 'Location must be 80 characters or fewer.';
	}

	if (empty($reportMessages['error'])) {
		$encryptedLocation = encrypt_sensitive_value($resolvedLocation);
		$encryptedDescription = encrypt_sensitive_value($formValues['description']);
		$severityParam = $formValues['severity'];
		$categoryParam = $formValues['category'];
		$insertStmt = mysqli_prepare(
			$conn,
			'INSERT INTO incidents (reported_by, equipment_id, severity, category, location, description) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?)'
		);
		if ($insertStmt) {
			mysqli_stmt_bind_param(
				$insertStmt,
				'iissss',
				$currentUserId,
				$equipmentIdValue,
				$severityParam,
				$categoryParam,
				$encryptedLocation,
				$encryptedDescription
			);
			if (mysqli_stmt_execute($insertStmt)) {
				$incidentId = mysqli_insert_id($conn) ?: null;
				log_audit_event(
					$conn,
					$currentUserId,
					'incident_reported',
					'incidents',
					$incidentId,
					[
						'severity' => $formValues['severity'],
						'category' => $formValues['category'],
						'equipment_id' => $equipmentIdValue ?: null,
					]
				);
				$reportMessages['success'][] = 'Incident report submitted successfully.';
				$formValues = [
					'equipment_id' => '',
					'severity' => 'low',
					'category' => 'other',
					'location' => '',
					'description' => '',
				];
			} else {
				$reportMessages['error'][] = 'Unable to submit your report right now.';
			}
			mysqli_stmt_close($insertStmt);
		} else {
			$reportMessages['error'][] = 'Unable to submit your report right now.';
		}
	}

	flash_store('report_fault.messages', $reportMessages);
	flash_store('report_fault.form', $formValues);
	redirect_to_current_uri('report-fault.php');
}

// CSRF token for the report form.
$csrfToken = generate_csrf_token('report_incident');
?>
<!DOCTYPE html>
<html lang="en" data-history-fallback="<?php echo htmlspecialchars($historyFallback, ENT_QUOTES); ?>">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Report Safety Incident</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<script src="assets/js/history-guard.js" defer></script>
		<!-- Base styles for the incident report form. -->
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
				background: radial-gradient(circle at top, #eef3ff, var(--bg));
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
				padding: 0;
				border-radius: 0;
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
				box-shadow: 0 12px 24px rgba(67, 97, 238, 0.25);
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.hero {
				max-width: 760px;
				margin-bottom: 2rem;
			}

			.hero p {
				color: var(--muted);
				line-height: 1.6;
			}

			.card {
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(76, 81, 191, 0.08);
				max-width: 760px;
			}

			.card h2 {
				margin-top: 0;
				font-size: 1.2rem;
			}

			.card p {
				color: var(--muted);
				margin-bottom: 1rem;
			}

			form {
				display: grid;
				gap: 0.85rem;
			}

			label {
				display: block;
			}

			label span {
				display: block;
				font-size: 0.9rem;
				margin-bottom: 0.2rem;
				color: var(--muted);
			}

			input,
			select,
			textarea {
				font-family: inherit;
				font-size: 1rem;
				padding: 0.65rem 0.85rem;
				border-radius: 0.6rem;
				border: 1px solid #d7def0;
				background-color: #fdfdff;
				width: 100%;
			}

			textarea {
				min-height: clamp(140px, 22vh, 240px);
				resize: vertical;
				line-height: 1.5;
			}


			button.primary {
				padding: 0.75rem 1rem;
				border-radius: 0.75rem;
				border: none;
				background: var(--accent);
				color: #fff;
				font-weight: 600;
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			button.primary:hover {
				transform: translateY(-2px);
				box-shadow: 0 15px 35px rgba(67, 97, 238, 0.3);
			}

			.is-hidden {
				display: none;
			}

			.alert {
				padding: 0.85rem 1rem;
				border-radius: 0.8rem;
				border: 1px solid #fde68a;
				background: #fef3c7;
				color: #92400e;
				margin-bottom: 0.75rem;
			}

			.alert.error {
				background: #fee2e2;
				border-color: #fecaca;
				color: #991b1b;
			}

			.alert.success {
				background: #dcfce7;
				border-color: #bbf7d0;
				color: #166534;
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
		<!-- Header with search and profile menu. -->
		<header>
			<div class="banner">
				<h1>Report Safety Incident</h1>
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
					<a class="icon-button" href="<?php echo htmlspecialchars($dashboardHref, ENT_QUOTES); ?>" aria-label="Home">
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
			<!-- Intro copy for reporting flow. -->
			<section class="hero">
				<h2>Report an Incident or Hazard</h2>
				<p>
					Use this form to report safety incidents, near misses, hazards, or damage in the
					AMC. Submissions go directly into the incident log for review.
				</p>
			</section>
			<!-- Incident report form card. -->
			<section class="card" aria-labelledby="incident-form-title">
				<h2 id="incident-form-title">Incident Report</h2>
				<p>Please include as much detail as possible so staff can respond quickly.</p>
				<?php foreach ($reportMessages['success'] as $message): ?>
					<div class="alert success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
				<?php endforeach; ?>
				<?php if (!empty($reportMessages['error'])): ?>
					<div class="alert error" role="alert">
						<ul>
							<?php foreach ($reportMessages['error'] as $message): ?>
								<li><?php echo htmlspecialchars($message, ENT_QUOTES); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ($equipmentError !== null): ?>
					<div class="alert error" role="alert"><?php echo htmlspecialchars($equipmentError, ENT_QUOTES); ?></div>
				<?php endif; ?>
				<form method="post">
					<input type="hidden" name="form_type" value="report_incident" />
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
					<label>
						<span>Machine</span>
						<select name="equipment_id" required data-equipment-select>
							<option value="" disabled <?php echo $formValues['equipment_id'] === '' ? 'selected' : ''; ?>>Select machine or general area</option>
							<option value="general" <?php echo $formValues['equipment_id'] === 'general' ? 'selected' : ''; ?>>General area / not listed</option>
							<?php foreach ($equipment as $machine): ?>
								<?php
									$machineIdValue = (string) $machine['equipment_id'];
									$isUnlocked = (bool) ($machine['is_unlocked'] ?? true);
									$optionLabel = (string) $machine['name'];
									if (!$isUnlocked) {
										$optionLabel .= ' (restricted)';
									}
								?>
								<option
									value="<?php echo htmlspecialchars($machineIdValue, ENT_QUOTES); ?>"
									<?php echo $formValues['equipment_id'] === $machineIdValue ? 'selected' : ''; ?>
									data-locked="<?php echo $isUnlocked ? '0' : '1'; ?>"
								>
									<?php echo htmlspecialchars($optionLabel, ENT_QUOTES); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Severity</span>
						<select name="severity" required>
							<?php foreach ($severityOptions as $value => $label): ?>
								<option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $formValues['severity'] === $value ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($label, ENT_QUOTES); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Category</span>
						<select name="category" required>
							<?php foreach ($categoryOptions as $value => $label): ?>
								<option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $formValues['category'] === $value ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($label, ENT_QUOTES); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label
						id="general-location-field"
						data-general-location
						class="<?php echo $formValues['equipment_id'] === 'general' ? '' : 'is-hidden'; ?>"
					>
						<span>Location</span>
						<input type="text" name="location" value="<?php echo htmlspecialchars($formValues['location'], ENT_QUOTES); ?>" placeholder="Room, lab, or area" />
					</label>
					<label>
						<span>Description</span>
						<textarea name="description" required placeholder="Describe what happened, immediate risks, and any injuries or hazards."><?php echo htmlspecialchars($formValues['description'], ENT_QUOTES); ?></textarea>
					</label>
					<button type="submit" class="primary">Submit Report</button>
				</form>
			</section>
		</main>
		<script nonce="<?php echo htmlspecialchars(get_csp_nonce(), ENT_QUOTES); ?>">
			(function () {
				function initGeneralLocationToggle() {
					const equipmentSelect = document.querySelector('[data-equipment-select]');
					const locationField = document.querySelector('[data-general-location]');
					const locationInput = locationField ? locationField.querySelector('input[name="location"]') : null;
					if (!equipmentSelect || !locationField || !locationInput) {
						return;
					}

					function setHiddenState(isHidden) {
						if (isHidden) {
							locationField.classList.add('is-hidden');
							locationField.hidden = true;
							locationField.style.display = 'none';
						} else {
							locationField.classList.remove('is-hidden');
							locationField.hidden = false;
							locationField.style.removeProperty('display');
						}
					}

					function applyLocationState(nextValue) {
						const isGeneral = nextValue === 'general';
						setHiddenState(!isGeneral);
						locationInput.required = isGeneral;
						locationInput.disabled = !isGeneral;
						if (!isGeneral) {
							locationInput.value = '';
						} else if (document.activeElement !== locationInput) {
							locationInput.focus();
						}
					}

					applyLocationState(equipmentSelect.value);
					equipmentSelect.addEventListener('change', function (event) {
						applyLocationState(event.target.value);
					});
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initGeneralLocationToggle);
				} else {
					initGeneralLocationToggle();
				}
			})();
		</script>
	</body>
</html>
