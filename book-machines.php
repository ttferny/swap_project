<?php declare(strict_types=1);
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current user and capture profile context.
$currentUser = require_login();
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Guest User';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'User'));
// CSRF token for logout action.
$logoutToken = generate_csrf_token('logout_form');
$currentUserId = isset($currentUser['user_id']) ? (int) $currentUser['user_id'] : null;
// Message buckets for booking and cancellation flows.
$bookingMessages = [
	'success' => [],
	'error' => [],
];
$cancelMessages = [
	'success' => [],
	'error' => [],
];
// Allowed booking duration options.
$durationOptions = [
	'30' => '30 minutes',
	'60' => '1 hour',
	'90' => '1 hour 30 minutes',
	'120' => '2 hours',
	'150' => '2 hours 30 minutes',
	'180' => '3 hours',
];

// Default form values for the booking form.
$formValues = [
	'machine_id' => '',
	'date' => '',
	'time' => '',
	'duration' => '60',
	'notes' => '',
];

// Equipment and certification lookup data.
$equipment = [];
$equipmentById = [];
$equipmentError = null;
$certEligibilityError = null;
$certsByEquipment = [];
$userCertsById = [];
$certifiedEquipmentIds = [];
$certifiedEquipment = [];

// Load equipment inventory for selection and availability.
$equipmentResult = mysqli_query(
	$conn,
	'SELECT equipment_id, name, current_status, location FROM equipment ORDER BY name ASC'
);
if ($equipmentResult === false) {
	$equipmentError = 'Unable to load equipment data: ' . mysqli_error($conn);
} else {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = $row['equipment_id'] ?? ($row['id'] ?? null);
		$name = $row['machine_name'] ?? $row['name'] ?? $row['equipment_name'] ?? null;
		if ($name === null) {
			$firstValue = null;
			foreach ($row as $value) {
				if (is_string($value) && $value !== '') {
					$firstValue = $value;
					break;
				}
			}
			// Fall back to the first non-empty string if no canonical machine name column exists.
			$name = $firstValue ?? 'Unnamed Machine';
		}

		$status = strtolower((string) ($row['current_status'] ?? 'operational'));
		$location = trim((string) ($row['location'] ?? ''));

		$equipment[] = [
			'id' => $equipmentId,
			'name' => $name,
			'current_status' => $status,
			'location' => $location,
		];
		if ($equipmentId !== null) {
			$equipmentById[(string) $equipmentId] = [
				'name' => $name,
				'current_status' => $status,
				'location' => $location,
			];
		}
	}

	mysqli_free_result($equipmentResult);
}

// Load equipment certification requirements.
$certsResult = mysqli_query(
	$conn,
	'SELECT equipment_id, cert_id FROM equipment_required_certs'
);
if ($certsResult === false) {
	$certEligibilityError = 'Unable to verify certification requirements right now.';
} else {
	while ($row = mysqli_fetch_assoc($certsResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		$certId = (int) ($row['cert_id'] ?? 0);
		if ($equipmentId > 0 && $certId > 0) {
			$certsByEquipment[$equipmentId][] = $certId;
		}
	}
	mysqli_free_result($certsResult);
}

// Load current user certifications to filter eligible equipment.
if ($currentUserId !== null) {
	$userCertStmt = mysqli_prepare(
		$conn,
		'SELECT cert_id, status, expires_at FROM user_certifications WHERE user_id = ?'
	);
	if ($userCertStmt !== false) {
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
					'status' => (string) ($row['status'] ?? ''),
					'expires_at' => $row['expires_at'] ?? null,
				];
			}
			mysqli_free_result($userCertResult);
		}
		mysqli_stmt_close($userCertStmt);
	} else {
		$certEligibilityError = $certEligibilityError ?? 'Unable to verify certifications right now.';
	}
}

