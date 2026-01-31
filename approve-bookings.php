<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current user and enforce booking-approval access.
$currentUser = enforce_capability($conn, 'approvals.bookings');
enforce_role_access(['admin', 'manager'], $currentUser);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Guest User';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Manager'));
// CSRF token for logout action.
$logoutToken = generate_csrf_token('logout_form');
$decisionCsrfToken = '';

// Page-level state and decision feedback.
$pageTitle = 'Approve Booking Requests';
$decisionFeedback = '';
$decisionFeedbackType = '';
$decisionFlash = flash_retrieve('approve_bookings.decision');
if (is_array($decisionFlash)) {
	if (array_key_exists('message', $decisionFlash)) {
		$decisionFeedback = $decisionFlash['message'];
	}
	if (array_key_exists('type', $decisionFlash)) {
		$decisionFeedbackType = $decisionFlash['type'];
	}
}

// HTML escape helper for safe output.
if (!function_exists('h')) {
	function h($value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}
}

// Audit logger for booking actions.
if (!function_exists('log_audit')) {
	function log_audit(mysqli $conn, ?int $actorUserId, string $action, string $entityType, int $entityId, array $details = []): void
	{
		$detailsJson = null;
		if (!empty($details)) {
			$detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($detailsJson === false || json_last_error() !== JSON_ERROR_NONE) {
				$detailsJson = null;
			}
		}

		$stmt = mysqli_prepare(
			$conn,
			'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details) VALUES (NULLIF(?, 0), ?, ?, ?, ?)'
		);
		if (!$stmt) {
			return;
		}

		$actorParam = $actorUserId ?? 0;
		mysqli_stmt_bind_param(
			$stmt,
			'issis',
			$actorParam,
			$action,
			$entityType,
			$entityId,
			$detailsJson
		);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
	}
}

// Waitlist promotion helper for rejected bookings.
if (!function_exists('promote_waitlist_entry')) {
	function promote_waitlist_entry(mysqli $conn, array $bookingContext, ?int $actorUserId = null): ?string
	{
		$equipmentId = (int) ($bookingContext['equipment_id'] ?? 0);
		$startTime = $bookingContext['start_time'] ?? '';
		$endTime = $bookingContext['end_time'] ?? '';
		if ($equipmentId <= 0 || $startTime === '' || $endTime === '') {
			return null;
		}

		$waitlistStmt = mysqli_prepare(
			$conn,
			"SELECT waitlist_id, user_id, desired_start, desired_end, COALESCE(note, '') AS note
			FROM booking_waitlist
			WHERE equipment_id = ?
				AND desired_start <= ?
				AND desired_end >= ?
			ORDER BY desired_start ASC, created_at ASC
			LIMIT 1"
		);
		if (!$waitlistStmt) {
			return null;
		}
		mysqli_stmt_bind_param($waitlistStmt, 'iss', $equipmentId, $endTime, $startTime);
		mysqli_stmt_execute($waitlistStmt);
		mysqli_stmt_bind_result($waitlistStmt, $waitlistId, $waitlistUserId, $desiredStart, $desiredEnd, $waitlistNote);
		$hasRow = mysqli_stmt_fetch($waitlistStmt);
		mysqli_stmt_close($waitlistStmt);
		if (!$hasRow) {
			return null;
		}
		$waitlistId = (int) $waitlistId;
		$waitlistUserId = (int) $waitlistUserId;

		if (!mysqli_begin_transaction($conn)) {
			return null;
		}

		$purpose = trim((string) $waitlistNote);
		if ($purpose === '') {
			$purpose = 'Auto-promoted waitlist request';
		}
		if (strlen($purpose) > 255) {
			$purpose = substr($purpose, 0, 255);
		}

		$insertStmt = mysqli_prepare(
			$conn,
			"INSERT INTO bookings (equipment_id, requester_id, start_time, end_time, purpose, status, requires_approval) VALUES (?, ?, ?, ?, ?, 'pending', 1)"
		);
		if (!$insertStmt) {
			mysqli_rollback($conn);
			return null;
		}
		mysqli_stmt_bind_param($insertStmt, 'iisss', $equipmentId, $waitlistUserId, $desiredStart, $desiredEnd, $purpose);
		$insertOk = mysqli_stmt_execute($insertStmt);
		$newBookingId = $insertOk ? mysqli_insert_id($conn) : 0;
		mysqli_stmt_close($insertStmt);
		if (!$insertOk) {
			mysqli_rollback($conn);
			return null;
		}
		if ($newBookingId > 0) {
			log_audit(
				$conn,
				$actorUserId,
				'booking_promoted_from_waitlist',
				'booking',
				$newBookingId,
				[
					'source_waitlist_id' => $waitlistId,
					'equipment_id' => $equipmentId,
					'requester_id' => $waitlistUserId,
					'start_time' => $desiredStart,
					'end_time' => $desiredEnd,
				]
			);
			if ($equipmentId > 0) {
				log_audit_event(
					$conn,
					$actorUserId,
					'equipment_waitlist_promoted',
					'equipment',
					$equipmentId,
					[
						'booking_id' => $newBookingId,
						'waitlist_id' => $waitlistId,
						'window_start' => $desiredStart,
						'window_end' => $desiredEnd,
						'promotion_source' => 'manager_panel',
					]
				);
			}
		}

		$deleteStmt = mysqli_prepare($conn, 'DELETE FROM booking_waitlist WHERE waitlist_id = ?');
		if (!$deleteStmt) {
			mysqli_rollback($conn);
			return null;
		}
		mysqli_stmt_bind_param($deleteStmt, 'i', $waitlistId);
		$deleteOk = mysqli_stmt_execute($deleteStmt);
		$deletedRows = $deleteOk ? mysqli_stmt_affected_rows($deleteStmt) : 0;
		mysqli_stmt_close($deleteStmt);
		if (!$deleteOk || $deletedRows < 1) {
			mysqli_rollback($conn);
			return null;
		}
		log_audit(
			$conn,
			$actorUserId,
			'waitlist_entry_removed',
			'booking_waitlist',
			$waitlistId,
			[
				'reason' => 'promoted_to_booking',
				'target_booking_id' => $newBookingId,
			]
		);

		mysqli_commit($conn);
		return 'A matching waitlist request was automatically promoted into the booking queue.';
	}
}

