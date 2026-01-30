<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$currentUser = enforce_capability($conn, 'portal.downloads');
$currentUserId = (int) ($currentUser['user_id'] ?? 0);

$materialId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$materialId) {
	http_response_code(400);
	exit('Invalid request.');
}

$sql = 'SELECT title, material_type, file_url, file_path, file_hash FROM training_materials WHERE material_id = ? LIMIT 1';
$stmt = mysqli_prepare($conn, $sql);
if ($stmt === false) {
	http_response_code(500);
	exit('Unable to prepare download.');
}

mysqli_stmt_bind_param($stmt, 'i', $materialId);
if (!mysqli_stmt_execute($stmt)) {
	mysqli_stmt_close($stmt);
	http_response_code(500);
	exit('Unable to load material.');
}

$result = mysqli_stmt_get_result($stmt);
$material = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$material) {
	http_response_code(404);
	exit('Material not found.');
}

$title = trim((string) ($material['title'] ?? 'training-material'));
if ($title === '') {
	$title = 'training-material';
}
$sanitizedTitle = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
if ($sanitizedTitle === '' || $sanitizedTitle === null) {
	$sanitizedTitle = 'training-material';
}
$downloadName = $sanitizedTitle;

$fileUrl = trim((string) ($material['file_url'] ?? ''));
$filePath = trim((string) ($material['file_path'] ?? ''));
$storedHash = trim((string) ($material['file_hash'] ?? ''));

$candidatePath = $filePath !== '' ? $filePath : $fileUrl;

if ($candidatePath === '') {
	http_response_code(404);
	exit('File not available.');
}

$associatedEquipmentIds = [];
$equipmentStmt = mysqli_prepare(
	$conn,
	'  SELECT equipment_id FROM equipment_training_materials WHERE material_id = ? '
);
if ($equipmentStmt) {
	mysqli_stmt_bind_param($equipmentStmt, 'i', $materialId);
	mysqli_stmt_execute($equipmentStmt);
	$equipmentResult = mysqli_stmt_get_result($equipmentStmt);
	if ($equipmentResult) {
		while ($equipmentRow = mysqli_fetch_assoc($equipmentResult)) {
			$equipmentId = isset($equipmentRow['equipment_id']) ? (int) $equipmentRow['equipment_id'] : 0;
			if ($equipmentId > 0) {
				$associatedEquipmentIds[] = $equipmentId;
			}
		}
		mysqli_free_result($equipmentResult);
	}
	mysqli_stmt_close($equipmentStmt);
}

if (!user_can_access_any_equipment($conn, $currentUserId, $associatedEquipmentIds)) {
	http_response_code(403);
	exit('You do not have permission to download this material.');
}

$logMaterialDownload = static function (string $deliveryType) use (&$downloadName, $conn, $currentUserId, $associatedEquipmentIds, $materialId, $title): void {
	$details = [
		'material_id' => $materialId,
		'material_title' => $title,
		'download_name' => $downloadName,
		'delivery' => $deliveryType,
	];
	if (!empty($associatedEquipmentIds)) {
		foreach ($associatedEquipmentIds as $equipmentId) {
			log_audit_event(
				$conn,
				$currentUserId,
				'equipment_resource_download',
				'equipment',
				$equipmentId,
				$details
			);
		}
	} else {
		log_audit_event(
			$conn,
			$currentUserId,
			'training_material_download',
			'training_material',
			$materialId,
			$details
		);
	}
};

if (filter_var($candidatePath, FILTER_VALIDATE_URL)) {
	$pathName = (string) parse_url($candidatePath, PHP_URL_PATH);
	$remoteName = $pathName !== '' ? basename($pathName) : '';
	if ($remoteName !== '') {
		$downloadName = $remoteName;
	}
	$logMaterialDownload('remote_url');
	header('Location: ' . $candidatePath);
	exit;
}


$resolvedPath = $candidatePath;
if (!preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $candidatePath)) {
	$resolvedPath = __DIR__ . DIRECTORY_SEPARATOR . ltrim($candidatePath, '/\\');
} elseif (strpos($candidatePath, '/') === 0 || strpos($candidatePath, '\\') === 0) {
	$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
	if ($docRoot !== false) {
		$resolvedPath = $docRoot . DIRECTORY_SEPARATOR . ltrim($candidatePath, '/\\');
	}
}

if ($storedHash !== '') {
	$computedHash = @hash_file('sha256', $realPath) ?: null;
	if ($computedHash === null || !hash_equals($storedHash, $computedHash)) {
		log_audit_event(
			$conn,
			$currentUserId,
			'material_hash_mismatch',
			'training_material',
			$materialId,
			[
				'expected' => $storedHash,
				'observed' => $computedHash,
				'path' => $candidatePath,
			]
		);
		http_response_code(409);
		exit('File integrity verification failed. Please contact an administrator.');
	}
}

$realPath = realpath($resolvedPath);
if ($realPath === false || !is_file($realPath)) {
	http_response_code(404);
	exit('File not found.');
}

$rootPath = realpath(__DIR__);
if ($rootPath !== false && stripos($realPath, $rootPath) !== 0) {
	http_response_code(403);
	exit('Access denied.');
}

$originalName = basename($realPath);
if ($originalName !== '') {
	$downloadName = $originalName;
} else {
	$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
	if ($extension !== '') {
		$downloadName .= '.' . $extension;
	}
}

$size = filesize($realPath);
if ($size === false) {
	$size = null;
}

$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	if ($finfo !== false) {
		$detected = finfo_file($finfo, $realPath);
		finfo_close($finfo);
		if (is_string($detected) && $detected !== '') {
			$mimeType = $detected;
		}
	}
}

$logMaterialDownload('local_file');

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
if ($size !== null) {
	header('Content-Length: ' . $size);
}

readfile($realPath);
exit;
