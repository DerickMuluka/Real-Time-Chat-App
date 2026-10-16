<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user   = requireAuth();
$db     = Database::getConnection();
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $db->prepare('SELECT id, type, title, body, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_read'] = (bool)$r['is_read']; }
        unset($r);

        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $unread = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $rows = []; $unread = 0;
    }
    jsonResponse(['success' => true, 'notifications' => $rows, 'unread' => $unread]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!verifyCsrfToken($input['csrf_token'] ?? '')) jsonResponse(['error' => 'Invalid session token'], 403);

    $action = (string)($input['action'] ?? 'read-all');
    try {
        if ($action === 'read-all') {
            $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
        } elseif ($action === 'read') {
            $id = (int)($input['id'] ?? 0);
            $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        } elseif ($action === 'clear') {
            $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        }
    } catch (Throwable $e) {}
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);