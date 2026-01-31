<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current user and enforce analytics access.
$currentUser = enforce_capability($conn, 'analytics.dashboard');
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Administrator';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));
// CSRF token for logout action.
$logoutToken = generate_csrf_token('logout_form');

// Data containers for weekly booking utilisation chart.
$equipmentWeekly = [];
$equipmentColors = [];
$weekLabels = [];
$bookingChartError = null;

// Severity and category labels used across incident analytics.
$severityLevels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
$severityColors = [
	'low' => '#38bdf8',
	'medium' => '#f59e0b',
	'high' => '#f97316',
	'critical' => '#ef4444',
];

$categoryLabels = [
	'near_miss' => 'Near miss',
	'injury' => 'Injury',
	'hazard' => 'Hazard',
	'damage' => 'Damage',
	'security' => 'Security',
	'other' => 'Other',
];
$categoryColors = [
	'near_miss' => '#14b8a6',
	'injury' => '#ef4444',
	'hazard' => '#f59e0b',
	'damage' => '#8b5cf6',
	'security' => '#0ea5e9',
	'other' => '#6b7280',
];

// Containers for downtime and breakdown summaries.
$downtimeThisMonth = [];
$downtimeError = null;
$breakdownCounts = [];
$breakdownError = null;
$incidentTopEquipment = [];
$incidentTopCategories = [];
$incidentAnalyticsError = null;
// Monthly safety summary data for the report and charts.
$monthlySafetySummary = [
	'total' => 0,
	'bySeverity' => [],
	'byCategory' => [],
];
$monthlySafetyReportText = '';

$monthlySafetySummary['bySeverity'] = array_fill_keys(array_keys($severityLevels), 0);
$monthlySafetySummary['byCategory'] = array_fill_keys(array_keys($categoryLabels), 0);

// Date helpers for the current reporting window.
$today = new DateTime('today');
$currentYear = (int) $today->format('Y');
$currentMonth = (int) $today->format('m');
$daysInMonth = (int) $today->format('t');
$weekCount = (int) ceil($daysInMonth / 7);
for ($i = 1; $i <= $weekCount; $i++) {
	$weekLabels[] = 'Week ' . $i;
}

// Booking utilisation query for the current month.
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

// Fall back to listing equipment with zeroed counts if no bookings exist.
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

// Expand to a clean multiple so chart bars rest on a sensible baseline.
$maxBookingCount = max(1, $maxBookingCount);
$maxBookingCount = (int) ceil($maxBookingCount / 5) * 5;
$maxBookingCount = max(5, $maxBookingCount);

$targetTicks = 6;
$tickStep = max(1, (int) ceil($maxBookingCount / $targetTicks));
if ($tickStep > 5) {
	$tickStep = (int) ceil($tickStep / 5) * 5;
}

$tickValues = [];
for ($tick = $maxBookingCount; $tick >= 0; $tick -= $tickStep) {
	$tickValues[] = $tick;
}
if (empty($tickValues) || end($tickValues) !== 0) {
	$tickValues[] = 0;
}