// Build the list of equipment the user is certified to book.
if ($certEligibilityError === null && !empty($equipment)) {
	foreach ($equipment as $machine) {
		$equipmentId = (int) ($machine['id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$requiredCerts = $certsByEquipment[$equipmentId] ?? [];
		$isCertified = true;
		if (!empty($requiredCerts)) {
			foreach ($requiredCerts as $certId) {
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
		if ($isCertified) {
			$certifiedEquipmentIds[$equipmentId] = true;
			$certifiedEquipment[] = $machine;
		}
	}
}

// Truncate audit log fields to safe lengths.
function truncateAuditText(?string $value, int $limit): string
{
	$value = (string) $value;
	if ($limit <= 0) {
		return '';
	}
	$lengthFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
	$substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
	if ($lengthFn($value) > $limit) {
		return $substrFn($value, 0, $limit);
	}
	return $value;
}

// Write audit log entries for booking actions.
function logAuditEntry(mysqli $conn, ?int $actorId, string $action, string $entityType, ?int $entityId, array $details = []): void
{
	static $cachedIp = null;
	static $cachedAgent = null;
	$action = truncateAuditText($action, 80);
	$entityType = truncateAuditText($entityType, 40);
	if ($cachedIp === null) {
		$rawIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$cachedIp = $rawIp !== '' ? substr($rawIp, 0, 45) : null;
	}
	if ($cachedAgent === null) {
		$rawAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$cachedAgent = $rawAgent !== '' ? truncateAuditText($rawAgent, 255) : null;
	}
	$detailsJson = null;
	if (!empty($details)) {
		$json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json !== false) {
			$detailsJson = $json;
		}
	}
	$stmt = mysqli_prepare(
		$conn,
		'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
	);
	if ($stmt) {
		mysqli_stmt_bind_param($stmt, 'ississs', $actorId, $action, $entityType, $entityId, $cachedIp, $cachedAgent, $detailsJson);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
	}
}

// Handle booking submissions and cancellation requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$formType = $_POST['form_type'] ?? 'submit_booking';
	if ($formType === 'cancel_booking') {
		$bookingIdRaw = trim((string) ($_POST['booking_id'] ?? ''));
		if ($currentUserId === null) {
			$cancelMessages['error'][] = 'Please sign in before cancelling a booking.';
		} elseif ($bookingIdRaw === '' || !ctype_digit($bookingIdRaw)) {
			$cancelMessages['error'][] = 'Invalid booking reference.';
		} else {
			$bookingId = (int) $bookingIdRaw;
			$startTimeStmt = mysqli_prepare(
				$conn,
				"SELECT start_time FROM bookings WHERE booking_id = ? AND requester_id = ? AND status IN ('pending', 'approved') LIMIT 1"
			);
			if (!$startTimeStmt) {
				$cancelMessages['error'][] = 'Unable to process cancellation right now.';
			} else {
				mysqli_stmt_bind_param($startTimeStmt, 'ii', $bookingId, $currentUserId);
				mysqli_stmt_execute($startTimeStmt);
				$startResult = mysqli_stmt_get_result($startTimeStmt);
				$startRow = $startResult ? mysqli_fetch_assoc($startResult) : null;
				if ($startResult) {
					mysqli_free_result($startResult);
				}
				mysqli_stmt_close($startTimeStmt);
				if (!$startRow || empty($startRow['start_time'])) {
					$cancelMessages['error'][] = 'Unable to cancel that booking. It may already be processed.';
				} else {
					try {
						$bookingStart = new DateTimeImmutable((string) $startRow['start_time']);
						$cutoff = $bookingStart->modify('-2 days');
						$now = new DateTimeImmutable('now');
						if ($now >= $cutoff) {
							$cancelMessages['error'][] = 'Bookings cannot be cancelled within 2 days of the start time.';
						} else {
							$cancelStmt = mysqli_prepare(
								$conn,
								"UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, updated_at = NOW() WHERE booking_id = ? AND requester_id = ? AND status IN ('pending', 'approved')"
							);
							if ($cancelStmt) {
								mysqli_stmt_bind_param($cancelStmt, 'iii', $currentUserId, $bookingId, $currentUserId);
								mysqli_stmt_execute($cancelStmt);
								if (mysqli_stmt_affected_rows($cancelStmt) === 1) {
									logAuditEntry(
										$conn,
										$currentUserId,
										'booking_cancelled',
										'bookings',
										$bookingId,
										['cancelled_by' => $currentUserId]
									);
									$cancelMessages['success'][] = 'Booking cancelled successfully. Waitlisted requests will be notified automatically.';
									promoteMatchingWaitlist($conn, $bookingId, $currentUserId);
								} else {
									$cancelMessages['error'][] = 'Unable to cancel that booking. It may already be processed.';
								}
								mysqli_stmt_close($cancelStmt);
							} else {
								$cancelMessages['error'][] = 'Unable to process cancellation right now.';
							}
						}
					} catch (Exception $exception) {
						$cancelMessages['error'][] = 'Unable to process cancellation right now.';
					}
				}
			}
		}
	} else {
		$formValues['machine_id'] = trim((string) ($_POST['machine_id'] ?? ''));
		$formValues['date'] = trim((string) ($_POST['booking_date'] ?? ''));
		$formValues['time'] = trim((string) ($_POST['booking_time'] ?? ''));
		$formValues['duration'] = trim((string) ($_POST['booking_duration'] ?? $formValues['duration']));
		$formValues['notes'] = trim((string) ($_POST['booking_notes'] ?? ''));
		$formValues['notes'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $formValues['notes']);

		if ($currentUserId === null) {
			$bookingMessages['error'][] = 'Please sign in before sending a booking request.';
		}

		if ($formValues['machine_id'] === '' || !ctype_digit($formValues['machine_id']) || !isset($equipmentById[$formValues['machine_id']])) {
			$bookingMessages['error'][] = 'Select a valid machine to continue.';
		}

		$selectedMachineId = (int) $formValues['machine_id'];
		$selectedMachineKey = (string) $formValues['machine_id'];
		if ($selectedMachineId > 0 && isset($equipmentById[$selectedMachineKey])) {
			$selectedStatus = strtolower((string) ($equipmentById[$selectedMachineKey]['current_status'] ?? 'operational'));
			if ($selectedStatus !== 'operational') {
				$bookingMessages['error'][] = 'This machine is currently ' . $selectedStatus . ' and cannot be booked right now.';
			}
		}
		if ($certEligibilityError !== null) {
			$bookingMessages['error'][] = 'Unable to verify certifications right now. Please try again later.';
		} elseif ($selectedMachineId > 0 && empty($certifiedEquipmentIds[$selectedMachineId])) {
			$bookingMessages['error'][] = 'You must complete all required certifications to book this machine.';
		}

		if ($formValues['date'] === '') {
			$bookingMessages['error'][] = 'Pick a booking date.';
		} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formValues['date'])) {
			$bookingMessages['error'][] = 'Enter a valid booking date (YYYY-MM-DD).';
		}

		if ($formValues['time'] === '') {
			$bookingMessages['error'][] = 'Enter a preferred start time.';
		} elseif (!preg_match('/^\d{2}:\d{2}$/', $formValues['time'])) {
			$bookingMessages['error'][] = 'Enter a valid start time (HH:MM).';
		}

		if (!isset($durationOptions[$formValues['duration']])) {
			$bookingMessages['error'][] = 'Select a valid duration option.';
		}

		$noteLength = function_exists('mb_strlen') ? mb_strlen($formValues['notes']) : strlen($formValues['notes']);
		if ($noteLength > 255) {
			$bookingMessages['error'][] = 'Notes must be 255 characters or fewer.';
		}

		$bookingStart = null;
		$bookingEnd = null;
		if (empty($bookingMessages['error'])) {
			$bookingStart = DateTime::createFromFormat('Y-m-d H:i', $formValues['date'] . ' ' . $formValues['time']);
			$errors = DateTime::getLastErrors();
			if ($bookingStart === false || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
				$bookingMessages['error'][] = 'Enter a valid booking date and time.';
			} else {
				$bookingEnd = clone $bookingStart;
				$bookingEnd->modify('+' . (int) $formValues['duration'] . ' minutes');
				if ($bookingEnd <= $bookingStart) {
					$bookingMessages['error'][] = 'End time must be after the start time.';
				}
				$now = new DateTimeImmutable('now');
				if ($bookingStart <= $now) {
					$bookingMessages['error'][] = 'Booking start time must be in the future.';
				}
			}
		}

		if (empty($bookingMessages['error']) && $bookingStart && $bookingEnd) {
			$equipmentIdInt = (int) $formValues['machine_id'];
			$desiredStartStr = $bookingStart->format('Y-m-d H:i:s');
			$desiredEndStr = $bookingEnd->format('Y-m-d H:i:s');
			$noteParam = $formValues['notes'] === '' ? 'Booking submitted via portal.' : $formValues['notes'];
			$noteParam = function_exists('mb_substr') ? mb_substr($noteParam, 0, 255) : substr($noteParam, 0, 255);

			$conflictStmt = mysqli_prepare(
				$conn,
				"SELECT booking_id FROM bookings WHERE equipment_id = ? AND status IN ('pending', 'approved') AND start_time < ? AND end_time > ? LIMIT 1"
			);
			if ($conflictStmt === false) {
				$bookingMessages['error'][] = 'Could not verify equipment availability.';
			} else {
				mysqli_stmt_bind_param($conflictStmt, 'iss', $equipmentIdInt, $desiredEndStr, $desiredStartStr);
				mysqli_stmt_execute($conflictStmt);
				mysqli_stmt_store_result($conflictStmt);
				$hasConflict = mysqli_stmt_num_rows($conflictStmt) > 0;
				mysqli_stmt_close($conflictStmt);

				if ($hasConflict) {
					$waitlistSql = "INSERT INTO booking_waitlist (equipment_id, user_id, desired_start, desired_end, note) VALUES (?, ?, ?, ?, NULLIF(?, ''))";
					$waitlistStmt = mysqli_prepare($conn, $waitlistSql);
					if ($waitlistStmt === false) {
						$bookingMessages['error'][] = 'Unable to add you to the waitlist right now.';
					} else {
						$waitlistNoteParam = function_exists('mb_substr') ? mb_substr($formValues['notes'], 0, 255) : substr($formValues['notes'], 0, 255);
						mysqli_stmt_bind_param(
							$waitlistStmt,
							'iisss',
							$equipmentIdInt,
							$currentUserId,
							$desiredStartStr,
							$desiredEndStr,
							$waitlistNoteParam
						);
						if (mysqli_stmt_execute($waitlistStmt)) {
							$waitlistId = mysqli_insert_id($conn) ?: null;
							logAuditEntry(
								$conn,
								$currentUserId,
								'waitlist_created',
								'booking_waitlist',
								$waitlistId,
								[
									'equipment_id' => $equipmentIdInt,
									'from_booking_id' => null,
									'desired_start' => $desiredStartStr,
									'desired_end' => $desiredEndStr,
								]
							);
							$bookingMessages['success'][] = 'That slot is currently booked. Your request is pending on the waitlist, and we will notify you if it opens up.';
							$formValues = [
								'machine_id' => '',
								'date' => '',
								'time' => '',
								'duration' => '60',
								'notes' => '',
							];
						} else {
							$bookingMessages['error'][] = 'We could not add you to the waitlist. Please try again.';
						}
						mysqli_stmt_close($waitlistStmt);
					}
				} else {
					$bookingSql = "INSERT INTO bookings (equipment_id, requester_id, start_time, end_time, purpose, status, requires_approval) VALUES (?, ?, ?, ?, ?, 'pending', 1)";
					$bookingStmt = mysqli_prepare($conn, $bookingSql);
					if ($bookingStmt === false) {
						$bookingMessages['error'][] = 'Unable to submit your booking. Please try again shortly.';
					} else {
						mysqli_stmt_bind_param(
							$bookingStmt,
							'iisss',
							$equipmentIdInt,
							$currentUserId,
							$desiredStartStr,
							$desiredEndStr,
							$noteParam
						);
						if (mysqli_stmt_execute($bookingStmt)) {
							$newBookingId = mysqli_insert_id($conn) ?: null;
							logAuditEntry(
								$conn,
								$currentUserId,
								'booking_created',
								'bookings',
								$newBookingId,
								[
									'equipment_id' => $equipmentIdInt,
									'purpose' => $noteParam,
									'origin' => 'portal_booking_form',
								]
							);
							$bookingMessages['success'][] = 'Booking submitted and awaiting manager approval.';
							$formValues = [
								'machine_id' => '',
								'date' => '',
								'time' => '',
								'duration' => '60',
								'notes' => '',
							];
						} else {
							$bookingMessages['error'][] = 'We could not save your booking request. Please try again.';
						}
						mysqli_stmt_close($bookingStmt);
					}
				}
			}
		}
	}
}

