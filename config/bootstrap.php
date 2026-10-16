<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

require_once __DIR__ . '/database.php';

function jsonResponse(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireAuth(): array
{
    if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Authentication required'], 401);

    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT id, username, email, display_name, avatar_color, avatar_path, bio, status, status_message, show_last_seen, theme, font_family FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) { session_destroy(); jsonResponse(['error' => 'User not found'], 401); }
    return $user;
}

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function ensureGeneralRoom(int $userId): void
{
    try {
        $db = Database::getConnection();
        $room = $db->query('SELECT id FROM rooms ORDER BY id ASC LIMIT 1')->fetch();

        if (!$room) {
            $db->prepare('INSERT INTO rooms (name, description, created_by) VALUES (?, ?, ?)')
               ->execute(['General', 'Main discussion room', $userId]);
            $roomId = (int)$db->lastInsertId();

            $db->prepare('INSERT INTO messages (room_id, user_id, content, message_type) VALUES (?, ?, ?, "system")')
               ->execute([$roomId, $userId, 'Room created']);

            $db->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, "owner")')
               ->execute([$roomId, $userId]);
        } else {
            $roomId = (int)$room['id'];

            // Ensure someone is the owner
            $hasOwner = (int)$db->query("SELECT COUNT(*) FROM room_members WHERE room_id = {$roomId} AND role='owner'")->fetchColumn();
            if ($hasOwner === 0) {
                $firstId = (int)$db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
                if ($firstId > 0) {
                    $db->prepare('INSERT IGNORE INTO room_members (room_id, user_id, role) VALUES (?, ?, "owner")')
                       ->execute([$roomId, $firstId]);
                }
            }
        }

        $stmt = $db->prepare('SELECT role FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$roomId, $userId]);
        if (!$stmt->fetch()) {
            $db->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, "member")')
               ->execute([$roomId, $userId]);
        }
    } catch (Throwable $e) {
        error_log('[Converse] ensureGeneralRoom: ' . $e->getMessage());
    }
}

function requireRoomAdmin(int $roomId, int $userId): string
{
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT role FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$roomId, $userId]);
    $row = $stmt->fetch();
    if (!$row || !in_array($row['role'], ['owner', 'admin'], true)) {
        jsonResponse(['error' => 'You do not have permission to do this.'], 403);
    }
    return $row['role'];
}

function pushNotification(int $userId, string $type, string $title, string $body = '', string $link = ''): void
{
    try {
        Database::getConnection()
            ->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $type, $title, $body, $link]);
    } catch (Throwable $e) {
        error_log('[Converse] pushNotification: ' . $e->getMessage());
    }
}