<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = require_login(['manager', 'admin']);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
    $userFullName = 'Manager';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Manager'));
$logoutToken = generate_csrf_token('logout_form');

$decisionError = null;
$decisionNotice = null;

function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Not scheduled';
    }
    $date = date_create($value);
    if ($date === false) {
        return $value;
    }
    return $date->format('M j, Y g:ia');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_decision'])) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
    $managerNote = trim((string) ($_POST['manager_note'] ?? ''));
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    try {
        if ($taskId <= 0) {
        $decisionError = 'Select a valid maintenance task.';
    } elseif (!validate_csrf_token('maintenance_approval_' . $taskId, $csrfToken)) {
        $decisionError = 'Your session expired. Please reload the page and try again.';
    } elseif (!in_array($decision, ['approve', 'reject'], true)) {
        $decisionError = 'Choose a valid review action.';
    } elseif ($decision === 'reject' && $managerNote === '') {
        $decisionError = 'Please include a short note when requesting changes.';
    } elseif (mb_strlen($managerNote) > 500) {
        $decisionError = 'Notes are limited to 500 characters.';
    } else {
        $currentStatus = null;
        $taskStmt = mysqli_prepare($conn, 'SELECT manager_status FROM maintenance_tasks WHERE task_id = ? LIMIT 1');
        if ($taskStmt === false) {
            $decisionError = 'Unable to load the selected task right now.';
        } else {
            mysqli_stmt_bind_param($taskStmt, 'i', $taskId);
            mysqli_stmt_execute($taskStmt);
            mysqli_stmt_bind_result($taskStmt, $currentStatus);
            if (!mysqli_stmt_fetch($taskStmt)) {
                $decisionError = 'The selected maintenance task could not be found.';
            }
            mysqli_stmt_close($taskStmt);
        }

        if ($decisionError === null && $currentStatus !== null) {
            $targetStatus = $decision === 'approve' ? 'approved' : 'rejected';
            if ($currentStatus === $targetStatus) {
                $decisionNotice = 'No changes made. This schedule is already ' . $targetStatus . '.';
            } else {
                $updateStmt = mysqli_prepare(
                    $conn,
                    "UPDATE maintenance_tasks
                        SET manager_status = ?,
                            manager_notes = NULLIF(?, ''),
                            manager_reviewed_by = ?,
                            manager_reviewed_at = NOW(),
                            updated_at = NOW()
                        WHERE task_id = ?"
                );
                if ($updateStmt === false) {
                    $decisionError = 'Unable to update the task right now.';
                } else {
                    $managerNotesParam = $managerNote;
                    $managerId = (int) ($currentUser['user_id'] ?? 0);
                    mysqli_stmt_bind_param($updateStmt, 'ssii', $targetStatus, $managerNotesParam, $managerId, $taskId);
                    if (mysqli_stmt_execute($updateStmt)) {
                        $decisionNotice = $targetStatus === 'approved'
                            ? 'Maintenance schedule approved and released to technicians.'
                            : 'Maintenance schedule sent back for revisions.';
                        log_audit_event(
                            $conn,
                            $managerId,
                            'maintenance_task_' . $targetStatus,
                            'maintenance_tasks',
                            $taskId,
                            [
                                'manager_note' => $managerNotesParam,
                                'decision' => $targetStatus,
                            ]
                        );
                    } else {
                        $decisionError = 'Unable to update the task right now.';
                    }
                    mysqli_stmt_close($updateStmt);
                }
            }
        }
    }
    } catch (Throwable $maintenanceException) {
        record_system_error($maintenanceException, ['route' => 'maintenance-approvals', 'task_id' => $taskId, 'decision' => $decision]);
        $decisionError = 'We could not apply that decision. Please try again or contact support.';
    }
}

$managerStats = [
    'submitted' => 0,
    'approved' => 0,
    'rejected' => 0,
];
$statsResult = mysqli_query($conn, 'SELECT manager_status, COUNT(*) AS total FROM maintenance_tasks GROUP BY manager_status');
if ($statsResult !== false) {
    while ($row = mysqli_fetch_assoc($statsResult)) {
        $statusKey = strtolower((string) ($row['manager_status'] ?? ''));
        if (isset($managerStats[$statusKey])) {
            $managerStats[$statusKey] = (int) ($row['total'] ?? 0);
        }
    }
    mysqli_free_result($statsResult);
}