// CSRF tokens for booking and cancel actions.
$bookingCsrfToken = generate_csrf_token('book_machine_submit');
$cancelCsrfToken = generate_csrf_token('book_machine_cancel');

// Load current user's bookings and availability data.
$userBookings = [];
$userBookingsError = null;
$availabilityCalendar = [];
$availabilityCalendarError = null;
$maintenanceMachines = [];
$maintenanceMachinesError = null;
if ($currentUserId !== null) {
	$userBookingsSql = 'SELECT b.booking_id, b.start_time, b.end_time, b.status, b.rejection_reason, e.name AS equipment_name FROM bookings b INNER JOIN equipment e ON e.equipment_id = b.equipment_id WHERE b.requester_id = ? ORDER BY b.start_time ASC';
	$userBookingsStmt = mysqli_prepare($conn, $userBookingsSql);
	if ($userBookingsStmt === false) {
		$userBookingsError = 'Unable to prepare the bookings query.';
	} else {
		mysqli_stmt_bind_param($userBookingsStmt, 'i', $currentUserId);
		if (!mysqli_stmt_execute($userBookingsStmt)) {
			$userBookingsError = 'Unable to load your bookings right now.';
		} else {
			mysqli_stmt_bind_result(
				$userBookingsStmt,
				$bookingId,
				$startTime,
				$endTime,
				$status,
				$rejectionReason,
				$equipmentName
			);
			while (mysqli_stmt_fetch($userBookingsStmt)) {
				$userBookings[] = [
					'booking_id' => $bookingId,
					'start_time' => $startTime,
					'end_time' => $endTime,
					'status' => $status,
					'rejection_reason' => $rejectionReason,
					'equipment_name' => $equipmentName,
				];
			}
		}
		mysqli_stmt_close($userBookingsStmt);
	}
}