// Approver identifiers used in audit trails.
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : null;
$approverIdParam = $currentUserId ?? 0;

// Handle direct booking approval/rejection form submissions.
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& ($_POST['form_context'] ?? '') === 'direct-booking'
	&& isset($_POST['booking_action'], $_POST['booking_id'])
) {
	if (!validate_csrf_token('booking_decision_form', $_POST['csrf_token'] ?? null)) {
		$decisionFeedback = 'Unable to verify that request. Please refresh and try again.';
		$decisionFeedbackType = 'error';
	} elseif (!isset($conn) || !($conn instanceof mysqli)) {
		$decisionFeedback = 'Cannot update booking because the database connection is unavailable.';
		$decisionFeedbackType = 'error';
	} else {
		$bookingId = (int) $_POST['booking_id'];
		$action = $_POST['booking_action'] === 'approve' ? 'approve' : 'reject';
		$reason = trim((string) ($_POST['rejection_reason'] ?? ''));
		if (strlen($reason) > 255) {
			$reason = substr($reason, 0, 255);
		}

		$bookingContext = null;
		$contextStmt = mysqli_prepare($conn, 'SELECT equipment_id, requester_id, start_time, end_time FROM bookings WHERE booking_id = ? LIMIT 1');
		if ($contextStmt) {
			mysqli_stmt_bind_param($contextStmt, 'i', $bookingId);
			mysqli_stmt_execute($contextStmt);
			mysqli_stmt_bind_result($contextStmt, $ctxEquipmentId, $ctxRequesterId, $ctxStartTime, $ctxEndTime);
			if (mysqli_stmt_fetch($contextStmt)) {
				$bookingContext = [
					'equipment_id' => $ctxEquipmentId,
					'requester_id' => $ctxRequesterId,
					'start_time' => $ctxStartTime,
					'end_time' => $ctxEndTime,
				];
			}
			mysqli_stmt_close($contextStmt);
		}

		if ($action === 'approve') {
			$sql = "UPDATE bookings SET status='approved', rejection_reason=NULL, approved_by = NULLIF(?, 0), approved_at = NOW(), updated_at = NOW() WHERE booking_id = ? AND status = 'pending'";
			$stmt = mysqli_prepare($conn, $sql);
			if ($stmt) {
				mysqli_stmt_bind_param($stmt, 'ii', $approverIdParam, $bookingId);
				mysqli_stmt_execute($stmt);
				if (mysqli_stmt_affected_rows($stmt) > 0) {
					$decisionFeedback = 'Booking approved and calendar updated.';
					$decisionFeedbackType = 'success';
					log_audit(
						$conn,
						$currentUserId,
						'booking_approved',
						'booking',
						$bookingId,
						[
							'equipment_id' => $bookingContext['equipment_id'] ?? null,
							'requester_id' => $bookingContext['requester_id'] ?? null,
							'start_time' => $bookingContext['start_time'] ?? null,
							'end_time' => $bookingContext['end_time'] ?? null,
						]
					);
					$equipmentIdForAudit = isset($bookingContext['equipment_id']) ? (int) $bookingContext['equipment_id'] : 0;
					if ($equipmentIdForAudit > 0) {
						log_audit_event(
							$conn,
							$currentUserId,
							'equipment_booking_approved',
							'equipment',
							$equipmentIdForAudit,
							[
								'booking_id' => $bookingId,
								'requester_id' => $bookingContext['requester_id'] ?? null,
								'window_start' => $bookingContext['start_time'] ?? null,
								'window_end' => $bookingContext['end_time'] ?? null,
							]
						);
					}
				} else {
					$decisionFeedback = 'No pending booking was updated. It may have been actioned already.';
					$decisionFeedbackType = 'error';
				}
				mysqli_stmt_close($stmt);
			} else {
				$decisionFeedback = 'Unable to process approval right now.';
				$decisionFeedbackType = 'error';
			}
		} else {
			$sql = "UPDATE bookings SET status='rejected', rejection_reason = ?, approved_by = NULLIF(?, 0), approved_at = NOW(), updated_at = NOW() WHERE booking_id = ? AND status = 'pending'";
			$stmt = mysqli_prepare($conn, $sql);
			if ($stmt) {
				mysqli_stmt_bind_param($stmt, 'sii', $reason, $approverIdParam, $bookingId);
				mysqli_stmt_execute($stmt);
				if (mysqli_stmt_affected_rows($stmt) > 0) {
					$decisionFeedback = 'Booking rejected and the requester will be notified.';
					$decisionFeedbackType = 'success';
					log_audit(
						$conn,
						$currentUserId,
						'booking_rejected',
						'booking',
						$bookingId,
						[
							'rejection_reason' => $reason,
							'equipment_id' => $bookingContext['equipment_id'] ?? null,
							'requester_id' => $bookingContext['requester_id'] ?? null,
							'start_time' => $bookingContext['start_time'] ?? null,
							'end_time' => $bookingContext['end_time'] ?? null,
						]
					);
					$equipmentIdForAudit = isset($bookingContext['equipment_id']) ? (int) $bookingContext['equipment_id'] : 0;
					if ($equipmentIdForAudit > 0) {
						log_audit_event(
							$conn,
							$currentUserId,
							'equipment_booking_rejected',
							'equipment',
							$equipmentIdForAudit,
							[
								'booking_id' => $bookingId,
								'requester_id' => $bookingContext['requester_id'] ?? null,
								'window_start' => $bookingContext['start_time'] ?? null,
								'window_end' => $bookingContext['end_time'] ?? null,
								'rejection_reason' => $reason,
							]
						);
					}
					if ($bookingContext !== null) {
						$promotionMessage = promote_waitlist_entry($conn, $bookingContext, $currentUserId);
						if ($promotionMessage !== null) {
							$decisionFeedback .= ' ' . $promotionMessage;
						}
					}
				} else {
					$decisionFeedback = 'No pending booking was updated. It may have been actioned already.';
					$decisionFeedbackType = 'error';
				}
				mysqli_stmt_close($stmt);
			} else {
				$decisionFeedback = 'Unable to process rejection right now.';
				$decisionFeedbackType = 'error';
			}
		}
	}

	flash_store('approve_bookings.decision', [
		'message' => $decisionFeedback,
		'type' => $decisionFeedbackType,
	]);
	redirect_to_current_uri('approve-bookings.php');
}

