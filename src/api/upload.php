<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log('[Converse][upload] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/bootstrap.php';

$user = requireAuth();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// CSRF check — accept from POST body only
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) {
    jsonResponse(['error' => 'Invalid session token'], 403);
}

if (empty($_FILES['file'])) {
    jsonResponse(['error' => 'No file uploaded. Check upload_max_filesize / post_max_size in php.ini.'], 422);
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'Upload interrupted',
        UPLOAD_ERR_NO_FILE    => 'No file',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
    ];
    $code = $_FILES['file']['error'];
    jsonResponse(['error' => $errors[$code] ?? 'Unknown upload error'], 422);
}

$kind = (string)($_POST['kind'] ?? 'file');
if (!in_array($kind, ['avatar', 'room', 'file'], true)) {
    $kind = 'file';
}

$file = $_FILES['file'];
$maxBytes = match ($kind) {
    'avatar', 'room' => 5 * 1024 * 1024,
    'file'           => 30 * 1024 * 1024,
};

if ($file['size'] > $maxBytes) {
    jsonResponse(['error' => 'File too large. Max ' . round($maxBytes / 1048576) . ' MB.'], 422);
}

/* ----------------------------------------------------------------
 * Detect MIME — try finfo, fall back to extension map
 * ---------------------------------------------------------------- */
$mime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = @$finfo->file($file['tmp_name']) ?: '';
}
if ($mime === '') {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $map = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
        'pdf'=>'application/pdf','zip'=>'application/zip','txt'=>'text/plain','csv'=>'text/csv',
        'doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'=>'application/vnd.ms-powerpoint',
        'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4','weba'=>'audio/webm',
        'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','ogv'=>'video/ogg',
    ];
    $mime = $map[$ext] ?? 'application/octet-stream';
}

$imageTypes = ['image/jpeg','image/png','image/gif','image/webp'];
$okTypes = match ($kind) {
    'avatar', 'room' => $imageTypes,
    'file' => array_merge($imageTypes, [
        'application/pdf','application/zip','application/x-zip-compressed',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain','text/csv',
        'audio/mpeg','audio/wav','audio/ogg','audio/mp4','audio/webm','audio/x-m4a',
        'video/mp4','video/webm','video/quicktime','video/ogg',
    ]),
};

if (!in_array($mime, $okTypes, true)) {
    jsonResponse(['error' => 'File type not allowed: ' . $mime], 422);
}

$ext = match ($mime) {
    'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
    'application/pdf'=>'pdf','application/zip','application/x-zip-compressed'=>'zip',
    'application/msword'=>'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/vnd.ms-excel'=>'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    'application/vnd.ms-powerpoint'=>'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
    'text/plain'=>'txt','text/csv'=>'csv',
    'audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/ogg'=>'ogg','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','audio/webm'=>'weba',
    'video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','video/ogg'=>'ogv',
    default => 'bin',
};

/* ----------------------------------------------------------------
 * Destination folder
 * ---------------------------------------------------------------- */
$publicDir = realpath(__DIR__ . '/../../public');
if ($publicDir === false) {
    jsonResponse(['error' => 'Public directory not found'], 500);
}

$uploadsRoot = $publicDir . DIRECTORY_SEPARATOR . 'uploads';
$subdir = match ($kind) {
    'avatar' => 'avatars',
    'room'   => 'rooms',
    default  => 'files',
};
$targetDir = $uploadsRoot . DIRECTORY_SEPARATOR . $subdir;

if (!is_dir($targetDir)) {
    if (!@mkdir($targetDir, 0777, true)) {
        jsonResponse(['error' => 'Could not create upload directory: ' . $subdir], 500);
    }
}

if (!is_writable($targetDir)) {
    jsonResponse(['error' => 'Upload directory not writable: ' . $subdir], 500);
}

$filename = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(8)), $ext);
$target = $targetDir . DIRECTORY_SEPARATOR . $filename;

if (!@move_uploaded_file($file['tmp_name'], $target)) {
    jsonResponse(['error' => 'Could not save file (move_uploaded_file failed).'], 500);
}

@chmod($target, 0644);

$publicPath = 'uploads/' . $subdir . '/' . $filename;

jsonResponse([
    'success'  => true,
    'path'     => $publicPath,
    'mime'     => $mime,
    'size'     => (int)$file['size'],
    'filename' => $file['name'],
]);