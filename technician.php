<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

/**
 * Build a current snapshot of all equipment maintenance statuses for the dashboard.
 *
 * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>, message: string, error: string|null}
 */
function build_equipment_status_snapshot(mysqli $conn): array
{
	$rows = [];
	$counts = [
		'operational' => 0,
		'maintenance' => 0,
		'faulty' => 0,
	];
	$message = '';
	$error = null;

	$result = mysqli_query(
		$conn,
		'SELECT equipment_id, name, location, current_status FROM equipment ORDER BY name ASC'
	);
	if ($result === false) {
		$error = 'Unable to load maintenance data right now. Please try again later.';
		return [
			'rows' => $rows,
			'counts' => $counts,
			'message' => $message,
			'error' => $error,
		];
	}

	while ($row = mysqli_fetch_assoc($result)) {
		$status = (string) ($row['current_status'] ?? 'operational');
		if (!isset($counts[$status])) {
			$counts[$status] = 0;
		}
		$counts[$status]++;
		$rows[] = [
			'equipment_id' => (int) ($row['equipment_id'] ?? 0),
			'name' => trim((string) ($row['name'] ?? 'Unnamed equipment')),
			'location' => trim((string) ($row['location'] ?? '')),
			'current_status' => $status,
		];
	}
	mysqli_free_result($result);

	$total = count($rows);
	$message = $total > 0
		? sprintf(
			'Tracking %d assets: %d operational, %d in maintenance, %d flagged faulty.',
			$total,
			(int) ($counts['operational'] ?? 0),
			(int) ($counts['maintenance'] ?? 0),
			(int) ($counts['faulty'] ?? 0)
		)
		: 'No equipment records were found.';

	return [
		'rows' => $rows,
		'counts' => $counts,
		'message' => $message,
		'error' => null,
	];
}

/**
 * Fetch the next few upcoming maintenance tasks for the dashboard schedule card.
 *
 * @return array{tasks: array<int, array<string, mixed>>, error: string|null}
 */
function fetch_upcoming_maintenance_tasks(mysqli $conn): array
{
	$tasks = [];
	$error = null;

	$sql = "SELECT
			mt.task_id,
			mt.title,
			mt.description,
			mt.task_type,
			mt.priority,
			mt.status,
			mt.scheduled_for,
			e.name AS equipment_name,
			u.full_name AS assigned_to_name
		FROM maintenance_tasks mt
		LEFT JOIN equipment e ON e.equipment_id = mt.equipment_id
		LEFT JOIN users u ON u.user_id = mt.assigned_to
		WHERE mt.status NOT IN ('done', 'cancelled')
		ORDER BY
			CASE WHEN mt.scheduled_for IS NULL THEN 1 ELSE 0 END,
			mt.scheduled_for ASC,
			mt.created_at ASC";

	$result = mysqli_query($conn, $sql);
	if ($result === false) {
		$error = 'Unable to load maintenance tasks right now. Please try again later.';
	} else {
		while ($row = mysqli_fetch_assoc($result)) {
			$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
			if ($equipmentName === '') {
				$equipmentName = 'Unassigned equipment';
			}
			$title = trim((string) ($row['title'] ?? 'Untitled task'));
			if ($title === '') {
				$title = 'Untitled task';
			}
			$tasks[] = [
				'task_id' => (int) ($row['task_id'] ?? 0),
				'title' => $title,
				'description' => trim((string) ($row['description'] ?? '')),
				'task_type' => (string) ($row['task_type'] ?? 'corrective'),
				'priority' => (string) ($row['priority'] ?? 'medium'),
				'status' => (string) ($row['status'] ?? 'open'),
				'scheduled_for' => $row['scheduled_for'] ?? null,
				'equipment_name' => $equipmentName,
				'assigned_to_name' => trim((string) ($row['assigned_to_name'] ?? '')),
			];
		}
		mysqli_free_result($result);
	}

	return [
		'tasks' => $tasks,
		'error' => $error,
	];
}

