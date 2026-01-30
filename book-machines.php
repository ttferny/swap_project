<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking-utils.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$bookingMessages = ['success' => [], 'error' => []];
$cancelMessages = ['success' => [], 'error' => []];
$formValues = [
'machine_id' => '',
'date' => '',
'time' => '',
'duration' => '60',
'notes' => '',
];
$durationOptions = [
'30' => '30 minutes',
'60' => '1 hour',
'90' => '1 hour 30 minutes',
'120' => '2 hours',
'180' => '3 hours',
'240' => '4 hours',
];
$availabilityCalendar = [];
$maintenanceMachines = [];
$equipmentOptionsError = null;
$availabilityCalendarError = null;
$maintenanceMachinesError = null;
$currentUserId = $_SESSION['user']['user_id'] ?? null;
$currentUserRole = $_SESSION['user']['role'] ?? '';

$machineCsrfToken = generate_csrf_token('book_machine_submit');
$cancelCsrfToken = generate_csrf_token('book_machine_cancel');

try {
$equipmentSql = "SELECT equipment_id, name, location, category FROM equipment WHERE status = 'active' ORDER BY name ASC";
$equipmentResult = mysqli_query($conn, $equipmentSql);
if ($equipmentResult === false) {
$equipmentOptionsError = 'Unable to load equipment list right now.';
} else {
$equipmentData = [];
while ($row = mysqli_fetch_assoc($equipmentResult)) {
$equipmentData[] = [
'id' => (int) ($row['equipment_id'] ?? 0),
'name' => trim((string) ($row['name'] ?? '')),
'location' => trim((string) ($row['location'] ?? 'Unknown location')),
'category' => trim((string) ($row['category'] ?? 'General equipment')),
];
}
mysqli_free_result($equipmentResult);
}
} catch (Throwable $equipmentException) {
record_system_error($equipmentException, ['route' => 'book-machines', 'context' => 'load_equipment']);
$equipmentOptionsError = 'Unable to load equipment list right now.';
}

$equipmentById = [];
if (!empty($equipmentData)) {
foreach ($equipmentData as $equipment) {
$equipmentById[(string) $equipment['id']] = $equipment;
}
}

$userIsAdmin = in_array($currentUserRole, ['admin', 'manager', 'technician'], true);
$userEquipmentAccessMap = [];
$limitEquipmentScope = false;
if (!$userIsAdmin && $currentUserId !== null) {
try {
$accessSql = "SELECT equipment_id FROM equipment_access WHERE user_id = ? AND access_level IN ('basic', 'advanced')";
$accessStmt = mysqli_prepare($conn, $accessSql);
if ($accessStmt !== false) {
mysqli_stmt_bind_param($accessStmt, 'i', $currentUserId);
mysqli_stmt_execute($accessStmt);
$result = mysqli_stmt_get_result($accessStmt);
while ($row = $result ? mysqli_fetch_assoc($result) : null) {
$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
if ($equipmentId > 0) {
$userEquipmentAccessMap[$equipmentId] = true;
}
}
if ($result) {
mysqli_free_result($result);
}
mysqli_stmt_close($accessStmt);
}
} catch (Throwable $accessException) {
record_system_error($accessException, ['route' => 'book-machines', 'context' => 'access_scope']);
}
$limitEquipmentScope = true;
}

