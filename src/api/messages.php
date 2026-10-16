<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user   = requireAuth();
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$user['id'];

/* ------------------------------------------------------------------
 * GET — messages
 * ------------------------------------------------------------------ */
if ($method === 'GET') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $before = (int)($_GET['before'] ?? 0);
    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));

    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);

    $stmt = $db->prepare('SELECT id, is_private, disappearing_seconds FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) jsonResponse(['error' => 'Room not found'], 404);

    if ((int)$room['is_private'] === 1) {
        $stmt = $db->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
        $stmt->execute([$roomId, $userId]);
        if (!$stmt->fetch()) jsonResponse(['error' => 'Access denied'], 403);
    }

    // Purge expired messages for this room
    $db->prepare('DELETE FROM messages WHERE room_id = ? AND expires_at IS NOT NULL AND expires_at < NOW()')
       ->execute([$roomId]);

    $sql = 'SELECT m.id, m.room_id, m.user_id, m.content, m.message_type,
                   m.attachment, m.attachment_meta, m.is_pinned, m.is_deleted,
                   m.expires_at, m.created_at,
                   u.display_name, u.username, u.avatar_color, u.avatar_path
            FROM messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.room_id = ?';
    $params = [$roomId];

    if ($before > 0) { $sql .= ' AND m.id < ?'; $params[] = $before; }

    $sql .= ' ORDER BY m.id DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = array_reverse($stmt->fetchAll());

    $messages = array_map(static function (array $r): array {
        return [
            'id'              => (int)$r['id'],
            'room_id'         => (int)$r['room_id'],
            'user_id'         => (int)$r['user_id'],
            'content'         => (int)$r['is_deleted'] === 1 ? '' : $r['content'],
            'message_type'    => $r['message_type'],
            'attachment'      => (int)$r['is_deleted'] === 1 ? null : $r['attachment'],
            'attachment_meta' => $r['attachment_meta'] ? json_decode($r['attachment_meta'], true) : null,
            'is_pinned'       => (bool)$r['is_pinned'],
            'is_deleted'      => (bool)$r['is_deleted'],
            'expires_at'      => $r['expires_at'],
            'created_at'      => $r['created_at'],
            'display_name'    => $r['display_name'],
            'username'        => $r['username'],
            'avatar_color'    => $r['avatar_color'],
            'avatar_path'     => $r['avatar_path'],
        ];
    }, $rows);

    jsonResponse(['success' => true, 'messages' => $messages]);
}

/* ------------------------------------------------------------------
 * POST
 * ------------------------------------------------------------------ */
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
        jsonResponse(['error' => 'Invalid session token'], 403);
    }

    $action = (string)($input['action'] ?? 'send');

    /* --- PIN / UNPIN --- */
    if ($action === 'pin' || $action === 'unpin') {
        $msgId = (int)($input['message_id'] ?? 0);
        if ($msgId <= 0) jsonResponse(['error' => 'Invalid message'], 422);

        $stmt = $db->prepare('SELECT room_id FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        $roomId = (int)$stmt->fetchColumn();
        if ($roomId <= 0) jsonResponse(['error' => 'Message not found'], 404);

        requireRoomAdmin($roomId, $userId);

        $val = $action === 'pin' ? 1 : 0;
        $db->prepare('UPDATE messages SET is_pinned = ? WHERE id = ?')->execute([$val, $msgId]);
        jsonResponse(['success' => true, 'is_pinned' => (bool)$val]);
    }

    /* --- DELETE --- */
    if ($action === 'delete') {
        $msgId = (int)($input['message_id'] ?? 0);
        if ($msgId <= 0) jsonResponse(['error' => 'Invalid message'], 422);

        $stmt = $db->prepare('SELECT room_id, user_id FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['error' => 'Message not found'], 404);

        $roomId = (int)$row['room_id'];
        $authorId = (int)$row['user_id'];

        // Allow: author OR room admin
        $allowed = ($authorId === $userId);
        if (!$allowed) {
            $stmt = $db->prepare('SELECT role FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$roomId, $userId]);
            $role = $stmt->fetchColumn();
            $allowed = in_array($role, ['owner', 'admin'], true);
        }
        if (!$allowed) jsonResponse(['error' => 'Not allowed'], 403);

        $db->prepare('UPDATE messages SET is_deleted = 1, content = "", attachment = NULL, attachment_meta = NULL WHERE id = ?')
           ->execute([$msgId]);
        jsonResponse(['success' => true]);
    }

    /* --- SEND MESSAGE --- */
    $roomId  = (int)($input['room_id'] ?? 0);
    $content = trim((string)($input['content'] ?? ''));
    $type    = (string)($input['message_type'] ?? 'text');
    $attach  = $input['attachment'] ?? null;
    $meta    = $input['attachment_meta'] ?? null;

    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);
    if ($content === '' && !$attach) jsonResponse(['error' => 'Message cannot be empty'], 422);
    if (mb_strlen($content) > 4000) jsonResponse(['error' => 'Message too long.'], 422);

    $allowed = ['text','file','image','audio','video','call','sticker'];
    if (!in_array($type, $allowed, true)) $type = 'text';

    $stmt = $db->prepare('SELECT id, is_private, disappearing_seconds FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) jsonResponse(['error' => 'Room not found'], 404);

    $stmt = $db->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$roomId, $userId]);
    if (!$stmt->fetch()) {
        if ((int)$room['is_private'] === 1) jsonResponse(['error' => 'Access denied'], 403);
        $db->prepare('INSERT IGNORE INTO room_members (room_id, user_id, role) VALUES (?, ?, "member")')
           ->execute([$roomId, $userId]);
    }

    // Disappearing message expiry
    $expiresAt = null;
    if ((int)$room['disappearing_seconds'] > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)$room['disappearing_seconds']);
    }

    $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type, attachment, attachment_meta, expires_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$roomId, $userId, $content, $type, $attach, $meta ? json_encode($meta) : null, $expiresAt]);
    $messageId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        'SELECT m.*, u.display_name, u.username, u.avatar_color, u.avatar_path
         FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?'
    );
    $stmt->execute([$messageId]);
    $r = $stmt->fetch();

    jsonResponse(['success' => true, 'message' => [
        'id'              => (int)$r['id'],
        'room_id'         => (int)$r['room_id'],
        'user_id'         => (int)$r['user_id'],
        'content'         => $r['content'],
        'message_type'    => $r['message_type'],
        'attachment'      => $r['attachment'],
        'attachment_meta' => $r['attachment_meta'] ? json_decode($r['attachment_meta'], true) : null,
        'is_pinned'       => false,
        'is_deleted'      => false,
        'expires_at'      => $r['expires_at'],
        'created_at'      => $r['created_at'],
        'display_name'    => $r['display_name'],
        'username'        => $r['username'],
        'avatar_color'    => $r['avatar_color'],
        'avatar_path'     => $r['avatar_path'],
    ]]);
}

jsonResponse(['error' => 'Method not allowed'], 405);