// CSRF token for decision forms.
$decisionCsrfToken = generate_csrf_token('booking_decision_form');
// Pending bookings feed for approval table.
$pendingBookings = [];
$pendingBookingsError = '';

if (isset($conn) && $conn instanceof mysqli) {
	$pendingBookingsQuery = "
		SELECT
			b.booking_id,
			COALESCE(u.full_name, 'Unknown Requester') AS full_name,
			COALESCE(u.tp_admin_no, 'N/A') AS tp_admin_no,
			e.name AS equipment_name,
			e.category AS equipment_category,
			b.start_time,
			b.end_time,
			COALESCE(b.purpose, '') AS purpose
		FROM bookings b
		INNER JOIN users u ON b.requester_id = u.user_id
		INNER JOIN equipment e ON b.equipment_id = e.equipment_id
		WHERE b.status = 'pending'
			AND b.requires_approval = 1
		ORDER BY b.start_time ASC
		LIMIT 50
	";

	$pendingResult = mysqli_query($conn, $pendingBookingsQuery);
	if ($pendingResult instanceof mysqli_result) {
		while ($row = mysqli_fetch_assoc($pendingResult)) {
			$pendingBookings[] = $row;
		}
		mysqli_free_result($pendingResult);
	} else {
		$pendingBookingsError = 'Unable to load pending bookings right now.';
	}
} else {
	$pendingBookingsError = 'Database connection unavailable.';
}