$certifiedEquipmentIds = [];
$certEligibilityError = null;
if ($currentUserId !== null) {
try {
$certSql = "SELECT ce.equipment_id FROM equipment_certifications ce INNER JOIN certifications c ON c.certification_id = ce.certification_id INNER JOIN user_certifications uc ON uc.certification_id = c.certification_id WHERE uc.user_id = ? AND uc.status = 'completed'";
$certStmt = mysqli_prepare($conn, $certSql);
if ($certStmt !== false) {
mysqli_stmt_bind_param($certStmt, 'i', $currentUserId);
mysqli_stmt_execute($certStmt);
$result = mysqli_stmt_get_result($certStmt);
while ($row = $result ? mysqli_fetch_assoc($result) : null) {
$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
if ($equipmentId > 0) {
$certifiedEquipmentIds[$equipmentId] = true;
}
}
if ($result) {
mysqli_free_result($result);
}
mysqli_stmt_close($certStmt);
}
} catch (Throwable $certException) {
record_system_error($certException, ['route' => 'book-machines', 'context' => 'certifications']);
$certEligibilityError = 'Unable to verify certifications right now.';
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$formType = $_POST['form_type'] ?? 'submit_booking';

if ($formType === 'cancel_booking') {
$bookingIdRaw = trim((string) ($_POST['booking_id'] ?? ''));
$cancelCsrfInput = (string) ($_POST['csrf_token'] ?? '');

if (!validate_csrf_token('book_machine_cancel', $cancelCsrfInput)) {
$cancelMessages['error'][] = 'Your session expired. Please reload the page and try again.';
} elseif ($currentUserId === null) {
$cancelMessages['error'][] = 'Please sign in before cancelling a booking.';
} elseif ($bookingIdRaw === '' || !ctype_digit($bookingIdRaw)) {
$cancelMessages['error'][] = 'Invalid booking reference.';
} else {
$bookingId = (int) $bookingIdRaw;
$startTimeStmt = mysqli_prepare(
$conn,
"SELECT start_time, equipment_id FROM bookings WHERE booking_id = ? AND requester_id = ? AND status IN ('pending', 'approved') LIMIT 1"
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
$equipmentIdForCancellation = isset($startRow['equipment_id']) ? (int) $startRow['equipment_id'] : 0;

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

if ($equipmentIdForCancellation > 0) {
log_audit_event(
$conn,
$currentUserId,
'equipment_booking_cancelled',
'equipment',
$equipmentIdForCancellation,
[
'booking_id' => $bookingId,
'booking_start' => (string) $startRow['start_time'],
]
);
}

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
$bookingCsrfInput = (string) ($_POST['csrf_token'] ?? '');

if (!validate_csrf_token('book_machine_submit', $bookingCsrfInput)) {
$bookingMessages['error'][] = 'Your session expired. Please reload the page and try again.';
}

if ($currentUserId === null) {
$bookingMessages['error'][] = 'Please sign in before sending a booking request.';
}

if ($formValues['machine_id'] === '' || !ctype_digit($formValues['machine_id']) || !isset($equipmentById[$formValues['machine_id']])) {
$bookingMessages['error'][] = 'Select a valid machine to continue.';
}

$selectedMachineId = ctype_digit($formValues['machine_id']) ? (int) $formValues['machine_id'] : 0;
if ($selectedMachineId > 0 && $limitEquipmentScope && !isset($userEquipmentAccessMap[$selectedMachineId])) {
$bookingMessages['error'][] = 'You do not have permission to book that machine.';
}

if ($certEligibilityError !== null) {
$bookingMessages['error'][] = 'Unable to verify certifications right now. Please try again later.';
} elseif ($selectedMachineId > 0 && empty($certifiedEquipmentIds[$selectedMachineId])) {
$bookingMessages['error'][] = 'You must complete all required certifications to book this machine.';
}

if ($formValues['date'] === '') {
$bookingMessages['error'][] = 'Pick a booking date.';
}

if ($formValues['time'] === '') {
$bookingMessages['error'][] = 'Pick a booking start time.';
}

$durationMinutes = null;
if ($formValues['duration'] === '' || !isset($durationOptions[$formValues['duration']])) {
$bookingMessages['error'][] = 'Select a valid duration option.';
} else {
$durationMinutes = (int) $formValues['duration'];
if ($durationMinutes <= 0 || $durationMinutes > 240) {
$bookingMessages['error'][] = 'Duration must be between 30 minutes and 4 hours.';
}
}

$bookingStart = null;
$bookingEnd = null;
if ($formValues['date'] !== '' && $formValues['time'] !== '' && $durationMinutes !== null) {
$dateTimeString = sprintf('%s %s', $formValues['date'], $formValues['time']);
$parsedStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateTimeString);

if ($parsedStart === false) {
$bookingMessages['error'][] = 'Enter a valid booking date and time.';
} else {
$now = new DateTimeImmutable('now');
$maxAdvance = $now->modify('+60 days');

if ($parsedStart <= $now) {
$bookingMessages['error'][] = 'Choose a start time in the future.';
} elseif ($parsedStart > $maxAdvance) {
$bookingMessages['error'][] = 'Bookings can only be scheduled up to 60 days in advance.';
} else {
$bookingStart = $parsedStart;
$bookingEnd = $bookingStart->modify('+' . $durationMinutes . ' minutes');
}
}
}

if (empty($bookingMessages['error']) && $bookingStart !== null && $bookingEnd !== null) {
$equipmentIdInt = (int) $formValues['machine_id'];
$desiredStartStr = $bookingStart->format('Y-m-d H:i:s');
$desiredEndStr = $bookingEnd->format('Y-m-d H:i:s');
$noteParam = $formValues['notes'] === '' ? 'Booking submitted via portal.' : $formValues['notes'];
$noteParam = function_exists('mb_substr') ? mb_substr($noteParam, 0, 255) : substr($noteParam, 0, 255);
$waitlistNote = $formValues['notes'] === '' ? null : (function_exists('mb_substr') ? mb_substr($formValues['notes'], 0, 255) : substr($formValues['notes'], 0, 255));

$transactionStarted = mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_WRITE);
if ($transactionStarted === false) {
$bookingMessages['error'][] = 'We could not secure that time slot. Please try again or contact support.';
} else {
$lockSql = "SELECT booking_id FROM bookings WHERE equipment_id = ? AND status IN ('pending', 'approved') AND start_time < ? AND end_time > ? FOR UPDATE";
$lockStmt = mysqli_prepare($conn, $lockSql);

if ($lockStmt === false) {
mysqli_rollback($conn);
$bookingMessages['error'][] = 'Could not verify equipment availability.';
} else {
mysqli_stmt_bind_param($lockStmt, 'iss', $equipmentIdInt, $desiredEndStr, $desiredStartStr);
mysqli_stmt_execute($lockStmt);
mysqli_stmt_store_result($lockStmt);
$hasConflict = mysqli_stmt_num_rows($lockStmt) > 0;
mysqli_stmt_close($lockStmt);

if ($hasConflict) {
mysqli_rollback($conn);

$waitlistSql = "INSERT INTO booking_waitlist (equipment_id, user_id, desired_start, desired_end, note) VALUES (?, ?, ?, ?, NULLIF(?, ''))";
$waitlistStmt = mysqli_prepare($conn, $waitlistSql);

if ($waitlistStmt === false) {
$bookingMessages['error'][] = 'Unable to add you to the waitlist right now.';
} else {
$waitlistNoteParam = $waitlistNote ?? '';
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

if ($equipmentIdInt > 0 && $waitlistId !== null) {
log_audit_event(
$conn,
$currentUserId,
'equipment_waitlist_requested',
'equipment',
$equipmentIdInt,
[
'waitlist_id' => $waitlistId,
'desired_start' => $desiredStartStr,
'desired_end' => $desiredEndStr,
'source' => 'booking_form',
]
);
}

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
mysqli_rollback($conn);
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
mysqli_commit($conn);
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

if ($equipmentIdInt > 0 && $newBookingId !== null) {
log_audit_event(
$conn,
$currentUserId,
'equipment_booking_requested',
'equipment',
$equipmentIdInt,
[
'booking_id' => $newBookingId,
'window_start' => $desiredStartStr,
'window_end' => $desiredEndStr,
'purpose' => $noteParam,
]
);
}

$bookingMessages['success'][] = 'Booking submitted and awaiting manager approval.';
$formValues = [
'machine_id' => '',
'date' => '',
'time' => '',
'duration' => '60',
'notes' => '',
];
} else {
mysqli_rollback($conn);
$bookingMessages['error'][] = 'We could not save your booking request. Please try again.';
}

mysqli_stmt_close($bookingStmt);
}
}
}
}
}
}

if ($formType !== 'cancel_booking') {
try {
flagBookingIfNecessary($conn, $currentUserId, $formValues['machine_id'] !== '' ? (int) $formValues['machine_id'] : null);
} catch (Throwable $flagException) {
record_system_error($flagException, ['route' => 'book-machines', 'context' => 'flagging']);
}
}
}