$currentUser = require_login(['technician', 'manager', 'admin']);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Guest User';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Technician'));
$logoutToken = generate_csrf_token('logout_form');
$maintenanceStatusVisible = false;
$maintenanceStatusMessage = '';
$maintenanceStatusError = '';
$maintenanceStatusUpdateNotice = '';
$equipmentStatusRows = [];
$equipmentStatusCounts = [
	'operational' => 0,
	'maintenance' => 0,
	'faulty' => 0,
];
$maintenanceScheduleTasks = [];
$maintenanceScheduleError = '';
$maintenanceHistoryEquipment = [];
$maintenanceHistoryEquipmentError = '';
$maintenanceHistoryRecords = [];
$maintenanceHistoryRecordsError = '';
$selectedHistoryEquipmentId = null;
$maintenanceTaskStatusMessages = [
	'success' => [],
	'error' => [],
];
$allowedEquipmentStatuses = ['operational', 'maintenance', 'faulty'];
$lastUpdatedEquipmentId = null;
$shouldLoadStatusSnapshot = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['maintenance_task_progress'])) {
		$taskId = (int) ($_POST['task_id'] ?? 0);
		$submittedToken = (string) ($_POST['csrf_token'] ?? '');
		if ($taskId <= 0) {
			$maintenanceTaskStatusMessages['error'][] = 'Invalid task selection.';
		} elseif (!validate_csrf_token('maintenance_task_progress_' . $taskId, $submittedToken)) {
			$maintenanceTaskStatusMessages['error'][] = 'Session validation failed. Please refresh and try again.';
		} else {
			$updateStmt = mysqli_prepare(
				$conn,
				"UPDATE maintenance_tasks SET status = 'in_progress', updated_at = NOW() WHERE task_id = ? AND status <> 'in_progress'"
			);
			if ($updateStmt === false) {
				$maintenanceTaskStatusMessages['error'][] = 'Unable to update the task status right now.';
			} else {
				mysqli_stmt_bind_param($updateStmt, 'i', $taskId);
				if (mysqli_stmt_execute($updateStmt) && mysqli_stmt_affected_rows($updateStmt) > 0) {
					$maintenanceTaskStatusMessages['success'][] = 'Task marked as in progress.';
				} else {
					$maintenanceTaskStatusMessages['error'][] = 'Task is already in progress or cannot be updated.';
				}
				mysqli_stmt_close($updateStmt);
			}
		}
	} elseif (isset($_POST['maintenance_task_cancel'])) {
		$taskId = (int) ($_POST['task_id'] ?? 0);
		$submittedToken = (string) ($_POST['csrf_token'] ?? '');
		if ($taskId <= 0) {
			$maintenanceTaskStatusMessages['error'][] = 'Invalid task selection.';
		} elseif (!validate_csrf_token('maintenance_task_cancel_' . $taskId, $submittedToken)) {
			$maintenanceTaskStatusMessages['error'][] = 'Session validation failed. Please refresh and try again.';
		} else {
			$updateStmt = mysqli_prepare(
				$conn,
				"UPDATE maintenance_tasks SET status = 'cancelled', updated_at = NOW() WHERE task_id = ? AND status <> 'cancelled'"
			);
			if ($updateStmt === false) {
				$maintenanceTaskStatusMessages['error'][] = 'Unable to update the task status right now.';
			} else {
				mysqli_stmt_bind_param($updateStmt, 'i', $taskId);
				if (mysqli_stmt_execute($updateStmt) && mysqli_stmt_affected_rows($updateStmt) > 0) {
					$maintenanceTaskStatusMessages['success'][] = 'Task marked as cancelled.';
				} else {
					$maintenanceTaskStatusMessages['error'][] = 'Task is already cancelled or cannot be updated.';
				}
				mysqli_stmt_close($updateStmt);
			}
		}
	} elseif (isset($_POST['maintenance_task_complete'])) {
		$taskId = (int) ($_POST['task_id'] ?? 0);
		$submittedToken = (string) ($_POST['csrf_token'] ?? '');
		if ($taskId <= 0) {
			$maintenanceTaskStatusMessages['error'][] = 'Invalid task selection.';
		} elseif (!validate_csrf_token('maintenance_task_complete_' . $taskId, $submittedToken)) {
			$maintenanceTaskStatusMessages['error'][] = 'Session validation failed. Please refresh and try again.';
		} else {
			$actorUserId = (int) ($currentUser['user_id'] ?? 0);
			$equipmentId = null;
			$currentStatus = '';
			$scheduledFor = null;
			$taskDescription = '';
			mysqli_begin_transaction($conn);
			try {
				$taskStmt = mysqli_prepare(
					$conn,
					'SELECT equipment_id, status, scheduled_for, description FROM maintenance_tasks WHERE task_id = ?'
				);
				if ($taskStmt === false) {
					throw new RuntimeException('Unable to load task details right now.');
				}
				mysqli_stmt_bind_param($taskStmt, 'i', $taskId);
				if (!mysqli_stmt_execute($taskStmt)) {
					mysqli_stmt_close($taskStmt);
					throw new RuntimeException('Unable to load task details right now.');
				}
				mysqli_stmt_bind_result($taskStmt, $equipmentId, $currentStatus, $scheduledFor, $taskDescription);
				if (!mysqli_stmt_fetch($taskStmt)) {
					mysqli_stmt_close($taskStmt);
					throw new RuntimeException('Task could not be found.');
				}
				mysqli_stmt_close($taskStmt);

				$currentStatus = (string) ($currentStatus ?? '');
				if (strtolower($currentStatus) === 'done') {
					throw new RuntimeException('Task is already marked as complete.');
				}

				$updateStmt = mysqli_prepare(
					$conn,
					"UPDATE maintenance_tasks SET status = 'done', completed_at = NOW() WHERE task_id = ?"
				);
				if ($updateStmt === false) {
					throw new RuntimeException('Unable to update the task status right now.');
				}
				mysqli_stmt_bind_param($updateStmt, 'i', $taskId);
				if (!mysqli_stmt_execute($updateStmt)) {
					mysqli_stmt_close($updateStmt);
					throw new RuntimeException('Unable to update the task status right now.');
				}
				mysqli_stmt_close($updateStmt);

				$recordStmt = mysqli_prepare(
					$conn,
					'INSERT INTO maintenance_records (equipment_id, task_id, downtime_start, downtime_end, notes, logged_by) VALUES (?, ?, ?, NOW(), ?, ?)'
				);
				if ($recordStmt === false) {
					throw new RuntimeException('Unable to log the maintenance record right now.');
				}
				$recordNote = trim((string) $taskDescription);
				if ($recordNote === '') {
					$recordNote = 'No additional description provided.';
				}
				$equipmentIdValue = (int) ($equipmentId ?? 0);
				$scheduledStartValue = $scheduledFor !== null ? (string) $scheduledFor : null;
				mysqli_stmt_bind_param($recordStmt, 'iissi', $equipmentIdValue, $taskId, $scheduledStartValue, $recordNote, $actorUserId);
				if (!mysqli_stmt_execute($recordStmt)) {
					mysqli_stmt_close($recordStmt);
					throw new RuntimeException('Unable to log the maintenance record right now.');
				}
				mysqli_stmt_close($recordStmt);

				mysqli_commit($conn);
				$maintenanceTaskStatusMessages['success'][] = 'Task marked as complete and maintenance record logged.';
			} catch (Throwable $error) {
				mysqli_rollback($conn);
				$maintenanceTaskStatusMessages['error'][] = $error->getMessage();
			}
		}
	} elseif (isset($_POST['maintenance_status_update'])) {
		$shouldLoadStatusSnapshot = true;
		$equipmentId = (int) ($_POST['equipment_id'] ?? 0);
		$newStatus = strtolower(trim((string) ($_POST['new_status'] ?? '')));
		$submittedToken = (string) ($_POST['csrf_token'] ?? '');
		if ($equipmentId <= 0) {
			$maintenanceStatusError = 'Invalid equipment selection.';
		} elseif (!in_array($newStatus, $allowedEquipmentStatuses, true)) {
			$maintenanceStatusError = 'Invalid status value supplied.';
		} elseif (!validate_csrf_token('equipment_status_' . $equipmentId, $submittedToken)) {
			$maintenanceStatusError = 'Session validation failed. Please refresh and try again.';
		} else {
			$updateStmt = mysqli_prepare(
				$conn,
				'UPDATE equipment SET current_status = ?, status_updated_at = NOW(), status_updated_by = ? WHERE equipment_id = ?'
			);
			if ($updateStmt === false) {
				$maintenanceStatusError = 'Unable to update equipment status right now. Please try again later.';
			} else {
				$actorUserId = (int) ($currentUser['user_id'] ?? 0);
				mysqli_stmt_bind_param($updateStmt, 'sii', $newStatus, $actorUserId, $equipmentId);
				$executeOk = mysqli_stmt_execute($updateStmt);
				if ($executeOk === false) {
					$maintenanceStatusError = 'Unable to update equipment status right now. Please try again later.';
				} else {
					$lastUpdatedEquipmentId = $equipmentId;
					if (mysqli_stmt_affected_rows($updateStmt) > 0) {
						log_audit_event(
							$conn,
							$actorUserId,
							'equipment_status_updated',
							'equipment',
							$equipmentId,
							['new_status' => $newStatus]
						);
						$maintenanceStatusUpdateNotice = 'Equipment status updated.';
					} else {
						$maintenanceStatusUpdateNotice = 'Equipment status already set to that value.';
					}
				}
				mysqli_stmt_close($updateStmt);
			}
		}
	}
}