$palette = ['#1d4ed8', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0f766e', '#db2777', '#b45309', '#0ea5e9', '#334155'];
$colorIndex = 0;
foreach ($equipmentWeekly as $equipmentId => $equipmentData) {
	$equipmentColors[$equipmentId] = $palette[$colorIndex % count($palette)];
	$colorIndex++;
}

// Date boundaries for monthly downtime and safety summaries.
$monthStart = (clone $today)->modify('first day of this month')->format('Y-m-d 00:00:00');
$monthEnd = (clone $today)->modify('last day of this month')->format('Y-m-d 23:59:59');

// Downtime aggregation for this month.
$downtimeSql = "SELECT mr.equipment_id, e.name AS equipment_name, SUM(TIMESTAMPDIFF(MINUTE, COALESCE(mr.downtime_start, mr.created_at), COALESCE(mr.downtime_end, NOW()))) AS total_minutes
	FROM maintenance_records mr
	INNER JOIN equipment e ON e.equipment_id = mr.equipment_id
	WHERE mr.downtime_start <= ? AND COALESCE(mr.downtime_end, NOW()) >= ?
	GROUP BY mr.equipment_id, e.name
	ORDER BY total_minutes DESC";

$downtimeStmt = mysqli_prepare($conn, $downtimeSql);
if ($downtimeStmt === false) {
	$downtimeError = 'Unable to load downtime data right now.';
} else {
	mysqli_stmt_bind_param($downtimeStmt, 'ss', $monthEnd, $monthStart);
	if (!mysqli_stmt_execute($downtimeStmt)) {
		$downtimeError = 'Unable to load downtime data right now.';
	} else {
		$result = mysqli_stmt_get_result($downtimeStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$equipmentId = (int) ($row['equipment_id'] ?? 0);
				$downtimeThisMonth[] = [
					'equipment_id' => $equipmentId,
					'name' => (string) ($row['equipment_name'] ?? 'Equipment'),
					'minutes' => max(0, (int) ($row['total_minutes'] ?? 0)),
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($downtimeStmt);
}

// Breakdown frequency over the past six months.
$breakdownSql = "SELECT mr.equipment_id, e.name AS equipment_name, COUNT(*) AS total_breakdowns
	FROM maintenance_records mr
	INNER JOIN equipment e ON e.equipment_id = mr.equipment_id
	WHERE mr.downtime_start >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
	GROUP BY mr.equipment_id, e.name
	ORDER BY total_breakdowns DESC";

$breakdownResult = mysqli_query($conn, $breakdownSql);
if ($breakdownResult === false) {
	$breakdownError = 'Unable to load breakdown frequency right now.';
} else {
	while ($row = mysqli_fetch_assoc($breakdownResult)) {
		$breakdownCounts[] = [
			'equipment_id' => (int) ($row['equipment_id'] ?? 0),
			'name' => (string) ($row['equipment_name'] ?? 'Equipment'),
			'count' => (int) ($row['total_breakdowns'] ?? 0),
		];
	}
	mysqli_free_result($breakdownResult);
}

// Equipment name list for location filtering.
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

// Optional location filter from query string.
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

// Safety trend containers for the rolling window chart.
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

// Safety trend query for the rolling six-month window.
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

$incidentWindowStart = (clone $today)->modify('-6 months')->format('Y-m-d 00:00:00');

// Incident hotspot queries for equipment and categories.
$incidentEquipmentSql = "SELECT COALESCE(e.name, 'General area / none specified') AS equipment_name, COUNT(*) AS total
	FROM incidents i
	LEFT JOIN equipment e ON e.equipment_id = i.equipment_id
	WHERE i.created_at >= ?
	GROUP BY equipment_name
	ORDER BY total DESC
	LIMIT 5";

$incidentEquipmentStmt = mysqli_prepare($conn, $incidentEquipmentSql);
if ($incidentEquipmentStmt === false) {
	$incidentAnalyticsError = 'Unable to load incident hotspot data right now.';
} else {
	mysqli_stmt_bind_param($incidentEquipmentStmt, 's', $incidentWindowStart);
	if (mysqli_stmt_execute($incidentEquipmentStmt)) {
		$result = mysqli_stmt_get_result($incidentEquipmentStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$name = trim((string) ($row['equipment_name'] ?? 'General area'));
				$incidentTopEquipment[] = [
					'name' => $name === '' ? 'General area / none specified' : $name,
					'count' => (int) ($row['total'] ?? 0),
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($incidentEquipmentStmt);
}

$incidentCategorySql = 'SELECT category, COUNT(*) AS total FROM incidents WHERE created_at >= ? GROUP BY category ORDER BY total DESC';
$incidentCategoryStmt = mysqli_prepare($conn, $incidentCategorySql);
if ($incidentCategoryStmt === false) {
	$incidentAnalyticsError = $incidentAnalyticsError ?? 'Unable to load incident trend data right now.';
} else {
	mysqli_stmt_bind_param($incidentCategoryStmt, 's', $incidentWindowStart);
	if (mysqli_stmt_execute($incidentCategoryStmt)) {
		$result = mysqli_stmt_get_result($incidentCategoryStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$key = strtolower((string) ($row['category'] ?? 'other'));
				$incidentTopCategories[] = [
					'key' => $key,
					'label' => $categoryLabels[$key] ?? ucfirst($key),
					'count' => (int) ($row['total'] ?? 0),
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($incidentCategoryStmt);
}

// Monthly safety report aggregation queries.
$monthlySeveritySql = 'SELECT severity, COUNT(*) AS total FROM incidents WHERE created_at BETWEEN ? AND ? GROUP BY severity';
$monthlyCategorySql = 'SELECT category, COUNT(*) AS total FROM incidents WHERE created_at BETWEEN ? AND ? GROUP BY category';
$monthlyEquipmentSql = "SELECT COALESCE(e.name, 'General area / none specified') AS equipment_name, COUNT(*) AS total
	FROM incidents i
	LEFT JOIN equipment e ON e.equipment_id = i.equipment_id
	WHERE i.created_at BETWEEN ? AND ?
	GROUP BY equipment_name
	ORDER BY total DESC
	LIMIT 3";

$monthlyEquipment = [];

$monthlySeverityStmt = mysqli_prepare($conn, $monthlySeveritySql);
if ($monthlySeverityStmt) {
	mysqli_stmt_bind_param($monthlySeverityStmt, 'ss', $monthStart, $monthEnd);
	if (mysqli_stmt_execute($monthlySeverityStmt)) {
		$result = mysqli_stmt_get_result($monthlySeverityStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$key = strtolower((string) ($row['severity'] ?? ''));
				$count = (int) ($row['total'] ?? 0);
				if (isset($monthlySafetySummary['bySeverity'][$key])) {
					$monthlySafetySummary['bySeverity'][$key] += $count;
					$monthlySafetySummary['total'] += $count;
				}
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($monthlySeverityStmt);
}

$monthlyCategoryStmt = mysqli_prepare($conn, $monthlyCategorySql);
if ($monthlyCategoryStmt) {
	mysqli_stmt_bind_param($monthlyCategoryStmt, 'ss', $monthStart, $monthEnd);
	if (mysqli_stmt_execute($monthlyCategoryStmt)) {
		$result = mysqli_stmt_get_result($monthlyCategoryStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$key = strtolower((string) ($row['category'] ?? ''));
				$count = (int) ($row['total'] ?? 0);
				if (isset($monthlySafetySummary['byCategory'][$key])) {
					$monthlySafetySummary['byCategory'][$key] += $count;
				}
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($monthlyCategoryStmt);
}

$monthlyEquipmentStmt = mysqli_prepare($conn, $monthlyEquipmentSql);
if ($monthlyEquipmentStmt) {
	mysqli_stmt_bind_param($monthlyEquipmentStmt, 'ss', $monthStart, $monthEnd);
	if (mysqli_stmt_execute($monthlyEquipmentStmt)) {
		$result = mysqli_stmt_get_result($monthlyEquipmentStmt);
		if ($result !== false) {
			while ($row = mysqli_fetch_assoc($result)) {
				$name = trim((string) ($row['equipment_name'] ?? 'General area / none specified'));
				$monthlyEquipment[] = [
					'name' => $name === '' ? 'General area / none specified' : $name,
					'count' => (int) ($row['total'] ?? 0),
				];
			}
			mysqli_free_result($result);
		}
	}
	mysqli_stmt_close($monthlyEquipmentStmt);
}

// Build the monthly safety report text.
$reportLines = [];
$reportLines[] = 'Monthly Safety Report - ' . $today->format('F Y');
$reportLines[] = 'Total incidents: ' . $monthlySafetySummary['total'];
$severityParts = [];
foreach ($severityLevels as $severityKey => $severityLabel) {
	$severityParts[] = $severityLabel . ': ' . ($monthlySafetySummary['bySeverity'][$severityKey] ?? 0);
}
$reportLines[] = 'Severity mix: ' . implode(' | ', $severityParts);

$categoryParts = [];
foreach ($monthlySafetySummary['byCategory'] as $categoryKey => $count) {
	if ($count > 0) {
		$categoryParts[] = ($categoryLabels[$categoryKey] ?? ucfirst($categoryKey)) . ' (' . $count . ')';
	}
}
$reportLines[] = 'Top categories: ' . (!empty($categoryParts) ? implode(', ', $categoryParts) : 'No incidents recorded.');

$equipmentParts = [];
foreach ($monthlyEquipment as $item) {
	$equipmentParts[] = ($item['name'] ?? 'General area') . ' (' . ($item['count'] ?? 0) . ')';
}
$reportLines[] = 'Top equipment: ' . (!empty($equipmentParts) ? implode(', ', $equipmentParts) : 'No equipment-linked incidents this month.');
$monthlySafetyReportText = implode("\r\n", $reportLines);

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
		<link rel="stylesheet" href="assets/css/live-maintenance.css" />
		<script src="assets/js/live-maintenance.js" defer></script>
		<!-- Base styles for analytics dashboard layout. -->
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



			.primary-button {
				display: inline-flex;
				align-items: center;
				gap: 0.45rem;
				border: none;
				border-radius: 0.75rem;
				padding: 0.65rem 1rem;
				background: var(--accent);
				color: #fff;
				font-weight: 600;
				text-decoration: none;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.primary-button:hover {
				transform: translateY(-1px);
				box-shadow: 0 12px 24px rgba(16, 185, 129, 0.25);
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
				max-width: 1200px;
				margin: 0 auto;
			}

			.intro {
				max-width: 640px;
				margin-bottom: 2rem;
			}

			.intro p {
				color: var(--muted);
				line-height: 1.6;
			}

			.intro-actions {
				display: flex;
				margin-top: 0.75rem;
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

			.safety-report-block {
				background: #f8faff;
				border: 1px solid #e2e8f0;
				border-radius: 0.85rem;
				padding: 0.85rem 1rem;
				font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
				white-space: pre-wrap;
				color: #0f172a;
			}

			.report-note {
				margin-top: 0.65rem;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.metric-list {
				list-style: none;
				padding: 0;
				margin: 0.25rem 0 0;
				display: flex;
				flex-direction: column;
				gap: 0.35rem;
			}

			.metric-list li {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 0.4rem 0;
				border-bottom: 1px solid #e2e8f0;
				font-weight: 500;
			}

			.metric-list li:last-child {
				border-bottom: none;
			}

			.metric-list span {
				color: var(--muted);
				font-weight: 600;
			}

			.chart-note {
				margin-top: 0.75rem;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.bar-chart {
				display: flex;
				gap: 1rem;
				align-items: flex-end;
				height: 260px;
				padding: 1.25rem 1rem 1rem 3.5rem;
				margin-top: 1rem;
				background: #f8faff;
				border-radius: 0.85rem;
				border: 1px solid #e2e8f0;
				position: relative;
				max-width: 100%;
				overflow-x: auto;
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
				display: flex;
				flex-direction: column;
				justify-content: flex-end;
				height: 100%;
				gap: 0.35rem;
				position: relative;
				padding: 0 0.4rem;
				min-width: 120px;
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
				grid-auto-flow: column;
				grid-auto-columns: minmax(10px, 1fr);
				align-items: end;
				gap: 6px;
				height: 100%;
				min-height: 0;
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
		<!-- Header with search, shortcuts, and profile menu. -->
		<header>
			<div class="banner">
				<h1>Analytics Dashboard</h1>
				<div class="banner-actions">
					<label class="search-bar" aria-label="Search the platform">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-label="Search icon"
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
							aria-label="Home icon"
						>
							<path d="M12 3 2 11h2v9h6v-6h4v6h6v-9h2L12 3z" />
						</svg>
					</a>
					<button class="icon-button" aria-label="Notifications">
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							role="img"
							aria-label="Notifications bell"
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
								aria-label="Profile icon"
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
			<!-- Intro section with primary actions. -->
			<section class="intro" aria-labelledby="analytics-intro-title">
				<h2 id="analytics-intro-title">Equipment Utilisation & Safety Trends</h2>
				<p>Explore equipment utilisation patterns and safety trends across the AMC.</p>
				<div class="intro-actions">
					<a class="primary-button" href="report-fault.php" aria-label="Report a new safety incident">
						Report a Safety Incident
					</a>
				</div>
			</section>
			<!-- Main analytics cards and charts. -->
			<section class="grid" aria-label="Analytics highlights">
				<!-- Booking utilisation chart. -->
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
				<!-- Safety trend chart and breakdowns. -->
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
				<!-- Downtime summary list. -->
				<article class="card">
					<h3>Downtime This Month</h3>
					<p>Minutes of downtime per machine (includes ongoing events).</p>
					<?php if ($downtimeError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($downtimeError, ENT_QUOTES); ?></p>
					<?php elseif (empty($downtimeThisMonth)): ?>
						<p class="chart-note">No downtime recorded so far this month.</p>
					<?php else: ?>
						<ul class="metric-list">
							<?php foreach ($downtimeThisMonth as $row): ?>
								<li>
									<strong><?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?></strong>
									<span><?php echo htmlspecialchars(number_format((float) $row['minutes']), ENT_QUOTES); ?> mins</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</article>
				<!-- Breakdown frequency list. -->
				<article class="card">
					<h3>Breakdown Frequency (6 mo)</h3>
					<p>Most frequent breakdowns based on recorded downtime events.</p>
					<?php if ($breakdownError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($breakdownError, ENT_QUOTES); ?></p>
					<?php elseif (empty($breakdownCounts)): ?>
						<p class="chart-note">No breakdowns recorded in the last six months.</p>
					<?php else: ?>
						<ul class="metric-list">
							<?php foreach ($breakdownCounts as $row): ?>
								<li>
									<strong><?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?></strong>
									<span><?php echo htmlspecialchars((string) $row['count'], ENT_QUOTES); ?> events</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</article>
				<!-- Incident hotspot list by equipment. -->
				<article class="card">
					<h3>Incident Hotspots (6 mo)</h3>
					<p>Machines or areas most frequently involved in incidents.</p>
					<?php if ($incidentAnalyticsError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($incidentAnalyticsError, ENT_QUOTES); ?></p>
					<?php elseif (empty($incidentTopEquipment)): ?>
						<p class="chart-note">No incident hotspots detected in the last six months.</p>
					<?php else: ?>
						<ul class="metric-list">
							<?php foreach ($incidentTopEquipment as $row): ?>
								<li>
									<strong><?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?></strong>
									<span><?php echo htmlspecialchars((string) $row['count'], ENT_QUOTES); ?> incidents</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</article>
				<!-- Incident category list. -->
				<article class="card">
					<h3>Incident Types (6 mo)</h3>
					<p>Most common incident categories across the AMC.</p>
					<?php if ($incidentAnalyticsError !== null): ?>
						<p class="chart-note"><?php echo htmlspecialchars($incidentAnalyticsError, ENT_QUOTES); ?></p>
					<?php elseif (empty($incidentTopCategories)): ?>
						<p class="chart-note">No incident categories recorded in the last six months.</p>
					<?php else: ?>
						<ul class="metric-list">
							<?php foreach ($incidentTopCategories as $row): ?>
								<li>
									<strong><?php echo htmlspecialchars($row['label'], ENT_QUOTES); ?></strong>
									<span><?php echo htmlspecialchars((string) $row['count'], ENT_QUOTES); ?> incidents</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</article>
				<!-- Generated monthly safety report text block. -->
				<article class="card">
					<h3>Monthly Safety Report</h3>
					<p>Auto-generated summary for <?php echo htmlspecialchars($today->format('F Y'), ENT_QUOTES); ?>.</p>
					<div class="safety-report-block" aria-label="Monthly safety report text"><?php echo htmlspecialchars($monthlySafetyReportText, ENT_QUOTES); ?></div>
					<p class="report-note">Use this summary in monthly reviews to highlight trends and hotspots.</p>
				</article>
			</section>
		</main>
	</body>
</html>
