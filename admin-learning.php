<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = enforce_sensitive_route_guard($conn);
$currentUserId = (int) ($currentUser['user_id'] ?? 0);
$userFullName = trim((string) ($currentUser['full_name'] ?? 'Administrator'));
if ($userFullName === '') {
    $userFullName = 'Administrator';
}

$allowedMaterialTypes = [
    'pdf' => 'PDF / Document',
    'video' => 'Video / Lecture',
    'sop' => 'SOP / Checklist',
    'manual' => 'Equipment Manual',
    'link' => 'External Link',
    'other' => 'Other',
];
$allowedSkillLevels = [
    'general' => 'General Overview',
    'novice' => 'Beginner / Orientation',
    'intermediate' => 'Intermediate / Operator',
    'advanced' => 'Advanced / Specialist',
];
$maxUploadBytes = 25 * 1024 * 1024;
$uploadDirectory = __DIR__ . '/uploads';
$defaultMaterialType = array_key_first($allowedMaterialTypes) ?? 'pdf';
$defaultSkillLevel = 'general';
$messages = ['success' => [], 'error' => []];
$defaultCreateDraft = [
    'title' => '',
    'material_type' => $defaultMaterialType,
    'skill_level' => $defaultSkillLevel,
    'version' => '',
    'file_url' => '',
    'equipment' => [],
];
$createDraft = $defaultCreateDraft;

if (!function_exists('ensure_training_material_schema')) {
    function ensure_training_material_schema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;
        $existing = [];
        $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM training_materials");
        if ($columnResult instanceof mysqli_result) {
            while ($column = mysqli_fetch_assoc($columnResult)) {
                $field = $column['Field'] ?? '';
                if ($field !== '') {
                    $existing[$field] = true;
                }
            }
            mysqli_free_result($columnResult);
        }
        if (!isset($existing['skill_level'])) {
            @mysqli_query(
                $conn,
                "ALTER TABLE training_materials ADD COLUMN skill_level VARCHAR(32) NOT NULL DEFAULT 'general' AFTER material_type"
            );
        }
    }
}

ensure_training_material_schema($conn);

if (!function_exists('normalize_material_equipment_selection')) {
    function normalize_material_equipment_selection($rawInput, array $equipmentLookup): array
    {
        $clean = [];
        if (!is_array($rawInput)) {
            return $clean;
        }
        foreach ($rawInput as $value) {
            $equipmentId = (int) $value;
            if ($equipmentId > 0 && isset($equipmentLookup[$equipmentId])) {
                $clean[$equipmentId] = $equipmentId;
            }
        }
        return array_values($clean);
    }
}

