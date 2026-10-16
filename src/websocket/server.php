<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

final class ConverseSocketServer
{
    private const HOST = '0.0.0.0';
    private const PORT = 8080;

    private $server;
    private array $clients = [];
    private array $meta = [];
    private PDO $db;
    private bool $running = true;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function run(): void
    {
        $this->server = stream_socket_server('tcp://' . self::HOST . ':' . self::PORT, $errno, $errstr);
        if (!$this->server) {
            fwrite(STDERR, "[Converse] Failed to bind: $errstr\n");
            exit(1);
        }
        stream_set_blocking($this->server, false);

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT,  fn() => $this->shutdown());
            pcntl_signal(SIGTERM, fn() => $this->shutdown());
        }

        echo "[Converse] WebSocket server listening on ws://" . self::HOST . ':' . self::PORT . "\n";
        echo "[Converse] Press Ctrl+C to stop.\n";

        while ($this->running) {
            $read   = array_merge([$this->server], array_values($this->clients));
            $write  = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) continue;

            foreach ($read as $stream) {
                if ($stream === $this->server) $this->acceptNewClient();
                else $this->readFromClient($stream);
            }
        }
    }

    private function acceptNewClient(): void
    {
        $conn = @stream_socket_accept($this->server, 0);
        if (!$conn) return;
        stream_set_blocking($conn, false);
        $id = (int)$conn;
        $this->clients[$id] = $conn;
        $this->meta[$id] = ['user_id' => 0, 'rooms' => [], 'handshaked' => false, 'buffer' => ''];
    }

    private function readFromClient($conn): void
    {
        $id   = (int)$conn;
        $data = @fread($conn, 8192);
        if ($data === '' || $data === false) { if (feof($conn)) $this->disconnect($id); return; }

        $this->meta[$id]['buffer'] .= $data;

        if (!$this->meta[$id]['handshaked']) {
            if (!$this->tryHandshake($id)) return;
        }

        while (($frame = $this->decodeFrame($this->meta[$id]['buffer'])) !== null) {
            [$opcode, $payload, $consumed] = $frame;
            $this->meta[$id]['buffer'] = substr($this->meta[$id]['buffer'], $consumed);

            if ($opcode === 0x8) { $this->disconnect($id); return; }
            if ($opcode === 0x9) { $this->sendRaw($id, $payload, 0xA); continue; }
            if ($opcode === 0xA) continue;
            if ($opcode === 0x1 || $opcode === 0x2) $this->handleMessage($id, $payload);
        }
    }

    private function tryHandshake(int $id): bool
    {
        $buffer = $this->meta[$id]['buffer'];
        $end    = strpos($buffer, "\r\n\r\n");
        if ($end === false) { if (strlen($buffer) > 16384) $this->disconnect($id); return false; }

        $headersRaw = substr($buffer, 0, $end);
        $this->meta[$id]['buffer'] = substr($buffer, $end + 4);

        $lines   = explode("\r\n", $headersRaw);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        if (empty($headers['sec-websocket-key'])) { $this->disconnect($id); return false; }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
        @fwrite($this->clients[$id], $response);
        $this->meta[$id]['handshaked'] = true;
        return true;
    }

    private function decodeFrame(string $buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) return null;
        $b1 = ord($buffer[0]); $b2 = ord($buffer[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) { if ($len < $offset + 2) return null; $payloadLen = unpack('n', substr($buffer, $offset, 2))[1]; $offset += 2; }
        elseif ($payloadLen === 127) { if ($len < $offset + 8) return null; $payloadLen = unpack('J', substr($buffer, $offset, 8))[1]; $offset += 8; }

        $maskKey = '';
        if ($masked) { if ($len < $offset + 4) return null; $maskKey = substr($buffer, $offset, 4); $offset += 4; }
        if ($len < $offset + $payloadLen) return null;
        $payload = substr($buffer, $offset, $payloadLen);
        $offset += $payloadLen;

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            $payload = $unmasked;
        }
        return [$opcode, $payload, $offset];
    }

    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $len   = strlen($payload);
        $frame = chr(0x80 | $opcode);
        if ($len < 126) $frame .= chr($len);
        elseif ($len < 65536) $frame .= chr(126) . pack('n', $len);
        else $frame .= chr(127) . pack('J', $len);
        return $frame . $payload;
    }

    private function sendRaw(int $id, string $payload, int $opcode = 0x1): void
    {
        if (!isset($this->clients[$id]) || !$this->meta[$id]['handshaked']) return;
        @fwrite($this->clients[$id], $this->encodeFrame($payload, $opcode));
    }

    private function sendJson(int $id, array $payload): void
    {
        $this->sendRaw($id, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function disconnect(int $id): void
    {
        $meta = $this->meta[$id] ?? null;
        if ($meta && $meta['user_id'] > 0) {
            try {
                $this->db->prepare('UPDATE users SET status = "offline" WHERE id = ?')->execute([(int)$meta['user_id']]);
                $this->broadcastPresence((int)$meta['user_id'], 'offline');
            } catch (Throwable $e) {}
        }
        if (isset($this->clients[$id])) @fclose($this->clients[$id]);
        unset($this->clients[$id], $this->meta[$id]);
    }

    private function handleMessage(int $id, string $raw): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['type'])) {
            $this->sendJson($id, ['type' => 'error', 'error' => 'Invalid payload']);
            return;
        }
        switch ($data['type']) {
            case 'auth':       $this->handleAuth($id, $data); break;
            case 'join_room':  $this->handleJoin($id, $data); break;
            case 'leave_room': $this->handleLeave($id, $data); break;
            case 'message':    $this->handleChatMessage($id, $data); break;
            case 'typing':     $this->handleTyping($id, $data); break;
            case 'status':     $this->handleStatus($id, $data); break;
            case 'call-signal':$this->handleCallSignal($id, $data); break;
            case 'ping':       $this->sendJson($id, ['type' => 'pong', 'ts' => time()]); break;
            default:           $this->sendJson($id, ['type' => 'error', 'error' => 'Unknown type']);
        }
    }

    private function handleAuth(int $id, array $data): void
    {
        $sid = (string)($data['session_id'] ?? '');
        if ($sid === '') { $this->sendJson($id, ['type' => 'auth', 'success' => false]); return; }

        $user = $this->resolveSessionUser($sid);
        if (!$user) { $this->sendJson($id, ['type' => 'auth', 'success' => false, 'error' => 'Session invalid']); return; }

        $this->meta[$id]['user_id'] = (int)$user['id'];
        try {
            $this->db->prepare('UPDATE users SET status = "online" WHERE id = ?')->execute([(int)$user['id']]);
        } catch (Throwable $e) {}

        $this->sendJson($id, ['type' => 'auth', 'success' => true, 'user_id' => (int)$user['id'], 'display_name' => $user['display_name']]);
        $this->broadcastPresence((int)$user['id'], 'online');
    }

    private function handleJoin(int $id, array $data): void
    {
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) return;
        $this->meta[$id]['rooms'][$roomId] = true;
        $this->sendJson($id, ['type' => 'joined', 'room_id' => $roomId]);
    }

    private function handleLeave(int $id, array $data): void
    {
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) return;
        unset($this->meta[$id]['rooms'][$roomId]);
        $this->sendJson($id, ['type' => 'left', 'room_id' => $roomId]);
    }

    private function handleChatMessage(int $id, array $data): void
    {
        $userId = (int)($this->meta[$id]['user_id'] ?? 0);
        if ($userId <= 0) { $this->sendJson($id, ['type' => 'error', 'error' => 'Not authenticated']); return; }

        $roomId  = (int)($data['room_id'] ?? 0);
        $content = trim((string)($data['content'] ?? ''));
        $type    = (string)($data['message_type'] ?? 'text');
        $attach  = $data['attachment'] ?? null;
        $meta    = $data['attachment_meta'] ?? null;

        if ($roomId <= 0 || ($content === '' && !$attach)) return;
        if (mb_strlen($content) > 2000) { $this->sendJson($id, ['type' => 'error', 'error' => 'Message too long']); return; }

        try {
            $stmt = $this->db->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$roomId, $userId]);
            if (!$stmt->fetch()) { $this->sendJson($id, ['type' => 'error', 'error' => 'Not a member']); return; }

            $this->db->prepare('INSERT INTO messages (room_id, user_id, content, message_type, attachment, attachment_meta) VALUES (?, ?, ?, ?, ?, ?)')
                     ->execute([$roomId, $userId, $content, $type, $attach, $meta ? json_encode($meta) : null]);
            $msgId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare('SELECT m.*, u.display_name, u.username, u.avatar_color, u.avatar_path
                                        FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?');
            $stmt->execute([$msgId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $this->sendJson($id, ['type' => 'error', 'error' => 'Could not send']);
            return;
        }

        $payload = ['type' => 'message', 'message' => [
            'id' => (int)$row['id'], 'room_id' => (int)$row['room_id'], 'user_id' => (int)$row['user_id'],
            'content' => $row['content'], 'message_type' => $row['message_type'],
            'attachment' => $row['attachment'],
            'attachment_meta' => $row['attachment_meta'] ? json_decode($row['attachment_meta'], true) : null,
            'created_at' => $row['created_at'], 'display_name' => $row['display_name'],
            'username' => $row['username'], 'avatar_color' => $row['avatar_color'], 'avatar_path' => $row['avatar_path'],
        ]];

        $this->broadcastToRoom($roomId, $payload);
    }

    private function handleTyping(int $id, array $data): void
    {
        $userId = (int)($this->meta[$id]['user_id'] ?? 0);
        if ($userId <= 0) return;
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) return;

        $this->broadcastToRoom($roomId, [
            'type' => 'typing', 'room_id' => $roomId, 'user_id' => $userId,
            'typing' => !empty($data['typing']),
        ], $id);
    }

    private function handleStatus(int $id, array $data): void
    {
        $userId = (int)($this->meta[$id]['user_id'] ?? 0);
        if ($userId <= 0) return;
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['online','away','busy','offline'], true)) return;
        try { $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]); } catch (Throwable $e) {}
        $this->broadcastPresence($userId, $status);
    }

    private function handleCallSignal(int $id, array $data): void
    {
        $userId = (int)($this->meta[$id]['user_id'] ?? 0);
        if ($userId <= 0) return;
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) return;

        $this->broadcastToRoom($roomId, [
            'type' => 'call-signal',
            'room_id' => $roomId,
            'from' => $userId,
            'signal' => $data['signal'] ?? null,
            'call_type' => $data['call_type'] ?? 'audio',
            'action' => $data['action'] ?? '',
        ], $id);
    }

    private function broadcastToRoom(int $roomId, array $payload, ?int $exceptId = null): void
    {
        foreach ($this->meta as $cid => $meta) {
            if ($exceptId !== null && $cid === $exceptId) continue;
            if (!empty($meta['rooms'][$roomId])) $this->sendJson($cid, $payload);
        }
    }

    private function broadcastPresence(int $userId, string $status): void
    {
        $payload = ['type' => 'presence', 'user_id' => $userId, 'status' => $status, 'ts' => time()];
        foreach ($this->meta as $cid => $meta) {
            if (($meta['user_id'] ?? 0) > 0 && $meta['user_id'] !== $userId) $this->sendJson($cid, $payload);
        }
    }

    private function resolveSessionUser(string $sessionId): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9,\-]{22,128}$/', $sessionId)) return null;

        $savePath = session_save_path() ?: sys_get_temp_dir();
        if (str_contains($savePath, ';')) { $p = explode(';', $savePath); $savePath = end($p); }

        $file = rtrim($savePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (!is_readable($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        $sessionData = $this->decodeSession($raw);
        $userId = (int)($sessionData['user_id'] ?? 0);
        if ($userId <= 0) return null;

        try {
            $stmt = $this->db->prepare('SELECT id, username, display_name FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) { return null; }
    }

    private function decodeSession(string $raw): array
    {
        $result = []; $offset = 0; $len = strlen($raw);
        while ($offset < $len) {
            $pos = strpos($raw, '|', $offset);
            if ($pos === false) break;
            $key = substr($raw, $offset, $pos - $offset);
            $offset = $pos + 1;
            if ($offset >= $len) break;
            $type = $raw[$offset]; $offset++;
            if ($type === 's') {
                $colon = strpos($raw, ':', $offset); if ($colon === false) break;
                $strLen = (int)substr($raw, $offset, $colon - $offset);
                $offset = $colon + 1;
                if ($offset < $len && $raw[$offset] === '"') $offset++;
                $result[$key] = substr($raw, $offset, $strLen);
                $offset += $strLen + 2;
            } elseif ($type === 'i') {
                $colon = strpos($raw, ':', $offset); if ($colon === false) break;
                $result[$key] = (int)substr($raw, $offset, $colon - $offset); $offset = $colon + 2;
            } elseif ($type === 'b') {
                $colon = strpos($raw, ':', $offset); if ($colon === false) break;
                $result[$key] = (bool)substr($raw, $offset, $colon - $offset); $offset = $colon + 2;
            } elseif ($type === 'd') {
                $colon = strpos($raw, ':', $offset); if ($colon === false) break;
                $result[$key] = (float)substr($raw, $offset, $colon - $offset); $offset = $colon + 2;
            } else {
                $semi = strpos($raw, ';', $offset); if ($semi === false) break;
                $offset = $semi + 1;
            }
        }
        return $result;
    }

    private function shutdown(): void
    {
        echo "\n[Converse] Shutting down...\n";
        $this->running = false;
        foreach (array_keys($this->clients) as $id) $this->disconnect($id);
        if ($this->server) @fclose($this->server);
    }
}

$server = new ConverseSocketServer();
$server->run();