try {
$calendarSql = "SELECT b.start_time, b.end_time, e.name AS equipment_name, e.equipment_id FROM bookings b INNER JOIN equipment e ON e.equipment_id = b.equipment_id WHERE b.status IN ('pending', 'approved') ORDER BY b.start_time ASC";
$calendarResult = mysqli_query($conn, $calendarSql);
if ($calendarResult === false) {
$availabilityCalendarError = 'Unable to load the availability calendar right now.';
} else {
while ($row = mysqli_fetch_assoc($calendarResult)) {
$startTime = (string) ($row['start_time'] ?? '');
$endTime = (string) ($row['end_time'] ?? '');
$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;

if ($startTime === '' || $endTime === '') {
continue;
}

if ($limitEquipmentScope && ($equipmentId <= 0 || !isset($userEquipmentAccessMap[$equipmentId]))) {
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
} catch (Throwable $calendarException) {
record_system_error($calendarException, ['route' => 'book-machines', 'context' => 'availability']);
$availabilityCalendarError = 'Unable to load the availability calendar right now.';
}

try {
$maintenanceSql = "SELECT DISTINCT e.equipment_id, e.name AS equipment_name FROM maintenance_tasks mt INNER JOIN equipment e ON e.equipment_id = mt.equipment_id WHERE mt.status = 'in_progress' AND mt.manager_status = 'approved' ORDER BY e.name ASC";
$maintenanceResult = mysqli_query($conn, $maintenanceSql);
if ($maintenanceResult === false) {
$maintenanceMachinesError = 'Unable to load maintenance status right now.';
} else {
while ($row = mysqli_fetch_assoc($maintenanceResult)) {
$equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;

if ($limitEquipmentScope && ($equipmentId <= 0 || !isset($userEquipmentAccessMap[$equipmentId]))) {
continue;
}

$equipmentName = trim((string) ($row['equipment_name'] ?? ''));
if ($equipmentName === '') {
$equipmentName = 'Unnamed equipment';
}

$maintenanceMachines[] = $equipmentName;
}

mysqli_free_result($maintenanceResult);
}
} catch (Throwable $maintenanceException) {
record_system_error($maintenanceException, ['route' => 'book-machines', 'context' => 'maintenance']);
$maintenanceMachinesError = 'Unable to load maintenance status right now.';
}

function flagBookingIfNecessary(mysqli $conn, ?int $currentUserId, ?int $equipmentId): void
{
if ($currentUserId === null) {
return;
}

if ($equipmentId === null || $equipmentId <= 0) {
return;
}

$recentSql = "SELECT COUNT(*) AS recent_count FROM bookings WHERE requester_id = ? AND equipment_id = ? AND start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$recentStmt = mysqli_prepare($conn, $recentSql);

if (!$recentStmt) {
return;
}

mysqli_stmt_bind_param($recentStmt, 'ii', $currentUserId, $equipmentId);
mysqli_stmt_execute($recentStmt);
$result = mysqli_stmt_get_result($recentStmt);
$row = $result ? mysqli_fetch_assoc($result) : null;
if ($result) {
mysqli_free_result($result);
}
mysqli_stmt_close($recentStmt);

if (!$row || !isset($row['recent_count'])) {
return;
}

$recentCount = (int) $row['recent_count'];
if ($recentCount < 3) {
return;
}

$flagStmt = mysqli_prepare(
$conn,
"INSERT INTO booking_flags (requester_id, equipment_id, reason_code, created_at) VALUES (?, ?, 'repeat_request', NOW())"
);

if (!$flagStmt) {
return;
}

mysqli_stmt_bind_param($flagStmt, 'ii', $currentUserId, $equipmentId);
mysqli_stmt_execute($flagStmt);
mysqli_stmt_close($flagStmt);

$flaggedBookingId = mysqli_insert_id($conn);
logAuditEntry(
$conn,
$currentUserId,
'booking_flagged',
'bookings',
$flaggedBookingId,
['requester_id' => $currentUserId]
);
}

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

if ($equipmentId > 0 && $newBookingId !== null) {
log_audit_event(
$conn,
$actorId,
'equipment_waitlist_promoted',
'equipment',
$equipmentId,
[
'waitlist_id' => $waitlistId,
'booking_id' => $newBookingId,
'window_start' => $startTime,
'window_end' => $endTime,
]
);
}

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
grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
gap: 1.5rem;
}

.card {
background: var(--card);
padding: 1.5rem;
border-radius: 1rem;
border: 1px solid #e2e8f0;
box-shadow: 0 15px 35px rgba(76, 81, 191, 0.08);
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

.stat-card {
display: flex;
align-items: center;
justify-content: space-between;
padding: 1rem;
border-radius: 1rem;
background: linear-gradient(135deg, rgba(67, 97, 238, 0.12), rgba(76, 201, 240, 0.18));
border: 1px solid rgba(67, 97, 238, 0.2);
}

.stat-card strong {
font-size: 1.5rem;
color: var(--accent);
}

.field-group label {
font-size: 0.9rem;
font-weight: 600;
color: var(--muted);
}

.field-group select,
.field-group input,
.field-group textarea {
width: 100%;
padding: 0.75rem;
border-radius: 0.65rem;
border: 1px solid #d7def0;
font-family: inherit;
font-size: 1rem;
}

textarea {
min-height: 80px;
resize: vertical;
}

button[type='submit'] {
background: var(--accent);
color: white;
border: none;
padding: 0.85rem 1.5rem;
border-radius: 0.75rem;
font-size: 1rem;
font-weight: 600;
cursor: pointer;
transition: transform 0.2s ease, box-shadow 0.2s ease;
}

button[type='submit']:hover {
transform: translateY(-2px);
box-shadow: 0 15px 25px rgba(67, 97, 238, 0.25);
}

.alert {
padding: 0.85rem 1rem;
border-radius: 0.75rem;
font-size: 0.95rem;
margin-bottom: 0.75rem;
}

.alert-success {
background: rgba(56, 142, 60, 0.12);
color: #2e7d32;
border: 1px solid rgba(56, 142, 60, 0.2);
}

.alert-error {
background: rgba(229, 62, 62, 0.12);
color: #c53030;
border: 1px solid rgba(229, 62, 62, 0.2);
}

.badge {
display: inline-flex;
align-items: center;
gap: 0.4rem;
font-size: 0.85rem;
padding: 0.3rem 0.75rem;
border-radius: 999px;
background: rgba(67, 97, 238, 0.12);
color: var(--accent);
font-weight: 600;
}

.list {
margin: 0;
padding: 0;
list-style: none;
display: flex;
flex-direction: column;
gap: 0.85rem;
}

.list li {
display: flex;
align-items: center;
justify-content: space-between;
padding: 0.75rem;
border-radius: 0.75rem;
background: rgba(67, 97, 238, 0.08);
border: 1px solid rgba(67, 97, 238, 0.18);
}

.empty-state {
text-align: center;
padding: 1rem;
color: var(--muted);
}

@media (max-width: 768px) {
.banner {
flex-direction: column;
align-items: flex-start;
}

.banner-actions {
width: 100%;
justify-content: space-between;
}
}
</style>
</head>
<body>
<header>
<div class="banner">
<div>
<p class="badge">Equipment Control Center</p>
<h1>Optimize your build time</h1>
<p style="color: var(--muted); margin: 0">
Book advanced fabrication equipment, monitor availability, and stay aligned with
maintenance schedules in one place.
</p>
</div>
<div class="banner-actions">
<div class="search-bar">
<svg viewBox="0 0 24 24" width="18" height="18" fill="none">
<path
d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm10 2-4.35-4.35"
stroke="currentColor"
stroke-width="2"
stroke-linecap="round"
></path>
</svg>
<input type="text" placeholder="Search machine or slot" />
</div>
<button class="icon-button">
<svg viewBox="0 0 24 24" fill="none">
<path
d="M12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z"
fill="currentColor"
></path>
</svg>
</button>
<button class="icon-button">
<svg viewBox="0 0 24 24" fill="none">
<path
d="M12 22c4.8-4 8-7.2 8-11.5A5.5 5.5 0 0 0 12 7a5.5 5.5 0 0 0-8 3.5C4 14.8 7.2 18 12 22Z"
fill="currentColor"
></path>
</svg>
</button>
</div>
</div>
</header>

<main>
<section class="hero">
<p>
Smooth equipment scheduling keeps your production calendar on track. Use the booking form to request
time and get instant feedback on availability, approvals, and maintenance conflicts.
</p>
</section>

<?php if (!empty($bookingMessages['success'])): ?>
<?php foreach ($bookingMessages['success'] as $message): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($bookingMessages['error'])): ?>
<?php foreach ($bookingMessages['error'] as $message): ?>
<div class="alert alert-error"><?= htmlspecialchars($message) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($cancelMessages['success'])): ?>
<?php foreach ($cancelMessages['success'] as $message): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($cancelMessages['error'])): ?>
<?php foreach ($cancelMessages['error'] as $message): ?>
<div class="alert alert-error"><?= htmlspecialchars($message) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<section class="booking-panel">
<div class="card">
<h2>Request a machine</h2>
<p>Select equipment, pick your time window, and include any fabrication notes.</p>
<form method="post">
<input type="hidden" name="form_type" value="submit_booking" />
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($machineCsrfToken) ?>" />
<div class="field-group">
<label for="machine_id">Machine</label>
<select name="machine_id" id="machine_id" required>
<option value="">Select a machine</option>
<?php if (!empty($equipmentData)): ?>
<?php foreach ($equipmentData as $machine): ?>
<option
value="<?= htmlspecialchars((string) $machine['id']) ?>"
<?php if ($formValues['machine_id'] === (string) $machine['id']): ?>selected<?php endif; ?>
>
<?= htmlspecialchars($machine['name']) ?>  <?= htmlspecialchars($machine['location']) ?>
</option>
<?php endforeach; ?>
<?php else: ?>
<option disabled>Equipment list unavailable</option>
<?php endif; ?>
</select>
</div>