// Waitlist queue feed for secondary table.
$waitlistEntries = [];
$waitlistError = '';

if (isset($conn) && $conn instanceof mysqli) {
	$waitlistQuery = "
		SELECT
			w.waitlist_id,
			COALESCE(u.full_name, 'Unknown Requester') AS full_name,
			COALESCE(u.tp_admin_no, 'N/A') AS tp_admin_no,
			e.name AS equipment_name,
			e.category AS equipment_category,
			w.desired_start,
			w.desired_end,
			COALESCE(w.note, '') AS note,
			TIMESTAMPDIFF(MINUTE, w.desired_start, w.desired_end) AS duration_minutes
		FROM booking_waitlist w
		INNER JOIN users u ON w.user_id = u.user_id
		INNER JOIN equipment e ON w.equipment_id = e.equipment_id
		ORDER BY w.created_at ASC
		LIMIT 50
	";

	$waitlistResult = mysqli_query($conn, $waitlistQuery);
	if ($waitlistResult instanceof mysqli_result) {
		while ($row = mysqli_fetch_assoc($waitlistResult)) {
			$row['duration_minutes'] = max(0, (int) ($row['duration_minutes'] ?? 0));
			$waitlistEntries[] = $row;
		}
		mysqli_free_result($waitlistResult);
	} else {
		$waitlistError = 'Unable to load waitlist entries right now.';
	}
} else {
	$waitlistError = 'Database connection unavailable.';
}

