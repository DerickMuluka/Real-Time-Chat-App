<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log('[Converse][register] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Server error. Please try again.']);
    exit;
});

require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
    jsonResponse(['error' => 'Session expired. Please refresh the page.'], 403);
}

$displayName = trim((string)($input['display_name'] ?? ''));
$username    = trim((string)($input['username'] ?? ''));
$email       = trim((string)($input['email'] ?? ''));
$password    = (string)($input['password'] ?? '');

if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
    jsonResponse(['error' => 'Display name must be between 2 and 100 characters.'], 422);
}
if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
    jsonResponse(['error' => 'Username must be 3–50 characters (letters, numbers, underscores).'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    jsonResponse(['error' => 'Please enter a valid email address.'], 422);
}
if (strlen($password) < 8) {
    jsonResponse(['error' => 'Password must be at least 8 characters.'], 422);
}

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    jsonResponse(['error' => 'Database unavailable. Check XAMPP MySQL is running.'], 500);
}

$stmt = $db->prepare('SELECT id, username FROM users WHERE username = ? OR email = ? LIMIT 1');
$stmt->execute([$username, $email]);
$existing = $stmt->fetch();

if ($existing) {
    if (strcasecmp($existing['username'], $username) === 0) {
        jsonResponse(['error' => 'That username is already taken.'], 409);
    }
    jsonResponse(['error' => 'That email is already registered.'], 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$palette = ['#6366f1','#8b5cf6','#ec4899','#f43f5e','#f59e0b','#10b981','#06b6d4','#3b82f6'];
$color = $palette[array_rand($palette)];

try {
    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, display_name, avatar_color) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, $email, $hash, $displayName, $color]);
    $userId = (int)$db->lastInsertId();
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error' => 'Could not create account. Please try again.'], 500);
}

try { ensureGeneralRoom($userId); } catch (Throwable $e) {}

jsonResponse([
    'success' => true,
    'message' => 'Account created. Please sign in to continue.',
    'user' => ['id' => $userId, 'username' => $username, 'display_name' => $displayName],
]);