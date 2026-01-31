<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = enforce_capability($conn, 'admin.core');
$userFullName = trim((string) ($currentUser['full_name'] ?? 'Administrator'));
if ($userFullName === '') {
    $userFullName = 'Administrator';
}
$roleDisplay = trim((string) ($currentUser['role_name'] ?? 'Admin'));

if (!function_exists('sync_equipment_requirements')) {
    function sync_equipment_requirements(mysqli $conn, int $equipmentId, array $certIds): bool
    {
        if ($equipmentId <= 0) {
            return false;
        }
        if (!mysqli_begin_transaction($conn)) {
            return false;
        }

        $deleteStmt = mysqli_prepare($conn, 'DELETE FROM equipment_required_certs WHERE equipment_id = ?');
        if ($deleteStmt === false) {
            mysqli_rollback($conn);
            return false;
        }
        mysqli_stmt_bind_param($deleteStmt, 'i', $equipmentId);
        if (!mysqli_stmt_execute($deleteStmt)) {
            mysqli_stmt_close($deleteStmt);
            mysqli_rollback($conn);
            return false;
        }
        mysqli_stmt_close($deleteStmt);

        if (!empty($certIds)) {
            $insertStmt = mysqli_prepare(
                $conn,
                'INSERT INTO equipment_required_certs (equipment_id, cert_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE cert_id = VALUES(cert_id)'
            );
            if ($insertStmt === false) {
                mysqli_rollback($conn);
                return false;
            }
            $equipmentParam = $equipmentId;
            $certParam = 0;
            mysqli_stmt_bind_param($insertStmt, 'ii', $equipmentParam, $certParam);
            foreach ($certIds as $certId) {
                $certParam = (int) $certId;
                if ($certParam <= 0) {
                    continue;
                }
                if (!mysqli_stmt_execute($insertStmt)) {
                    mysqli_stmt_close($insertStmt);
                    mysqli_rollback($conn);
                    return false;
                }
            }
            mysqli_stmt_close($insertStmt);
        }

        if (!mysqli_commit($conn)) {
            mysqli_rollback($conn);
            return false;
        }

        return true;
    }
}

$messages = ['success' => [], 'error' => []];
$certFlash = flash_retrieve('admin_equipment_certs');
if (is_array($certFlash) && isset($certFlash['messages']) && is_array($certFlash['messages'])) {
    foreach (['success', 'error'] as $type) {
        if (isset($certFlash['messages'][$type]) && is_array($certFlash['messages'][$type])) {
            $messages[$type] = $certFlash['messages'][$type];
        }
    }
}

$equipmentList = [];
$equipmentLookup = [];
$equipmentResult = mysqli_query($conn, 'SELECT equipment_id, name, category, risk_level FROM equipment ORDER BY name ASC');
if ($equipmentResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($equipmentResult)) {
        $equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
        if ($equipmentId <= 0) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Equipment #' . $equipmentId;
        }
        $category = trim((string) ($row['category'] ?? ''));
        $riskLevel = trim((string) ($row['risk_level'] ?? ''));
        $equipmentList[] = [
            'id' => $equipmentId,
            'name' => $name,
            'category' => $category,
            'risk_level' => $riskLevel,
        ];
        $equipmentLookup[$equipmentId] = [
            'name' => $name,
            'category' => $category,
            'risk_level' => $riskLevel,
        ];
    }
    mysqli_free_result($equipmentResult);
}

$certifications = [];
$certLookup = [];
$certResult = mysqli_query($conn, 'SELECT cert_id, name, description FROM certifications ORDER BY name ASC');
if ($certResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($certResult)) {
        $certId = isset($row['cert_id']) ? (int) $row['cert_id'] : 0;
        if ($certId <= 0) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? 'Certification #' . $certId));
        $description = trim((string) ($row['description'] ?? ''));
        $certifications[] = [
            'id' => $certId,
            'name' => $name,
            'description' => $description,
        ];
        $certLookup[$certId] = $name;
    }
    mysqli_free_result($certResult);
}

