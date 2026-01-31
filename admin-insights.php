<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current admin user and dashboard destination.
$currentUser = enforce_capability($conn, 'admin.core');
$dashboardHref = dashboard_home_path($currentUser);
$userFullName = trim((string) ($currentUser['full_name'] ?? 'Administrator'));
if ($userFullName === '') {
    $userFullName = 'Administrator';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));

// Format datetime values consistently for tables and summaries.
function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $dt = date_create($value);
    if (!$dt) {
        return $value;
    }
    return $dt->format('d M Y, H:i');
}

// Provide a short, human-readable summary for audit detail payloads.
function summarize_audit_details(?string $jsonPayload): string
{
    if ($jsonPayload === null || trim($jsonPayload) === '') {
        return '—';
    }
    $decoded = json_decode($jsonPayload, true);
    if (!is_array($decoded) || empty($decoded)) {
        return '—';
    }
    $segments = [];
    foreach ($decoded as $key => $value) {
        if (count($segments) >= 3) {
            break;
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }
        $segments[] = trim((string) $key) . ': ' . trim((string) $value);
    }
    $summary = implode('; ', $segments);
    if ($summary === '') {
        return '—';
    }
    if (function_exists('mb_strlen')) {
        return mb_strlen($summary) > 140 ? mb_substr($summary, 0, 137) . '...' : $summary;
    }
    return strlen($summary) > 140 ? substr($summary, 0, 137) . '...' : $summary;
}

// Aggregate booking counts by status for the KPI cards.
$bookingSummary = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'flagged' => 0,
    'rejected' => 0,
];
$bookingSummaryResult = mysqli_query($conn, 'SELECT status, COUNT(*) AS total FROM bookings GROUP BY status');
if ($bookingSummaryResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($bookingSummaryResult)) {
        $status = strtolower((string) ($row['status'] ?? ''));
        $count = (int) ($row['total'] ?? 0);
        $bookingSummary['total'] += $count;
        if (isset($bookingSummary[$status])) {
            $bookingSummary[$status] += $count;
        }
    }
    mysqli_free_result($bookingSummaryResult);
}

// Aggregate incident counts by severity for safety reporting.
$incidentSummary = [
    'total' => 0,
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
];
$incidentResult = mysqli_query($conn, 'SELECT severity, COUNT(*) AS total FROM incidents GROUP BY severity');
if ($incidentResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($incidentResult)) {
        $severity = strtolower((string) ($row['severity'] ?? ''));
        $count = (int) ($row['total'] ?? 0);
        $incidentSummary['total'] += $count;
        if (isset($incidentSummary[$severity])) {
            $incidentSummary[$severity] += $count;
        }
    }
    mysqli_free_result($incidentResult);
}

// Aggregate maintenance counts by status for reliability insights.
$maintenanceSummary = [
    'total' => 0,
    'open' => 0,
    'in_progress' => 0,
    'done' => 0,
];
$maintenanceResult = mysqli_query($conn, 'SELECT status, COUNT(*) AS total FROM maintenance_tasks GROUP BY status');
if ($maintenanceResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($maintenanceResult)) {
        $status = strtolower((string) ($row['status'] ?? ''));
        $count = (int) ($row['total'] ?? 0);
        $maintenanceSummary['total'] += $count;
        if (isset($maintenanceSummary[$status])) {
            $maintenanceSummary[$status] += $count;
        }
    }
    mysqli_free_result($maintenanceResult);
}

// Recent booking activity table source.
$recentBookings = [];
$recentBookingSql = 'SELECT b.booking_id, b.status, b.start_time, b.end_time, b.created_at,
        e.name AS equipment_name,
        u.full_name AS requester_name
    FROM bookings b
    LEFT JOIN equipment e ON e.equipment_id = b.equipment_id
    LEFT JOIN users u ON u.user_id = b.requester_id
    ORDER BY b.created_at DESC
    LIMIT 10';
if ($result = mysqli_query($conn, $recentBookingSql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $recentBookings[] = $row;
    }
    mysqli_free_result($result);
}

// Recent incident activity table source.
$recentIncidents = [];
$incidentSql = 'SELECT inc.incident_id, inc.severity, inc.category, inc.created_at,
        inc.location, e.name AS equipment_name, u.full_name AS reporter_name
    FROM incidents inc
    LEFT JOIN equipment e ON e.equipment_id = inc.equipment_id
    LEFT JOIN users u ON u.user_id = inc.reported_by
    ORDER BY inc.created_at DESC
    LIMIT 10';
