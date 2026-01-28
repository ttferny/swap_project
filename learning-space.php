<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = require_login();
$userFullName = trim((string) ($currentUser['full_name'] ?? ''));
if ($userFullName === '') {
	$userFullName = 'Student';
}

$equipment = [];
$equipmentError = null;

$equipmentResult = mysqli_query(
	$conn,
	'SELECT equipment_id, name, category, location FROM equipment ORDER BY name ASC'
);
if ($equipmentResult === false) {
	$equipmentError = 'Unable to load equipment right now.';
} else {
	while ($row = mysqli_fetch_assoc($equipmentResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$equipment[] = [
			'equipment_id' => $equipmentId,
			'name' => trim((string) ($row['name'] ?? 'Unnamed equipment')),
			'category' => trim((string) ($row['category'] ?? '')),
			'location' => trim((string) ($row['location'] ?? '')),
		];
	}
	mysqli_free_result($equipmentResult);
}

$materialsByEquipment = [];
$materialsError = null;

$materialsSql = "SELECT
		etm.equipment_id,
		tm.material_id,
		tm.title,
		tm.material_type,
		tm.file_url,
		tm.file_path
	FROM equipment_training_materials etm
	INNER JOIN training_materials tm ON tm.material_id = etm.material_id
	ORDER BY tm.title ASC";
$materialsResult = mysqli_query($conn, $materialsSql);
if ($materialsResult === false) {
	$materialsError = 'Unable to load learning materials right now.';
} else {
	while ($row = mysqli_fetch_assoc($materialsResult)) {
		$equipmentId = (int) ($row['equipment_id'] ?? 0);
		if ($equipmentId <= 0) {
			continue;
		}
		$title = trim((string) ($row['title'] ?? 'Untitled material'));
		$materialsByEquipment[$equipmentId][] = [
			'material_id' => (int) ($row['material_id'] ?? 0),
			'title' => $title === '' ? 'Untitled material' : $title,
			'material_type' => trim((string) ($row['material_type'] ?? 'other')),
			'file_url' => trim((string) ($row['file_url'] ?? '')),
			'file_path' => trim((string) ($row['file_path'] ?? '')),
		];
	}
	mysqli_free_result($materialsResult);
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Learning Space</title>
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
				background: radial-gradient(circle at top, #eef2ff, var(--bg));
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

			header h1 {
				margin: 0;
				font-size: clamp(1.6rem, 3vw, 2.4rem);
				font-weight: 600;
			}

			main {
				padding: clamp(2rem, 5vw, 4rem);
			}

			.intro {
				max-width: 680px;
				margin-bottom: 2rem;
			}

			.intro p {
				color: var(--muted);
				line-height: 1.6;
			}

			.grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 1.5rem;
			}

			.card {
				background: var(--card);
				padding: 1.25rem;
				border-radius: 1rem;
				border: 1px solid #e2e8f0;
				box-shadow: 0 15px 35px rgba(67, 97, 238, 0.12);
				display: flex;
				flex-direction: column;
			}

			.card details {
				margin: 0;
				flex: 1;
				display: flex;
				flex-direction: column;
			}

			.card summary {
				list-style: none;
				cursor: pointer;
				flex: 1;
				display: flex;
				flex-direction: column;
			}

			.card summary::-webkit-details-marker {
				display: none;
			}

			.card summary h2 {
				margin: 0 0 0.35rem;
				font-size: 1.1rem;
			}

			.card summary p {
				margin: 0;
				color: var(--muted);
				font-size: 0.9rem;
			}

			.material-count {
				margin-top: auto;
				display: inline-flex;
				align-self: flex-start;
				align-items: center;
				gap: 0.35rem;
				padding: 0.3rem 0.75rem;
				border-radius: 999px;
				background: var(--accent-soft);
				color: var(--accent);
				font-size: 0.85rem;
				font-weight: 600;
			}

			.materials {
				margin-top: 1rem;
				padding-top: 1rem;
				border-top: 1px solid rgba(67, 97, 238, 0.18);
			}

			.materials ul {
				list-style: none;
				padding: 0;
				margin: 0;
				display: flex;
				flex-direction: column;
				gap: 0.65rem;
			}

			.material-item {
				display: flex;
				flex-direction: column;
				gap: 0.2rem;
			}

			.material-item a {
				color: var(--text);
				text-decoration: none;
				font-weight: 600;
			}

			.material-item a:hover {
				color: var(--accent);
			}

			.material-type {
				font-size: 0.8rem;
				color: var(--muted);
			}

			.empty-state {
				color: var(--muted);
				font-style: italic;
			}
		</style>
	</head>
	<body>
		<header>
			<h1>Learning Space</h1>
		</header>
		<main>
			<section class="intro">
				<h2>Welcome, <?php echo htmlspecialchars($userFullName, ENT_QUOTES); ?></h2>
				<p>
					Explore equipment-specific learning materials and certification resources. Select a
					machine to view guides, SOPs, and videos aligned with your training path.
				</p>
			</section>
			<?php if ($equipmentError !== null): ?>
				<p class="empty-state" role="alert"><?php echo htmlspecialchars($equipmentError, ENT_QUOTES); ?></p>
			<?php elseif (empty($equipment)): ?>
				<p class="empty-state">No equipment has been added yet.</p>
			<?php elseif ($materialsError !== null): ?>
				<p class="empty-state" role="alert"><?php echo htmlspecialchars($materialsError, ENT_QUOTES); ?></p>
			<?php endif; ?>
			<section class="grid">
				<?php foreach ($equipment as $machine): ?>
					<?php
						$machineId = (int) $machine['equipment_id'];
						$materials = $materialsByEquipment[$machineId] ?? [];
						$materialCount = count($materials);
						$locationParts = [];
						if ($machine['category'] !== '') {
							$locationParts[] = $machine['category'];
						}
						if ($machine['location'] !== '') {
							$locationParts[] = $machine['location'];
						}
						$subtitle = $locationParts !== [] ? implode(' • ', $locationParts) : 'Certification resources';
					?>
					<article class="card">
						<details>
							<summary>
								<h2><?php echo htmlspecialchars($machine['name'], ENT_QUOTES); ?></h2>
								<p><?php echo htmlspecialchars($subtitle, ENT_QUOTES); ?></p>
								<span class="material-count">
									<?php echo $materialCount; ?> material<?php echo $materialCount === 1 ? '' : 's'; ?>
								</span>
							</summary>
							<div class="materials">
								<?php if ($materialCount === 0): ?>
									<p class="empty-state">No learning materials available yet.</p>
								<?php else: ?>
									<ul>
										<?php foreach ($materials as $material): ?>
											<?php
												$materialId = (int) ($material['material_id'] ?? 0);
												$label = $material['title'];
												$materialType = strtolower($material['material_type']);
												$typeLabel = strtoupper($materialType);
												$link = $material['file_url'] !== '' ? $material['file_url'] : $material['file_path'];
												$useDownloadHandler = $materialId > 0 && $link !== '';
												if ($useDownloadHandler) {
													$link = 'download-material.php?id=' . $materialId;
												}
											?>
											<li class="material-item">
												<?php if ($link !== ''): ?>
													<a href="<?php echo htmlspecialchars($link, ENT_QUOTES); ?>">
														<?php echo htmlspecialchars($label, ENT_QUOTES); ?>
													</a>
												<?php else: ?>
													<span><?php echo htmlspecialchars($label, ENT_QUOTES); ?></span>
												<?php endif; ?>
												<span class="material-type"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						</details>
					</article>
				<?php endforeach; ?>
			</section>
		</main>
	</body>
</html>
