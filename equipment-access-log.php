<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Enforce admin access before serving audit-related content.
enforce_capability($conn, 'admin.core');

// Hard block direct access; audits are accessed via secured database tooling.
http_response_code(404);
exit('Audit logs are only available through secured database access.');

// Helper to truncate long detail values for display.
function truncate_text(string $value, int $limit): string
{
	$lengthFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
	$substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
	if ($limit <= 0) {
		return '';
	}
	if ($lengthFn($value) <= $limit) {
		return $value;
	}
	return $substrFn($value, 0, $limit - 3) . '...';
}

// Filter inputs for narrowing audit results.
$selectedEquipmentId = filter_input(INPUT_GET, 'equipment_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$startDateInput = trim((string) ($_GET['start_date'] ?? ''));
$endDateInput = trim((string) ($_GET['end_date'] ?? ''));

$defaultStartDate = (new DateTimeImmutable('now'))->modify('-30 days')->format('Y-m-d');
if ($startDateInput === '') {
	$startDateInput = $defaultStartDate;
}

// Build dynamic SQL conditions for audit query.
$conditions = ["entity_type = 'equipment'"];
$types = '';
$params = [];

if ($selectedEquipmentId !== null) {
	$conditions[] = 'entity_id = ?';
	$types .= 'i';
	$params[] = $selectedEquipmentId;
}

if ($actionFilter !== '') {
	$conditions[] = 'action LIKE ?';
	$types .= 's';
	$params[] = '%' . $actionFilter . '%';
}

if (is_valid_date($startDateInput)) {
	$conditions[] = 'created_at >= ?';
	$types .= 's';
	$params[] = $startDateInput . ' 00:00:00';
} else {
	$startDateInput = '';
}

if ($endDateInput !== '' && is_valid_date($endDateInput)) {
	$conditions[] = 'created_at <= ?';
	$types .= 's';
	$params[] = $endDateInput . ' 23:59:59';
} elseif ($endDateInput !== '') {
	$endDateInput = '';
}

// Assemble the audit query with filters applied.
$whereClause = implode(' AND ', $conditions);
$auditEvents = [];
$auditError = null;
$limit = 250;

// Query recent equipment audit activity.
$sql = "SELECT
		al.audit_id AS audit_log_id,
		al.created_at,
		al.action,
		al.entity_id,
		al.details,
		al.actor_user_id,
		al.ip_address,
		al.user_agent,
		COALESCE(u.full_name, 'Unknown User') AS actor_name,
		COALESCE(e.name, CONCAT('Equipment #', al.entity_id)) AS equipment_name
	FROM audit_logs al
	LEFT JOIN users u ON u.user_id = al.actor_user_id
	LEFT JOIN equipment e ON e.equipment_id = al.entity_id
	WHERE $whereClause
	ORDER BY al.created_at DESC
	LIMIT $limit";

$stmt = mysqli_prepare($conn, $sql);
if ($stmt === false) {
	$auditError = 'Unable to load audit events right now.';
} else {
	if ($types !== '' && !empty($params)) {
		$bindParams = $params;
		$references = [];
		foreach ($bindParams as $index => &$value) {
			$references[$index] = &$value;
		}
		array_unshift($references, $types);
		call_user_func_array([$stmt, 'bind_param'], $references);
	}
	if (mysqli_stmt_execute($stmt)) {
		$result = mysqli_stmt_get_result($stmt);
		if ($result) {
			while ($row = mysqli_fetch_assoc($result)) {
				$auditEvents[] = $row;
			}
			mysqli_free_result($result);
		}
	} else {
		$auditError = 'Unable to load audit events right now.';
	}
	mysqli_stmt_close($stmt);
}

// Compute summary metrics for the header cards.
$uniqueActors = [];
foreach ($auditEvents as $event) {
	$actorId = isset($event['actor_user_id']) ? (int) $event['actor_user_id'] : 0;
	if ($actorId > 0) {
		$uniqueActors[$actorId] = true;
	}
}
$uniqueActorsCount = count($uniqueActors);
$totalEvents = count($auditEvents);

// Load equipment options for the filter dropdown.
$equipmentOptions = [];
$equipmentResult = mysqli_query($conn, 'SELECT equipment_id, name FROM equipment ORDER BY name ASC');
if ($equipmentResult instanceof mysqli_result) {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
		if ($equipmentId <= 0) {
			continue;
		}
		$name = trim((string) ($row['name'] ?? ''));
		if ($name === '') {
			$name = 'Unnamed equipment';
		}
		$equipmentOptions[] = [
			'id' => $equipmentId,
			'name' => $name,
		];
	}
	mysqli_free_result($equipmentResult);
}

// Derive label for active equipment filter.
$activeEquipmentLabel = 'All assets';
if ($selectedEquipmentId !== null) {
	foreach ($equipmentOptions as $option) {
		if ((int) $option['id'] === $selectedEquipmentId) {
			$activeEquipmentLabel = $option['name'];
			break;
		}
	}
	if ($activeEquipmentLabel === 'All assets') {
		$activeEquipmentLabel = 'Equipment #' . $selectedEquipmentId;
	}
}