if ($result = mysqli_query($conn, $incidentSql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['location'] = decrypt_sensitive_value($row['location'] ?? null);
        $recentIncidents[] = $row;
    }
    mysqli_free_result($result);
}

// Recent maintenance activity table source.
$recentMaintenance = [];
$maintenanceSql = 'SELECT mt.task_id, mt.title, mt.status, mt.priority, mt.updated_at,
        e.name AS equipment_name
    FROM maintenance_tasks mt
    LEFT JOIN equipment e ON e.equipment_id = mt.equipment_id
    ORDER BY mt.updated_at DESC
    LIMIT 10';
if ($result = mysqli_query($conn, $maintenanceSql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $recentMaintenance[] = $row;
    }
    mysqli_free_result($result);
}

// Audit trail feed for compliance visibility.
$auditTrail = [];
$auditTrailSql = "SELECT al.audit_id, al.actor_user_id, al.action, al.entity_type, al.entity_id, al.ip_address, al.user_agent, al.details, al.created_at
    FROM audit_logs al
    ORDER BY al.created_at DESC
    LIMIT 8";
if ($result = mysqli_query($conn, $auditTrailSql)) {
	while ($row = mysqli_fetch_assoc($result)) {
		$auditTrail[] = $row;
	}
	mysqli_free_result($result);
}

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Administrator Insights Hub</title>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
            rel="stylesheet"
        />
        <!-- Base styles for the admin insights layout. -->
        <style>
            :root {
                --bg: #f8fbff;
                --card: #ffffff;
                --text: #0f172a;
                --muted: #64748b;
                --accent: #0ea5e9;
                --accent-strong: #0369a1;
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
                box-shadow: 0 24px 45px rgba(14, 165, 233, 0.15);
                position: sticky;
                top: 0;
                z-index: 10;
            }

            main {
                padding: clamp(2rem, 5vw, 4rem);
            }

            h1 {
                margin: 0;
                font-size: clamp(1.5rem, 3vw, 2.4rem);
            }

            p.lede {
                margin: 0.35rem 0 0;
                color: var(--muted);
                max-width: 780px;
            }

            .summary-grid {
                margin-top: 1.5rem;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 1rem;
            }

            .summary-card {
                padding: 1.2rem;
                border-radius: 1rem;
                border: 1px solid var(--border);
                background: var(--card);
                box-shadow: 0 15px 30px rgba(14, 165, 233, 0.12);
            }

            .summary-card h3 {
                margin: 0;
                font-size: 0.95rem;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: 0.08em;
            }

            .summary-card p {
                margin: 0.4rem 0 0;
                font-size: 2rem;
                font-weight: 600;
                color: var(--accent-strong);
            }

            .panel {
                margin-top: 2rem;
                padding: 1.5rem;
                border-radius: 1.25rem;
                border: 1px solid var(--border);
                background: var(--card);
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            }

            .panel h2 {
                margin-top: 0;
                font-size: 1.2rem;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 1rem;
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

            .status-pill {
                display: inline-flex;
                align-items: center;
                padding: 0.15rem 0.65rem;
                border-radius: 999px;
                font-size: 0.78rem;
                font-weight: 600;
                text-transform: capitalize;
            }

            .status-booking-approved {
                background: rgba(16, 185, 129, 0.15);
                color: #047857;
            }

            .status-booking-pending {
                background: rgba(245, 158, 11, 0.18);
                color: #92400e;
            }

            .status-booking-flagged,
            .status-booking-rejected {
                background: rgba(239, 68, 68, 0.18);
                color: #b91c1c;
            }

            .status-severity-critical {
                background: rgba(239, 68, 68, 0.18);
                color: #b91c1c;
            }

            .status-severity-high {
                background: rgba(249, 115, 22, 0.18);
                color: #c2410c;
            }

            .status-severity-medium {
                background: rgba(234, 179, 8, 0.18);
                color: #92400e;
            }

            .status-severity-low {
                background: rgba(16, 185, 129, 0.18);
                color: #065f46;
            }

            .actions-row {
                margin-top: 1rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .actions-row a {
                text-decoration: none;
                border-radius: 0.85rem;
                padding: 0.65rem 1.4rem;
                font-weight: 600;
                border: 1px solid transparent;
            }

            .actions-row a.primary {
                background: var(--accent);
                color: #fff;
            }

            .actions-row a.secondary {
                background: rgba(14, 165, 233, 0.1);
                color: var(--accent-strong);
                border-color: rgba(14, 165, 233, 0.2);
            }

            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                margin-top: 2.5rem;
                padding: 0.7rem 1.2rem;
                border-radius: 999px;
                background: var(--accent);
                color: #fff;
                text-decoration: none;
                font-weight: 600;
            }

            @media (max-width: 768px) {
                table,
                thead,
                tbody,
                th,
                td,
                tr {
                    display: block;
                }

                thead {
                    display: none;
                }

                td {
                    border-bottom: 1px solid rgba(226, 232, 240, 0.7);
                }

                td::before {
                    content: attr(data-label);
                    display: block;
                    font-size: 0.78rem;
                    color: var(--muted);
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    margin-bottom: 0.25rem;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header with signed-in admin context. -->
        <header>
            <h1>Administrator Insights Hub</h1>
            <p class="lede">
                Signed in as <?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?> (<?php echo htmlspecialchars($roleDisplay, ENT_QUOTES); ?>).
                Review dashboards, reports, and historical activity without leaving the admin workspace.
            </p>
        </header>
        <main>
            <!-- Summary KPI cards for quick status checks. -->
            <section class="summary-grid">
                <article class="summary-card">
                    <h3>Total Bookings</h3>
                    <p><?php echo number_format($bookingSummary['total']); ?></p>
                </article>
                <article class="summary-card">
                    <h3>Active Maintenance Tasks</h3>
                    <p><?php echo number_format($maintenanceSummary['open'] + $maintenanceSummary['in_progress']); ?></p>
                </article>
                <article class="summary-card">
                    <h3>Safety Reports Logged</h3>
                    <p><?php echo number_format($incidentSummary['total']); ?></p>
                </article>
                <article class="summary-card">
                    <h3>Approved Bookings</h3>
                    <p><?php echo number_format($bookingSummary['approved']); ?></p>
                </article>
            </section>

            <!-- Recent bookings table. -->
            <section class="panel">
                <h2>Booking History</h2>
                <p class="lede">
                    Monitor the latest reservations, including who requested them, asset details, and scheduling windows.
                </p>
                <?php if (empty($recentBookings)): ?>
                    <p class="lede">No bookings recorded yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Equipment</th>
                                <th>Requester</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Logged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentBookings as $booking): ?>
                                <?php $status = strtolower((string) ($booking['status'] ?? 'pending')); ?>
                                <tr>
                                    <td data-label="ID">#<?php echo (int) $booking['booking_id']; ?></td>
                                    <td data-label="Equipment"><?php echo htmlspecialchars($booking['equipment_name'] ?: 'Unassigned asset', ENT_QUOTES); ?></td>
                                    <td data-label="Requester"><?php echo htmlspecialchars($booking['requester_name'] ?: 'Unknown', ENT_QUOTES); ?></td>
                                    <td data-label="Start"><?php echo htmlspecialchars(format_datetime($booking['start_time']), ENT_QUOTES); ?></td>
                                    <td data-label="End"><?php echo htmlspecialchars(format_datetime($booking['end_time']), ENT_QUOTES); ?></td>
                                    <td data-label="Status">
                                        <span class="status-pill status-booking-<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"><?php echo htmlspecialchars($status, ENT_QUOTES); ?></span>
                                    </td>
                                    <td data-label="Logged"><?php echo htmlspecialchars(format_datetime($booking['created_at']), ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <!-- Incidents and safety reports table. -->
            <section class="panel">
                <h2>Incidents & Safety Reports</h2>
                <p class="lede">Track the latest submissions with severity markers and affected equipment.</p>
                <?php if (empty($recentIncidents)): ?>
                    <p class="lede">No incident reports submitted yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Severity</th>
                                <th>Category</th>
                                <th>Equipment</th>
                                <th>Location</th>
                                <th>Reporter</th>
                                <th>Logged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentIncidents as $incident): ?>
                                <?php $severity = strtolower((string) ($incident['severity'] ?? 'low')); ?>
                                <tr>
                                    <td data-label="ID">#<?php echo (int) $incident['incident_id']; ?></td>
                                    <td data-label="Severity">
                                        <span class="status-pill status-severity-<?php echo htmlspecialchars($severity, ENT_QUOTES); ?>"><?php echo htmlspecialchars($severity, ENT_QUOTES); ?></span>
                                    </td>
                                    <td data-label="Category"><?php echo htmlspecialchars((string) $incident['category'], ENT_QUOTES); ?></td>
                                    <td data-label="Equipment"><?php echo htmlspecialchars($incident['equipment_name'] ?: 'Not specified', ENT_QUOTES); ?></td>
                                    <td data-label="Location"><?php echo htmlspecialchars($incident['location'] ?: 'Not provided', ENT_QUOTES); ?></td>
                                    <td data-label="Reporter"><?php echo htmlspecialchars($incident['reporter_name'] ?: 'Unknown', ENT_QUOTES); ?></td>
                                    <td data-label="Logged"><?php echo htmlspecialchars(format_datetime($incident['created_at']), ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <!-- Maintenance updates table. -->
            <section class="panel">
                <h2>Maintenance & Reliability Timeline</h2>
                <p class="lede">Review recent task updates to understand equipment availability and outstanding work.</p>
                <?php if (empty($recentMaintenance)): ?>
                    <p class="lede">No maintenance tasks recorded.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Equipment</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentMaintenance as $task): ?>
                                <tr>
                                    <td data-label="ID">#<?php echo (int) $task['task_id']; ?></td>
                                    <td data-label="Title"><?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?></td>
                                    <td data-label="Equipment"><?php echo htmlspecialchars($task['equipment_name'] ?: 'General', ENT_QUOTES); ?></td>
                                    <td data-label="Status" style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', strtolower((string) $task['status'])), ENT_QUOTES); ?></td>
                                    <td data-label="Priority" style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', strtolower((string) $task['priority'])), ENT_QUOTES); ?></td>
                                    <td data-label="Updated"><?php echo htmlspecialchars(format_datetime($task['updated_at']), ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <!-- Audit log table for compliance activity. -->
            <section class="panel">
                <h2>Recent Audit Activity</h2>
                <p class="lede">Review the latest security and workflow events captured in the audit trail.</p>
                <?php if (empty($auditTrail)): ?>
                    <p class="lede">No audit events recorded yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Audit ID</th>
                                <th>Actor User ID</th>
                                <th>Action</th>
                                <th>Entity Type</th>
                                <th>Entity ID</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                                <th>Details</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditTrail as $event): ?>
                                <tr>
                                    <td data-label="Audit ID">#<?php echo (int) ($event['audit_id'] ?? 0); ?></td>
                                    <td data-label="Actor User ID"><?php echo $event['actor_user_id'] !== null ? (int) $event['actor_user_id'] : '—'; ?></td>
                                    <td data-label="Action" style="text-transform: lowercase; letter-spacing: 0.04em;">
                                        <?php echo htmlspecialchars((string) ($event['action'] ?? ''), ENT_QUOTES); ?>
                                    </td>
                                    <td data-label="Entity Type" style="text-transform: capitalize;">
                                        <?php echo htmlspecialchars((string) ($event['entity_type'] ?? '—'), ENT_QUOTES); ?>
                                    </td>
                                    <td data-label="Entity ID"><?php echo $event['entity_id'] !== null ? (int) $event['entity_id'] : '—'; ?></td>
                                    <td data-label="IP Address"><?php echo htmlspecialchars((string) ($event['ip_address'] ?? '—'), ENT_QUOTES); ?></td>
                                    <td data-label="User Agent"><?php echo htmlspecialchars((string) ($event['user_agent'] ?? '—'), ENT_QUOTES); ?></td>
                                    <td data-label="Details"><?php echo htmlspecialchars(summarize_audit_details($event['details'] ?? null), ENT_QUOTES); ?></td>
                                    <td data-label="Created At"><?php echo htmlspecialchars(format_datetime($event['created_at'] ?? null), ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <!-- Quick links to other admin dashboards. -->
            <section class="panel">
                <h2>More Dashboards & Reports</h2>
                <p class="lede">Jump straight into specialist views when you need deeper analysis or exports.</p>
                <div class="actions-row">
                    <a class="primary" href="analytics-dashboard.php">Analytics Control Center</a>
                    <a class="secondary" href="approve-bookings.php">Booking Approvals</a>
                    <a class="secondary" href="maintenance-approvals.php">Maintenance Reviews</a>
                    <a class="secondary" href="incident-reports.php">Incident Registry</a>
                    <a class="secondary" href="learning-space.php">Training Materials</a>
                </div>
            </section>

            <!-- Return to the role-based dashboard. -->
            <a class="back-link" href="<?php echo htmlspecialchars($dashboardHref, ENT_QUOTES); ?>">← Back to your dashboard</a>
        </main>
    </body>
</html>