$flaggedBookingIds = [];
foreach ($userBookings as $booking) {
	if (strtolower((string) ($booking['status'] ?? '')) === 'flagged' && !empty($booking['booking_id'])) {
		$flaggedBookingIds[] = (int) $booking['booking_id'];
	}
}

// Ensure flagged bookings are logged to the audit trail.
if (!empty($flaggedBookingIds)) {
	$checkStmt = mysqli_prepare(
		$conn,
		'SELECT 1 FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ? LIMIT 1'
	);
	if ($checkStmt) {
		foreach ($flaggedBookingIds as $flaggedBookingId) {
			$action = 'booking_flagged';
			$entityType = 'bookings';
			mysqli_stmt_bind_param($checkStmt, 'ssi', $action, $entityType, $flaggedBookingId);
			mysqli_stmt_execute($checkStmt);
			$checkResult = mysqli_stmt_get_result($checkStmt);
			$alreadyLogged = $checkResult ? mysqli_fetch_assoc($checkResult) !== null : false;
			if ($checkResult) {
				mysqli_free_result($checkResult);
			}
			if (!$alreadyLogged) {
				logAuditEntry(
					$conn,
					null,
					'booking_flagged',
					'bookings',
					$flaggedBookingId,
					['requester_id' => $currentUserId]
				);
			}
		}
		mysqli_stmt_close($checkStmt);
	}
}

// Availability calendar for upcoming bookings.
$calendarSql = "SELECT b.start_time, b.end_time, e.name AS equipment_name
	FROM bookings b
	INNER JOIN equipment e ON e.equipment_id = b.equipment_id
	WHERE b.status IN ('pending', 'approved')
	ORDER BY b.start_time ASC";
$calendarResult = mysqli_query($conn, $calendarSql);
if ($calendarResult === false) {
	$availabilityCalendarError = 'Unable to load the availability calendar right now.';
} else {
	while ($row = mysqli_fetch_assoc($calendarResult)) {
		$startTime = (string) ($row['start_time'] ?? '');
		$endTime = (string) ($row['end_time'] ?? '');
		$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
		if ($startTime === '' || $endTime === '') {
			continue;
		}
		$dateKey = date('Y-m-d', strtotime($startTime));
		if (!isset($availabilityCalendar[$dateKey])) {
			$availabilityCalendar[$dateKey] = [];
		}
		$availabilityCalendar[$dateKey][] = [
			'equipment_name' => $equipmentName !== '' ? $equipmentName : 'Unnamed equipment',
			'start_time' => $startTime,
			'end_time' => $endTime,
		];
	}
	mysqli_free_result($calendarResult);
}

