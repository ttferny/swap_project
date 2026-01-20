<?php
require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>TP AMC Management System</title>
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

			.intro {
				max-width: 540px;
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
				box-shadow: 0 15px 35px rgba(76, 81, 191, 0.08);
				display: block;
				text-decoration: none;
				color: inherit;
				cursor: pointer;
				transition: transform 0.2s ease, box-shadow 0.2s ease,
					border-color 0.2s ease;
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
				font-size: 1.2rem;
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
			<div class="intro">
				<h2>TP AMC Management System</h2>
				<p>
					Streamline asset maintenance, ensure compliance, and keep every service
					request on track from a single, modern dashboard designed for teams of
					every size.
				</p>
			</div>
			<section class="grid">
				<a class="card" href="/swap_project/book-machines.php">
					<h2>Book Machines</h2>
					<p>View and book machines in advance.</p>
				</a>
				<a class="card" href="/swap_project/learning-space.php">
					<h2>Learning Space</h2>
					<p>Find your training content, safety guidelines, and certificates here.</p>
				</a>
				<a class="card" href="/swap_project/report-fault.php">
					<h2>Report Fault</h2>
					<p>Report whatever faults and safety hazards you see here.</p>
				</a>
			</section>
		</main>
	</body>
</html>
