<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user = requireAuth();
$db   = Database::getConnection();

$stmt = $db->prepare(
    'SELECT id, username, display_name, avatar_color, avatar_path, bio,
            status, status_message, show_last_seen, last_seen
     FROM users WHERE id != ? ORDER BY display_name ASC LIMIT 500'
);
$stmt->execute([(int)$user['id']]);
$users = $stmt->fetchAll();

foreach ($users as &$u) {
    $u['id'] = (int)$u['id'];
    $u['show_last_seen'] = (bool)$u['show_last_seen'];
    if (!$u['show_last_seen']) $u['last_seen'] = null;
}
unset($u);

jsonResponse(['success' => true, 'users' => $users]);