<div class="field-group">
<label for="booking_date">Date</label>
<input
type="date"
name="booking_date"
value="<?= htmlspecialchars($formValues['date']) ?>"
required
/>
</div>

<div class="field-group">
<label for="booking_time">Start Time</label>
<input
type="time"
name="booking_time"
value="<?= htmlspecialchars($formValues['time']) ?>"
required
/>
</div>

<div class="field-group">
<label for="booking_duration">Duration</label>
<select name="booking_duration" id="booking_duration" required>
<?php foreach ($durationOptions as $minutes => $label): ?>
<option
value="<?= htmlspecialchars($minutes) ?>"
<?php if ($formValues['duration'] === $minutes): ?>selected<?php endif; ?>
>
<?= htmlspecialchars($label) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="field-group">
<label for="booking_notes">Notes</label>
<textarea
name="booking_notes"
placeholder="Share context for your booking: material, setup, safety considerations..."
><?= htmlspecialchars($formValues['notes']) ?></textarea>
</div>

<button type="submit">Send booking request</button>
</form>
</div>

<div class="card">
<h2>Team bookings</h2>
<p>Cancel a pending booking or release an upcoming slot for others to use.</p>
<form method="post">
<input type="hidden" name="form_type" value="cancel_booking" />
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($cancelCsrfToken) ?>" />
<div class="field-group">
<label for="booking_id">Booking Reference</label>
<input
type="number"
name="booking_id"
placeholder="Enter booking ID"
required
/>
</div>
<button type="submit" style="background: #f97316">Cancel booking</button>
</form>
</div>