if ($shouldLoadStatusSnapshot) {
	$snapshot = build_equipment_status_snapshot($conn);
	if ($snapshot['error'] !== null) {
		$maintenanceStatusError = $snapshot['error'];
		$maintenanceStatusVisible = false;
		$maintenanceStatusUpdateNotice = '';
	} else {
		$maintenanceStatusVisible = true;
		$equipmentStatusRows = $snapshot['rows'];
		$equipmentStatusCounts = $snapshot['counts'];
		$maintenanceStatusMessage = $snapshot['message'];
		if ($maintenanceStatusUpdateNotice !== '' && $lastUpdatedEquipmentId !== null) {
			foreach ($equipmentStatusRows as $statusRow) {
				if ((int) $statusRow['equipment_id'] === $lastUpdatedEquipmentId) {
					$maintenanceStatusUpdateNotice = sprintf(
						'%s is now marked %s.',
						$statusRow['name'],
						ucfirst($statusRow['current_status'])
					);
					break;
				}
			}
		}
	}
}

$scheduleResult = fetch_upcoming_maintenance_tasks($conn);
$maintenanceScheduleTasks = $scheduleResult['tasks'];
if ($scheduleResult['error'] !== null) {
	$maintenanceScheduleError = $scheduleResult['error'];
}

$equipmentResult = mysqli_query(
	$conn,
	'SELECT equipment_id, name FROM equipment ORDER BY name ASC'
);
if ($equipmentResult === false) {
	$maintenanceHistoryEquipmentError = 'Unable to load equipment right now.';
} else {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$equipmentName = trim((string) ($row['name'] ?? ''));
		if ($equipmentName === '') {
			$equipmentName = 'Unnamed equipment';
		}
		$maintenanceHistoryEquipment[] = [
			'equipment_id' => $equipmentId,
			'name' => $equipmentName,
		];
	}
	mysqli_free_result($equipmentResult);
}