if (!function_exists('apply_material_equipment_links')) {
    function apply_material_equipment_links(mysqli $conn, int $materialId, array $equipmentIds, int $userId): bool
    {
        $deleteStmt = mysqli_prepare($conn, 'DELETE FROM equipment_training_materials WHERE material_id = ?');
        if ($deleteStmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($deleteStmt, 'i', $materialId);
        if (!mysqli_stmt_execute($deleteStmt)) {
            mysqli_stmt_close($deleteStmt);
            return false;
        }
        mysqli_stmt_close($deleteStmt);

        if (empty($equipmentIds)) {
            return true;
        }

        $insertSql = "INSERT INTO equipment_training_materials (equipment_id, material_id, linked_by, linked_at) VALUES (?, ?, NULLIF(?, 0), NOW())";
        $insertStmt = mysqli_prepare($conn, $insertSql);
        if ($insertStmt === false) {
            return false;
        }
        $equipmentParam = 0;
        $materialParam = $materialId;
        $linkedByParam = $userId > 0 ? $userId : 0;
        mysqli_stmt_bind_param($insertStmt, 'iii', $equipmentParam, $materialParam, $linkedByParam);
        foreach ($equipmentIds as $equipmentId) {
            $equipmentParam = $equipmentId;
            if (!mysqli_stmt_execute($insertStmt)) {
                mysqli_stmt_close($insertStmt);
                return false;
            }
        }
        mysqli_stmt_close($insertStmt);
        return true;
    }
}

if (!function_exists('resolve_material_storage_path')) {
    function resolve_material_storage_path(?string $storedPath): ?string
    {
        $path = trim((string) $storedPath);
        if ($path === '') {
            return null;
        }
        if (preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $path) === 1) {
            return $path;
        }
        return __DIR__ . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

if (!function_exists('delete_material_file')) {
    function delete_material_file(?string $storedPath): void
    {
        $resolved = resolve_material_storage_path($storedPath);
        if ($resolved === null || !is_file($resolved)) {
            return;
        }
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $fileRealPath = realpath($resolved);
        if ($uploadsRoot === false || $fileRealPath === false) {
            return;
        }
        if (strpos($fileRealPath, $uploadsRoot) === 0) {
            @unlink($fileRealPath);
        }
    }
}

if (!function_exists('process_material_upload')) {
    function process_material_upload(?array $fileData, string $uploadDirectory, int $maxBytes): array
    {
        $result = [
            'status' => 'none',
            'path' => null,
            'error' => null,
        ];
        if (!isset($fileData) || !is_array($fileData)) {
            return $result;
        }
        $errorCode = (int) ($fileData['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return $result;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            $result['status'] = 'error';
            $result['error'] = 'Upload failed (code ' . $errorCode . '). Please try again.';
            return $result;
        }
        $size = (int) ($fileData['size'] ?? 0);
        if ($size <= 0) {
            $result['status'] = 'error';
            $result['error'] = 'Uploaded file appears to be empty.';
            return $result;
        }
        if ($size > $maxBytes) {
            $result['status'] = 'error';
            $result['error'] = 'File exceeds the ' . ceil($maxBytes / (1024 * 1024)) . ' MB limit.';
            return $result;
        }
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true)) {
            $result['status'] = 'error';
            $result['error'] = 'Unable to prepare the upload directory on the server.';
            return $result;
        }
        if (!is_writable($uploadDirectory)) {
            $result['status'] = 'error';
            $result['error'] = 'Upload folder is not writable on the server.';
            return $result;
        }
        $originalName = (string) ($fileData['name'] ?? 'training-material');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $baseName);
        if ($baseName === null || $baseName === '') {
            $baseName = 'training_material';
        }
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Exception $exception) {
            $suffix = (string) mt_rand(1000, 9999);
        }
        $targetName = $baseName . '_' . $suffix;
        if ($extension !== '') {
            $targetName .= '.' . $extension;
        }
        $relativePath = 'uploads/' . $targetName;
        $destination = rtrim($uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetName;
        $tmpName = (string) ($fileData['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $result['status'] = 'error';
            $result['error'] = 'Temporary upload missing. Please try again.';
            return $result;
        }
        if (!move_uploaded_file($tmpName, $destination)) {
            $result['status'] = 'error';
            $result['error'] = 'Server could not save the uploaded file.';
            return $result;
        }
        $result['status'] = 'stored';
        $result['path'] = $relativePath;
        return $result;
    }
}

$equipmentOptions = [];
$equipmentLookup = [];
$equipmentResult = mysqli_query($conn, 'SELECT equipment_id, name, category FROM equipment ORDER BY name ASC');
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
        $category = trim((string) ($row['category'] ?? 'Uncategorized'));
        $equipmentOptions[] = [
            'id' => $equipmentId,
            'name' => $name,
            'category' => $category,
        ];
        $equipmentLookup[$equipmentId] = [
            'name' => $name,
            'category' => $category,
        ];
    }
    mysqli_free_result($equipmentResult);
} else {
    $messages['error'][] = 'Unable to load equipment right now. Refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_material') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!validate_csrf_token('learning_material_create', $csrfToken)) {
            $messages['error'][] = 'Your submission expired. Please refresh and try again.';
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $materialType = trim((string) ($_POST['material_type'] ?? $defaultMaterialType));
        if (!isset($allowedMaterialTypes[$materialType])) {
            $materialType = $defaultMaterialType;
            $messages['error'][] = 'Select a valid material type.';
        }
        $skillLevel = trim((string) ($_POST['skill_level'] ?? $defaultSkillLevel));
        if (!isset($allowedSkillLevels[$skillLevel])) {
            $skillLevel = $defaultSkillLevel;
            $messages['error'][] = 'Select a valid skill level.';
        }
        $version = trim((string) ($_POST['version'] ?? ''));
        $fileUrl = trim((string) ($_POST['file_url'] ?? ''));
        if ($fileUrl !== '' && filter_var($fileUrl, FILTER_VALIDATE_URL) === false) {
            $messages['error'][] = 'Provide a valid URL for the external resource.';
        }
        $selectedEquipment = normalize_material_equipment_selection($_POST['equipment_ids'] ?? [], $equipmentLookup);

        $createDraft['title'] = $title;
        $createDraft['material_type'] = $materialType;
        $createDraft['skill_level'] = $skillLevel;
        $createDraft['version'] = $version;
        $createDraft['file_url'] = $fileUrl;
        $createDraft['equipment'] = $selectedEquipment;

        $uploadResult = process_material_upload($_FILES['material_file'] ?? null, $uploadDirectory, $maxUploadBytes);
        if ($uploadResult['status'] === 'error' && $uploadResult['error'] !== null) {
            $messages['error'][] = $uploadResult['error'];
        }
        $filePath = $uploadResult['status'] === 'stored' ? $uploadResult['path'] : '';

        if ($title === '') {
            $messages['error'][] = 'Title is required.';
        }
        if ($fileUrl === '' && $filePath === '') {
            $messages['error'][] = 'Upload a file or provide a URL so learners have something to open.';
        }

        $createSucceeded = false;
        if (empty($messages['error'])) {
            if (!mysqli_begin_transaction($conn)) {
                $messages['error'][] = 'Unable to start a database transaction right now.';
            } else {
                $insertSql = "INSERT INTO training_materials (title, material_type, skill_level, file_url, file_path, version, uploaded_by)
                              VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, 0))";
                $stmt = mysqli_prepare($conn, $insertSql);
                if ($stmt === false) {
                    mysqli_rollback($conn);
                    $messages['error'][] = 'Unable to prepare the save statement.';
                } else {
                    $fileUrlParam = $fileUrl;
                    $filePathParam = $filePath;
                    $versionParam = $version;
                    $uploadedByParam = $currentUserId > 0 ? $currentUserId : 0;
                    mysqli_stmt_bind_param(
                        $stmt,
                        'ssssssi',
                        $title,
                        $materialType,
                        $skillLevel,
                        $fileUrlParam,
                        $filePathParam,
                        $versionParam,
                        $uploadedByParam
                    );
                    if (!mysqli_stmt_execute($stmt)) {
                        $messages['error'][] = 'Unable to save the training material. Please try again.';
                        mysqli_stmt_close($stmt);
                        mysqli_rollback($conn);
                    } else {
                        $materialId = (int) mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);
                        if ($materialId <= 0) {
                            $messages['error'][] = 'Material record was not created.';
                            mysqli_rollback($conn);
                        } elseif (!apply_material_equipment_links($conn, $materialId, $selectedEquipment, $currentUserId)) {
                            $messages['error'][] = 'Unable to link this material to equipment.';
                            mysqli_rollback($conn);
                        } else {
                            mysqli_commit($conn);
                            $messages['success'][] = 'Training material "' . $title . '" was added.';
                            $createDraft = $defaultCreateDraft;
                            $createSucceeded = true;
                            log_audit_event(
                                $conn,
                                $currentUserId,
                                'training_material_created',
                                'training_material',
                                $materialId,
                                [
                                    'title' => $title,
                                    'material_type' => $materialType,
                                    'linked_equipment' => $selectedEquipment,
                                ]
                            );
                        }
                    }
                }
            }
        }

        if (!$createSucceeded && $filePath !== '') {
            delete_material_file($filePath);
        }
    } elseif ($action === 'update_material') {
        $materialId = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if ($materialId <= 0) {
            $messages['error'][] = 'Invalid material selected for update.';
        } elseif (!validate_csrf_token('learning_material_update_' . $materialId, $csrfToken)) {
            $messages['error'][] = 'Your edit request expired. Please try again.';
        } else {
            $fetchStmt = mysqli_prepare($conn, 'SELECT title, material_type, skill_level, version, file_url, file_path FROM training_materials WHERE material_id = ? LIMIT 1');
            if ($fetchStmt === false) {
                $messages['error'][] = 'Unable to load the current material details.';
            } else {
                mysqli_stmt_bind_param($fetchStmt, 'i', $materialId);
                mysqli_stmt_execute($fetchStmt);
                $result = mysqli_stmt_get_result($fetchStmt);
                $existing = $result ? mysqli_fetch_assoc($result) : null;
                if ($result) {
                    mysqli_free_result($result);
                }
                mysqli_stmt_close($fetchStmt);

                if (!$existing) {
                    $messages['error'][] = 'That training material no longer exists.';
                } else {
                    $title = trim((string) ($_POST['title'] ?? $existing['title'] ?? ''));
                    $materialType = trim((string) ($_POST['material_type'] ?? $existing['material_type'] ?? $defaultMaterialType));
                    if (!isset($allowedMaterialTypes[$materialType])) {
                        $materialType = $existing['material_type'] ?? $defaultMaterialType;
                        $messages['error'][] = 'Select a valid material type.';
                    }
                    $skillLevel = trim((string) ($_POST['skill_level'] ?? ($existing['skill_level'] ?? $defaultSkillLevel)));
                    if (!isset($allowedSkillLevels[$skillLevel])) {
                        $skillLevel = $existing['skill_level'] ?? $defaultSkillLevel;
                        $messages['error'][] = 'Select a valid skill level.';
                    }
                    $version = trim((string) ($_POST['version'] ?? ($existing['version'] ?? '')));
                    $fileUrl = trim((string) ($_POST['file_url'] ?? ($existing['file_url'] ?? '')));
                    if ($fileUrl !== '' && filter_var($fileUrl, FILTER_VALIDATE_URL) === false) {
                        $messages['error'][] = 'Provide a valid URL for the external resource.';
                    }
                    $selectedEquipment = normalize_material_equipment_selection($_POST['equipment_ids'] ?? [], $equipmentLookup);
                    $removeFile = isset($_POST['remove_file']) && (string) $_POST['remove_file'] === '1';

                    $uploadResult = process_material_upload($_FILES['material_file'] ?? null, $uploadDirectory, $maxUploadBytes);
                    if ($uploadResult['status'] === 'error' && $uploadResult['error'] !== null) {
                        $messages['error'][] = $uploadResult['error'];
                    }

                    $previousFilePath = trim((string) ($existing['file_path'] ?? ''));
                    $nextFilePath = $previousFilePath;
                    if ($removeFile) {
                        $nextFilePath = '';
                    }
                    if ($uploadResult['status'] === 'stored' && $uploadResult['path'] !== null) {
                        $nextFilePath = $uploadResult['path'];
                    }

                    if ($title === '') {
                        $messages['error'][] = 'Title is required.';
                    }
                    if ($fileUrl === '' && $nextFilePath === '') {
                        $messages['error'][] = 'Keep a local file or a URL so learners can open the material.';
                    }

                    $updateSucceeded = false;
                    if (empty($messages['error'])) {
                        if (!mysqli_begin_transaction($conn)) {
                            $messages['error'][] = 'Unable to start a database transaction right now.';
                        } else {
                            $updateSql = "UPDATE training_materials
                                          SET title = ?, material_type = ?, skill_level = ?, file_url = NULLIF(?, ''), file_path = NULLIF(?, ''), version = NULLIF(?, ''), updated_at = NOW()
                                          WHERE material_id = ?";
                            $stmt = mysqli_prepare($conn, $updateSql);
                            if ($stmt === false) {
                                mysqli_rollback($conn);
                                $messages['error'][] = 'Unable to prepare the update statement.';
                            } else {
                                $fileUrlParam = $fileUrl;
                                $filePathParam = $nextFilePath;
                                $versionParam = $version;
                                mysqli_stmt_bind_param(
                                    $stmt,
                                    'ssssssi',
                                    $title,
                                    $materialType,
                                    $skillLevel,
                                    $fileUrlParam,
                                    $filePathParam,
                                    $versionParam,
                                    $materialId
                                );
                                if (!mysqli_stmt_execute($stmt)) {
                                    $messages['error'][] = 'Unable to save your edits right now.';
                                    mysqli_stmt_close($stmt);
                                    mysqli_rollback($conn);
                                } else {
                                    mysqli_stmt_close($stmt);
                                    if (!apply_material_equipment_links($conn, $materialId, $selectedEquipment, $currentUserId)) {
                                        $messages['error'][] = 'Unable to update equipment links for this material.';
                                        mysqli_rollback($conn);
                                    } else {
                                        mysqli_commit($conn);
                                        $messages['success'][] = 'Training material "' . $title . '" was updated.';
                                        $updateSucceeded = true;
                                        log_audit_event(
                                            $conn,
                                            $currentUserId,
                                            'training_material_updated',
                                            'training_material',
                                            $materialId,
                                            [
                                                'title' => $title,
                                                'material_type' => $materialType,
                                                'linked_equipment' => $selectedEquipment,
                                                'has_file' => $nextFilePath !== '',
                                                'has_url' => $fileUrl !== '',
                                            ]
                                        );
                                    }
                                }
                            }
                        }
                    }

                    if ($updateSucceeded) {
                        if ($uploadResult['status'] === 'stored' && $previousFilePath !== '' && $previousFilePath !== $nextFilePath) {
                            delete_material_file($previousFilePath);
                        } elseif ($removeFile && $previousFilePath !== '' && $nextFilePath === '') {
                            delete_material_file($previousFilePath);
                        }
                    } elseif ($uploadResult['status'] === 'stored' && $nextFilePath !== '') {
                        delete_material_file($nextFilePath);
                    }
                }
            }
        }
    } elseif ($action === 'delete_material') {
        $materialId = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if ($materialId <= 0) {
            $messages['error'][] = 'Invalid material selected for deletion.';
        } elseif (!validate_csrf_token('learning_material_delete_' . $materialId, $csrfToken)) {
            $messages['error'][] = 'Your delete request expired. Please try again.';
        } else {
            $fetchStmt = mysqli_prepare($conn, 'SELECT title, file_path FROM training_materials WHERE material_id = ? LIMIT 1');
            if ($fetchStmt === false) {
                $messages['error'][] = 'Unable to check the selected material.';
            } else {
                mysqli_stmt_bind_param($fetchStmt, 'i', $materialId);
                mysqli_stmt_execute($fetchStmt);
                $result = mysqli_stmt_get_result($fetchStmt);
                $existing = $result ? mysqli_fetch_assoc($result) : null;
                if ($result) {
                    mysqli_free_result($result);
                }
                mysqli_stmt_close($fetchStmt);

                if (!$existing) {
                    $messages['error'][] = 'That training material no longer exists.';
                } else {
                    $deleteStmt = mysqli_prepare($conn, 'DELETE FROM training_materials WHERE material_id = ? LIMIT 1');
                    if ($deleteStmt === false) {
                        $messages['error'][] = 'Unable to prepare the delete statement.';
                    } else {
                        mysqli_stmt_bind_param($deleteStmt, 'i', $materialId);
                        if (!mysqli_stmt_execute($deleteStmt)) {
                            $messages['error'][] = 'Unable to delete that material right now.';
                        } elseif (mysqli_stmt_affected_rows($deleteStmt) < 1) {
                            $messages['error'][] = 'Material was not deleted. Please refresh.';
                        } else {
                            $messages['success'][] = 'Training material "' . ($existing['title'] ?? 'Untitled') . '" was removed.';
                            delete_material_file($existing['file_path'] ?? null);
                            log_audit_event(
                                $conn,
                                $currentUserId,
                                'training_material_deleted',
                                'training_material',
                                $materialId,
                                [
                                    'title' => $existing['title'] ?? '',
                                ]
                            );
                        }
                        mysqli_stmt_close($deleteStmt);
                    }
                }
            }
        }
    }
}