$normalizeCertSelection = static function ($rawInput) use ($certLookup): array {
    $clean = [];
    if (!is_array($rawInput)) {
        return $clean;
    }
    foreach ($rawInput as $value) {
        $certId = (int) $value;
        if ($certId > 0 && isset($certLookup[$certId])) {
            $clean[$certId] = $certId;
        }
    }
    return array_values($clean);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'sync_requirements') {
        $equipmentId = isset($_POST['equipment_id']) ? (int) $_POST['equipment_id'] : 0;
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if ($equipmentId <= 0 || !isset($equipmentLookup[$equipmentId])) {
            $messages['error'][] = 'Select a valid piece of equipment before saving.';
        } elseif (!validate_csrf_token('equip_certs_' . $equipmentId, $csrfToken)) {
            $messages['error'][] = 'Your update request expired. Please refresh and try again.';
        } else {
            $selectedCerts = $normalizeCertSelection($_POST['cert_ids'] ?? []);
            if (sync_equipment_requirements($conn, $equipmentId, $selectedCerts)) {
                $equipmentName = $equipmentLookup[$equipmentId]['name'] ?? ('Equipment #' . $equipmentId);
                $messages['success'][] = 'Certification requirements updated for ' . $equipmentName . '.';
                record_data_modification_audit(
                    $conn,
                    $currentUser,
                    'equipment',
                    $equipmentId,
                    [
                        'action' => 'requirements_sync',
                        'certifications' => $selectedCerts,
                    ]
                );
            } else {
                $messages['error'][] = 'Unable to save requirements for that equipment right now.';
            }

            flash_store('admin_equipment_certs', ['messages' => $messages]);
            redirect_to_current_uri('admin-equipment-certs.php');
        }
    }
}

