<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) {
    error_log('[Converse][login] ' . $e->getMessage());
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

$identifier = trim((string)($input['identifier'] ?? ''));
$password   = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    jsonResponse(['error' => 'Enter your credentials to continue.'], 422);
}

$db = Database::getConnection();
$stmt = $db->prepare('SELECT id, username, password_hash, display_name FROM users WHERE username = ? OR email = ? LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    usleep(random_int(120000, 260000));
    jsonResponse(['error' => 'Invalid credentials. Please try again.'], 401);
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
       ->execute([$newHash, (int)$user['id']]);
}

session_regenerate_id(true);
$_SESSION['user_id']      = (int)$user['id'];
$_SESSION['username']     = $user['username'];
$_SESSION['display_name'] = $user['display_name'];

try { ensureGeneralRoom((int)$user['id']); } catch (Throwable $e) {}

$db->prepare('UPDATE users SET status = "online" WHERE id = ?')->execute([(int)$user['id']]);

jsonResponse(['success' => true]);