$materials = [];
$materialsResult = mysqli_query($conn, 'SELECT material_id, title, material_type, skill_level, file_url, file_path, version, uploaded_by, created_at, updated_at FROM training_materials ORDER BY updated_at DESC');
if ($materialsResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($materialsResult)) {
        $materialId = isset($row['material_id']) ? (int) $row['material_id'] : 0;
        if ($materialId <= 0) {
            continue;
        }
        $materials[$materialId] = [
            'material_id' => $materialId,
            'title' => trim((string) ($row['title'] ?? 'Untitled material')),
            'material_type' => (string) ($row['material_type'] ?? 'other'),
            'skill_level' => (string) ($row['skill_level'] ?? 'general'),
            'file_url' => trim((string) ($row['file_url'] ?? '')),
            'file_path' => trim((string) ($row['file_path'] ?? '')),
            'version' => trim((string) ($row['version'] ?? '')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    mysqli_free_result($materialsResult);
}

$materialEquipmentMap = [];
$coverageSet = [];
$mapResult = mysqli_query($conn, 'SELECT material_id, equipment_id FROM equipment_training_materials');
if ($mapResult instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($mapResult)) {
        $materialId = isset($row['material_id']) ? (int) $row['material_id'] : 0;
        $equipmentId = isset($row['equipment_id']) ? (int) $row['equipment_id'] : 0;
        if ($materialId <= 0 || $equipmentId <= 0) {
            continue;
        }
        if (!isset($materialEquipmentMap[$materialId])) {
            $materialEquipmentMap[$materialId] = [];
        }
        $materialEquipmentMap[$materialId][$equipmentId] = $equipmentId;
        $coverageSet[$equipmentId] = true;
    }
    mysqli_free_result($mapResult);
}

$materialCount = count($materials);
$coveredEquipmentCount = count($coverageSet);
$unlinkedMaterialCount = 0;
$latestUpdatedAt = null;
foreach ($materials as $materialId => $material) {
    if (empty($materialEquipmentMap[$materialId])) {
        $unlinkedMaterialCount++;
    }
    $candidateTs = $material['updated_at'] ?: $material['created_at'];
    if ($candidateTs !== '') {
        if ($latestUpdatedAt === null || strcmp($candidateTs, $latestUpdatedAt) > 0) {
            $latestUpdatedAt = $candidateTs;
        }
    }
}
$latestUpdatedLabel = $latestUpdatedAt ? date('j M Y, g:i A', strtotime($latestUpdatedAt)) : 'No updates yet';
$maxUploadMbLabel = (int) round($maxUploadBytes / (1024 * 1024));

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Learning Repository Control</title>
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
                --accent-soft: #e0e7ff;
                --muted: #64748b;
                --text: #0f172a;
                --border: #e2e8f0;
                --danger: #dc2626;
                --success: #059669;
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

            header h1 {
                margin: 0;
                font-size: clamp(1.5rem, 3vw, 2.6rem);
                font-weight: 600;
            }

            header p {
                max-width: 720px;
                color: var(--muted);
                line-height: 1.6;
            }

            main {
                padding: clamp(2rem, 5vw, 4rem);
            }

            .flash-stack {
                display: flex;
                flex-direction: column;
                gap: 0.65rem;
                margin-bottom: 1.5rem;
            }

            .flash {
                padding: 0.85rem 1rem;
                border-radius: 0.9rem;
                font-weight: 600;
            }

            .flash.success {
                background: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }

            .flash.error {
                background: #fef2f2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }

            .panel-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1.5rem;
                margin-bottom: 2rem;
            }

            .panel {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 1.2rem;
                padding: 1.5rem;
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
            }

            .panel h2,
            .panel h3 {
                margin-top: 0;
            }

            form label {
                display: block;
                margin-bottom: 0.9rem;
            }

            form label span {
                display: block;
                font-size: 0.9rem;
                color: var(--muted);
                margin-bottom: 0.25rem;
            }

            input[type="text"],
            input[type="url"],
            select,
            textarea {
                width: 100%;
                border-radius: 0.8rem;
                border: 1px solid var(--border);
                padding: 0.7rem 0.9rem;
                font-family: inherit;
                font-size: 1rem;
                background: #fdfdff;
            }

            textarea {
                min-height: 120px;
                resize: vertical;
            }

            input[type="file"] {
                width: 100%;
                font-family: inherit;
                font-size: 0.95rem;
            }

            .hint {
                font-size: 0.85rem;
                color: var(--muted);
                margin-top: -0.4rem;
                margin-bottom: 0.9rem;
            }

            .checkbox-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 0.6rem;
                margin-bottom: 1rem;
            }

            .checkbox-card {
                border: 1px solid rgba(67, 97, 238, 0.25);
                background: rgba(67, 97, 238, 0.06);
                border-radius: 0.85rem;
                padding: 0.35rem 0.6rem;
                display: flex;
                align-items: flex-start;
                gap: 0.5rem;
                font-size: 0.9rem;
            }

            .checkbox-card input {
                margin-top: 0.3rem;
            }

            .form-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.8rem;
            }

            button,
            .ghost-button {
                border: none;
                border-radius: 0.9rem;
                padding: 0.75rem 1.4rem;
                font-weight: 600;
                font-size: 0.95rem;
                font-family: inherit;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.4rem;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }

            button.primary {
                background: var(--accent);
                color: #fff;
                box-shadow: 0 15px 30px rgba(67, 97, 238, 0.35);
            }

            button.secondary,
            .ghost-button {
                background: var(--accent-soft);
                color: var(--accent);
            }

            button.danger {
                background: rgba(220, 38, 38, 0.1);
                color: var(--danger);
                border: 1px solid rgba(220, 38, 38, 0.3);
            }

            button:hover,
            .ghost-button:hover {
                transform: translateY(-2px);
            }

            .material-list {
                display: grid;
                gap: 1.5rem;
            }

            .material-card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 1.2rem;
                padding: 1.5rem;
                box-shadow: 0 25px 55px rgba(15, 23, 42, 0.12);
            }

            .material-card header {
                padding: 0;
                border: none;
                box-shadow: none;
                position: static;
                margin-bottom: 1rem;
                background: transparent;
            }

            .material-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                color: var(--muted);
                font-size: 0.9rem;
            }

            .taglist {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
                margin: 0.5rem 0 1rem;
            }

            .tag {
                padding: 0.2rem 0.75rem;
                border-radius: 999px;
                background: rgba(67, 97, 238, 0.12);
                color: var(--accent);
                font-weight: 600;
                font-size: 0.8rem;
            }

            details.edit-block {
                border: 1px dashed var(--border);
                border-radius: 0.9rem;
                padding: 0.85rem 1rem;
                background: #f8fafc;
            }

            details.edit-block summary {
                cursor: pointer;
                color: var(--accent);
                font-weight: 600;
                margin-bottom: 0.75rem;
            }

            details.edit-block[open] {
                background: #fff;
                box-shadow: inset 0 0 0 1px rgba(67, 97, 238, 0.12);
            }

            .empty-state {
                text-align: center;
                padding: 3rem 1rem;
                background: rgba(67, 97, 238, 0.05);
                border: 1px dashed var(--border);
                border-radius: 1rem;
                margin-top: 1rem;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 1rem;
                margin-top: 1rem;
            }

            .stat-card {
                padding: 1rem 1.2rem;
                border-radius: 1rem;
                background: rgba(67, 97, 238, 0.08);
            }

            .stat-card h4 {
                margin: 0;
                font-size: 2rem;
            }

            .stat-card span {
                color: var(--muted);
                font-size: 0.85rem;
            }

            .back-link {
                display: inline-flex;
                margin-top: 2rem;
                text-decoration: none;
                color: var(--accent);
                font-weight: 600;
                gap: 0.4rem;
            }

            @media (max-width: 640px) {
                .material-meta {
                    flex-direction: column;
                }
            }
        </style>
    </head>
    <body>
        <header>
            <h1>Learning Repository Control</h1>
            <p>
                Upload, edit, and retire the safety and training assets that appear inside the learner-facing
                workspace. Every update here flows instantly to the Learning Space and download links.
            </p>
        </header>
        <main>
            <?php if (!empty($messages['success']) || !empty($messages['error'])): ?>
                <div class="flash-stack">
                    <?php foreach ($messages['success'] as $message): ?>
                        <div class="flash success"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($messages['error'] as $message): ?>
                        <div class="flash error"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="panel-grid">
                <section class="panel">
                    <h2>Add New Material</h2>
                    <p class="hint">Maximum upload size: <?php echo $maxUploadMbLabel; ?> MB</p>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create_material" />
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token('learning_material_create'), ENT_QUOTES); ?>" />
                        <label>
                            <span>Title</span>
                            <input type="text" name="title" required value="<?php echo htmlspecialchars($createDraft['title'], ENT_QUOTES); ?>" />
                        </label>
                        <label>
                            <span>Material Type</span>
                            <select name="material_type">
                                <?php foreach ($allowedMaterialTypes as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $createDraft['material_type'] === $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                            <label>
                                <span>Skill Level</span>
                                <select name="skill_level">
                                    <?php foreach ($allowedSkillLevels as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $createDraft['skill_level'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <label>
                            <span>Version (optional)</span>
                            <input type="text" name="version" value="<?php echo htmlspecialchars($createDraft['version'], ENT_QUOTES); ?>" />
                        </label>
                        <label>
                            <span>Upload File</span>
                            <input type="file" name="material_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.mp4,.mov,.m4v,.zip,.rar" />
                        </label>
                        <label>
                            <span>External Link (https://...)</span>
                            <input type="url" name="file_url" placeholder="https://" value="<?php echo htmlspecialchars($createDraft['file_url'], ENT_QUOTES); ?>" />
                        </label>
                        <p class="hint">Provide at least one source: either upload a file or paste a trusted URL.</p>
                        <div class="checkbox-grid">
                            <?php if (empty($equipmentOptions)): ?>
                                <p class="hint">Add equipment records to start linking materials.</p>
                            <?php else: ?>
                                <?php foreach ($equipmentOptions as $equipment): ?>
                                    <?php
                                        $checkboxId = 'new-equip-' . $equipment['id'];
                                        $isChecked = in_array($equipment['id'], $createDraft['equipment'], true);
                                    ?>
                                    <label class="checkbox-card" for="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>">
                                        <input
                                            type="checkbox"
                                            id="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>"
                                            name="equipment_ids[]"
                                            value="<?php echo htmlspecialchars((string) $equipment['id'], ENT_QUOTES); ?>"
                                            <?php echo $isChecked ? 'checked' : ''; ?>
                                        />
                                        <span>
                                            <strong><?php echo htmlspecialchars($equipment['name'], ENT_QUOTES); ?></strong>
                                            <br />
                                            <small><?php echo htmlspecialchars($equipment['category'], ENT_QUOTES); ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="primary">Save Material</button>
                            <button type="reset" class="secondary" onclick="return confirm('Clear the form?');">Clear</button>
                        </div>
                    </form>
                </section>
                <section class="panel">
                    <h3>Repository Snapshot</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h4><?php echo $materialCount; ?></h4>
                            <span>Total materials</span>
                        </div>
                        <div class="stat-card">
                            <h4><?php echo $coveredEquipmentCount; ?></h4>
                            <span>Equipment covered</span>
                        </div>
                        <div class="stat-card">
                            <h4><?php echo $unlinkedMaterialCount; ?></h4>
                            <span>Unlinked materials</span>
                        </div>
                        <div class="stat-card">
                            <h4><?php echo htmlspecialchars($latestUpdatedLabel, ENT_QUOTES); ?></h4>
                            <span>Last update</span>
                        </div>
                    </div>
                    <p class="hint" style="margin-top: 1rem;">
                        Link every high-risk asset to both a SOP (PDF) and a short video so students see context before attempting a booking.
                    </p>
                </section>
            </div>

            <section>
                <h2>Repository Materials</h2>
                <?php if (empty($materials)): ?>
                    <div class="empty-state">
                        <p>No training materials yet. Upload a PDF, SOP, or link to start populating the Learning Space.</p>
                    </div>
                <?php else: ?>
                    <div class="material-list">
                        <?php foreach ($materials as $materialId => $material): ?>
                            <?php
                                $attachedEquipmentIds = array_values($materialEquipmentMap[$materialId] ?? []);
                                $equipmentBadges = [];
                                foreach ($attachedEquipmentIds as $equipmentId) {
                                    $equipmentBadges[] = $equipmentLookup[$equipmentId]['name'] ?? ('Equipment #' . $equipmentId);
                                }
                                $hasLocalFile = $material['file_path'] !== '';
                                $hasRemoteLink = $material['file_url'] !== '';
                                $updateToken = generate_csrf_token('learning_material_update_' . $materialId);
                                $deleteToken = generate_csrf_token('learning_material_delete_' . $materialId);
                            ?>
                            <article class="material-card" id="material-<?php echo htmlspecialchars((string) $materialId, ENT_QUOTES); ?>">
                                <header>
                                    <div class="material-meta">
                                        <span><?php echo htmlspecialchars($allowedMaterialTypes[$material['material_type']] ?? ucfirst($material['material_type']), ENT_QUOTES); ?></span>
                                        <span><?php echo htmlspecialchars($allowedSkillLevels[$material['skill_level']] ?? 'General Overview', ENT_QUOTES); ?></span>
                                        <?php if ($material['version'] !== ''): ?>
                                            <span>Version <?php echo htmlspecialchars($material['version'], ENT_QUOTES); ?></span>
                                        <?php endif; ?>
                                        <span>Updated <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($material['updated_at'] ?: $material['created_at'])), ENT_QUOTES); ?></span>
                                    </div>
                                    <h3><?php echo htmlspecialchars($material['title'], ENT_QUOTES); ?></h3>
                                    <div class="form-actions" style="margin-top: 0.5rem;">
                                        <?php if ($hasLocalFile): ?>
                                            <a class="ghost-button" href="download-material.php?id=<?php echo (int) $materialId; ?>">Download</a>
                                        <?php endif; ?>
                                        <?php if ($hasRemoteLink): ?>
                                            <a class="ghost-button" href="<?php echo htmlspecialchars($material['file_url'], ENT_QUOTES); ?>" target="_blank" rel="noopener">Open Link</a>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('Delete this material? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete_material" />
                                            <input type="hidden" name="material_id" value="<?php echo htmlspecialchars((string) $materialId, ENT_QUOTES); ?>" />
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteToken, ENT_QUOTES); ?>" />
                                            <button type="submit" class="danger">Delete</button>
                                        </form>
                                    </div>
                                </header>
                                <div class="taglist">
                                    <?php if (empty($equipmentBadges)): ?>
                                        <span class="tag" style="background: rgba(100, 116, 139, 0.2); color: var(--muted);">Not linked to any equipment</span>
                                    <?php else: ?>
                                        <?php foreach ($equipmentBadges as $label): ?>
                                            <span class="tag"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <span class="tag" style="background: rgba(5, 150, 105, 0.15); color: #047857;">
                                        Skill: <?php echo htmlspecialchars($allowedSkillLevels[$material['skill_level']] ?? 'General Overview', ENT_QUOTES); ?>
                                    </span>
                                </div>
                                <details class="edit-block">
                                    <summary>Edit this material</summary>
                                    <form method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="update_material" />
                                        <input type="hidden" name="material_id" value="<?php echo htmlspecialchars((string) $materialId, ENT_QUOTES); ?>" />
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($updateToken, ENT_QUOTES); ?>" />
                                        <label>
                                            <span>Title</span>
                                            <input type="text" name="title" required value="<?php echo htmlspecialchars($material['title'], ENT_QUOTES); ?>" />
                                        </label>
                                        <label>
                                            <span>Material Type</span>
                                            <select name="material_type">
                                                <?php foreach ($allowedMaterialTypes as $value => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $material['material_type'] === $value ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Skill Level</span>
                                            <select name="skill_level">
                                                <?php foreach ($allowedSkillLevels as $value => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" <?php echo $material['skill_level'] === $value ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Version</span>
                                            <input type="text" name="version" value="<?php echo htmlspecialchars($material['version'], ENT_QUOTES); ?>" />
                                        </label>
                                        <label>
                                            <span>Replace File (optional)</span>
                                            <input type="file" name="material_file" />
                                        </label>
                                        <?php if ($hasLocalFile): ?>
                                            <label style="display: flex; align-items: center; gap: 0.35rem;">
                                                <input type="checkbox" name="remove_file" value="1" /> Remove existing uploaded file
                                            </label>
                                        <?php endif; ?>
                                        <label>
                                            <span>External Link (https://...)</span>
                                            <input type="url" name="file_url" value="<?php echo htmlspecialchars($material['file_url'], ENT_QUOTES); ?>" />
                                        </label>
                                        <div class="checkbox-grid">
                                            <?php if (empty($equipmentOptions)): ?>
                                                <p class="hint">No equipment found to link.</p>
                                            <?php else: ?>
                                                <?php foreach ($equipmentOptions as $equipment): ?>
                                                    <?php
                                                        $checkboxId = 'edit-' . $materialId . '-' . $equipment['id'];
                                                        $isChecked = in_array($equipment['id'], $attachedEquipmentIds, true);
                                                    ?>
                                                    <label class="checkbox-card" for="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>">
                                                        <input
                                                            type="checkbox"
                                                            id="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES); ?>"
                                                            name="equipment_ids[]"
                                                            value="<?php echo htmlspecialchars((string) $equipment['id'], ENT_QUOTES); ?>"
                                                            <?php echo $isChecked ? 'checked' : ''; ?>
                                                        />
                                                        <span>
                                                            <strong><?php echo htmlspecialchars($equipment['name'], ENT_QUOTES); ?></strong>
                                                            <br />
                                                            <small><?php echo htmlspecialchars($equipment['category'], ENT_QUOTES); ?></small>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="primary">Update Material</button>
                                        </div>
                                    </form>
                                </details>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <a class="back-link" href="admin.php">&larr; Back to Admin Workspace</a>
        </main>
    </body>
</html>
