<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    $requestedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    if ($requestedScript !== '' && $requestedScript === realpath(__FILE__)) {
        http_response_code(404);
        exit;
    }
}

function flagBookingIfNecessary(mysqli $conn, ?int $currentUserId, ?int $equipmentId): void
{
    if ($currentUserId <= 0) {
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

function acquire_equipment_booking_lock(mysqli $conn, int $equipmentId, float $timeoutSeconds = 0.95): bool
{
    if ($equipmentId <= 0) {
        return false;
    }
    $lockName = sprintf('booking_equipment_%d', $equipmentId);
    $lockStmt = mysqli_prepare($conn, 'SELECT GET_LOCK(?, ?)');
    if ($lockStmt === false) {
        return false;
    }
    mysqli_stmt_bind_param($lockStmt, 'sd', $lockName, $timeoutSeconds);
    mysqli_stmt_execute($lockStmt);
    mysqli_stmt_bind_result($lockStmt, $lockResult);
    mysqli_stmt_fetch($lockStmt);
    mysqli_stmt_close($lockStmt);
    return (int) ($lockResult ?? 0) === 1;
}

function release_equipment_booking_lock(mysqli $conn, int $equipmentId): void
{
    if ($equipmentId <= 0) {
        return;
    }
    $lockName = sprintf('booking_equipment_%d', $equipmentId);
    $releaseStmt = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
    if ($releaseStmt === false) {
        return;
    }
    mysqli_stmt_bind_param($releaseStmt, 's', $lockName);
    mysqli_stmt_execute($releaseStmt);
    mysqli_stmt_close($releaseStmt);
}