$selectedHistoryEquipmentRaw = trim((string) ($_GET['equipment_id'] ?? ''));
if ($selectedHistoryEquipmentRaw !== '') {
	if (!ctype_digit($selectedHistoryEquipmentRaw)) {
		$maintenanceHistoryRecordsError = 'Select a valid equipment entry.';
	} else {
		$selectedHistoryEquipmentId = (int) $selectedHistoryEquipmentRaw;
		$equipmentIdList = array_column($maintenanceHistoryEquipment, 'equipment_id');
		if (!in_array($selectedHistoryEquipmentId, $equipmentIdList, true)) {
			$maintenanceHistoryRecordsError = 'Selected equipment could not be found.';
		} else {
			$recordsStmt = mysqli_prepare(
				$conn,
				"SELECT
					mr.record_id,
					mr.downtime_start,
					mr.downtime_end,
					mr.notes,
					mr.created_at,
					u.full_name AS logged_by_name
				FROM maintenance_records mr
				LEFT JOIN users u ON u.user_id = mr.logged_by
				WHERE mr.equipment_id = ?
				ORDER BY COALESCE(mr.downtime_start, mr.created_at) DESC, mr.created_at DESC"
			);
			if ($recordsStmt === false) {
				$maintenanceHistoryRecordsError = 'Unable to load maintenance history right now.';
			} else {
				mysqli_stmt_bind_param($recordsStmt, 'i', $selectedHistoryEquipmentId);
				if (!mysqli_stmt_execute($recordsStmt)) {
					$maintenanceHistoryRecordsError = 'Unable to load maintenance history right now.';
					mysqli_stmt_close($recordsStmt);
				} else {
					$result = mysqli_stmt_get_result($recordsStmt);
					if ($result !== false) {
						while ($row = mysqli_fetch_assoc($result)) {
							$maintenanceHistoryRecords[] = [
								'record_id' => (int) ($row['record_id'] ?? 0),
								'downtime_start' => $row['downtime_start'] ?? null,
								'downtime_end' => $row['downtime_end'] ?? null,
								'notes' => trim((string) ($row['notes'] ?? '')),
								'created_at' => $row['created_at'] ?? null,
								'logged_by_name' => trim((string) ($row['logged_by_name'] ?? '')),
							];
						}
						mysqli_free_result($result);
					}
					mysqli_stmt_close($recordsStmt);
				}
			}
		}
	}
}