// Active maintenance list for booking awareness.
$maintenanceSql = "SELECT DISTINCT e.name AS equipment_name
	FROM maintenance_tasks mt
	INNER JOIN equipment e ON e.equipment_id = mt.equipment_id
	WHERE mt.status = 'in_progress'
	ORDER BY e.name ASC";
$maintenanceResult = mysqli_query($conn, $maintenanceSql);
if ($maintenanceResult === false) {
	$maintenanceMachinesError = 'Unable to load maintenance status right now.';
} else {
	while ($row = mysqli_fetch_assoc($maintenanceResult)) {
		$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
		if ($equipmentName === '') {
			$equipmentName = 'Unnamed equipment';
		}
		$maintenanceMachines[] = $equipmentName;
	}
	mysqli_free_result($maintenanceResult);
}

// Promote a matching waitlist entry when a booking is cancelled.
function promoteMatchingWaitlist(mysqli $conn, int $bookingId, ?int $actorId = null): void
{
	$bookingStmt = mysqli_prepare(
		$conn,
		' SELECT equipment_id, start_time, end_time FROM bookings WHERE booking_id = ? LIMIT 1 '
	);
	if (!$bookingStmt) {
		return;
	}
	mysqli_stmt_bind_param($bookingStmt, 'i', $bookingId);
	mysqli_stmt_execute($bookingStmt);
	$result = mysqli_stmt_get_result($bookingStmt);
	$bookingRow = $result ? mysqli_fetch_assoc($result) : null;
	if ($result) {
		mysqli_free_result($result);
	}
	mysqli_stmt_close($bookingStmt);
	if (!$bookingRow) {
		return;
	}
	$equipmentId = (int) $bookingRow['equipment_id'];
	$startTime = $bookingRow['start_time'];
	$endTime = $bookingRow['end_time'];
	if ($equipmentId <= 0 || $startTime === null || $endTime === null) {
		return;
	}
	$waitlistStmt = mysqli_prepare(
		$conn,
		' SELECT waitlist_id, user_id, note FROM booking_waitlist WHERE equipment_id = ? AND desired_start = ? AND desired_end = ? ORDER BY created_at ASC LIMIT 1 '
	);
	if (!$waitlistStmt) {
		return;
	}
	mysqli_stmt_bind_param($waitlistStmt, 'iss', $equipmentId, $startTime, $endTime);
	mysqli_stmt_execute($waitlistStmt);
	$waitlistResult = mysqli_stmt_get_result($waitlistStmt);
	$waitlistEntry = $waitlistResult ? mysqli_fetch_assoc($waitlistResult) : null;
	if ($waitlistResult) {
		mysqli_free_result($waitlistResult);
	}
	mysqli_stmt_close($waitlistStmt);
	if (!$waitlistEntry) {
		return;
	}
	$slotCheckStmt = mysqli_prepare(
		$conn,
		"SELECT 1 FROM bookings WHERE equipment_id = ? AND status IN ('pending', 'approved') AND start_time < ? AND end_time > ? LIMIT 1"
	);
	if ($slotCheckStmt) {
		mysqli_stmt_bind_param($slotCheckStmt, 'iss', $equipmentId, $endTime, $startTime);
		mysqli_stmt_execute($slotCheckStmt);
		mysqli_stmt_store_result($slotCheckStmt);
		$slotTaken = mysqli_stmt_num_rows($slotCheckStmt) > 0;
		mysqli_stmt_close($slotCheckStmt);
		if ($slotTaken) {
			return;
		}
	}
	$purpose = trim((string) ($waitlistEntry['note'] ?? ''));
	if ($purpose === '') {
		$purpose = 'Auto-promoted from waitlist.';
	}
	$purpose = function_exists('mb_substr') ? mb_substr($purpose, 0, 255) : substr($purpose, 0, 255);
	mysqli_begin_transaction($conn);
	$newBookingId = null;
	$insertStmt = mysqli_prepare(
		$conn,
		"INSERT INTO bookings (equipment_id, requester_id, start_time, end_time, purpose, status, requires_approval) VALUES (?, ?, ?, ?, ?, 'pending', 1)"
	);
	if (!$insertStmt) {
		mysqli_rollback($conn);
		return;
	}
	$requesterId = (int) $waitlistEntry['user_id'];
	mysqli_stmt_bind_param(
		$insertStmt,
		'iisss',
		$equipmentId,
		$requesterId,
		$startTime,
		$endTime,
		$purpose
	);
	if (!mysqli_stmt_execute($insertStmt)) {
		mysqli_stmt_close($insertStmt);
		mysqli_rollback($conn);
		return;
	}
	$newBookingId = mysqli_insert_id($conn) ?: null;
	mysqli_stmt_close($insertStmt);
	$deleteStmt = mysqli_prepare($conn, 'DELETE FROM booking_waitlist WHERE waitlist_id = ?');
	if (!$deleteStmt) {
		mysqli_rollback($conn);
		return;
	}
	$waitlistId = (int) $waitlistEntry['waitlist_id'];
	mysqli_stmt_bind_param($deleteStmt, 'i', $waitlistId);
	if (!mysqli_stmt_execute($deleteStmt)) {
		mysqli_stmt_close($deleteStmt);
		mysqli_rollback($conn);
		return;
	}
	mysqli_stmt_close($deleteStmt);
	mysqli_commit($conn);
	logAuditEntry(
		$conn,
		$actorId,
		'booking_created_from_waitlist',
		'bookings',
		$newBookingId,
		[
			'equipment_id' => $equipmentId,
			'waitlist_id' => $waitlistId,
			'desired_start' => $startTime,
			'desired_end' => $endTime,
		]
	);
	logAuditEntry(
		$conn,
		$actorId,
		'waitlist_removed',
		'booking_waitlist',
			$waitlistId,
		[
			'reason' => 'promoted_to_booking',
			'moved_booking_id' => $newBookingId,
		]
	);
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Equipment Booking</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<!-- Base styles for the booking portal. -->
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

			/* Shared header/search layout (matches index.html) */
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

			.top-nav {
				display: flex;
				gap: 0.75rem;
				align-items: center;
				flex-wrap: wrap;
				margin: 0;
				padding: 0;
				list-style: none;
			}

			.top-nav a {
				display: inline-flex;
				align-items: center;
				gap: 0.35rem;
				padding: 0.45rem 0.85rem;
				border-radius: 0.75rem;
				background: #e2e8f0;
				color: #0f172a;
				font-weight: 600;
				text-decoration: none;
				transition: background 0.2s ease, color 0.2s ease;
			}

			.top-nav a:hover,
			.top-nav a:focus-visible {
				background: #cbd5e1;
				outline: none;
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

			main {
				padding: clamp(2rem, 5vw, 4rem);
				max-width: 1280px;
				margin: 0 auto;
			}

			.hero {
				max-width: 720px;
				margin-bottom: 2rem;
			}

			.hero p {
				color: var(--muted);
				line-height: 1.6;
			}

			.booking-panel {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
				gap: 1.5rem;
				align-items: start;
			}

			.card {
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(76, 81, 191, 0.08);
				height: 100%;
			}

			.card h2 {
				margin-top: 0;
				font-size: 1.1rem;
			}

			.card p {
				color: var(--muted);
				margin-bottom: 1rem;
			}

			.card form {
				display: grid;
				gap: 0.75rem;
			}

			.calendar-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
				gap: 1rem;
				margin-bottom: 1.5rem;
			}

			.calendar-day {
				background: var(--accent-soft);
				border-radius: 0.8rem;
				padding: 0.75rem;
				border: 1px solid #d7def0;
			}

			.calendar-day h3 {
				margin: 0 0 0.35rem;
				font-size: 0.95rem;
			}

			.calendar-day ul,
			.maintenance-list ul {
				list-style: none;
				padding: 0;
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.calendar-day li + li,
			.maintenance-list li + li {
				margin-top: 0.35rem;
			}

			.maintenance-list h3 {
				margin: 0 0 0.4rem;
				font-size: 0.95rem;
			}

			.table-wrapper {
				overflow-x: auto;
				max-width: 100%;
			}

			.table-wrapper.compact {
				margin-top: 1rem;
			}

			.data-table {
				width: 100%;
				border-collapse: collapse;
			}

			.data-table th,
			.data-table td {
				font-size: 0.9rem;
				text-align: left;
				padding: 0.7rem 0.4rem;
				border-bottom: 1px solid #e2e8f0;
			}

			.data-table th {
				color: var(--muted);
				font-weight: 600;
			}

			.data-table tr:last-child td {
				border-bottom: none;
			}

			.table-actions {
				text-align: right;
			}

			.status-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 0.15rem 0.65rem;
				min-width: 110px;
				white-space: nowrap;
				border-radius: 999px;
				font-size: 0.8rem;
				font-weight: 600;
			}

			.status-available {
				background: #dcfce7;
				color: #166534;
			}

			.status-maintenance {
				background: #fef9c3;
				color: #854d0e;
			}

			.status-downtime {
				background: #fee2e2;
				color: #b91c1c;
			}

			.rejection-reason {
				margin-top: 0.35rem;
				font-size: 0.85rem;
				color: #991b1b;
				line-height: 1.3;
			}

			.status-pending {
				background: var(--accent-soft);
				color: var(--accent);
			}

			.status-approved {
				background: #dcfce7;
				color: #166534;
			}

			.status-cancelled,
			.status-rejected {
				background: #fee2e2;
				color: #991b1b;
			}

			.status-over {
				background: #e2e8f0;
				color: #334155;
			}

			button.ghost {
				padding: 0.35rem 0.75rem;
				border-radius: 0.6rem;
				border: 1px solid #cbd5f5;
				background: transparent;
				font-weight: 600;
				cursor: pointer;
			}

			button.ghost.danger {
				border-color: #fda4af;
				color: #be123c;
			}

			label span {
				display: block;
				font-size: 0.9rem;
				margin-bottom: 0.2rem;
				color: var(--muted);
			}

			.muted-text {
				color: var(--muted);
				font-size: 0.85rem;
			}

			.placeholder-dash {
				display: block;
				text-align: center;
			}

			.card form input,
			.card form select,
			.card form textarea,
			.card form button {
				font-family: inherit;
				font-size: 1rem;
			}

			.card form input,
			.card form select,
			.card form textarea {
				padding: 0.65rem 0.85rem;
				border-radius: 0.6rem;
				border: 1px solid #d7def0;
				background-color: #fdfdff; width: 100%;
			}

			.card form select {
				-webkit-appearance: none;
				appearance: none;
				padding-right: 2.5rem;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%234361ee' d='M1.41.59 6 5.17 10.59.59 12 2l-6 6-6-6z'/%3E%3C/svg%3E");
				background-repeat: no-repeat;
				background-position: right 0.85rem center;
				background-size: 0.65rem;
			}

			.card form textarea {
				min-height: 110px;
				resize: vertical;
			}

			.alert {
				margin-bottom: 1.5rem;
				padding: 0.85rem 1rem;
				border-radius: 0.8rem;
				background: #fef3c7;
				border: 1px solid #fde68a;
				color: #92400e;
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

			.alert ul {
				margin: 0;
				padding-left: 1.25rem;
			}

			.empty-state {
				margin: 0;
				color: var(--muted);
				font-style: italic;
			}

			.card form input[type="time"]::-webkit-calendar-picker-indicator {
				filter: grayscale(1);
			}

			button.primary {
				padding: 0.75rem 1rem; width: 100%;
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
		<!-- Header with navigation and search. -->
		<header>
			<div class="banner">
				<h1>Welcome to TP AMC's Management System</h1>
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
					<button class="icon-button" aria-label="Profile">
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
					</button>
				</div>
			</div>
		</header>
		<main>
			<!-- Intro text for the booking portal. -->
			<section class="hero">
				<h2>Equipment Booking</h2>
				<p>
					Reserve production machinery before you arrive on site, verify availability
					at a glance, and keep every booking aligned with safety and maintenance
					requirements.
				</p>
			</section>
			<!-- Equipment load alerts and empty state. -->
			<?php if ($equipmentError): ?>
				<div class="alert" role="alert">
					<?php echo htmlspecialchars($equipmentError, ENT_QUOTES); ?>
				</div>
			<?php elseif (empty($equipment)): ?>
				<div class="alert" role="status">
					No equipment records found yet. Add machines to the database to enable bookings.
				</div>
			<?php endif; ?>
			<!-- Booking form, user bookings, and availability panels. -->
			<section class="booking-panel">
				<!-- Booking submission form. -->
				<article class="card">
					<h2>Schedule a Machine</h2>
					<p>Pick the equipment, date, and duration you need.</p>
					<form method="post">
						<input type="hidden" name="form_type" value="submit_booking" />
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($bookingCsrfToken, ENT_QUOTES); ?>" />
						<?php foreach ($bookingMessages['success'] as $message): ?>
							<div class="alert success" role="status">
								<?php echo htmlspecialchars($message, ENT_QUOTES); ?>
							</div>
						<?php endforeach; ?>
						<?php if (!empty($bookingMessages['error'])): ?>
							<div class="alert error" role="alert">
								<ul>
									<?php foreach ($bookingMessages['error'] as $error): ?>
										<li><?php echo htmlspecialchars($error, ENT_QUOTES); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
						<label>
							<span>Machine</span>
							<select name="machine_id" required>
								<?php if (!empty($certifiedEquipment)): ?>
									<option value="" <?php echo $formValues['machine_id'] === '' ? 'selected' : ''; ?>>Select a machine</option>
									<?php foreach ($certifiedEquipment as $machine): ?>
										<?php if (!isset($machine['id']) || $machine['id'] === null) { continue; } ?>
										<?php
											$machineIdValue = (string) $machine['id'];
											$status = strtolower((string) ($machine['current_status'] ?? 'operational'));
											$isDown = in_array($status, ['maintenance', 'faulty'], true);
											$label = $machine['name'];
											if ($status !== 'operational') {
												$label .= ' (' . ucfirst($status) . ')';
											}
										?>
										<option
											value="<?php echo htmlspecialchars($machineIdValue, ENT_QUOTES); ?>"
											<?php echo $formValues['machine_id'] === $machineIdValue ? 'selected' : ''; ?>
											<?php echo $isDown ? 'disabled' : ''; ?>
											data-status="<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"
										>
											<?php echo htmlspecialchars($label, ENT_QUOTES); ?>
										</option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value="">No certified machines available</option>
								<?php endif; ?>
							</select>
						</label>
						<label>
							<span>Date</span>
							<input type="date" name="booking_date" value="<?php echo htmlspecialchars($formValues['date'], ENT_QUOTES); ?>" required />
						</label>
						<label>
							<span>Time</span>
							<input type="time" name="booking_time" value="<?php echo htmlspecialchars($formValues['time'], ENT_QUOTES); ?>" required />
						</label>
						<label>
							<span>Duration</span>
							<select name="booking_duration" required>
								<?php foreach ($durationOptions as $value => $label): ?>
									<option value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES); ?>" <?php echo $formValues['duration'] === (string) $value ? 'selected' : ''; ?>>
										<?php echo htmlspecialchars((string) $label, ENT_QUOTES); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span>Notes (optional)</span>
							<textarea
								name="booking_notes"
								placeholder="Share special requirements, safety context, or handover instructions."><?php echo htmlspecialchars($formValues['notes'], ENT_QUOTES); ?></textarea>
						</label>
						<button type="submit" class="primary">Submit Booking</button>
					</form>
				</article>
				<!-- User booking history and cancellation controls. -->
				<article class="card">
					<h2>Your Bookings</h2>
					<p>Manage your upcoming reservations and cancel slots you no longer need.</p>
					<?php if ($userBookingsError !== null): ?>
						<div class="alert error" role="alert"><?php echo htmlspecialchars($userBookingsError, ENT_QUOTES); ?></div>
					<?php endif; ?>
					<?php foreach ($cancelMessages['success'] as $message): ?>
						<div class="alert success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
					<?php endforeach; ?>
					<?php foreach ($cancelMessages['error'] as $message): ?>
						<div class="alert error" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
					<?php endforeach; ?>
					<?php if ($currentUserId === null): ?>
						<p class="empty-state">Sign in to view and manage your bookings.</p>
					<?php elseif (empty($userBookings)): ?>
						<p class="empty-state">You have no bookings yet.</p>
					<?php else: ?>
						<div class="table-wrapper compact">
							<table class="data-table">
								<thead>
									<tr>
										<th>Machine</th>
										<th>Schedule</th>
										<th>Status</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($userBookings as $booking): ?>
										<?php
											$status = strtolower((string) $booking['status']);
											if ($status === 'flagged') {
												continue;
											}
											$endTimestamp = strtotime($booking['end_time']);
											$isBookingOver = $endTimestamp !== false && $endTimestamp < time() && $status === 'approved';
											$displayStatus = $status === 'flagged' ? 'pending' : $status;
											$statusLabel = ucfirst($displayStatus);
											$statusClass = 'status-' . $displayStatus;
											if ($isBookingOver) {
												$statusLabel = 'Booking over';
												$statusClass = 'status-over';
											}
											$rejectionReason = trim((string) ($booking['rejection_reason'] ?? ''));
										?>
										<tr>
											<td><?php echo htmlspecialchars($booking['equipment_name'], ENT_QUOTES); ?></td>
											<td>
												<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time'])), ENT_QUOTES); ?>
											</td>
											<td>
												<span class="status-badge <?php echo htmlspecialchars($statusClass, ENT_QUOTES); ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?></span>
											</td>
											<td class="table-actions">
												<?php if ($isBookingOver): ?>
													<span class="muted-text placeholder-dash">—</span>
												<?php elseif (in_array($status, ['pending', 'approved'], true)): ?>
													<?php
														$startTimestamp = strtotime($booking['start_time']);
														$cancelLock = $startTimestamp !== false && time() >= ($startTimestamp - (2 * 24 * 60 * 60));
													?>
													<?php if ($cancelLock): ?>
														<span class="muted-text">Cancellation locked (within 2 days)</span>
													<?php else: ?>
													<form method="post">
														<input type="hidden" name="form_type" value="cancel_booking" />
														<input type="hidden" name="booking_id" value="<?php echo (int) $booking['booking_id']; ?>" />
														<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($cancelCsrfToken, ENT_QUOTES); ?>" />
														<button type="submit" class="ghost danger">Cancel</button>
													</form>
													<?php endif; ?>
												<?php else: ?>
													<?php if ($status === 'rejected' && $rejectionReason !== ''): ?>
														<span class="rejection-reason"><?php echo htmlspecialchars($rejectionReason, ENT_QUOTES); ?></span>
													<?php else: ?>
														<span class="muted-text placeholder-dash">—</span>
													<?php endif; ?>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</article>
				<!-- Availability calendar and maintenance list. -->
				<article class="card">
					<h2>Availability Calendar</h2>
					<p>Unavailable machines</p>
					<?php if ($availabilityCalendarError !== null): ?>
						<p class="empty-state" role="alert"><?php echo htmlspecialchars($availabilityCalendarError, ENT_QUOTES); ?></p>
					<?php elseif (empty($availabilityCalendar)): ?>
						<p class="empty-state">No upcoming bookings found yet.</p>
					<?php else: ?>
						<div class="calendar-grid">
							<?php foreach ($availabilityCalendar as $dateKey => $entries): ?>
								<?php $dateLabel = date('D, M j', strtotime($dateKey)); ?>
								<div class="calendar-day">
									<h3><?php echo htmlspecialchars($dateLabel, ENT_QUOTES); ?></h3>
									<ul>
										<?php foreach ($entries as $entry): ?>
											<li>
												<?php
													$startLabel = date('g:i A', strtotime($entry['start_time']));
													$endLabel = date('g:i A', strtotime($entry['end_time']));
												?>
												<span class="status-badge status-downtime" aria-label="Booked and unavailable">Unavailable</span>
												<?php echo htmlspecialchars($entry['equipment_name'], ENT_QUOTES); ?> — <?php echo htmlspecialchars($startLabel . ' - ' . $endLabel, ENT_QUOTES); ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<div class="maintenance-list">
						<h3>Machines in Maintenance</h3>
						<?php if ($maintenanceMachinesError !== null): ?>
							<p class="empty-state" role="alert"><?php echo htmlspecialchars($maintenanceMachinesError, ENT_QUOTES); ?></p>
						<?php elseif (empty($maintenanceMachines)): ?>
							<p class="empty-state">No machines currently in progress.</p>
						<?php else: ?>
							<ul>
								<?php foreach ($maintenanceMachines as $machineName): ?>
									<li>
										<span class="status-badge status-maintenance" aria-label="Under maintenance">Maintenance</span>
										<?php echo htmlspecialchars($machineName, ENT_QUOTES); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</article>
			</section>
		</main>
	</body>
</html>