// Render key/value detail badges from JSON payloads.
function render_detail_badges(?string $json): array
{
	if ($json === null || $json === '') {
		return [];
	}
	$decoded = json_decode($json, true);
	if (!is_array($decoded)) {
		return [];
	}
	$badges = [];
	foreach ($decoded as $key => $value) {
		if (is_array($value) || is_object($value)) {
			$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		if (is_bool($value)) {
			$value = $value ? 'true' : 'false';
		}
		if ($value === null) {
			$value = 'null';
		}
		$badges[] = truncate_text((string) $key . ': ' . (string) $value, 80);
	}
	return $badges;
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Equipment Access Audits</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<!-- Base styles for audit log view. -->
		<style>
			:root {
				--bg: #f5f7ff;
				--card: #ffffff;
				--accent: #0ea5e9;
				--accent-strong: #0369a1;
				--muted: #64748b;
				--text: #0f172a;
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
				background: radial-gradient(circle at top, #e0f2fe, var(--bg));
				min-height: 100vh;
			}

			header {
				padding: 1.5rem clamp(1.5rem, 5vw, 4rem);
				background: var(--card);
				border-bottom: 1px solid var(--border);
				box-shadow: 0 24px 45px rgba(3, 105, 161, 0.12);
				position: sticky;
				top: 0;
				z-index: 10;
			}

			.navbar {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 1rem;
			}

			.profile-menu {
				position: relative;
			}

			.profile-menu summary {
				list-style: none;
				cursor: pointer;
				padding: 0.4rem 0.9rem;
				border-radius: 999px;
				background: var(--accent);
				color: #fff;
				font-weight: 600;
			}

			.profile-menu summary::-webkit-details-marker {
				display: none;
			}

			.profile-dropdown {
				position: absolute;
				top: calc(100% + 0.4rem);
				right: 0;
				min-width: 220px;
				padding: 1rem;
				border-radius: 1rem;
				border: 1px solid var(--border);
				box-shadow: 0 20px 45px rgba(15, 23, 42, 0.16);
				background: var(--card);
				opacity: 0;
				pointer-events: none;
				transform: translateY(-4px);
				transition: opacity 0.2s ease, transform 0.2s ease;
			}

			.profile-menu[open] .profile-dropdown {
				opacity: 1;
				pointer-events: auto;
				transform: translateY(0);
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.summary-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 1rem;
				margin-bottom: 2rem;
			}

			.summary-card {
				padding: 1.25rem;
				border-radius: 1rem;
				border: 1px solid var(--border);
				background: var(--card);
				box-shadow: 0 18px 35px rgba(14, 165, 233, 0.15);
			}

			.summary-card h3 {
				margin: 0;
				font-size: 0.95rem;
				color: var(--muted);
			}

			.summary-card p {
				margin: 0.35rem 0 0;
				font-size: 1.8rem;
				font-weight: 600;
			}

			.filters {
				background: var(--card);
				border: 1px solid var(--border);
				border-radius: 1rem;
				padding: 1.25rem;
				margin-bottom: 1.5rem;
				box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
			}

			.filters form {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 1rem;
			}

			.filters label {
				display: flex;
				flex-direction: column;
				font-size: 0.85rem;
				font-weight: 600;
				color: var(--muted);
			}

			.filters input,
			.filters select {
				margin-top: 0.35rem;
				border-radius: 0.75rem;
				border: 1px solid var(--border);
				padding: 0.55rem 0.75rem;
				font-family: inherit;
				font-size: 0.95rem;
			}

			.filters button {
				border: none;
				border-radius: 0.85rem;
				padding: 0.65rem 1rem;
				font-size: 0.95rem;
				font-weight: 600;
				cursor: pointer;
				background: var(--accent);
				color: #fff;
				margin-top: 1.6rem;
			}

			.log-table-wrapper {
				background: var(--card);
				border: 1px solid var(--border);
				border-radius: 1.25rem;
				box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
				overflow-x: auto;
			}

			table {
				width: 100%;
				border-collapse: collapse;
			}

			thead {
				background: rgba(14, 165, 233, 0.08);
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

			.badges {
				display: flex;
				flex-wrap: wrap;
				gap: 0.4rem;
			}

			.badge {
				padding: 0.15rem 0.65rem;
				border-radius: 999px;
				font-size: 0.78rem;
				background: rgba(14, 165, 233, 0.15);
				color: var(--accent-strong);
			}

			@media (max-width: 768px) {
				thead {
					display: none;
				}

				table,
				tbody,
				td,
				tr {
					display: block;
					width: 100%;
				}

				td {
					padding: 0.75rem;
					border-bottom: 1px solid rgba(226, 232, 240, 0.6);
				}

				td::before {
					content: attr(data-label);
					display: block;
					font-size: 0.78rem;
					text-transform: uppercase;
					letter-spacing: 0.05em;
					color: var(--muted);
					margin-bottom: 0.3rem;
				}
			}
		</style>
	</head>
	<body>
		<!-- Header with page title and profile menu. -->
		<header>
			<div class="navbar">
				<div>
					<p style="margin: 0; color: var(--muted); font-size: 0.9rem;">Equipment oversight</p>
					<h1 style="margin: 0; font-size: clamp(1.5rem, 3vw, 2.5rem);">Access Audit Trail</h1>
					<p style="margin: 0.35rem 0 0; color: var(--muted);">Review who interacted with each machine and why.</p>
				</div>
				<details class="profile-menu">
					<summary><?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></summary>
					<div class="profile-dropdown">
						<p style="margin: 0; font-weight: 600;">Logged in as</p>
						<p style="margin: 0.2rem 0 0.8rem; color: var(--muted);"><?php echo htmlspecialchars($roleDisplay, ENT_QUOTES); ?></p>
						<form method="post" action="logout.php">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutToken, ENT_QUOTES); ?>" />
							<input type="hidden" name="redirect_to" value="login.php" />
							<button type="submit" style="width: 100%; border: none; border-radius: 0.8rem; padding: 0.6rem; font-weight: 600; background: var(--accent); color: #fff; cursor: pointer;">Log out</button>
						</form>
					</div>
				</details>
			</div>
		</header>
		<main>
			<!-- Summary cards for quick audit stats. -->
			<div class="summary-grid">
				<div class="summary-card">
					<h3>Events captured</h3>
					<p><?php echo number_format($totalEvents); ?></p>
				</div>
				<div class="summary-card">
					<h3>Unique actors</h3>
					<p><?php echo number_format($uniqueActorsCount); ?></p>
				</div>
				<div class="summary-card">
					<h3>Active filters</h3>
					<p><?php echo htmlspecialchars($activeEquipmentLabel, ENT_QUOTES); ?></p>
				</div>
			</div>

			<!-- Filters for equipment, action, and date range. -->
			<section class="filters">
				<form method="get">
					<label>
						Equipment
						<select name="equipment_id">
							<option value="">All machines</option>
							<?php foreach ($equipmentOptions as $option): ?>
								<option value="<?php echo (int) $option['id']; ?>" <?php echo $selectedEquipmentId === (int) $option['id'] ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($option['name'], ENT_QUOTES); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						Action keyword
						<input type="text" name="action" value="<?php echo htmlspecialchars($actionFilter, ENT_QUOTES); ?>" placeholder="e.g. booking" />
					</label>
					<label>
						Start date
						<input type="date" name="start_date" value="<?php echo htmlspecialchars($startDateInput, ENT_QUOTES); ?>" />
					</label>
					<label>
						End date
						<input type="date" name="end_date" value="<?php echo htmlspecialchars($endDateInput, ENT_QUOTES); ?>" />
					</label>
					<div>
						<button type="submit">Apply filters</button>
					</div>
				</form>
			</section>

			<!-- Audit table or empty/error states. -->
			<?php if ($auditError !== null): ?>
				<p style="color: #b45309; font-weight: 600;"><?php echo htmlspecialchars($auditError, ENT_QUOTES); ?></p>
			<?php elseif (empty($auditEvents)): ?>
				<p style="color: var(--muted);">No audit events match the current filters.</p>
			<?php else: ?>
				<div class="log-table-wrapper">
					<table>
						<thead>
							<tr>
								<th>Timestamp</th>
								<th>Action</th>
								<th>Equipment</th>
								<th>Actor</th>
								<th>Details</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($auditEvents as $event): ?>
								<?php $badges = render_detail_badges($event['details'] ?? null); ?>
								<tr>
									<td data-label="Timestamp">
										<?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime((string) $event['created_at'])), ENT_QUOTES); ?>
									</td>
									<td data-label="Action">
										<strong><?php echo htmlspecialchars($event['action'] ?? 'unknown', ENT_QUOTES); ?></strong>
									</td>
									<td data-label="Equipment">
										<?php echo htmlspecialchars($event['equipment_name'] ?? 'Equipment', ENT_QUOTES); ?>
									</td>
									<td data-label="Actor">
										<?php echo htmlspecialchars($event['actor_name'] ?? 'Unknown User', ENT_QUOTES); ?>
										<?php if (!empty($event['ip_address'])): ?>
											<span style="display: block; color: var(--muted); font-size: 0.8rem;">
												<?php echo htmlspecialchars($event['ip_address'], ENT_QUOTES); ?>
											</span>
										<?php endif; ?>
									</td>
									<td data-label="Details">
										<?php if (empty($badges)): ?>
											<span style="color: var(--muted);">No additional context</span>
										<?php else: ?>
											<div class="badges">
												<?php foreach ($badges as $badge): ?>
													<span class="badge"><?php echo htmlspecialchars($badge, ENT_QUOTES); ?></span>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</main>
	</body>
</html>