$pendingTasks = [];
$pendingQueueError = null;
$pendingSql = "SELECT
        mt.task_id,
        mt.title,
        mt.description,
        mt.task_type,
        mt.priority,
        mt.status,
        mt.manager_status,
        mt.scheduled_for,
        mt.created_at,
        e.name AS equipment_name,
        u.full_name AS assigned_to_name,
        creator.full_name AS created_by_name
    FROM maintenance_tasks mt
    LEFT JOIN equipment e ON e.equipment_id = mt.equipment_id
    LEFT JOIN users u ON u.user_id = mt.assigned_to
    LEFT JOIN users creator ON creator.user_id = mt.created_by
    WHERE mt.manager_status = 'submitted'
    ORDER BY
        CASE WHEN mt.scheduled_for IS NULL THEN 1 ELSE 0 END,
        mt.scheduled_for ASC,
        mt.created_at ASC";
$pendingResult = mysqli_query($conn, $pendingSql);
if ($pendingResult === false) {
    $pendingQueueError = 'Unable to load pending schedules right now.';
} else {
    while ($row = mysqli_fetch_assoc($pendingResult)) {
        $pendingTasks[] = [
            'task_id' => (int) ($row['task_id'] ?? 0),
            'title' => trim((string) ($row['title'] ?? 'Untitled task')),
            'description' => trim((string) ($row['description'] ?? '')),
            'task_type' => (string) ($row['task_type'] ?? 'corrective'),
            'priority' => (string) ($row['priority'] ?? 'medium'),
            'status' => (string) ($row['status'] ?? 'open'),
            'scheduled_for' => $row['scheduled_for'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'equipment_name' => trim((string) ($row['equipment_name'] ?? 'Unassigned equipment')),
            'assigned_to_name' => trim((string) ($row['assigned_to_name'] ?? '')),
            'created_by_name' => trim((string) ($row['created_by_name'] ?? '')),
        ];
    }
    mysqli_free_result($pendingResult);
}

$recentDecisions = [];
$historyError = null;
$historySql = "SELECT
        mt.task_id,
        mt.title,
        mt.priority,
        mt.task_type,
        mt.manager_status,
        mt.manager_notes,
        mt.manager_reviewed_at,
        mt.scheduled_for,
        e.name AS equipment_name,
        reviewer.full_name AS reviewer_name
    FROM maintenance_tasks mt
    LEFT JOIN equipment e ON e.equipment_id = mt.equipment_id
    LEFT JOIN users reviewer ON reviewer.user_id = mt.manager_reviewed_by
    WHERE mt.manager_status IN ('approved', 'rejected')
    ORDER BY COALESCE(mt.manager_reviewed_at, mt.updated_at) DESC
    LIMIT 6";
$historyResult = mysqli_query($conn, $historySql);
if ($historyResult === false) {
    $historyError = 'Unable to load recent approvals.';
} else {
    while ($row = mysqli_fetch_assoc($historyResult)) {
        $recentDecisions[] = [
            'task_id' => (int) ($row['task_id'] ?? 0),
            'title' => trim((string) ($row['title'] ?? 'Untitled task')),
            'priority' => (string) ($row['priority'] ?? 'medium'),
            'task_type' => (string) ($row['task_type'] ?? 'corrective'),
            'manager_status' => (string) ($row['manager_status'] ?? 'approved'),
            'manager_notes' => trim((string) ($row['manager_notes'] ?? '')),
            'manager_reviewed_at' => $row['manager_reviewed_at'] ?? null,
            'scheduled_for' => $row['scheduled_for'] ?? null,
            'equipment_name' => trim((string) ($row['equipment_name'] ?? 'Unassigned equipment')),
            'reviewer_name' => trim((string) ($row['reviewer_name'] ?? 'Manager')),
        ];
    }
    mysqli_free_result($historyResult);
}

$pendingUnscheduled = 0;
foreach ($pendingTasks as $task) {
    if ($task['scheduled_for'] === null || $task['scheduled_for'] === '') {
        $pendingUnscheduled++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Maintenance Schedule Approvals</title>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
            rel="stylesheet"
        />
        <style>
            :root {
                --bg: #f8fbff;
                --accent: #0ea5e9;
                --accent-soft: #e0f2fe;
                --text: #0f172a;
                --muted: #64748b;
                --card: #ffffff;
                --danger: #ef4444;
                --success: #10b981;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                font-family: "Space Grotesk", "Segoe UI", Tahoma, sans-serif;
                color: var(--text);
                background: radial-gradient(circle at top, #eefcff, var(--bg));
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
                background: #bae6fd;
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

            main {
                padding: clamp(2rem, 5vw, 4rem);
            }

            .intro {
                max-width: 720px;
                margin-bottom: 2rem;
            }

            .intro h2 {
                margin-top: 0;
            }

            .intro p {
                color: var(--muted);
                line-height: 1.6;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 1rem;
                margin-bottom: 1.5rem;
            }

            .stat-card {
                background: var(--card);
                border: 1px solid #e2e8f0;
                border-radius: 1rem;
                padding: 1rem 1.25rem;
                box-shadow: 0 12px 30px rgba(14, 165, 233, 0.08);
            }

            .stat-card span {
                display: block;
                font-size: 0.85rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--muted);
            }

            .stat-card strong {
                display: block;
                font-size: 1.8rem;
                margin-top: 0.4rem;
            }

            .alert {
                margin: 0 0 1.25rem;
                padding: 0.85rem 1rem;
                border-radius: 0.85rem;
                border: 1px solid transparent;
                font-weight: 500;
            }

            .alert-error {
                background: rgba(239, 68, 68, 0.08);
                border-color: rgba(239, 68, 68, 0.35);
                color: #b91c1c;
            }

            .alert-success {
                background: rgba(16, 185, 129, 0.08);
                border-color: rgba(16, 185, 129, 0.35);
                color: #047857;
            }

            .layout {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1.5rem;
            }

            .card {
                background: var(--card);
                border: 1px solid #e2e8f0;
                border-radius: 1.1rem;
                padding: 1.5rem;
                box-shadow: 0 18px 35px rgba(15, 23, 42, 0.08);
            }

            .card h3 {
                margin-top: 0;
            }

            .task-list {
                list-style: none;
                padding: 0;
                margin: 1.25rem 0 0;
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            .task-card {
                border: 1px solid rgba(14, 165, 233, 0.25);
                border-radius: 1rem;
                padding: 1rem 1.25rem;
                background: rgba(224, 242, 254, 0.45);
            }

            .task-header {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                gap: 0.75rem;
                align-items: center;
            }

            .task-title {
                margin: 0;
                font-size: 1.1rem;
            }

            .pill {
                border-radius: 999px;
                padding: 0.3rem 0.85rem;
                font-size: 0.85rem;
                font-weight: 600;
                text-transform: capitalize;
            }

            .pill-priority-high {
                background: rgba(239, 68, 68, 0.15);
                color: #b91c1c;
            }

            .pill-priority-medium {
                background: rgba(251, 191, 36, 0.2);
                color: #92400e;
            }

            .pill-priority-low {
                background: rgba(16, 185, 129, 0.18);
                color: #047857;
            }

            .pill-type {
                background: rgba(99, 102, 241, 0.18);
                color: #4338ca;
            }

            .task-meta {
                margin: 0.6rem 0 0;
                color: var(--muted);
                font-size: 0.92rem;
                display: grid;
                gap: 0.35rem;
            }

            .task-description {
                margin: 0.9rem 0 0;
                color: var(--text);
                line-height: 1.5;
            }

            .decision-form {
                margin-top: 1rem;
                display: flex;
                flex-direction: column;
                gap: 0.6rem;
            }

            .decision-form textarea {
                width: 100%;
                min-height: 90px;
                border-radius: 0.75rem;
                border: 1px solid #cbd5f5;
                padding: 0.75rem;
                font-family: inherit;
                font-size: 0.95rem;
                resize: vertical;
            }

            .decision-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                justify-content: flex-end;
            }

            .decision-button {
                border: none;
                border-radius: 0.9rem;
                padding: 0.55rem 1.2rem;
                font-size: 0.95rem;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }

            .decision-button--primary {
                background: linear-gradient(135deg, #0284c7, #0ea5e9);
                color: #fff;
                box-shadow: 0 15px 30px rgba(14, 165, 233, 0.35);
            }

            .decision-button--secondary {
                background: rgba(239, 68, 68, 0.12);
                color: #b91c1c;
                border: 1px solid rgba(239, 68, 68, 0.25);
            }

            .decision-button:hover {
                transform: translateY(-1px);
            }

            .history-list {
                list-style: none;
                padding: 0;
                margin: 1.1rem 0 0;
                display: flex;
                flex-direction: column;
                gap: 0.85rem;
            }

            .history-item {
                border: 1px solid #e2e8f0;
                border-radius: 0.9rem;
                padding: 0.9rem 1rem;
                background: rgba(248, 250, 255, 0.8);
            }

            .history-header {
                display: flex;
                justify-content: space-between;
                gap: 0.5rem;
                font-size: 0.95rem;
            }

            .history-status {
                font-weight: 600;
                text-transform: capitalize;
            }

            .history-status.approved {
                color: var(--success);
            }

            .history-status.rejected {
                color: var(--danger);
            }

            .history-notes {
                margin: 0.5rem 0 0;
                color: var(--muted);
            }

            .empty-state {
                margin-top: 1rem;
                color: var(--muted);
                font-style: italic;
            }

            @media (max-width: 1024px) {
                .layout {
                    grid-template-columns: 1fr;
                }
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
                <h1>Maintenance Schedule Approvals</h1>
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
            <section class="intro" aria-labelledby="approvals-intro">
                <h2 id="approvals-intro">Keep service work under control</h2>
                <p>
                    Review incoming maintenance schedules, request clarification when something looks risky, and
                    release approved work to technicians. Unscheduled items waiting on calendar slots: <?php echo (int) $pendingUnscheduled; ?>.
                </p>
            </section>
            <section class="stats-grid" aria-label="Approval summary">
                <article class="stat-card">
                    <span>Awaiting review</span>
                    <strong><?php echo htmlspecialchars((string) $managerStats['submitted'], ENT_QUOTES); ?></strong>
                </article>
                <article class="stat-card">
                    <span>Approved</span>
                    <strong><?php echo htmlspecialchars((string) $managerStats['approved'], ENT_QUOTES); ?></strong>
                </article>
                <article class="stat-card">
                    <span>Sent back</span>
                    <strong><?php echo htmlspecialchars((string) $managerStats['rejected'], ENT_QUOTES); ?></strong>
                </article>
            </section>
            <?php if ($decisionError !== null): ?>
                <p class="alert alert-error" role="alert"><?php echo htmlspecialchars($decisionError, ENT_QUOTES); ?></p>
            <?php elseif ($decisionNotice !== null): ?>
                <p class="alert alert-success" role="status"><?php echo htmlspecialchars($decisionNotice, ENT_QUOTES); ?></p>
            <?php endif; ?>
            <div class="layout">
                <section class="card">
                    <h3>Pending schedules</h3>
                    <p>Approve what is ready, or send it back with notes for rework.</p>
                    <?php if ($pendingQueueError !== null): ?>
                        <p class="alert alert-error" role="alert"><?php echo htmlspecialchars($pendingQueueError, ENT_QUOTES); ?></p>
                    <?php elseif (empty($pendingTasks)): ?>
                        <p class="empty-state">No schedules are waiting for review.</p>
                    <?php else: ?>
                        <ul class="task-list" aria-live="polite">
                            <?php foreach ($pendingTasks as $task): ?>
                                <?php
                                    $priorityClass = 'pill-priority-medium';
                                    if ($task['priority'] === 'high') {
                                        $priorityClass = 'pill-priority-high';
                                    } elseif ($task['priority'] === 'low') {
                                        $priorityClass = 'pill-priority-low';
                                    }
                                    $csrfValue = generate_csrf_token('maintenance_approval_' . $task['task_id']);
                                    $scheduledLabel = format_datetime($task['scheduled_for']);
                                    $submittedLabel = format_datetime($task['created_at']);
                                ?>
                                <li class="task-card">
                                    <div class="task-header">
                                        <div>
                                            <p class="task-title"><?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?></p>
                                            <span class="pill <?php echo htmlspecialchars($priorityClass, ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($task['priority'], ENT_QUOTES); ?> priority
                                            </span>
                                        </div>
                                        <span class="pill pill-type"><?php echo htmlspecialchars($task['task_type'], ENT_QUOTES); ?></span>
                                    </div>
                                    <div class="task-meta">
                                        <span><strong>Equipment:</strong> <?php echo htmlspecialchars($task['equipment_name'], ENT_QUOTES); ?></span>
                                        <span><strong>Target slot:</strong> <?php echo htmlspecialchars($scheduledLabel, ENT_QUOTES); ?></span>
                                        <span><strong>Submitted:</strong> <?php echo htmlspecialchars($submittedLabel, ENT_QUOTES); ?></span>
                                        <?php if ($task['assigned_to_name'] !== ''): ?>
                                            <span><strong>Assigned to:</strong> <?php echo htmlspecialchars($task['assigned_to_name'], ENT_QUOTES); ?></span>
                                        <?php endif; ?>
                                        <?php if ($task['created_by_name'] !== ''): ?>
                                            <span><strong>Requested by:</strong> <?php echo htmlspecialchars($task['created_by_name'], ENT_QUOTES); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($task['description'] !== ''): ?>
                                        <p class="task-description"><?php echo htmlspecialchars($task['description'], ENT_QUOTES); ?></p>
                                    <?php endif; ?>
                                    <form class="decision-form" method="post">
                                        <label for="note-<?php echo (int) $task['task_id']; ?>">Manager note</label>
                                        <textarea
                                            id="note-<?php echo (int) $task['task_id']; ?>"
                                            name="manager_note"
                                            placeholder="Share context, required for rejections (500 char max)."
                                            maxlength="500"
                                        ></textarea>
                                        <div class="decision-actions">
                                            <button
                                                type="submit"
                                                name="decision"
                                                value="reject"
                                                class="decision-button decision-button--secondary"
                                            >
                                                Request changes
                                            </button>
                                            <button
                                                type="submit"
                                                name="decision"
                                                value="approve"
                                                class="decision-button decision-button--primary"
                                            >
                                                Approve schedule
                                            </button>
                                        </div>
                                        <input type="hidden" name="maintenance_decision" value="1" />
                                        <input type="hidden" name="task_id" value="<?php echo (int) $task['task_id']; ?>" />
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfValue, ENT_QUOTES); ?>" />
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
                <section class="card">
                    <h3>Recent decisions</h3>
                    <p>Snapshot of the last few approvals or rejections.</p>
                    <?php if ($historyError !== null): ?>
                        <p class="alert alert-error" role="alert"><?php echo htmlspecialchars($historyError, ENT_QUOTES); ?></p>
                    <?php elseif (empty($recentDecisions)): ?>
                        <p class="empty-state">No reviews have been logged yet.</p>
                    <?php else: ?>
                        <ul class="history-list">
                            <?php foreach ($recentDecisions as $decision): ?>
                                <?php $reviewedLabel = format_datetime($decision['manager_reviewed_at']); ?>
                                <li class="history-item">
                                    <div class="history-header">
                                        <span><?php echo htmlspecialchars($decision['equipment_name'], ENT_QUOTES); ?></span>
                                        <span class="history-status <?php echo htmlspecialchars($decision['manager_status'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($decision['manager_status'], ENT_QUOTES); ?>
                                        </span>
                                    </div>
                                    <p class="task-title" style="margin: 0.4rem 0 0;">
                                        <?php echo htmlspecialchars($decision['title'], ENT_QUOTES); ?>
                                    </p>
                                    <p class="task-meta" style="margin-top: 0.4rem;">
                                        <span><strong>Priority:</strong> <?php echo htmlspecialchars($decision['priority'], ENT_QUOTES); ?></span>
                                        <span><strong>Reviewer:</strong> <?php echo htmlspecialchars($decision['reviewer_name'], ENT_QUOTES); ?></span>
                                        <span><strong>Decision time:</strong> <?php echo htmlspecialchars($reviewedLabel, ENT_QUOTES); ?></span>
                                    </p>
                                    <?php if ($decision['manager_notes'] !== ''): ?>
                                        <p class="history-notes">“<?php echo htmlspecialchars($decision['manager_notes'], ENT_QUOTES); ?>”</p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </body>
</html>
