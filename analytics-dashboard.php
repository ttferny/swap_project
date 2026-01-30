<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = enforce_sensitive_route_guard($conn);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Administrator';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));
$logoutToken = generate_csrf_token('logout_form');

$equipmentWeekly = [];
$equipmentColors = [];
$weekLabels = [];
$bookingChartError = null;

$severityLevels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
$severityColors = [
	'low' => '#38bdf8',
	'medium' => '#f59e0b',
	'high' => '#f97316',
	'critical' => '#ef4444',
];

$today = new DateTime('today');
$currentYear = (int) $today->format('Y');
$currentMonth = (int) $today->format('m');
$daysInMonth = (int) $today->format('t');
$weekCount = (int) ceil($daysInMonth / 7);
for ($i = 1; $i <= $weekCount; $i++) {
	$weekLabels[] = 'Week ' . $i;
}

$bookingSql = "SELECT e.equipment_id, e.name AS equipment_name,
	FLOOR((DAYOFMONTH(b.start_time) - 1) / 7) + 1 AS week_of_month,
	COUNT(b.booking_id) AS total
	FROM equipment e
	LEFT JOIN bookings b ON b.equipment_id = e.equipment_id
		AND b.status = 'approved'
		AND b.start_time < NOW()
		AND YEAR(b.start_time) = ?
		AND MONTH(b.start_time) = ?
	GROUP BY e.equipment_id, e.name, week_of_month
	ORDER BY e.name";

