<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user   = requireAuth();
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$user['id'];

if ($method === 'GET') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);

    $stmt = $db->prepare('SELECT role FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$roomId, $userId]);
    $me = $stmt->fetch();
    if (!$me) jsonResponse(['error' => 'Access denied'], 403);

    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.display_name, u.avatar_color, u.avatar_path,
                u.status, u.status_message, u.show_last_seen, u.last_seen,
                rm.role, rm.joined_at
         FROM room_members rm
         JOIN users u ON u.id = rm.user_id
         WHERE rm.room_id = ?
         ORDER BY FIELD(rm.role, "owner","admin","member"), u.display_name ASC'
    );
    $stmt->execute([$roomId]);
    $members = $stmt->fetchAll();
    foreach ($members as &$m) {
        $m['id'] = (int)$m['id'];
        $m['show_last_seen'] = (bool)$m['show_last_seen'];
        if (!$m['show_last_seen'] && (int)$m['id'] !== $userId) $m['last_seen'] = null;
    }
    unset($m);
    jsonResponse(['success' => true, 'members' => $members, 'my_role' => $me['role']]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!verifyCsrfToken($input['csrf_token'] ?? '')) jsonResponse(['error' => 'Invalid session token'], 403);

    $action = (string)($input['action'] ?? '');
    $roomId = (int)($input['room_id'] ?? 0);
    $target = (int)($input['user_id'] ?? 0);
    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);

    $stmt = $db->prepare('SELECT role FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$roomId, $userId]);
    $myRole = $stmt->fetchColumn();
    if (!$myRole) jsonResponse(['error' => 'You are not a member of this room.'], 403);

    $canManage = in_array($myRole, ['owner', 'admin'], true);

    if ($action === 'add') {
        if (!$canManage) jsonResponse(['error' => 'Only room admins can add members.'], 403);
        if ($target <= 0) jsonResponse(['error' => 'Invalid user'], 422);

        $stmt = $db->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$roomId, $target]);
        if ($stmt->fetch()) jsonResponse(['error' => 'User is already in this room.'], 409);

        $db->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, "member")')->execute([$roomId, $target]);

        $stmt = $db->prepare('SELECT display_name FROM users WHERE id = ?'); $stmt->execute([$target]);
        $added = $stmt->fetch();

        $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type) VALUES (?, ?, ?, "system")')
           ->execute([$roomId, $userId, $user['display_name'] . ' added ' . ($added['display_name'] ?? 'a member')]);

        $stmt = $db->prepare('SELECT name FROM rooms WHERE id = ?'); $stmt->execute([$roomId]);
        $roomName = $stmt->fetchColumn() ?: 'a room';
        pushNotification($target, 'added_to_room', 'Added to room', 'You were added to "' . $roomName . '"', 'chat.php');

        jsonResponse(['success' => true]);
    }

    if ($action === 'remove') {
        if (!$canManage) jsonResponse(['error' => 'Not allowed'], 403);
        if ($target === $userId) jsonResponse(['error' => 'Use leave instead'], 422);

        $stmt = $db->prepare('SELECT role, u.display_name FROM room_members rm JOIN users u ON u.id = rm.user_id WHERE rm.room_id = ? AND rm.user_id = ? LIMIT 1');
        $stmt->execute([$roomId, $target]);
        $t = $stmt->fetch();
        $targetRole = $t['role'] ?? '';
        if (!$targetRole) jsonResponse(['error' => 'User is not in this room.'], 404);
        if ($targetRole === 'owner') jsonResponse(['error' => 'Cannot remove the room owner.'], 403);
        if ($myRole === 'admin' && $targetRole === 'admin') jsonResponse(['error' => 'Admins cannot remove other admins.'], 403);

        $db->prepare('DELETE FROM room_members WHERE room_id = ? AND user_id = ?')->execute([$roomId, $target]);
        $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type) VALUES (?, ?, ?, "system")')
           ->execute([$roomId, $userId, ($t['display_name'] ?? 'A member') . ' was removed']);
        pushNotification($target, 'removed_from_room', 'Removed from room', 'You were removed from a room', 'chat.php');
        jsonResponse(['success' => true]);
    }

    if ($action === 'promote' || $action === 'demote') {
        if ($myRole !== 'owner') jsonResponse(['error' => 'Only owner can change roles.'], 403);
        if ($target === $userId) jsonResponse(['error' => 'You cannot change your own role.'], 422);

        $newRole = $action === 'promote' ? 'admin' : 'member';
        $db->prepare('UPDATE room_members SET role = ? WHERE room_id = ? AND user_id = ?')->execute([$newRole, $roomId, $target]);
        jsonResponse(['success' => true]);
    }

    if ($action === 'leave') {
        if ($myRole === 'owner') jsonResponse(['error' => 'Owners must transfer ownership or delete the room.'], 403);
        $db->prepare('DELETE FROM room_members WHERE room_id = ? AND user_id = ?')->execute([$roomId, $userId]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown action'], 422);
}

jsonResponse(['error' => 'Method not allowed'], 405);