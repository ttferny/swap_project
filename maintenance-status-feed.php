<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

if (php_sapi_name() === 'cli') {
	echo json_encode(['error' => 'Feed is not available via CLI context.'], JSON_PRETTY_PRINT) . PHP_EOL;
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	http_response_code(405);
	header('Allow: GET');
	exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_login(['admin', 'manager', 'technician']);

const FEED_LIMIT = 5;
$now = new DateTimeImmutable('now');
$downtimeEntries = [];
$taskEntries = [];
$errors = [];
$lastChangeEpoch = 0;
$activeDowntimeCount = 0;
$pendingDecisions = 0;

function parse_datetime(?string $value): ?DateTimeImmutable
{
	if ($value === null || $value === '') {
		return null;
	}
	try {
		return new DateTimeImmutable($value);
	} catch (Throwable $exception) {
		return null;
	}
}

function duration_label(?DateTimeImmutable $start, ?DateTimeImmutable $end): string
{
	if ($start === null) {
		return '';
	}
	$effectiveEnd = $end ?? new DateTimeImmutable('now');
	$seconds = max(0, $effectiveEnd->getTimestamp() - $start->getTimestamp());
	if ($seconds < 60) {
		return $seconds . 's';
	}
	$minutes = (int) floor($seconds / 60);
	if ($minutes < 60) {
		return $minutes . 'm';
	}
	$hours = (int) floor($minutes / 60);
	$remainder = $minutes % 60;
	if ($remainder === 0) {
		return $hours . 'h';
	}
	return $hours . 'h ' . $remainder . 'm';
}

function relative_label(?DateTimeImmutable $point, DateTimeImmutable $reference): string
{
	if ($point === null) {
		return 'Unknown';
	}
	$diff = $reference->getTimestamp() - $point->getTimestamp();
	$abs = abs($diff);
	if ($abs < 60) {
		return $diff >= 0 ? 'Just now' : 'In moments';
	}
	if ($abs < 3600) {
		$minutes = (int) round($abs / 60);
		return $diff >= 0 ? $minutes . 'm ago' : 'In ' . $minutes . 'm';
	}
	if ($abs < 86400) {
		$hours = (int) round($abs / 3600);
		return $diff >= 0 ? $hours . 'h ago' : 'In ' . $hours . 'h';
	}
	return $point->format('M j, g:ia');
}

function short_label(?DateTimeImmutable $point): string
{
	return $point ? $point->format('M j, g:ia') : 'Unknown time';
}

function iso_value(?DateTimeImmutable $point): ?string
{
	return $point ? $point->format(DateTimeInterface::ATOM) : null;
}

try {
	$downtimeSql = "SELECT
			mr.record_id,
			mr.equipment_id,
			mr.downtime_start,
			mr.downtime_end,
			mr.created_at,
			mr.notes,
			e.name AS equipment_name
		FROM maintenance_records mr
		LEFT JOIN equipment e ON e.equipment_id = mr.equipment_id
		ORDER BY
			CASE WHEN mr.downtime_end IS NULL THEN 0 ELSE 1 END ASC,
			COALESCE(mr.downtime_end, mr.downtime_start, mr.created_at) DESC
		LIMIT ?";
	$downtimeStmt = mysqli_prepare($conn, $downtimeSql);
	if ($downtimeStmt === false) {
		throw new RuntimeException('Unable to prepare downtime query.');
	}
	$limit = FEED_LIMIT;
	mysqli_stmt_bind_param($downtimeStmt, 'i', $limit);
	if (mysqli_stmt_execute($downtimeStmt)) {
		$result = mysqli_stmt_get_result($downtimeStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$startAt = parse_datetime($row['downtime_start'] ?? $row['created_at'] ?? null);
				$endAt = parse_datetime($row['downtime_end'] ?? null);
				$createdAt = parse_datetime($row['created_at'] ?? null);
				$isActive = $endAt === null || $endAt->getTimestamp() > $now->getTimestamp();
				if ($isActive) {
					$activeDowntimeCount++;
				}
				$sinceLabel = $isActive
					? 'Since ' . short_label($startAt ?? $createdAt)
					: 'Resolved ' . relative_label($endAt ?? $startAt ?? $createdAt, $now);
				$durationText = duration_label($startAt ?? $createdAt, $endAt ?? ($isActive ? $now : $endAt));
				$lastMoment = $endAt ?? $startAt ?? $createdAt ?? $now;
				$lastChangeEpoch = max($lastChangeEpoch, $lastMoment->getTimestamp());
				$note = trim((string) ($row['notes'] ?? ''));
				if ($note !== '') {
					$note = function_exists('mb_substr') ? mb_substr($note, 0, 120) : substr($note, 0, 120);
				}
				$downtimeEntries[] = [
					'record_id' => (int) ($row['record_id'] ?? 0),
					'equipment_id' => (int) ($row['equipment_id'] ?? 0),
					'equipment_name' => $row['equipment_name'] ?? 'Equipment',
					'status' => $isActive ? 'active' : 'resolved',
					'status_label' => $isActive ? 'Active' : 'Resolved',
					'downtime_start' => iso_value($startAt ?? $createdAt),
					'downtime_end' => iso_value($endAt),
					'since_label' => $sinceLabel,
					'duration_label' => $durationText,
					'note_excerpt' => $note,
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($downtimeStmt);
} catch (Throwable $downtimeException) {
	$errors[] = 'Downtime feed unavailable.';
	record_system_error($downtimeException, ['route' => 'maintenance-status-feed', 'segment' => 'downtime']);
}

try {
	$tasksSql = "SELECT
			mt.task_id,
			mt.title,
			mt.priority,
			mt.status,
			mt.manager_status,
			mt.updated_at,
			mt.scheduled_for,
			e.name AS equipment_name
		FROM maintenance_tasks mt
		LEFT JOIN equipment e ON e.equipment_id = mt.equipment_id
		ORDER BY mt.updated_at DESC
		LIMIT ?";
	$tasksStmt = mysqli_prepare($conn, $tasksSql);
	if ($tasksStmt === false) {
		throw new RuntimeException('Unable to prepare task query.');
	}
	$limitTasks = FEED_LIMIT;
	mysqli_stmt_bind_param($tasksStmt, 'i', $limitTasks);
	if (mysqli_stmt_execute($tasksStmt)) {
		$result = mysqli_stmt_get_result($tasksStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$updatedAt = parse_datetime($row['updated_at'] ?? null);
				$scheduledFor = parse_datetime($row['scheduled_for'] ?? null);
				$priority = strtolower((string) ($row['priority'] ?? 'medium'));
				$managerStatus = strtolower((string) ($row['manager_status'] ?? 'submitted'));
				if ($managerStatus === 'submitted') {
					$pendingDecisions++;
				}
				$lastMoment = $updatedAt ?? $scheduledFor ?? $now;
				$lastChangeEpoch = max($lastChangeEpoch, $lastMoment->getTimestamp());
				$taskEntries[] = [
					'task_id' => (int) ($row['task_id'] ?? 0),
					'title' => trim((string) ($row['title'] ?? 'Maintenance task')),
					'priority' => $priority,
					'priority_label' => ucfirst($priority) . ' priority',
					'manager_status' => $managerStatus,
					'manager_status_label' => ucfirst($managerStatus),
					'equipment_name' => trim((string) ($row['equipment_name'] ?? 'Unassigned equipment')),
					'scheduled_label' => $scheduledFor ? 'Scheduled ' . short_label($scheduledFor) : 'Not scheduled',
					'updated_label' => 'Updated ' . relative_label($updatedAt ?? $now, $now),
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($tasksStmt);
} catch (Throwable $taskException) {
	$errors[] = 'Maintenance task stream unavailable.';
	record_system_error($taskException, ['route' => 'maintenance-status-feed', 'segment' => 'tasks']);
}

$metaStatusLabel = 'Live telemetry synced.';

if ($lastChangeEpoch > 0) {
	$changeMoment = (new DateTimeImmutable('@' . $lastChangeEpoch))->setTimezone($now->getTimezone());
	$metaStatusLabel = 'Last maintenance change ' . relative_label($changeMoment, $now) . '.';
} elseif (!empty($errors)) {
	$metaStatusLabel = 'Live feed responded with warnings.';
}

$meta = [
	'poll_interval_ms' => 4000,
	'server_time_epoch' => $now->getTimestamp(),
	'server_time_iso' => $now->format(DateTimeInterface::ATOM),
	'last_change_epoch' => $lastChangeEpoch,
	'status_label' => $metaStatusLabel,
	'target_label' => 'Dashboards refresh <= 5s',
	'active_downtime' => $activeDowntimeCount,
	'pending_decisions' => $pendingDecisions,
];
if (!empty($errors)) {
	$meta['errors'] = $errors;
}

$response = [
	'downtime' => $downtimeEntries,
	'tasks' => $taskEntries,
	'meta' => $meta,
];

$json = json_encode($response, JSON_UNESCAPED_SLASHES);
if ($json === false) {
	http_response_code(500);
	echo json_encode(['error' => 'Unable to encode feed payload.']);
	exit;
}

$etag = 'W/"' . substr(hash('sha256', $json), 0, 32) . '"';
$clientTag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($clientTag !== '' && $clientTag === $etag) {
	http_response_code(304);
	header('ETag: ' . $etag);
	exit;
}

header('ETag: ' . $etag);
$freshness = $lastChangeEpoch > 0 ? max(0, $now->getTimestamp() - $lastChangeEpoch) : 0;
header('X-Data-Freshness: ' . $freshness);
echo $json;
