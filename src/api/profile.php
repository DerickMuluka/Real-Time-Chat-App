<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$user   = requireAuth();
$db     = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$user['id'];

if ($method === 'GET') {
    jsonResponse(['success' => true, 'user' => [
        'id'             => $userId,
        'username'       => $user['username'],
        'display_name'   => $user['display_name'],
        'avatar_color'   => $user['avatar_color'],
        'avatar_path'    => $user['avatar_path'],
        'bio'            => $user['bio'],
        'status'         => $user['status'],
        'status_message' => $user['status_message'],
        'show_last_seen' => (bool)$user['show_last_seen'],
        'theme'          => $user['theme'],
        'font_family'    => $user['font_family'],
    ]]);
}

if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrfToken($input['csrf_token'] ?? '')) jsonResponse(['error' => 'Invalid session token'], 403);

$updates = [];
$params  = [];

if (isset($input['display_name'])) {
    $dn = trim((string)$input['display_name']);
    if (mb_strlen($dn) < 2 || mb_strlen($dn) > 100) jsonResponse(['error' => 'Display name must be 2–100 characters.'], 422);
    $updates[] = 'display_name = ?'; $params[] = $dn;
}

if (isset($input['bio'])) {
    $bio = trim((string)$input['bio']);
    if (mb_strlen($bio) > 160) jsonResponse(['error' => 'Bio must be 160 characters or less.'], 422);
    $updates[] = 'bio = ?'; $params[] = $bio;
}

if (isset($input['status'])) {
    $status = (string)$input['status'];
    if (!in_array($status, ['online','away','busy','offline'], true)) jsonResponse(['error' => 'Invalid status.'], 422);
    $updates[] = 'status = ?'; $params[] = $status;
}

if (isset($input['status_message'])) {
    $sm = trim((string)$input['status_message']);
    if (mb_strlen($sm) > 120) jsonResponse(['error' => 'Status message must be 120 chars or less.'], 422);
    $updates[] = 'status_message = ?'; $params[] = $sm;
}

if (isset($input['show_last_seen'])) {
    $updates[] = 'show_last_seen = ?'; $params[] = !empty($input['show_last_seen']) ? 1 : 0;
}

if (isset($input['avatar_color'])) {
    $color = (string)$input['avatar_color'];
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) jsonResponse(['error' => 'Invalid color.'], 422);
    $updates[] = 'avatar_color = ?'; $params[] = $color;
}

if (isset($input['avatar_path'])) {
    $updates[] = 'avatar_path = ?'; $params[] = (string)$input['avatar_path'];
}

if (isset($input['theme'])) {
    $allowed = ['default','dark','light','midnight','emerald','rose'];
    if (in_array($input['theme'], $allowed, true)) {
        $updates[] = 'theme = ?'; $params[] = $input['theme'];
    }
}

if (isset($input['font_family'])) {
    $allowed = ['inter','roboto','mono','serif','rounded'];
    if (in_array($input['font_family'], $allowed, true)) {
        $updates[] = 'font_family = ?'; $params[] = $input['font_family'];
    }
}

if (empty($updates)) jsonResponse(['error' => 'Nothing to update'], 422);

$params[] = $userId;
$db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

jsonResponse(['success' => true]);