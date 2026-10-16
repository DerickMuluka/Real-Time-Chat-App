<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    try {
        Database::getConnection()
            ->prepare('UPDATE users SET status = "offline" WHERE id = ?')
            ->execute([(int)$_SESSION['user_id']]);
    } catch (Throwable $e) {}
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($wantsJson) jsonResponse(['success' => true]);

header('Location: ../../public/index.php');
exit;