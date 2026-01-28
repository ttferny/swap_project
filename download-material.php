<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

require_login();

$materialId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$materialId) {
	http_response_code(400);
	exit('Invalid request.');
}

$sql = 'SELECT title, material_type, file_url, file_path FROM training_materials WHERE material_id = ? LIMIT 1';
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

$candidatePath = $filePath !== '' ? $filePath : $fileUrl;

if ($candidatePath === '') {
	http_response_code(404);
	exit('File not available.');
}

if (filter_var($candidatePath, FILTER_VALIDATE_URL)) {
	$pathName = (string) parse_url($candidatePath, PHP_URL_PATH);
	$remoteName = $pathName !== '' ? basename($pathName) : '';
	if ($remoteName !== '') {
		$downloadName = $remoteName;
	}
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
