<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = enforce_capability($conn, 'manager.console');
$historyFallback = dashboard_home_path($currentUser);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Guest User';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Manager'));
$logoutToken = generate_csrf_token('logout_form');

if (!function_exists('format_manager_booking_time')) {
	function format_manager_booking_time(?string $timestamp): string
	{
		if ($timestamp === null) {
			return 'Not recorded';
		}
		$cleanTimestamp = trim($timestamp);
		if ($cleanTimestamp === '') {
			return 'Not recorded';
		}
		try {
			$displayDate = new DateTimeImmutable($cleanTimestamp);
			return $displayDate->format('M j, Y · g:ia');
		} catch (Throwable $exception) {
			return $cleanTimestamp;
		}
	}
}

$pastBookings = [];
$pastBookingsError = '';
$pastBookingsLimit = 8;

if ($conn instanceof mysqli) {
	$pastBookingsQuery = "
		SELECT
			b.booking_id,
			b.start_time,
			b.end_time,
			COALESCE(u.full_name, 'Unknown Requester') AS requester_name,
			COALESCE(u.tp_admin_no, 'N/A') AS requester_identifier,
			e.name AS equipment_name,
			TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time) AS duration_minutes
		FROM bookings b
		INNER JOIN users u ON u.user_id = b.requester_id
		INNER JOIN equipment e ON e.equipment_id = b.equipment_id
		WHERE b.start_time < NOW()
			AND b.status IN ('approved', 'completed')
		ORDER BY b.start_time DESC
		LIMIT " . (int) $pastBookingsLimit;

	$pastResult = mysqli_query($conn, $pastBookingsQuery);
	if ($pastResult instanceof mysqli_result) {
		while ($row = mysqli_fetch_assoc($pastResult)) {
			$row['start_label'] = format_manager_booking_time($row['start_time'] ?? null);
			$row['end_label'] = format_manager_booking_time($row['end_time'] ?? null);
			$row['duration_minutes'] = max(0, (int) ($row['duration_minutes'] ?? 0));
			$pastBookings[] = $row;
		}
		mysqli_free_result($pastResult);
	} else {
		$pastBookingsError = 'Unable to load past bookings right now.';
	}
} else {
	$pastBookingsError = 'Database connection unavailable.';
}
?>
<!DOCTYPE html>
<html lang="en" data-history-fallback="<?php echo htmlspecialchars($historyFallback, ENT_QUOTES); ?>">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>TP AMC Manager Hub</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<script src="assets/js/history-guard.js" defer></script>
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
				border-radius: 0.85rem;
				padding: 0.65rem 1rem;
				font-size: 0.95rem;
				font-weight: 600;
				color: #fff;
				background: var(--accent);
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 0.45rem;
				letter-spacing: 0.02em;
				box-shadow: 0 10px 20px rgba(16, 185, 129, 0.25);
			}

			.logout-form button:hover {
				transform: translateY(-1px);
				box-shadow: 0 12px 26px rgba(16, 185, 129, 0.35);
			}

			.logout-form button:focus-visible {
				outline: 2px solid rgba(255, 255, 255, 0.75);
				outline-offset: 2px;
			}

			.logout-form button svg {
				width: 18px;
				height: 18px;
				fill: currentColor;
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
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(16, 185, 129, 0.12);
				text-decoration: none;
				color: inherit;
				transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			}

			.card:hover,
			.card:focus-visible {
				transform: translateY(-4px);
				border-color: var(--accent);
				box-shadow: 0 20px 40px rgba(16, 185, 129, 0.2);
				outline: none;
			}

			.card h2 {
				margin-top: 0;
				font-size: 1.15rem;
			}

			.card p {
				color: var(--muted);
				margin-bottom: 0;
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
				box-shadow: 0 15px 35px rgba(16, 185, 129, 0.35);
			}

			.history-panel {
				margin-top: 3rem;
				display: grid;
				gap: 1.5rem;
			}

			.history-card {
				background: var(--card);
				border: 1px solid #e2e8f0;
				border-radius: 1rem;
				padding: 1.5rem;
				box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
			}

			.history-card__header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 1.25rem;
				flex-wrap: wrap;
			}

			.history-card__header h2 {
				margin: 0;
			}

			.history-card__eyebrow {
				margin: 0;
				text-transform: uppercase;
				letter-spacing: 0.08em;
				font-size: 0.78rem;
				color: var(--muted);
			}

			.history-card__support {
				margin: 0;
				color: var(--muted);
				max-width: 420px;
			}

			.history-card__list {
				list-style: none;
				margin: 1.25rem 0 0;
				padding: 0;
				display: flex;
				flex-direction: column;
				gap: 1rem;
			}

			.history-row {
				display: flex;
				justify-content: space-between;
				gap: 1rem;
				border: 1px solid #edf2f7;
				border-radius: 0.9rem;
				padding: 1rem 1.1rem;
				background: #fdfefe;
			}

			.history-row__primary {
				display: flex;
				flex-direction: column;
				gap: 0.25rem;
			}

			.history-row__equipment {
				margin: 0;
				font-weight: 600;
			}

			.history-row__requester {
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.history-row__meta {
				display: flex;
				flex-direction: column;
				gap: 0.35rem;
				text-align: right;
				font-size: 0.9rem;
				color: var(--muted);
			}

			.status-error {
				color: #b91c1c;
				font-weight: 600;
			}

			.status-muted {
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

				.history-row {
					flex-direction: column;
					align-items: flex-start;
				}

				.history-row__meta {
					text-align: left;
				}
			}
		</style>
	</head>
	<body>
		<header>
			<div class="banner">
				<h1>Manager Workspace (Preview)</h1>
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
								<button type="submit">
									<span>Log out</span>
									<svg
										xmlns="http://www.w3.org/2000/svg"
										viewBox="0 0 24 24"
										role="img"
										aria-hidden="true"
									>
										<path
											d="M10 17l5-5-5-5v3H3v4h7zm9-12h-6v2h6v12h-6v2h6a2 2 0 002-2V7a2 2 0 00-2-2z"
										/>
									</svg>
								</button>
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
					This interim screen mirrors the dashboard layout so stakeholders can review layout
					options while resource planning, reporting, and communications modules are built.
				</p>
			</div>
			<section class="grid">
				<a class="card" href="approve-bookings.php">
					<h2>Approve Booking Requests</h2>
					<p>Review pending waitlist submissions and approve equipment reservations.</p>
				</a>
				<a class="card" href="incident-reports.php">
					<h2>Safety Incidents</h2>
					<p>Open the incident log to review every submitted report and its status.</p>
				</a>
				<a class="card" href="maintenance-approvals.php">
					<h2>Maintenance Schedules</h2>
					<p>Review technician-submitted service plans and approve or send back feedback.</p>
				</a>
				<a class="card" href="analytics-dashboard.php">
					<h2>Analytics Dashboard</h2>
					<p>Track equipment utilisation and safety trends in one place.</p>
				</a>
			</section>
			<section class="history-panel" aria-labelledby="past-bookings-heading">
				<div class="history-card">
					<div class="history-card__header">
						<div>
							<p class="history-card__eyebrow">Completed sessions</p>
							<h2 id="past-bookings-heading">Recent Past Bookings</h2>
						</div>
						<p class="history-card__support">
							Quick reference for bookings that already ran so you can trace who used what and when.
						</p>
					</div>
					<?php if ($pastBookingsError !== ''): ?>
						<p class="status-error" role="alert"><?php echo htmlspecialchars($pastBookingsError, ENT_QUOTES); ?></p>
					<?php elseif (empty($pastBookings)): ?>
						<p class="status-muted" role="status">No completed bookings recorded yet.</p>
					<?php else: ?>
						<ul class="history-card__list">
							<?php foreach ($pastBookings as $booking): ?>
								<li class="history-row">
									<div class="history-row__primary">
										<p class="history-row__equipment"><?php echo htmlspecialchars($booking['equipment_name'], ENT_QUOTES); ?></p>
										<p class="history-row__requester">
											<?php echo htmlspecialchars($booking['requester_name'], ENT_QUOTES); ?>
											<span aria-hidden="true"> · </span>
											<span><?php echo htmlspecialchars($booking['requester_identifier'], ENT_QUOTES); ?></span>
										</p>
									</div>
									<div class="history-row__meta">
										<span><strong>Start:</strong> <?php echo htmlspecialchars($booking['start_label'], ENT_QUOTES); ?></span>
										<span><strong>End:</strong> <?php echo htmlspecialchars($booking['end_label'], ENT_QUOTES); ?></span>
										<span><strong>Duration:</strong> <?php echo htmlspecialchars($booking['duration_minutes'] > 0 ? $booking['duration_minutes'] . ' min' : 'Not set', ENT_QUOTES); ?></span>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</section>
		</main>
	</body>
</html>