$equipmentCertMap = [];
$mapResult = mysqli_query($conn, 'SELECT equipment_id, cert_id FROM equipment_required_certs');
if ($mapResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($mapResult)) {
        $equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
        $certId = isset($row['cert_id']) ? (int) $row['cert_id'] : 0;
        if ($equipmentId <= 0 || $certId <= 0) {
            continue;
        }
        if (!isset($equipmentCertMap[$equipmentId])) {
            $equipmentCertMap[$equipmentId] = [];
        }
        $equipmentCertMap[$equipmentId][$certId] = true;
    }
    mysqli_free_result($mapResult);
}

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Equipment Certification Rules</title>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap"
            rel="stylesheet"
        />
        <style>
            :root {
                --bg: #f8fbff;
                --card: #ffffff;
                --accent: #4361ee;
                --muted: #64748b;
                --text: #0f172a;
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
                background: radial-gradient(circle at top, #eef3ff, var(--bg));
                min-height: 100vh;
            }

            header {
                padding: 1.5rem clamp(1.5rem, 5vw, 4rem);
                background: var(--card);
                border-bottom: 1px solid var(--border);
                box-shadow: 0 24px 45px rgba(67, 97, 238, 0.12);
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
                color: var(--muted);
                max-width: 720px;
                line-height: 1.6;
                margin-bottom: 1.5rem;
            }

            .notice {
                margin-bottom: 1rem;
                padding: 0.85rem 1rem;
                border-radius: 0.75rem;
                font-weight: 600;
            }

            .notice.success {
                background: #ecfdf5;
                color: #065f46;
            }

            .notice.error {
                background: #fef2f2;
                color: #991b1b;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 1rem;
                overflow: hidden;
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
            }

            thead {
                background: rgba(67, 97, 238, 0.08);
            }

            th,
            td {
                padding: 0.95rem 1rem;
                text-align: left;
                border-bottom: 1px solid var(--border);
            }

            tbody tr:last-child td {
                border-bottom: none;
            }

            .equipment-name {
                font-weight: 600;
            }

            .taglist {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
            }

            .tag {
                padding: 0.18rem 0.6rem;
                border-radius: 999px;
                background: rgba(67, 97, 238, 0.12);
                color: var(--accent);
                font-size: 0.78rem;
                font-weight: 600;
            }

            .tag.empty {
                background: rgba(100, 116, 139, 0.2);
                color: var(--muted);
            }

            details.manager {
                border: 1px solid var(--border);
                border-radius: 0.9rem;
                padding: 0.85rem;
                background: #f8fafc;
                transition: box-shadow 0.2s ease;
            }

            details.manager[open] {
                background: #fff;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
            }

            details summary {
                cursor: pointer;
                font-weight: 600;
                color: var(--accent);
                outline: none;
            }

            details summary::-webkit-details-marker {
                color: var(--accent);
            }

            .checkbox-grid {
                margin-top: 0.8rem;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 0.6rem;
            }

            .checkbox-card {
                display: flex;
                align-items: flex-start;
                gap: 0.5rem;
                padding: 0.45rem 0.65rem;
                border-radius: 0.85rem;
                border: 1px solid rgba(67, 97, 238, 0.2);
                background: rgba(67, 97, 238, 0.05);
            }

            .checkbox-card input {
                margin-top: 0.35rem;
            }

            .checkbox-card strong {
                display: block;
                font-size: 0.95rem;
            }

            .checkbox-card span {
                display: block;
                font-size: 0.8rem;
                color: var(--muted);
            }

            .actions {
                margin-top: 0.75rem;
                display: flex;
                gap: 0.75rem;
                flex-wrap: wrap;
            }

            .actions button,
            .actions a {
                border: none;
                border-radius: 0.8rem;
                padding: 0.6rem 1.25rem;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
            }

            .actions button.save {
                background: var(--accent);
                color: #fff;
            }

            .actions a.secondary {
                background: #e0e7ff;
                color: var(--accent);
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
                    margin-bottom: 0.35rem;
                }
            }
        </style>
    </head>
    <body>
        <header id="top">
            <h1>Equipment Certification Rules</h1>
            <p class="lede">
                Decide which certifications are mandatory before learners, staff, or technicians can
                operate each asset. Updates apply instantly across booking approvals and workspace
                checks.
            </p>
        </header>
        <main>
            <?php foreach (['success', 'error'] as $type): ?>
                <?php foreach ($messages[$type] as $message): ?>
                    <div class="notice <?php echo $type; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if (empty($equipmentList) || empty($certifications)): ?>
                <p class="lede">Add equipment and certifications to start configuring requirements.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Equipment</th>
                            <th>Category</th>
                            <th>Risk Level</th>
                            <th>Required Certifications</th>
                            <th>Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipmentList as $equipment): ?>
                            <?php
                                $equipmentId = (int) $equipment['id'];
                                $currentCerts = array_keys($equipmentCertMap[$equipmentId] ?? []);
                                $currentNames = [];
                                foreach ($currentCerts as $certId) {
                                    if (isset($certLookup[$certId])) {
                                        $currentNames[] = $certLookup[$certId];
                                    }
                                }
                                $summary = empty($currentNames)
                                    ? 'No certifications required'
                                    : count($currentNames) . ' certification' . (count($currentNames) === 1 ? '' : 's');
                                $formId = 'equip-form-' . $equipmentId;
                                $token = generate_csrf_token('equip_certs_' . $equipmentId);
                            ?>
                            <form id="<?php echo htmlspecialchars($formId, ENT_QUOTES); ?>" method="post">
                                <input type="hidden" name="action" value="sync_requirements" />
                                <input type="hidden" name="equipment_id" value="<?php echo $equipmentId; ?>" />
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>" />
                            </form>
                            <tr>
                                <td data-label="Equipment" class="equipment-name"><?php echo htmlspecialchars($equipment['name'], ENT_QUOTES); ?></td>
                                <td data-label="Category"><?php echo htmlspecialchars($equipment['category'] !== '' ? $equipment['category'] : 'Uncategorized', ENT_QUOTES); ?></td>
                                <td data-label="Risk Level"><?php echo htmlspecialchars(ucfirst($equipment['risk_level'] ?: 'unknown'), ENT_QUOTES); ?></td>
                                <td data-label="Required Certifications">
                                    <div class="taglist">
                                        <?php if (empty($currentNames)): ?>
                                            <span class="tag empty">None assigned</span>
                                        <?php else: ?>
                                            <?php foreach ($currentNames as $name): ?>
                                                <span class="tag"><?php echo htmlspecialchars($name, ENT_QUOTES); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Manage">
                                    <details class="manager">
                                        <summary><?php echo htmlspecialchars($summary, ENT_QUOTES); ?></summary>
                                        <div class="checkbox-grid">
                                            <?php foreach ($certifications as $cert): ?>
                                                <?php
                                                    $certId = (int) $cert['id'];
                                                    $checkboxId = 'cert-' . $equipmentId . '-' . $certId;
                                                    $isChecked = in_array($certId, $currentCerts, true);
                                                ?>
                                                <label class="checkbox-card" for="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>">
                                                    <input
                                                        type="checkbox"
                                                        id="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>"
                                                        name="cert_ids[]"
                                                        value="<?php echo $certId; ?>"
                                                        form="<?php echo htmlspecialchars($formId, ENT_QUOTES); ?>"
                                                        <?php echo $isChecked ? 'checked' : ''; ?>
                                                    />
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($cert['name'], ENT_QUOTES); ?></strong>
                                                        <?php if (($cert['description'] ?? '') !== ''): ?>
                                                            <span><?php echo htmlspecialchars($cert['description'], ENT_QUOTES); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="actions">
                                            <button type="submit" class="save" form="<?php echo htmlspecialchars($formId, ENT_QUOTES); ?>">Save rules</button>
                                            <a class="secondary" href="#top">Back to top</a>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </main>
    </body>
</html>