?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>TP AMC Technician Hub</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<style>
			:root {
				--bg: #fffaf5;
				--accent: #f97316;
				--accent-soft: #ffe9d7;
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
				background: radial-gradient(circle at top, #fff3e6, var(--bg));
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

			.intro h2 {
				font-size: clamp(1.6rem, 2.6vw, 2.1rem);
				margin-top: 0;
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
				border: 1px solid #fed7aa;
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
				background: #ffd7b7;
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
				border: 1px solid #ffe0c2;
				border-radius: 0.9rem;
				box-shadow: 0 20px 45px rgba(249, 115, 22, 0.25);
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
				box-shadow: 0 10px 20px rgba(249, 115, 22, 0.25);
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

			.grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
				gap: 1.5rem;
			}

			.card {
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #ffe0c2;
				box-shadow: 0 15px 35px rgba(249, 115, 22, 0.12);
				text-decoration: none;
				color: inherit;
				transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			}

			.card:hover,
			.card:focus-visible {
				transform: translateY(-4px);
				border-color: var(--accent);
				box-shadow: 0 20px 40px rgba(249, 115, 22, 0.2);
				outline: none;
			}

			.card h2 {
				margin-top: 0;
				font-size: 1.35rem;
			}

			.card p {
				color: var(--muted);
				margin-bottom: 0;
			}

			.status-panel {
				margin-top: 1rem;
				padding: 1rem;
				border-radius: 0.85rem;
				background: #fff6ed;
				border: 1px solid #fed7aa;
				color: var(--text);
			}

			.status-panel strong {
				display: block;
				margin-bottom: 0.35rem;
			}

			.status-panel ul {
				padding-left: 1.1rem;
				margin: 0.35rem 0 0;
				color: var(--muted);
			}

			.status-panel li {
				margin-bottom: 0.25rem;
			}

			.status-error {
				margin-top: 0.75rem;
				padding: 0.75rem 1rem;
				border-radius: 0.75rem;
				background: #fee2e2;
				border: 1px solid #fecaca;
				color: #b91c1c;
			}

			.status-success {
				margin-top: 0.75rem;
				padding: 0.75rem 1rem;
				border-radius: 0.75rem;
				background: #ecfdf5;
				border: 1px solid #a7f3d0;
				color: #047857;
			}

			.status-action {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				padding: 0.65rem 1.15rem;
				border-radius: 999px;
				border: 1px solid transparent;
				background: var(--accent-soft);
				color: var(--accent);
				font-weight: 600;
				font-size: 0.95rem;
				white-space: nowrap;
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
			}

			.status-action:hover {
				transform: translateY(-1px);
				box-shadow: 0 12px 25px rgba(249, 115, 22, 0.25);
			}

			.status-snapshot {
				display: flex;
				flex-wrap: wrap;
				gap: 0.75rem;
				margin-top: 1rem;
			}

			.status-chip {
				flex: 1 1 140px;
				min-width: 140px;
				border-radius: 0.9rem;
				border: 1px solid #fed7aa;
				background: #fff;
				padding: 0.75rem 1rem;
				box-shadow: inset 0 1px 0 rgba(249, 115, 22, 0.08);
			}

			.status-chip span {
				display: block;
				font-size: 0.85rem;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				color: var(--muted);
			}

			.status-chip strong {
				display: block;
				font-size: 1.35rem;
				margin-top: 0.25rem;
			}

			.status-list {
				list-style: none;
				padding: 0;
				margin: 1rem 0 0;
				display: flex;
				flex-direction: column;
				gap: 0.65rem;
			}

			.status-row {
				display: flex;
				flex-wrap: wrap;
				align-items: flex-start;
				gap: 0.6rem;
				padding: 0.75rem 1rem;
				border-radius: 0.85rem;
				border: 1px solid rgba(249, 115, 22, 0.15);
				background: rgba(255, 255, 255, 0.6);
			}

			.status-row h3 {
				margin: 0;
				font-size: 1rem;
			}

			.status-info {
				flex: 1 1 220px;
			}

			.status-meta {
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.status-badge {
				display: inline-flex;
				align-items: center;
				gap: 0.25rem;
				padding: 0.35rem 0.85rem;
				border-radius: 999px;
				font-size: 0.85rem;
				font-weight: 600;
				text-transform: capitalize;
			}

			.status-badge.status-operational {
				background: rgba(34, 197, 94, 0.15);
				color: #15803d;
			}

			.status-badge.status-maintenance {
				background: rgba(249, 115, 22, 0.15);
				color: #c2410c;
			}

			.status-badge.status-faulty {
				background: rgba(248, 113, 113, 0.2);
				color: #b91c1c;
			}

			.status-edit-form {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 0.45rem;
				margin-left: auto;
			}

			.status-edit-form select {
				border-radius: 0.65rem;
				border: 1px solid #fed7aa;
				padding: 0.4rem 0.75rem;
				background: #fff;
				font-family: inherit;
				font-size: 0.9rem;
				min-width: 150px;
			}

			.status-edit-form button {
				border: none;
				border-radius: 0.75rem;
				padding: 0.45rem 0.95rem;
				font-size: 0.9rem;
				font-weight: 600;
				background: var(--accent);
				color: #fff;
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.status-edit-form button:hover {
				transform: translateY(-1px);
				box-shadow: 0 10px 18px rgba(249, 115, 22, 0.25);
			}

			.task-empty {
				margin-top: 1rem;
				color: var(--muted);
				font-style: italic;
			}

			.task-list {
				list-style: none;
				padding: 0;
				margin: 1rem 0 0;
				display: flex;
				flex-direction: column;
				gap: 1rem;
			}

			.task-item {
				border: 1px solid rgba(249, 115, 22, 0.18);
				border-radius: 1rem;
				padding: 1rem 1.15rem;
				background: rgba(255, 255, 255, 0.8);
				box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
			}

			.task-item summary {
				list-style: none;
				cursor: pointer;
			}

			.task-item summary::-webkit-details-marker {
				display: none;
			}

			.task-item:focus-within {
				outline: 2px solid rgba(249, 115, 22, 0.4);
				outline-offset: 2px;
			}

			.task-item-header {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 0.75rem;
			}

			.task-item h3 {
				margin: 0;
				font-size: 1rem;
			}

			.task-subtext {
				margin: 0.35rem 0;
				color: var(--muted);
				font-size: 0.9rem;
				display: flex;
				align-items: center;
				gap: 0.4rem;
			}

			.task-meta {
				display: flex;
				flex-wrap: wrap;
				gap: 0.9rem;
				margin: 0.35rem 0 0;
				font-size: 0.9rem;
				color: var(--text);
			}

			.task-meta strong {
				margin-right: 0.25rem;
				font-weight: 600;
			}

			.task-description {
				margin: 0.7rem 0 0;
				color: var(--muted);
				line-height: 1.4;
				font-size: 0.9rem;
			}

			.task-summary {
				margin: 0.5rem 0 0;
				color: var(--muted);
				font-size: 0.9rem;
				line-height: 1.4;
			}

			.task-details {
				margin-top: 0.9rem;
				padding-top: 0.9rem;
				border-top: 1px solid rgba(249, 115, 22, 0.18);
			}

			.task-actions {
				display: flex;
				align-items: center;
				flex-wrap: nowrap;
				justify-content: center;
				gap: 0.6rem;
				margin-top: 0.9rem;
			}

			.task-action-form {
				margin: 0;
			}

			.history-select {
				width: 100%;
				font-family: inherit;
				font-size: 1rem;
				margin-top: 1rem;
				padding: 0.65rem 0.85rem;
				border-radius: 0.6rem;
				border: 1px solid #fed7aa;
				background-color: #fff;
				-webkit-appearance: none;
				appearance: none;
				padding-right: 2.5rem;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23f97316' d='M1.41.59 6 5.17 10.59.59 12 2l-6 6-6-6z'/%3E%3C/svg%3E");
				background-repeat: no-repeat;
				background-position: right 0.85rem center;
				background-size: 0.65rem;
			}

			.history-form {
				margin: 0;
				display: flex;
				flex-wrap: wrap;
				gap: 0.6rem;
				align-items: center;
			}

			.history-submit {
				padding: 0.6rem 1.1rem;
				font-size: 0.9rem;
			}

			.history-list {
				list-style: none;
				padding: 0;
				margin: 1rem 0 0;
				display: flex;
				flex-direction: column;
				gap: 0.75rem;
			}

			.history-item {
				border: 1px solid rgba(249, 115, 22, 0.18);
				border-radius: 0.9rem;
				padding: 0.85rem 1rem;
				background: rgba(255, 255, 255, 0.85);
			}

			.history-meta {
				display: grid;
				gap: 0.35rem;
				font-size: 0.9rem;
				color: var(--text);
			}

			.history-meta strong {
				margin-right: 0.25rem;
			}

			.history-notes {
				margin: 0.6rem 0 0;
				color: var(--muted);
				font-size: 0.9rem;
				line-height: 1.4;
			}

			.task-actions .status-action {
				padding: 0.5rem 0.9rem;
				font-size: 0.85rem;
			}

			.task-actions .status-action--progress {
				background: rgba(59, 130, 246, 0.18);
				color: #1d4ed8;
				border-color: rgba(59, 130, 246, 0.35);
				box-shadow: none;
			}

			.task-actions .status-action--cancel {
				background: rgba(248, 113, 113, 0.18);
				color: #b91c1c;
				border-color: rgba(248, 113, 113, 0.35);
				box-shadow: none;
			}

			.task-actions .status-action--complete {
				background: rgba(34, 197, 94, 0.18);
				color: #15803d;
				border-color: rgba(34, 197, 94, 0.35);
				box-shadow: none;
			}

			.task-actions .status-action--progress:hover {
				box-shadow: 0 14px 24px rgba(59, 130, 246, 0.25);
			}

			.task-actions .status-action--cancel:hover {
				box-shadow: 0 14px 24px rgba(248, 113, 113, 0.25);
			}

			.task-actions .status-action--complete:hover {
				box-shadow: 0 14px 24px rgba(34, 197, 94, 0.25);
			}

			.task-details__meta {
				display: grid;
				gap: 0.4rem;
				font-size: 0.9rem;
			}

			.task-details__meta span {
				color: var(--muted);
			}

			.task-pills {
				display: flex;
				flex-wrap: wrap;
				gap: 0.5rem;
				margin-top: 0.75rem;
			}

			.task-pill {
				border-radius: 999px;
				padding: 0.3rem 0.85rem;
				font-size: 0.8rem;
				font-weight: 600;
				text-transform: capitalize;
				background: var(--accent-soft);
				color: var(--accent);
				min-width: 120px;
				text-align: center;
				white-space: nowrap;
			}

			.task-pill-priority-low {
				background: rgba(34, 197, 94, 0.18);
				color: #15803d;
			}

			.task-pill-priority-medium {
				background: rgba(251, 191, 36, 0.2);
				color: #92400e;
			}

			.task-pill-priority-high {
				background: rgba(248, 113, 113, 0.2);
				color: #b91c1c;
			}

			.task-pill-status-open {
				background: rgba(59, 130, 246, 0.18);
				color: #1d4ed8;
			}

			.task-pill-status-in_progress {
				background: rgba(250, 204, 21, 0.25);
				color: #92400e;
			}

			.task-pill-status-done {
				background: rgba(34, 197, 94, 0.18);
				color: #15803d;
			}

			.task-pill-status-cancelled {
				background: rgba(148, 163, 184, 0.35);
				color: #475569;
			}

			.sr-only {
				position: absolute;
				width: 1px;
				height: 1px;
				padding: 0;
				margin: -1px;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}

			.back-link {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				margin-top: 2.5rem;
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
				box-shadow: 0 15px 35px rgba(249, 115, 22, 0.3);
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
				<h1>Technician Workspace (Preview)</h1>
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
			<div class="intro">
				<h2><?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></h2>
				<p>
					This page will soon power job assignments, checklists, and rapid fault reporting.
					For now it mirrors the home layout so routing and navigation decisions can be
					tested while development continues.
				</p>
			</div>
			<section class="grid">
				<div class="card">
					<h2>Maintenance Status</h2>
					<p>Request the latest service windows, calibration holds, and safety notices.</p>
					<?php if ($maintenanceStatusError !== ''): ?>
						<p class="status-error" role="alert"><?php echo htmlspecialchars($maintenanceStatusError, ENT_QUOTES); ?></p>
					<?php endif; ?>
					<?php if ($maintenanceStatusUpdateNotice !== '' && $maintenanceStatusError === ''): ?>
						<p class="status-success" role="status"><?php echo htmlspecialchars($maintenanceStatusUpdateNotice, ENT_QUOTES); ?></p>
					<?php endif; ?>
					<?php if ($maintenanceStatusVisible): ?>
						<div class="status-panel" aria-live="polite">
							<strong>Live equipment snapshot</strong>
							<?php if ($maintenanceStatusMessage !== ''): ?>
								<p><?php echo htmlspecialchars($maintenanceStatusMessage, ENT_QUOTES); ?></p>
							<?php endif; ?>
							<?php if (!empty($equipmentStatusRows)): ?>
								<div class="status-snapshot" role="list">
									<div class="status-chip" role="listitem">
										<span>Operational</span>
										<strong><?php echo (int) ($equipmentStatusCounts['operational'] ?? 0); ?></strong>
									</div>
									<div class="status-chip" role="listitem">
										<span>Maintenance</span>
										<strong><?php echo (int) ($equipmentStatusCounts['maintenance'] ?? 0); ?></strong>
									</div>
									<div class="status-chip" role="listitem">
										<span>Faulty</span>
										<strong><?php echo (int) ($equipmentStatusCounts['faulty'] ?? 0); ?></strong>
									</div>
								</div>
								<ul class="status-list">
									<?php foreach ($equipmentStatusRows as $equipmentRow): ?>
										<?php
											$rowEquipmentId = (int) ($equipmentRow['equipment_id'] ?? 0);
											if ($rowEquipmentId <= 0) {
												continue;
											}
											$rowSelectId = 'status-select-' . $rowEquipmentId;
											$rowTokenValue = generate_csrf_token('equipment_status_' . $rowEquipmentId);
										?>
										<li class="status-row">
											<div class="status-info">
												<h3><?php echo htmlspecialchars($equipmentRow['name'], ENT_QUOTES); ?></h3>
												<?php if ($equipmentRow['location'] !== ''): ?>
													<p class="status-meta">Located in <?php echo htmlspecialchars($equipmentRow['location'], ENT_QUOTES); ?></p>
												<?php endif; ?>
											</div>
											<span class="status-badge status-<?php echo htmlspecialchars($equipmentRow['current_status'], ENT_QUOTES); ?>">
												<?php echo htmlspecialchars(ucfirst($equipmentRow['current_status']), ENT_QUOTES); ?>
											</span>
											<form class="status-edit-form" method="post" action="technician.php">
												<label class="sr-only" for="<?php echo htmlspecialchars($rowSelectId, ENT_QUOTES); ?>">
													Update status for <?php echo htmlspecialchars($equipmentRow['name'], ENT_QUOTES); ?>
												</label>
												<input type="hidden" name="maintenance_status_update" value="1" />
												<input type="hidden" name="equipment_id" value="<?php echo $rowEquipmentId; ?>" />
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($rowTokenValue, ENT_QUOTES); ?>" />
												<select name="new_status" id="<?php echo htmlspecialchars($rowSelectId, ENT_QUOTES); ?>">
													<?php foreach ($allowedEquipmentStatuses as $statusOption): ?>
														<option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES); ?>" <?php echo $equipmentRow['current_status'] === $statusOption ? 'selected' : ''; ?>>
															<?php echo htmlspecialchars(ucfirst($statusOption), ENT_QUOTES); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<button type="submit">Update</button>
											</form>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="card">
					<h2>Maintenance Schedule</h2>
					<p>Upcoming service commitments and their assigned leads.</p>
					<?php if ($maintenanceScheduleError !== ''): ?>
						<p class="status-error" role="alert"><?php echo htmlspecialchars($maintenanceScheduleError, ENT_QUOTES); ?></p>
					<?php endif; ?>
					<?php foreach ($maintenanceTaskStatusMessages['success'] as $message): ?>
						<p class="status-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></p>
					<?php endforeach; ?>
					<?php foreach ($maintenanceTaskStatusMessages['error'] as $message): ?>
						<p class="status-error" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></p>
					<?php endforeach; ?>
					<?php if (empty($maintenanceScheduleTasks) && $maintenanceScheduleError === ''): ?>
						<p class="task-empty">No maintenance tasks have been logged yet.</p>
					<?php else: ?>
						<ul class="task-list">
							<?php foreach ($maintenanceScheduleTasks as $task): ?>
								<?php
									$scheduledLabel = 'Awaiting slot';
									if (!empty($task['scheduled_for'])) {
										$scheduledTime = date_create($task['scheduled_for']);
										if ($scheduledTime !== false) {
											$scheduledLabel = $scheduledTime->format('M j, Y g:ia');
										} else {
											$scheduledLabel = $task['scheduled_for'];
										}
									}
									$assignedLabel = $task['assigned_to_name'] !== '' ? $task['assigned_to_name'] : 'Unassigned';
									$statusLabel = ucwords(str_replace('_', ' ', $task['status']));
									$summary = $task['description'] !== '' ? mb_substr($task['description'], 0, 120) : 'Tap to view full details.';
								?>
								<li>
									<details class="task-item">
										<summary>
											<div class="task-item-header">
												<h3><?php echo htmlspecialchars($task['equipment_name'], ENT_QUOTES); ?></h3>
												<span class="task-pill task-pill-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES); ?>">
													<?php echo htmlspecialchars(ucfirst($task['priority']), ENT_QUOTES); ?> priority
												</span>
											</div>
											<p class="task-meta">
												<span><strong>Date</strong> <?php echo htmlspecialchars($scheduledLabel, ENT_QUOTES); ?></span>
											</p>
											<p class="task-summary">Click to view full details.</p>
										</summary>
										<div class="task-details">
											<p class="task-subtext">
												<?php echo htmlspecialchars($task['equipment_name'], ENT_QUOTES); ?>
												<span aria-hidden="true">•</span>
												<?php echo htmlspecialchars(ucfirst($task['task_type']), ENT_QUOTES); ?>
											</p>
											<div class="task-details__meta">
												<p><span>Title</span> <?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?></p>
												<p><span>Status:</span> <?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?></p>
												<p><span>Priority:</span> <?php echo htmlspecialchars(ucfirst($task['priority']), ENT_QUOTES); ?></p>
												<p><span>Due:</span> <?php echo htmlspecialchars($scheduledLabel, ENT_QUOTES); ?></p>
												<p><span>Assigned:</span> <?php echo htmlspecialchars($assignedLabel, ENT_QUOTES); ?></p>
											</div>
											<div class="task-actions">
												<form method="post" class="task-action-form">
													<input type="hidden" name="maintenance_task_progress" value="1" />
													<input type="hidden" name="task_id" value="<?php echo (int) $task['task_id']; ?>" />
													<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token('maintenance_task_progress_' . (int) $task['task_id']), ENT_QUOTES); ?>" />
													<button type="submit" class="status-action status-action--progress">In progress</button>
												</form>
												<form method="post" class="task-action-form">
													<input type="hidden" name="maintenance_task_cancel" value="1" />
													<input type="hidden" name="task_id" value="<?php echo (int) $task['task_id']; ?>" />
													<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token('maintenance_task_cancel_' . (int) $task['task_id']), ENT_QUOTES); ?>" />
													<button type="submit" class="status-action status-action--cancel">Cancelled</button>
												</form>
												<form method="post" class="task-action-form">
													<input type="hidden" name="maintenance_task_complete" value="1" />
													<input type="hidden" name="task_id" value="<?php echo (int) $task['task_id']; ?>" />
													<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token('maintenance_task_complete_' . (int) $task['task_id']), ENT_QUOTES); ?>" />
													<button type="submit" class="status-action status-action--complete">Complete</button>
												</form>
											</div>
											<?php if ($task['description'] !== ''): ?>
												<p class="task-description"><?php echo htmlspecialchars($task['description'], ENT_QUOTES); ?></p>
											<?php else: ?>
												<p class="task-description">No additional description was provided.</p>
											<?php endif; ?>
										</div>
									</details>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<div class="card">
					<h2>View Maintenance History</h2>
					<p>Review completed maintenance logs, downtime windows, and technician notes.</p>
					<?php if ($maintenanceHistoryEquipmentError !== ''): ?>
						<p class="status-error" role="alert"><?php echo htmlspecialchars($maintenanceHistoryEquipmentError, ENT_QUOTES); ?></p>
					<?php elseif (!empty($maintenanceHistoryEquipment)): ?>
						<form method="get" class="history-form">
							<label class="sr-only" for="maintenance-history-equipment">Select equipment</label>
							<select id="maintenance-history-equipment" name="equipment_id" class="history-select">
								<option value="">Select equipment</option>
								<?php foreach ($maintenanceHistoryEquipment as $equipment): ?>
									<option value="<?php echo (int) $equipment['equipment_id']; ?>" <?php echo $selectedHistoryEquipmentId === (int) $equipment['equipment_id'] ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars($equipment['name'], ENT_QUOTES); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="status-action status-action--progress history-submit">View</button>
						</form>
						<?php if ($selectedHistoryEquipmentId !== null): ?>
							<?php if ($maintenanceHistoryRecordsError !== ''): ?>
								<p class="status-error" role="alert"><?php echo htmlspecialchars($maintenanceHistoryRecordsError, ENT_QUOTES); ?></p>
							<?php elseif (empty($maintenanceHistoryRecords)): ?>
								<p class="task-empty">No maintenance records found for this equipment.</p>
							<?php else: ?>
								<ul class="history-list">
									<?php foreach ($maintenanceHistoryRecords as $record): ?>
										<?php
											$startLabel = $record['downtime_start'] ? date('M j, Y g:ia', strtotime((string) $record['downtime_start'])) : 'Not set';
											$endLabel = $record['downtime_end'] ? date('M j, Y g:ia', strtotime((string) $record['downtime_end'])) : 'Not set';
											$loggedBy = $record['logged_by_name'] !== '' ? $record['logged_by_name'] : 'Unknown';
										?>
										<li class="history-item">
											<div class="history-meta">
												<span><strong>Downtime:</strong> <?php echo htmlspecialchars($startLabel . ' - ' . $endLabel, ENT_QUOTES); ?></span>
												<span><strong>Logged by:</strong> <?php echo htmlspecialchars($loggedBy, ENT_QUOTES); ?></span>
											</div>
											<?php if ($record['notes'] !== ''): ?>
												<p class="history-notes"><?php echo htmlspecialchars($record['notes'], ENT_QUOTES); ?></p>
											<?php else: ?>
												<p class="history-notes">No notes provided.</p>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						<?php endif; ?>
					<?php else: ?>
						<p class="task-empty">No equipment found.</p>
					<?php endif; ?>
				</div>
			</section>
			<a class="back-link" href="index.php">Return to main dashboard</a>
		</main>
	</body>
</html>