// Combined decision workload indicator.
$decisionCount = count($pendingBookings) + count($waitlistEntries);
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title><?php echo htmlspecialchars($pageTitle); ?></title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet" />
		<!-- Base styles for the booking approval view. -->
		<style>
			:root {
				--bg: #f8fbff;
				--surface: #ffffff;
				--card: #ffffff;
				--text: #0f172a;
				--muted: #64748b;
				--accent: #10b981;
				--accent-soft: #e6fff5;
				--accent-strong: #047857;
				--danger: #ef4444;
				--grid-line: rgba(15, 23, 42, 0.08);
				font-size: 16px;
			}

			*, *::before, *::after {
				box-sizing: border-box;
			}

			body {
				margin: 0;
				font-family: 'Space Grotesk', 'IBM Plex Sans', 'Segoe UI', Tahoma, sans-serif;
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
				min-width: 210px;
				background: var(--surface);
				border: 1px solid #e2e8f0;
				border-radius: 0.9rem;
				box-shadow: 0 20px 45px rgba(15, 23, 42, 0.15);
				padding: 1rem;
				opacity: 0;
				transform: translateY(-6px);
				pointer-events: none;
				transition: opacity 0.2s ease, transform 0.2s ease;
				z-index: 20;
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
				max-width: 1200px;
				margin: 0 auto;
				padding: clamp(1.25rem, 4vw, 3rem);
				display: flex;
				flex-direction: column;
				gap: 1.5rem;
			}

			.intro {
				margin: 0;
				margin-bottom: 2rem;
				max-width: 720px;
			}

			.intro h2 {
				margin: 0 0 0.5rem;
				font-size: 1.35rem;
			}

			.intro p {
				margin: 0;
				color: var(--muted);
				line-height: 1.6;
				font-size: 1rem;
			}

			.panel {
				background: var(--surface);
				border-radius: 1.25rem;
				padding: 1.5rem;
				box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
				border: 1px solid rgba(148, 163, 184, 0.25);
			}

			.panel h2 {
				margin: 0 0 0.35rem;
				font-size: 1.35rem;
			}

			.helper {
				margin: 0 0 1.25rem;
				color: var(--muted);
				font-size: 0.98rem;
			}

			.table-wrapper {
				border: 1px dashed rgba(148, 163, 184, 0.5);
				border-radius: 1rem;
				overflow-x: auto;
				background: var(--card);
			}

			.table-wrapper::-webkit-scrollbar {
				height: 6px;
			}

			.table-wrapper::-webkit-scrollbar-thumb {
				background: rgba(15, 23, 42, 0.2);
				border-radius: 999px;
			}

			table {
				width: 100%;
				border-collapse: collapse;
				min-width: 760px;
			}

			th {
				text-align: left;
				font-size: 0.85rem;
				text-transform: uppercase;
				letter-spacing: 0.08em;
				color: var(--muted);
				border-bottom: 1px solid rgba(148, 163, 184, 0.4);
				padding: 0.75rem 1rem;
			}

			td {
				padding: 1rem;
				border-bottom: 1px solid rgba(226, 232, 240, 0.9);
				vertical-align: top;
				font-size: 0.98rem;
				color: var(--text);
			}

			tbody tr:last-child td {
				border-bottom: none;
			}

			.meta-muted {
				color: var(--muted);
				font-size: 0.85rem;
			}

			.muted-text {
				color: rgba(15, 23, 42, 0.45);
				font-style: italic;
			}

			.purpose {
				margin: 0;
				line-height: 1.4;
			}

			.status-chip {
				display: inline-flex;
				align-items: center;
				gap: 0.35rem;
				padding: 0.2rem 0.75rem;
				border-radius: 999px;
				font-size: 0.85rem;
				font-weight: 600;
				text-transform: uppercase;
			}

			.status-chip.waiting {
				background: rgba(251, 191, 36, 0.15);
				color: #92400e;
			}

			.alert {
				border-radius: 1rem;
				padding: 1rem 1.25rem;
				border: 1px solid transparent;
				font-weight: 500;
			}

			.alert.success {
				background: rgba(16, 185, 129, 0.1);
				border-color: rgba(16, 185, 129, 0.4);
				color: var(--accent-strong);
			}

			.alert.error {
				background: rgba(239, 68, 68, 0.08);
				border-color: rgba(239, 68, 68, 0.3);
				color: #7f1d1d;
			}

			.alert.info {
				background: rgba(59, 130, 246, 0.08);
				border-color: rgba(59, 130, 246, 0.25);
				color: #1d4ed8;
			}

			.decision-form {
				display: flex;
				flex-direction: column;
				gap: 0.65rem;
			}

			.decision-form input[type='text'],
			.decision-form input[type='search'] {
				border-radius: 0.75rem;
				border: 1px solid rgba(148, 163, 184, 0.55);
				padding: 0.55rem 0.75rem;
				font-family: inherit;
				font-size: 0.95rem;
				background: #fff;
				transition: border-color 0.15s ease, box-shadow 0.15s ease;
			}

			.decision-form input:focus {
				outline: none;
				border-color: var(--accent);
				box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
			}

			.button-row {
				display: flex;
				gap: 0.5rem;
			}

			.button-row button {
				flex: 1;
				border: none;
				border-radius: 0.75rem;
				padding: 0.55rem 0.6rem;
				font-weight: 600;
				cursor: pointer;
				font-family: inherit;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.button-row button:hover {
				transform: translateY(-1px);
				box-shadow: 0 18px 25px rgba(15, 23, 42, 0.12);
			}

			button.approve {
				background: var(--accent);
				color: #fff;
			}

			button.reject {
				background: var(--danger);
				color: #fff;
			}

			.empty-state {
				margin: 0;
				padding: 1rem;
				text-align: center;
				border: 1px dashed rgba(148, 163, 184, 0.5);
				border-radius: 1rem;
				color: var(--muted);
			}

			@media (max-width: 768px) {
				.button-row {
					flex-direction: column;
				}
			}

			@media (max-width: 640px) {
				header .banner {
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
				<h1><?php echo h($pageTitle); ?></h1>
				<div class="banner-actions">
					<label class="search-bar" aria-label="Search the platform">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-hidden="true">
							<path d="M11 4a7 7 0 1 1 0 14 7 7 0 0 1 0-14zm0-2a9 9 0 1 0 5.9 15.7l4.2 4.2 1.4-1.4-4.2-4.2A9 9 0 0 0 11 2z" />
						</svg>
						<input type="search" placeholder="Search" />
					</label>
					<a class="icon-button" href="manager.php" aria-label="Home">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-hidden="true">
							<path d="M12 3 2 11h2v9h6v-6h4v6h6v-9h2L12 3z" />
						</svg>
					</a>
					<button class="icon-button" aria-label="Notifications">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-hidden="true">
							<path d="M12 3a6 6 0 0 0-6 6v3.6l-1.6 2.7A1 1 0 0 0 5.3 17H18.7a1 1 0 0 0 .9-1.7L18 12.6V9a6 6 0 0 0-6-6zm0 19a3 3 0 0 0 3-3H9a3 3 0 0 0 3 3z" />
						</svg>
					</button>
					<details class="profile-menu">
						<summary class="icon-button" aria-label="Profile menu" role="button">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-hidden="true">
								<path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-3.3 0-9 1.7-9 5v2h18v-2c0-3.3-5.7-5-9-5z" />
							</svg>
						</summary>
						<div class="profile-dropdown">
							<p class="profile-name"><?php echo h($userFullName); ?></p>
							<p class="profile-role"><?php echo h($roleDisplay); ?></p>
							<form class="logout-form" method="post" action="logout.php">
								<input type="hidden" name="csrf_token" value="<?php echo h($logoutToken); ?>" />
								<input type="hidden" name="redirect_to" value="login.php" />
								<button type="submit">Log Out</button>
							</form>
						</div>
					</details>
				</div>
			</div>
		</header>
		<main>
			<!-- Intro text for the approvals workflow. -->
			<section class="intro">
				<h2>Review Booking Requests</h2>
				<p>
					Work through pending approvals, surface context from the waitlist, and keep every machine ready for the next shift.
					Confirm openings in seconds, align with maintenance windows, and keep disruptions from reaching the floor.
				</p>
			</section>
			<!-- Feedback after an approve/reject action. -->
			<?php if ($decisionFeedback !== ''): ?>
				<div class="alert <?php echo h($decisionFeedbackType !== '' ? $decisionFeedbackType : 'info'); ?>">
					<?php echo h($decisionFeedback); ?>
				</div>
			<?php endif; ?>
			<!-- Pending bookings table with action forms. -->
			<section class="panel">
				<h2>Pending Direct Bookings</h2>
				<p class="helper">Requests already on the ledger that still need a green light.</p>
				<?php if ($pendingBookingsError !== ''): ?>
					<p class="empty-state"><?php echo h($pendingBookingsError); ?></p>
				<?php elseif (empty($pendingBookings)): ?>
					<p class="empty-state">No direct bookings are waiting for your approval.</p>
				<?php else: ?>
					<div class="table-wrapper">
						<table>
							<thead>
								<tr>
									<th>Requester</th>
									<th>Machine</th>
									<th>Date</th>
									<th>Time</th>
									<th>Purpose</th>
									<th>Decision</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($pendingBookings as $booking): ?>
									<tr>
										<td>
											<strong><?php echo h($booking['full_name']); ?></strong><br />
											<span class="meta-muted"><?php echo h(mask_sensitive_identifier($booking['tp_admin_no'] ?? '')); ?></span>
										</td>
										<td>
											<?php echo h($booking['equipment_name']); ?><br />
											<span class="meta-muted"><?php echo h($booking['equipment_category']); ?></span>
										</td>
										<td><?php echo h(date('M j, Y', strtotime($booking['start_time']))); ?></td>
										<td><?php echo h(date('g:i A', strtotime($booking['start_time']))); ?> → <?php echo h(date('g:i A', strtotime($booking['end_time']))); ?></td>
										<td>
											<?php if (trim((string) $booking['purpose']) !== ''): ?>
												<p class="purpose"><?php echo h($booking['purpose']); ?></p>
											<?php else: ?>
												<span class="muted-text">No purpose supplied</span>
											<?php endif; ?>
										</td>
										<td>
											<form class="decision-form" method="post" action="">
												<input type="hidden" name="form_context" value="direct-booking" />
												<input type="hidden" name="csrf_token" value="<?php echo h($decisionCsrfToken); ?>" />
												<input type="hidden" name="booking_id" value="<?php echo (int) $booking['booking_id']; ?>" />
												<input type="text" name="rejection_reason" placeholder="Reason if rejecting" />
												<div class="button-row">
													<button type="submit" name="booking_action" value="approve" class="approve">Approve</button>
													<button type="submit" name="booking_action" value="reject" class="reject">Reject</button>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>

			<!-- Waitlist overview table. -->
			<section class="panel">
				<h2>Waitlist Queue</h2>
				<p class="helper">Entries still waiting for an open slot. Promote or reject with context.</p>
				<?php if ($waitlistError !== ''): ?>
					<p class="empty-state"><?php echo h($waitlistError); ?></p>
				<?php elseif (empty($waitlistEntries)): ?>
					<p class="empty-state">The waitlist is clear.</p>
				<?php else: ?>
					<div class="table-wrapper">
						<table>
							<thead>
								<tr>
									<th>Requester</th>
									<th>Machine</th>
									<th>Desired Slot</th>
									<th>Duration</th>
									<th>Notes</th>
									<th>Status</th>
									<th>Decision</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($waitlistEntries as $entry): ?>
									<tr>
										<td>
											<strong><?php echo h($entry['full_name']); ?></strong><br />
											<span class="meta-muted"><?php echo h(mask_sensitive_identifier($entry['tp_admin_no'] ?? '')); ?></span>
										</td>
										<td>
											<?php echo h($entry['equipment_name']); ?><br />
											<span class="meta-muted"><?php echo h($entry['equipment_category']); ?></span>
										</td>
										<td><?php echo h(date('M j, Y g:i A', strtotime($entry['desired_start']))); ?> → <?php echo h(date('g:i A', strtotime($entry['desired_end']))); ?></td>
										<td><?php echo h((string) ($entry['duration_minutes'] ?? 0)); ?> mins</td>
										<td>
											<?php if (trim((string) $entry['note']) !== ''): ?>
												<?php echo h($entry['note']); ?>
											<?php else: ?>
												<span class="muted-text">No extra notes</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="status-chip waiting">Pending</span>
										</td>
										<td>
											<form class="decision-form">
												<input type="text" placeholder="Reason if rejecting" />
												<div class="button-row">
													<button type="button" class="reject">Reject</button>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>
		</main>
	</body>
</html>