$bookingStmt = mysqli_prepare($conn, $bookingSql);
if (!$bookingStmt) {
	$bookingChartError = 'Unable to load booking utilisation data right now.';
} else {
	mysqli_stmt_bind_param($bookingStmt, 'ii', $currentYear, $currentMonth);
	if (!mysqli_stmt_execute($bookingStmt)) {
		$bookingChartError = 'Unable to load booking utilisation data right now.';
	} else {
		$result = mysqli_stmt_get_result($bookingStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$equipmentId = (int) ($row['equipment_id'] ?? 0);
				$equipmentName = (string) ($row['equipment_name'] ?? 'Equipment');
				$weekIndex = (int) ($row['week_of_month'] ?? 0);
				$count = (int) ($row['total'] ?? 0);
				if (!isset($equipmentWeekly[$equipmentId])) {
					$equipmentWeekly[$equipmentId] = [
						'name' => $equipmentName,
						'counts' => array_fill(1, $weekCount, 0),
					];
				}
				if ($weekIndex >= 1 && $weekIndex <= $weekCount) {
					$equipmentWeekly[$equipmentId]['counts'][$weekIndex] = $count;
				}
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($bookingStmt);
}

if (empty($equipmentWeekly) && $bookingChartError === null) {
	$equipmentListSql = 'SELECT equipment_id, name FROM equipment ORDER BY name';
	$equipmentResult = mysqli_query($conn, $equipmentListSql);
	if ($equipmentResult !== false) {
		while ($row = mysqli_fetch_assoc($equipmentResult)) {
			$equipmentId = (int) ($row['equipment_id'] ?? 0);
			$equipmentName = (string) ($row['name'] ?? 'Equipment');
			$equipmentWeekly[$equipmentId] = [
				'name' => $equipmentName,
				'counts' => array_fill(1, $weekCount, 0),
			];
		}
		mysqli_free_result($equipmentResult);
	}
}

$maxBookingCount = 0;
foreach ($equipmentWeekly as $equipmentData) {
	foreach ($equipmentData['counts'] as $count) {
		if ($count > $maxBookingCount) {
			$maxBookingCount = $count;
		}
	}
}
if ($maxBookingCount === 0) {
	$maxBookingCount = 1;
}

$tickValues = [30, 25, 20, 15, 10, 5, 0];
$maxBookingCount = 30;

$palette = ['#1d4ed8', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0f766e', '#db2777', '#b45309', '#0ea5e9', '#334155'];
$colorIndex = 0;
foreach ($equipmentWeekly as $equipmentId => $equipmentData) {
	$equipmentColors[$equipmentId] = $palette[$colorIndex % count($palette)];
	$colorIndex++;
}

$equipmentNames = [];
$equipmentNameLookup = [];
$equipmentNameResult = mysqli_query($conn, 'SELECT name FROM equipment ORDER BY name');
if ($equipmentNameResult !== false) {
	while ($row = mysqli_fetch_assoc($equipmentNameResult)) {
		$name = trim((string) ($row['name'] ?? ''));
		if ($name === '') {
			continue;
		}
		$equipmentNames[] = $name;
		$equipmentNameLookup[strtolower($name)] = $name;
	}
	mysqli_free_result($equipmentNameResult);
}

$locationFilter = trim((string) ($_GET['location_scope'] ?? 'all'));
if ($locationFilter === '') {
	$locationFilter = 'all';
}
$locationFilterKey = strtolower($locationFilter);
$validLocationKeys = array_merge(['all', 'others'], array_keys($equipmentNameLookup));
if (!in_array($locationFilterKey, $validLocationKeys, true)) {
	$locationFilterKey = 'all';
	$locationFilter = 'all';
}

$trendDays = [];
$safetyTrendError = null;
$safetyMonthsToShow = 6;
$safetyMonthlyBuckets = [];
$safetyMonthlyTotals = [];
$safetySeverityTotals = [];
$safetyTrendMessage = '';
$safetyChartMax = 1;
$safetyDataAvailable = false;

foreach ($severityLevels as $severityKey => $severityLabel) {
	$safetySeverityTotals[$severityKey] = 0;
}

$safetyWindowStart = (clone $today);
$safetyWindowStart->modify('first day of this month');
$safetyWindowStart->modify('-' . ($safetyMonthsToShow - 1) . ' months');
for ($i = 0; $i < $safetyMonthsToShow; $i++) {
	$bucketMonth = (clone $safetyWindowStart)->modify('+' . $i . ' months');
	$monthKey = $bucketMonth->format('Y-m');
	$safetyMonthlyBuckets[$monthKey] = [
		'label' => $bucketMonth->format('M y'),
		'bySeverity' => array_fill_keys(array_keys($severityLevels), 0),
		'total' => 0,
	];
}
$safetyWindowStartStr = $safetyWindowStart->format('Y-m-d 00:00:00');

$safetySql = "SELECT severity, DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
	FROM incidents
	WHERE created_at >= ?
	GROUP BY month_key, severity
	ORDER BY month_key ASC";

$safetyStmt = mysqli_prepare($conn, $safetySql);
if ($safetyStmt === false) {
	$safetyTrendError = 'Unable to load safety trend data right now.';
} else {
	mysqli_stmt_bind_param($safetyStmt, 's', $safetyWindowStartStr);
	if (!mysqli_stmt_execute($safetyStmt)) {
		$safetyTrendError = 'Unable to load safety trend data right now.';
	} else {
		$result = mysqli_stmt_get_result($safetyStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$monthKey = (string) ($row['month_key'] ?? '');
				$severityKey = strtolower((string) ($row['severity'] ?? ''));
				if (!isset($safetyMonthlyBuckets[$monthKey]) || !isset($safetySeverityTotals[$severityKey])) {
					continue;
				}
				$count = (int) ($row['total'] ?? 0);
				$safetyMonthlyBuckets[$monthKey]['bySeverity'][$severityKey] += $count;
				$safetySeverityTotals[$severityKey] += $count;
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($safetyStmt);
}

$safetyMonthlyTotals = [];
$safetyChartMax = 1;
foreach ($safetyMonthlyBuckets as $monthKey => $bucket) {
	$total = array_sum($bucket['bySeverity']);
	$bucket['total'] = $total;
	$safetyMonthlyBuckets[$monthKey] = $bucket;
	$safetyMonthlyTotals[] = $total;
	if ($total > $safetyChartMax) {
		$safetyChartMax = $total;
	}
}
if ($safetyChartMax === 0) {
	$safetyChartMax = 1;
}

$safetyTotalIncidents = array_sum($safetyMonthlyTotals);
$safetyDataAvailable = $safetyTotalIncidents > 0;
$safetyTrendDelta = 0;
if (count($safetyMonthlyTotals) >= 2) {
	$last = $safetyMonthlyTotals[count($safetyMonthlyTotals) - 1];
	$prev = $safetyMonthlyTotals[count($safetyMonthlyTotals) - 2];
	$safetyTrendDelta = $last - $prev;
	if ($last === 0 && $prev === 0) {
		$safetyTrendMessage = 'No incidents recorded in the last two months.';
	} elseif ($safetyTrendDelta > 0) {
		$safetyTrendMessage = 'Incident volume increased by ' . $safetyTrendDelta . ' vs last month.';
	} elseif ($safetyTrendDelta < 0) {
		$safetyTrendMessage = 'Incident volume decreased by ' . abs($safetyTrendDelta) . ' vs last month.';
	} else {
		$safetyTrendMessage = 'Incident volume is flat compared to last month.';
	}
} else {
	$safetyTrendMessage = 'Not enough data to compute a month-over-month trend.';
}

$safetyWindowLabel = 'Rolling 6-month window';
$safetyBucketValues = array_values($safetyMonthlyBuckets);
if (!empty($safetyBucketValues)) {
	$firstBucket = $safetyBucketValues[0];
	$lastBucket = $safetyBucketValues[count($safetyBucketValues) - 1];
	$firstLabel = trim((string) ($firstBucket['label'] ?? ''));
	$lastLabel = trim((string) ($lastBucket['label'] ?? ''));
	if ($firstLabel !== '' && $lastLabel !== '') {
		$safetyWindowLabel = $firstLabel . ' – ' . $lastLabel;
	}
}

$maintenanceCostError = null;
$maintenanceCostWindowDays = 90;
$maintenanceCostRates = [
	'high' => 260.0,
	'medium' => 190.0,
	'low' => 140.0,
];
$defaultCostRate = 170.0;
$maintenanceCostStart = (clone $today);
$maintenanceCostStart->modify('-' . ($maintenanceCostWindowDays - 1) . ' days');
$maintenanceCostStart->setTime(0, 0, 0);
$maintenanceCostSummary = [
	'total_cost' => 0.0,
	'total_hours' => 0.0,
	'equipment' => [],
];
$maintenanceMonthsToShow = 4;
$maintenanceMonthlyBuckets = [];
$maintenanceMonthlyMax = 1;

$maintenanceMonthStart = (clone $today);
$maintenanceMonthStart->modify('first day of this month');
$maintenanceMonthStart->modify('-' . ($maintenanceMonthsToShow - 1) . ' months');
for ($i = 0; $i < $maintenanceMonthsToShow; $i++) {
	$bucketMonth = (clone $maintenanceMonthStart)->modify('+' . $i . ' months');
	$monthKey = $bucketMonth->format('Y-m');
	$maintenanceMonthlyBuckets[$monthKey] = [
		'label' => $bucketMonth->format('M y'),
		'cost' => 0.0,
	];
}

$maintenanceSql = "SELECT
		mr.record_id,
		mr.equipment_id,
		mr.downtime_start,
		mr.downtime_end,
		mr.created_at,
		e.name AS equipment_name,
		e.risk_level
	FROM maintenance_records mr
	LEFT JOIN equipment e ON e.equipment_id = mr.equipment_id
	WHERE mr.created_at >= ?
	ORDER BY COALESCE(mr.downtime_end, mr.created_at) DESC";

$maintenanceStmt = mysqli_prepare($conn, $maintenanceSql);
if ($maintenanceStmt === false) {
	$maintenanceCostError = 'Unable to load maintenance cost data right now.';
} else {
	$maintenanceStartStr = $maintenanceCostStart->format('Y-m-d 00:00:00');
	mysqli_stmt_bind_param($maintenanceStmt, 's', $maintenanceStartStr);
	if (!mysqli_stmt_execute($maintenanceStmt)) {
		$maintenanceCostError = 'Unable to load maintenance cost data right now.';
	} else {
		$result = mysqli_stmt_get_result($maintenanceStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$startRaw = $row['downtime_start'] ?? $row['created_at'] ?? null;
				$endRaw = $row['downtime_end'] ?? $row['created_at'] ?? null;
				if ($startRaw === null || $endRaw === null) {
					continue;
				}
				try {
					$startTime = new DateTime((string) $startRaw);
					$endTime = new DateTime((string) $endRaw);
				} catch (Exception $e) {
					continue;
				}
				$durationSeconds = abs($endTime->getTimestamp() - $startTime->getTimestamp());
				if ($durationSeconds === 0) {
					$durationSeconds = 1800;
				}
				$durationHours = $durationSeconds / 3600;
				$riskLevel = strtolower((string) ($row['risk_level'] ?? ''));
				$rate = $maintenanceCostRates[$riskLevel] ?? $defaultCostRate;
				$cost = $durationHours * $rate;
				$maintenanceCostSummary['total_hours'] += $durationHours;
				$maintenanceCostSummary['total_cost'] += $cost;

				$equipmentId = (int) ($row['equipment_id'] ?? 0);
				$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
				if ($equipmentName === '') {
					$equipmentName = $equipmentId > 0 ? 'Equipment #' . $equipmentId : 'Unassigned equipment';
				}
				if (!isset($maintenanceCostSummary['equipment'][$equipmentId])) {
					$maintenanceCostSummary['equipment'][$equipmentId] = [
						'name' => $equipmentName,
						'hours' => 0.0,
						'cost' => 0.0,
					];
				}
				$maintenanceCostSummary['equipment'][$equipmentId]['hours'] += $durationHours;
				$maintenanceCostSummary['equipment'][$equipmentId]['cost'] += $cost;

				$bucketDate = $row['downtime_end'] ?? $row['created_at'] ?? $row['downtime_start'];
				if ($bucketDate !== null) {
					try {
						$bucketDateTime = new DateTime((string) $bucketDate);
						$monthKey = $bucketDateTime->format('Y-m');
						if (isset($maintenanceMonthlyBuckets[$monthKey])) {
							$maintenanceMonthlyBuckets[$monthKey]['cost'] += $cost;
						}
					} catch (Exception $e) {
					}
				}
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($maintenanceStmt);
}

$maintenanceMonthlyMax = 1;
foreach ($maintenanceMonthlyBuckets as $monthKey => $bucket) {
	if ($bucket['cost'] > $maintenanceMonthlyMax) {
		$maintenanceMonthlyMax = $bucket['cost'];
	}
}
if ($maintenanceMonthlyMax <= 0) {
	$maintenanceMonthlyMax = 1;
}

$maintenanceCostDataAvailable = $maintenanceCostSummary['total_cost'] > 0 && $maintenanceCostSummary['total_hours'] > 0;
$maintenanceCostTopEquipment = array_values($maintenanceCostSummary['equipment']);
usort(
	$maintenanceCostTopEquipment,
	static function (array $a, array $b): int {
		return $b['cost'] <=> $a['cost'];
	}
);
$maintenanceCostTopEquipment = array_slice($maintenanceCostTopEquipment, 0, 3);

$maintenanceCostWindowLabel = sprintf('%s – %s', $maintenanceCostStart->format('M j'), $today->format('M j'));
$maintenanceCostNote = 'Downtime cost uses risk-weighted hourly multipliers (High $260/h, Medium $190/h, Low $140/h).';
$maintenanceBucketValues = array_values($maintenanceMonthlyBuckets);
if (empty($maintenanceBucketValues)) {
	$maintenanceCostWindowLabel = 'Rolling 90-day window';
}
?>

<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Analytics Dashboard</title>
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

			.grid {
				display: grid;
				grid-template-columns: 1fr;
				gap: 1.5rem;
			}

			.card {
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(16, 185, 129, 0.12);
			}

			.card h3 {
				margin-top: 0;
				font-size: 1.1rem;
			}

			.card p {
				color: var(--muted);
				margin-bottom: 0;
			}

			.chart-note {
				margin-top: 0.75rem;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.bar-chart {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
				gap: 1rem;
				align-items: end;
				height: 260px;
				padding: 1.25rem 1rem 1rem 3.5rem;
				margin-top: 1rem;
				background: #f8faff;
				border-radius: 0.85rem;
				border: 1px solid #e2e8f0;
				position: relative;
			}

			.chart-y-axis {
				position: absolute;
				left: 0.75rem;
				top: 1.25rem;
				bottom: 2.25rem;
				display: flex;
				flex-direction: column;
				justify-content: space-between;
				font-size: 0.75rem;
				color: var(--muted);
				pointer-events: none;
				width: 2.5rem;
			}

			.chart-y-axis::after {
				content: '';
				position: absolute;
				left: 2.2rem;
				top: 0;
				bottom: 0;
				width: 1px;
				background: #e2e8f0;
			}

			.chart-grid {
				position: absolute;
				left: 3.1rem;
				top: 1.25rem;
				right: 1rem;
				bottom: 2.25rem;
				display: flex;
				flex-direction: column;
				justify-content: space-between;
				pointer-events: none;
			}

			.chart-grid span {
				height: 1px;
				background: #e2e8f0;
				opacity: 0.7;
			}

			.chart-column {
				display: grid;
				grid-template-rows: 1fr auto;
				height: 100%;
				gap: 0.35rem;
				position: relative;
				padding: 0 0.4rem;
			}

			.chart-column::after {
				content: '';
				position: absolute;
				top: 0;
				right: 0;
				bottom: 1.6rem;
				width: 1px;
				background: rgba(226, 232, 240, 0.8);
			}

			.chart-column:last-child::after {
				display: none;
			}

			.chart-group {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(14px, 1fr));
				align-items: end;
				gap: 6px;
				height: 100%;
			}

			.bar {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: flex-end;
				gap: 0.35rem;
				height: 100%;
			}

			.bar-fill {
				width: 100%;
				border-radius: 6px 6px 3px 3px;
				background: var(--bar-color);
				height: var(--bar-height);
				min-height: 3px;
				transition: height 0.2s ease;
			}

			.bar-label {
				font-size: 0.65rem;
				color: var(--muted);
				white-space: nowrap;
			}

			.week-label {
				margin-top: 0.4rem;
				text-align: center;
				font-size: 0.75rem;
				color: var(--muted);
			}

			.chart-legend {
				display: flex;
				flex-wrap: wrap;
				gap: 0.75rem;
				margin-top: 1rem;
			}

			.area-chart-wrapper {
				margin-top: 1rem;
				background: #f8faff;
				border-radius: 0.85rem;
				border: 1px solid #e2e8f0;
				padding: 1rem;
			}

			.area-chart-filter {
				display: flex;
				flex-wrap: wrap;
				gap: 0.75rem;
				align-items: center;
				margin-top: 0.75rem;
			}

			.area-chart-filter select,
			.area-chart-filter button {
				font: inherit;
				padding: 0.45rem 0.75rem;
				border-radius: 0.5rem;
				border: 1px solid #cbd5f5;
				background: #fff;
			}

			.area-chart-filter button {
				background: var(--accent);
				color: #fff;
				border: none;
				font-weight: 600;
			}

			.area-bar-chart {
				height: 260px;
				margin-top: 1rem;
				background: #f8faff;
				border-radius: 0.85rem;
				border: 1px solid #e2e8f0;
				position: relative;
			}
			.area-group {
				position: relative;
				width: 100%;
				height: 100%;
				display: flex;
				flex-direction: column-reverse;
			}
			.area-bar {
				position: absolute;
				left: 0;
				width: 100%;
				background: var(--bar-color);
				opacity: 0.65;
				border-radius: 3px 3px 0 0;
				bottom: calc(var(--bar-base, 0) * 1%);
				height: calc(var(--bar-height, 0) * 1%);
				z-index: 1;
			}
			.area-label {
				font-size: 0.75rem;
				color: var(--muted);
				text-align: center;
				margin-top: 0.4rem;
				white-space: nowrap;
			}

			.area-axis text {
				fill: var(--muted);
				font-size: 10px;
			}

			.area-grid line {
				stroke: #e2e8f0;
				stroke-width: 1;
			}

			.legend-item {
				display: inline-flex;
				align-items: center;
				gap: 0.4rem;
				font-size: 0.85rem;
				color: var(--muted);
			}

			.legend-swatch {
				width: 10px;
				height: 10px;
				border-radius: 3px;
				background: var(--swatch-color);
			}

			.muted-label {
				display: block;
				font-size: 0.78rem;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				color: var(--muted);
			}

			.trend-summary {
				display: flex;
				flex-wrap: wrap;
				gap: 1rem;
				align-items: center;
				margin-top: 1rem;
			}

			.trend-summary strong {
				font-size: 1.6rem;
			}

			.trend-message {
				font-weight: 600;
			}

			.trend-up {
				color: #ef4444;
			}

			.trend-down {
				color: #10b981;
			}

			.trend-flat {
				color: var(--muted);
			}

			.safety-chart {
				display: flex;
				gap: 1rem;
				align-items: flex-end;
				height: 260px;
				margin-top: 1.25rem;
				background: #f8faff;
				border-radius: 0.85rem;
				border: 1px solid #e2e8f0;
				padding: 1.25rem;
			}

			.safety-column {
				flex: 1;
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 0.75rem;
			}

			.safety-bar {
				width: 100%;
				height: 180px;
				display: flex;
				align-items: flex-end;
				background: linear-gradient(180deg, rgba(15, 23, 42, 0.04), rgba(15, 23, 42, 0.02));
				border-radius: 0.85rem;
				padding: 0.3rem;
			}

			.safety-stack {
				width: 100%;
				display: flex;
				flex-direction: column;
				justify-content: flex-end;
				gap: 2px;
			}

			.safety-slice {
				display: block;
				width: 100%;
				border-radius: 0.4rem;
				min-height: 1px;
			}

			.safety-month {
				text-align: center;
			}

			.safety-month span {
				display: block;
				font-size: 0.82rem;
				color: var(--muted);
			}

			.safety-month strong {
				display: block;
				font-size: 0.95rem;
				margin-top: 0.1rem;
			}

			.trend-breakdown {
				list-style: none;
				padding: 0;
				margin: 1rem 0 0;
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 0.75rem;
			}

			.trend-breakdown li {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 0.6rem;
				padding: 0.5rem 0.75rem;
				border-radius: 0.8rem;
				border: 1px solid #e2e8f0;
				background: #f8faff;
				font-size: 0.9rem;
			}

			.cost-metrics {
				display: flex;
				flex-wrap: wrap;
				gap: 0.75rem;
				margin-top: 1rem;
			}

			.cost-metric {
				flex: 1 1 150px;
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				padding: 0.85rem 1rem;
				background: #f8faff;
				box-shadow: inset 0 1px 0 rgba(15, 23, 42, 0.04);
			}

			.cost-metric strong {
				display: block;
				margin-top: 0.25rem;
				font-size: 1.2rem;
			}

			.cost-list {
				list-style: none;
				padding: 0;
				margin: 1rem 0 0;
				display: flex;
				flex-direction: column;
				gap: 0.75rem;
			}

			.cost-row {
				display: flex;
				justify-content: space-between;
				gap: 1rem;
				align-items: flex-start;
				padding-bottom: 0.35rem;
				border-bottom: 1px dashed #e2e8f0;
			}

			.cost-row:last-child {
				border-bottom: none;
			}

			.cost-value {
				font-weight: 700;
				color: var(--text);
			}

			.cost-chart {
				margin-top: 1.25rem;
				display: flex;
				align-items: flex-end;
				gap: 0.85rem;
				height: 200px;
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				padding: 1.1rem;
				background: #f8faff;
			}

			.cost-bar {
				flex: 1;
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 0.4rem;
			}

			.cost-bar-fill {
				width: 100%;
				border-radius: 0.75rem 0.75rem 0 0;
				background: linear-gradient(180deg, #10b981, #0f766e);
				min-height: 4px;
			}

			.cost-bar-label {
				text-align: center;
				font-size: 0.85rem;
				color: var(--muted);
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
				<h1>Analytics Dashboard</h1>
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
			<section class="intro" aria-labelledby="analytics-intro-title">
				<h2 id="analytics-intro-title">Equipment Utilisation & Safety Trends</h2>
				<p>Explore equipment utilisation patterns and safety trends across the AMC.</p>
			</section>
			<section class="grid" aria-label="Analytics highlights">
				<article class="card">
					<h3>Equipment Utilisation</h3>
					<p>Completed bookings per machine by week this month.</p>
					<?php if ($bookingChartError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($bookingChartError, ENT_QUOTES); ?></p>
					<?php elseif (empty($equipmentWeekly)): ?>
						<p class="chart-note">No equipment bookings found for this month.</p>
					<?php else: ?>
						<div class="bar-chart" role="img" aria-label="Completed bookings per machine by week this month">
							<div class="chart-y-axis" aria-hidden="true">
								<?php foreach ($tickValues as $tick): ?>
									<span><?php echo htmlspecialchars((string) $tick, ENT_QUOTES); ?></span>
								<?php endforeach; ?>
							</div>
							<div class="chart-grid" aria-hidden="true">
								<?php foreach ($tickValues as $tick): ?>
									<span></span>
								<?php endforeach; ?>
							</div>
							<?php foreach ($weekLabels as $index => $label): ?>
								<div class="chart-column">
									<div class="chart-group">
										<?php foreach ($equipmentWeekly as $equipmentId => $equipmentData): ?>
											<?php
												$count = (int) ($equipmentData['counts'][$index + 1] ?? 0);
												$height = ($count / $maxBookingCount) * 100;
												$color = $equipmentColors[$equipmentId] ?? '#2563eb';
											?>
											<div class="bar" title="<?php echo htmlspecialchars($equipmentData['name'] . ' · ' . $label . ': ' . $count, ENT_QUOTES); ?>">
												<div class="bar-fill" style="--bar-height: <?php echo htmlspecialchars((string) $height, ENT_QUOTES); ?>%; --bar-color: <?php echo htmlspecialchars($color, ENT_QUOTES); ?>;"></div>
											</div>
										<?php endforeach; ?>
									</div>
									<div class="week-label"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="chart-legend" aria-label="Machine legend">
							<?php foreach ($equipmentWeekly as $equipmentId => $equipmentData): ?>
								<div class="legend-item">
									<span class="legend-swatch" style="--swatch-color: <?php echo htmlspecialchars($equipmentColors[$equipmentId] ?? '#2563eb', ENT_QUOTES); ?>;"></span>
									<span><?php echo htmlspecialchars($equipmentData['name'], ENT_QUOTES); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="chart-note">Counts reflect approved bookings with start times before today.</p>
					<?php endif; ?>
				</article>
				<article class="card">
					<h3>Safety Trends</h3>
					<p>Incident mix captured over the past six months.</p>
					<?php if ($safetyTrendError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($safetyTrendError, ENT_QUOTES); ?></p>
					<?php elseif (!$safetyDataAvailable): ?>
						<p class="chart-note">No safety incidents fall inside the current window yet.</p>
					<?php else: ?>
						<div class="trend-summary">
							<div>
								<span class="muted-label">6-month total</span>
								<strong><?php echo htmlspecialchars(number_format((float) $safetyTotalIncidents), ENT_QUOTES); ?></strong>
							</div>
							<div class="trend-message <?php echo htmlspecialchars($safetyTrendDelta > 0 ? 'trend-up' : ($safetyTrendDelta < 0 ? 'trend-down' : 'trend-flat'), ENT_QUOTES); ?>">
								<?php echo htmlspecialchars($safetyTrendMessage, ENT_QUOTES); ?>
							</div>
						</div>
						<div class="safety-chart" role="img" aria-label="Incidents per month by severity">
							<?php foreach ($safetyMonthlyBuckets as $bucket): ?>
								<?php
									$columnHeight = $safetyChartMax > 0
										? max(0.5, ($bucket['total'] / $safetyChartMax) * 100)
										: 0;
								?>
								<div class="safety-column" title="<?php echo htmlspecialchars($bucket['label'] . ' · ' . $bucket['total'] . ' incidents', ENT_QUOTES); ?>">
									<div class="safety-bar">
										<div class="safety-stack" style="height: <?php echo htmlspecialchars((string) $columnHeight, ENT_QUOTES); ?>%;">
											<?php foreach ($severityLevels as $severityKey => $severityLabel): ?>
												<?php
													$count = (int) ($bucket['bySeverity'][$severityKey] ?? 0);
													$segmentHeight = ($bucket['total'] > 0 && $columnHeight > 0)
														? ($count / $bucket['total']) * 100
														: 0;
												?>
												<span
													class="safety-slice"
													style="height: <?php echo htmlspecialchars((string) $segmentHeight, ENT_QUOTES); ?>%; background: <?php echo htmlspecialchars($severityColors[$severityKey], ENT_QUOTES); ?>;"
													aria-label="<?php echo htmlspecialchars($severityLabel . ': ' . $count . ' incidents', ENT_QUOTES); ?>"
												></span>
											<?php endforeach; ?>
										</div>
									</div>
									<div class="safety-month">
										<span><?php echo htmlspecialchars($bucket['label'], ENT_QUOTES); ?></span>
										<strong><?php echo htmlspecialchars((string) $bucket['total'], ENT_QUOTES); ?></strong>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<ul class="trend-breakdown" aria-label="Incident counts by severity">
							<?php foreach ($severityLevels as $severityKey => $severityLabel): ?>
								<li>
									<span class="legend-swatch" style="--swatch-color: <?php echo htmlspecialchars($severityColors[$severityKey], ENT_QUOTES); ?>;"></span>
									<span><?php echo htmlspecialchars($severityLabel, ENT_QUOTES); ?></span>
									<strong><?php echo htmlspecialchars((string) $safetySeverityTotals[$severityKey], ENT_QUOTES); ?></strong>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="chart-note">Window: <?php echo htmlspecialchars($safetyWindowLabel, ENT_QUOTES); ?></p>
					<?php endif; ?>
				</article>
				<article class="card">
					<h3>Maintenance Cost Impact</h3>
					<p>Estimated downtime cost built from recent maintenance records.</p>
					<?php if ($maintenanceCostError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($maintenanceCostError, ENT_QUOTES); ?></p>
					<?php elseif (!$maintenanceCostDataAvailable): ?>
						<p class="chart-note">No maintenance records have closed within the <?php echo htmlspecialchars((string) $maintenanceCostWindowDays, ENT_QUOTES); ?>-day window yet.</p>
					<?php else: ?>
						<div class="cost-metrics">
							<div class="cost-metric">
								<span class="muted-label">Window</span>
								<strong><?php echo htmlspecialchars($maintenanceCostWindowLabel, ENT_QUOTES); ?></strong>
							</div>
							<div class="cost-metric">
								<span class="muted-label">Downtime hours</span>
								<strong><?php echo htmlspecialchars(number_format($maintenanceCostSummary['total_hours'], 1), ENT_QUOTES); ?> h</strong>
							</div>
							<div class="cost-metric">
								<span class="muted-label">Cost impact</span>
								<strong>$<?php echo htmlspecialchars(number_format($maintenanceCostSummary['total_cost'], 0), ENT_QUOTES); ?></strong>
							</div>
						</div>
						<ul class="cost-list" aria-label="Highest cost assets">
							<?php foreach ($maintenanceCostTopEquipment as $equipmentRow): ?>
								<li class="cost-row">
									<div>
										<strong><?php echo htmlspecialchars($equipmentRow['name'], ENT_QUOTES); ?></strong>
										<span class="muted-label"><?php echo htmlspecialchars(number_format($equipmentRow['hours'], 1), ENT_QUOTES); ?> h downtime</span>
									</div>
									<div class="cost-value">$<?php echo htmlspecialchars(number_format($equipmentRow['cost'], 0), ENT_QUOTES); ?></div>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="cost-chart" role="img" aria-label="Maintenance cost trend by month">
							<?php foreach ($maintenanceMonthlyBuckets as $bucket): ?>
								<?php
									$barHeight = $maintenanceMonthlyMax > 0
										? max(2, ($bucket['cost'] / $maintenanceMonthlyMax) * 100)
										: 0;
								?>
								<div class="cost-bar" title="<?php echo htmlspecialchars($bucket['label'] . ' · $' . number_format($bucket['cost'], 0), ENT_QUOTES); ?>">
									<div class="cost-bar-fill" style="height: <?php echo htmlspecialchars((string) $barHeight, ENT_QUOTES); ?>%;"></div>
									<div class="cost-bar-label">
										<span><?php echo htmlspecialchars($bucket['label'], ENT_QUOTES); ?></span>
										<strong>$<?php echo htmlspecialchars(number_format($bucket['cost'], 0), ENT_QUOTES); ?></strong>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="chart-note"><?php echo htmlspecialchars($maintenanceCostNote, ENT_QUOTES); ?></p>
					<?php endif; ?>
				</article>
			</section>
		</main>
	</body>
</html>