<div class="card">
<h2>Maintenance watch</h2>
<p>Machines currently in service or awaiting availability.</p>
<div class="maintenance-list">
<?php if ($maintenanceMachinesError !== null): ?>
<div class="alert alert-error">
<?= htmlspecialchars($maintenanceMachinesError) ?>
</div>
<?php elseif (!empty($maintenanceMachines)): ?>
<ul>
<?php foreach ($maintenanceMachines as $equipmentName): ?>
<li>
<span><?= htmlspecialchars($equipmentName) ?></span>
<span class="badge" style="background: rgba(249, 115, 22, 0.15); color: #f97316">In service</span>
</li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<div class="empty-state">No machines are under maintenance </div>
<?php endif; ?>
</div>
</div>
</section>

<section style="margin-top: 2rem">
<h2>Availability Radar</h2>
<p style="color: var(--muted)">Upcoming bookings across the floor.</p>
<div class="calendar-grid">
<?php if ($availabilityCalendarError !== null): ?>
<div class="alert alert-error">
<?= htmlspecialchars($availabilityCalendarError) ?>
</div>
<?php elseif (!empty($availabilityCalendar)): ?>
<?php foreach ($availabilityCalendar as $date => $entries): ?>
<div class="calendar-day">
<h3><?= date('D, M j', strtotime($date)) ?></h3>
<ul>
<?php foreach ($entries as $entry): ?>
<li>
<strong><?= htmlspecialchars($entry['equipment_name']) ?></strong><br />
<span style="color: var(--muted)">
<?= date('H:i', strtotime($entry['start_time'])) ?> 
<?= date('H:i', strtotime($entry['end_time'])) ?>
</span>
</li>
<?php endforeach; ?>
</ul>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state" style="grid-column: 1 / -1">
No upcoming bookings logged.
</div>
<?php endif; ?>
</div>
</section>
</main>
</body>
</html>
