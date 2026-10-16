<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user   = requireAuth();
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$user['id'];

/* GET */
if ($method === 'GET') {
    $stmt = $db->prepare(
        'SELECT r.id, r.name, r.description, r.image_path, r.wallpaper, r.theme, r.font_family,
                r.disappearing_seconds, r.is_private, r.created_by, r.created_at,
                (SELECT content FROM messages m WHERE m.room_id = r.id AND m.is_deleted = 0 ORDER BY m.created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM messages m WHERE m.room_id = r.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM room_members rm2 WHERE rm2.room_id = r.id) AS member_count,
                (SELECT role FROM room_members rm3 WHERE rm3.room_id = r.id AND rm3.user_id = ?) AS my_role
         FROM rooms r
         WHERE r.is_private = 0 OR r.id IN (SELECT room_id FROM room_members WHERE user_id = ?)
         ORDER BY COALESCE(last_at, r.created_at) DESC'
    );
    $stmt->execute([$userId, $userId]);
    $rooms = $stmt->fetchAll();

    foreach ($rooms as &$r) {
        $r['id'] = (int)$r['id'];
        $r['created_by'] = (int)$r['created_by'];
        $r['is_private'] = (bool)$r['is_private'];
        $r['member_count'] = (int)$r['member_count'];
        $r['disappearing_seconds'] = (int)$r['disappearing_seconds'];
        $r['is_admin'] = in_array($r['my_role'] ?? '', ['owner','admin'], true);
        $r['is_owner'] = ($r['my_role'] ?? '') === 'owner';
    }
    unset($r);

    jsonResponse(['success' => true, 'rooms' => $rooms]);
}

/* POST */
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrfToken($input['csrf_token'] ?? '')) jsonResponse(['error' => 'Invalid session token'], 403);

$action = (string)($input['action'] ?? 'create');

/* CREATE */
if ($action === 'create') {
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) jsonResponse(['error' => 'Room name must be 2–100 characters.'], 422);

    $stmt = $db->prepare('SELECT id FROM rooms WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    if ($stmt->fetch()) jsonResponse(['error' => 'A room with that name already exists.'], 409);

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO rooms (name, description, created_by, is_private) VALUES (?, ?, ?, 0)')
           ->execute([$name, $description, $userId]);
        $roomId = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, "owner")')
           ->execute([$roomId, $userId]);

        $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type) VALUES (?, ?, ?, "system")')
           ->execute([$roomId, $userId, $user['display_name'] . ' created this room']);

        $db->commit();

        jsonResponse(['success' => true, 'room' => [
            'id' => $roomId, 'name' => $name, 'description' => $description,
            'is_private' => false, 'member_count' => 1,
            'is_admin' => true, 'is_owner' => true,
            'wallpaper' => 'default', 'theme' => 'default', 'font_family' => 'inter',
            'disappearing_seconds' => 0,
            'image_path' => null, 'created_by' => $userId,
        ]]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonResponse(['error' => 'Could not create room.'], 500);
    }
}

/* UPDATE */
if ($action === 'update') {
    $roomId = (int)($input['room_id'] ?? 0);
    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);

    requireRoomAdmin($roomId, $userId);

    $updates = [];
    $params  = [];

    if (isset($input['name'])) {
        $name = trim((string)$input['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) jsonResponse(['error' => 'Room name must be 2–100 characters.'], 422);

        $stmt = $db->prepare('SELECT id FROM rooms WHERE name = ? AND id != ? LIMIT 1');
        $stmt->execute([$name, $roomId]);
        if ($stmt->fetch()) jsonResponse(['error' => 'A room with that name already exists.'], 409);

        $updates[] = 'name = ?';
        $params[] = $name;
    }

    if (isset($input['description'])) {
        $updates[] = 'description = ?';
        $params[] = trim((string)$input['description']);
    }

    if (isset($input['wallpaper'])) {
        $allowed = ['default','aurora','sunset','ocean','forest','midnight','lavender','paper','space','nebula'];
        if (in_array($input['wallpaper'], $allowed, true)) {
            $updates[] = 'wallpaper = ?';
            $params[] = $input['wallpaper'];
        }
    }

    if (isset($input['theme'])) {
        $allowed = ['default','dark','light','midnight','emerald','rose'];
        if (in_array($input['theme'], $allowed, true)) {
            $updates[] = 'theme = ?';
            $params[] = $input['theme'];
        }
    }

    if (isset($input['font_family'])) {
        $allowed = ['inter','roboto','mono','serif','rounded'];
        if (in_array($input['font_family'], $allowed, true)) {
            $updates[] = 'font_family = ?';
            $params[] = $input['font_family'];
        }
    }

    if (isset($input['disappearing_seconds'])) {
        $s = max(0, min(604800, (int)$input['disappearing_seconds']));
        $updates[] = 'disappearing_seconds = ?';
        $params[] = $s;
    }

    if (isset($input['image_path'])) {
        $updates[] = 'image_path = ?';
        $params[] = (string)$input['image_path'];
    }

    if (empty($updates)) jsonResponse(['error' => 'Nothing to update'], 422);

    $params[] = $roomId;
    $db->prepare('UPDATE rooms SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    if (isset($input['name'])) {
        $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type) VALUES (?, ?, ?, "system")')
           ->execute([$roomId, $userId, $user['display_name'] . ' renamed the room to "' . trim((string)$input['name']) . '"']);
    }

    jsonResponse(['success' => true]);
}

/* DELETE */
if ($action === 'delete') {
    $roomId = (int)($input['room_id'] ?? 0);
    if ($roomId <= 0) jsonResponse(['error' => 'Invalid room'], 422);

    $role = requireRoomAdmin($roomId, $userId);
    if ($role !== 'owner') jsonResponse(['error' => 'Only the room owner can delete this room.'], 403);

    $db->prepare('DELETE FROM rooms WHERE id = ?')->execute([$roomId]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);