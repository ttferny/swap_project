<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

// Resolve current admin user and enforce role-based access.
$currentUser = enforce_capability($conn, 'admin.core');
enforce_role_access(['admin'], $currentUser);
$dashboardHref = dashboard_home_path($currentUser);
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Guest User';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));
// CSRF token for the logout action.
$logoutToken = generate_csrf_token('logout_form');
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>TP AMC Admin Hub</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
			rel="stylesheet"
		/>
		<!-- Base styles for the admin hub layout. -->
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
				box-shadow: 0 10px 20px rgba(67, 97, 238, 0.25);
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.intro {
				max-width: 720px;
				margin-bottom: 2rem;
			}

			.intro p {
				color: var(--muted);
				line-height: 1.6;
			}

			.callout {
				margin-top: 1.25rem;
				padding: 1rem 1.25rem;
				border-left: 4px solid var(--accent);
				background: rgba(67, 97, 238, 0.08);
				border-radius: 0.75rem;
				color: var(--text);
			}

			.grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
				gap: 1.5rem;
			}

			.card {
				display: block;
				background: var(--card);
				padding: 1.5rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(76, 81, 191, 0.08);
				text-decoration: none;
				color: inherit;
				transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			}

			.card:hover,
			.card:focus-visible {
				transform: translateY(-4px);
				border-color: var(--accent);
				box-shadow: 0 20px 40px rgba(67, 97, 238, 0.15);
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
		<!-- Sticky header with search, notifications, and profile menu. -->
		<header>
			<div class="banner">
				<h1>Admin Workspace (Preview)</h1>
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
								<button type="submit">Log Out</button>
							</form>
						</div>
					</details>
				</div>
			</div>
		</header>
		<main>
			<!-- Intro copy for the admin hub. -->
			<div class="intro">
				<h2><?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></h2>
				<p>
					This placeholder view mirrors the main dashboard while we finish building account
					management, role provisioning, and analytics for administrators. Use this space to
					preview how quick links and summaries will feel once the real tools arrive.
				</p>
			</div>
			<!-- Primary navigation cards for admin modules. -->
			<section class="grid">
				<a class="card" href="admin-users.php">
					<h2>User Accounts</h2>
					<p>Create, edit, or remove users across every role with full admin privileges.</p>
				</a>
				<a class="card" href="admin-insights.php">
					<h2>Insights & Reports</h2>
					<p>Review dashboards, historical bookings, incidents, and maintenance activity in one place.</p>
				</a>
				<a class="card" href="admin-equipment-certs.php">
					<h2>Equipment Certifications</h2>
					<p>Define which training credentials each machine demands before someone can operate it.</p>
				</a>
				<a class="card" href="admin-learning.php">
					<h2>Learning Repository</h2>
					<p>Upload, edit, or retire the training assets that surface inside the Learning Space.</p>
				</a>
				<a class="card" href="manager.php">
					<h2>Manager Workspace</h2>
					<p>Jump into the manager dashboard for scheduling, approvals, and escalations.</p>
				</a>
				<a class="card" href="technician.php">
					<h2>Technician Dispatch</h2>
					<p>Monitor equipment status, assign work, and close maintenance tasks.</p>
				</a>
				<a class="card" href="approve-bookings.php">
					<h2>Bookings & Waitlists</h2>
					<p>Review reservations, move the waitlist, and keep utilisation balanced.</p>
				</a>
				<a class="card" href="maintenance-approvals.php">
					<h2>Maintenance Schedules</h2>
					<p>Authorize technician plans and capture notes for every asset.</p>
				</a>
				<a class="card" href="incident-reports.php">
					<h2>Safety & Incidents</h2>
					<p>Audit safety submissions and drive follow-up actions.</p>
				</a>
				<a class="card" href="analytics-dashboard.php">
					<h2>Analytics Control Center</h2>
					<p>View utilisation, safety trends, and maintenance spend in one place.</p>
				</a>
				<a class="card" href="book-machines.php">
					<h2>Equipment Booking Portal</h2>
					<p>See the learner-facing booking experience exactly as users do.</p>
				</a>
				<a class="card" href="learning-space.php">
					<h2>Learning Space</h2>
					<p>Manage training content, certifications, and compliance resources.</p>
				</a>
				<a class="card" href="report-fault.php">
					<h2>Fault Reporting Inbox</h2>
					<p>Submit or triage hazard reports when you need to reproduce user flows.</p>
				</a>
			</section>
			<!-- Reminder banner about admin privileges. -->
			<div class="callout">
				<strong>Full-system authority:</strong> You’re signed in as an administrator, so every module and configuration endpoint is available across the platform. Use this hub as your starting point and jump directly into any workspace above.
			</div>
		</main>
	</body>
</html>
