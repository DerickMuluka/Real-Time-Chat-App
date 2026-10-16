<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$db = Database::getConnection();
$stmt = $db->prepare('SELECT id, username, display_name, avatar_color, avatar_path, bio, status, status_message, show_last_seen FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) { session_destroy(); header('Location: index.php'); exit; }

ensureGeneralRoom((int)$user['id']);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0f172a">
    <title>Converse — Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/chat.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="chat-body"
      data-user-id="<?= (int)$user['id'] ?>"
      data-user-name="<?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?>"
      data-user-color="<?= htmlspecialchars($user['avatar_color'], ENT_QUOTES, 'UTF-8') ?>"
      data-user-avatar="<?= htmlspecialchars($user['avatar_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
      data-csrf="<?= $csrfToken ?>">

    <div class="app-shell" id="appShell">
        <!-- ================= SIDEBAR ================= -->
        <aside class="sidebar" id="sidebar">
            <header class="sidebar-header">
                <div class="app-logo">
                    <svg viewBox="0 0 48 48" fill="none"><path d="M8 20C8 13.373 13.373 8 20 8h8c6.627 0 12 5.373 12 12v8c0 6.627-5.373 12-12 12h-8c-6.627 0-12-5.373-12-12v-8z" stroke="currentColor" stroke-width="2.5"/><circle cx="18" cy="24" r="2.5" fill="currentColor"/><circle cx="24" cy="24" r="2.5" fill="currentColor"/><circle cx="30" cy="24" r="2.5" fill="currentColor"/></svg>
                    <span>Converse</span>
                </div>
                <button class="icon-btn" id="newRoomBtn" title="New room" aria-label="Create new room">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </header>

            <div class="sidebar-tabs">
                <button class="side-tab active" data-panel="roomsPanel">Rooms</button>
                <button class="side-tab" data-panel="peoplePanel">People</button>
            </div>

            <div class="panel-wrap">
                <div class="panel active" id="roomsPanel">
                    <div class="search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="roomSearch" placeholder="Search rooms..." aria-label="Search rooms">
                    </div>
                    <nav class="room-list" id="roomList" aria-label="Chat rooms">
                        <div class="list-skeleton"><div class="skel-item"></div><div class="skel-item"></div><div class="skel-item"></div></div>
                    </nav>
                </div>

                <div class="panel" id="peoplePanel">
                    <div class="search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="peopleSearch" placeholder="Search people..." aria-label="Search people">
                    </div>
                    <div class="people-list" id="peopleList"></div>
                </div>
            </div>

            <footer class="sidebar-footer">
                <button class="user-chip" id="userChip" title="Open profile settings">
                    <div class="avatar" id="userAvatar"
                         style="background:<?= htmlspecialchars($user['avatar_color'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($user['avatar_path']): ?>
                            <img src="<?= htmlspecialchars($user['avatar_path'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($user['display_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <strong><?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small class="presence" data-state="<?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(ucfirst($user['status']), ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </div>
                </button>
                <button class="icon-btn small" id="logoutBtn" title="Sign out" aria-label="Sign out">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </button>
            </footer>
        </aside>

        <!-- ================= MAIN ================= -->
        <main class="chat-main" id="chatMain">
            <header class="chat-header">
                <button class="icon-btn menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="room-head" id="roomHead">
                    <div class="room-avatar room-avatar-header" id="roomAvatarHeader"></div>
                    <div class="room-head-text">
                        <h2 id="roomTitle">Select a room</h2>
                        <p id="roomSubtitle">Choose a conversation to start messaging</p>
                    </div>
                </div>
                <div class="header-actions">
    <button class="icon-btn" id="audioCallBtn" title="Start audio call" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
    </button>
    <button class="icon-btn" id="videoCallBtn" title="Start video call" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
    </button>
    <button class="icon-btn" id="roomSettingsBtn" title="Room settings" disabled>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
    <button class="icon-btn notif-btn" id="notifBtn" title="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="notif-badge" id="notifBadge" hidden>0</span>
    </button>
    <span class="conn-status" id="connStatus" data-state="connecting">
        <span class="dot"></span>
        <span class="label">Connecting</span>
    </span>
</div>
            </header>

            <section class="messages" id="messages" data-wallpaper="default" aria-live="polite">
                <div class="empty-state" id="emptyState">
                    <div class="empty-icon">
                        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 24c0-6.627 5.373-12 12-12h16c6.627 0 12 5.373 12 12v8c0 6.627-5.373 12-12 12H24c-6.627 0-12-5.373-12-12v-8z"/>
                            <circle cx="26" cy="28" r="2"/><circle cx="32" cy="28" r="2"/><circle cx="38" cy="28" r="2"/>
                        </svg>
                    </div>
                    <h3>No conversation selected</h3>
                    <p>Pick a room from the sidebar or create a new one to get started.</p>
                </div>
            </section>

            <form class="composer" id="composer" autocomplete="off">
    <div class="reply-preview" id="replyPreview">
        <div>
            <strong id="replyName">Replying to</strong>
            <span id="replyText"></span>
        </div>
        <button type="button" class="close-reply" id="closeReply">×</button>
    </div>
    <div class="composer-tools">
        <button type="button" class="icon-btn" id="emojiBtn" title="Emojis" aria-label="Emojis" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
        </button>
        <button type="button" class="icon-btn" id="stickerBtn" title="Stickers" aria-label="Stickers" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </button>
        <button type="button" class="icon-btn" id="attachBtn" title="Attach" aria-label="Attach file" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 1 1 5.66 5.66L9.64 16.2a2 2 0 1 1-2.83-2.83l8.49-8.48"/></svg>
        </button>
    </div>
    <input type="file" id="fileInput" hidden>
    <textarea id="messageInput" name="content" rows="1" placeholder="Type a message…" maxlength="2000" aria-label="Message input" disabled></textarea>
    <button type="submit" class="send-btn" id="sendBtn" disabled aria-label="Send message">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    </button>
</form>
        </main>

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    </div>

    <!-- ================= MODALS ================= -->

    <!-- New Room -->
    <div class="modal" id="roomModal" hidden>
        <div class="modal-card">
            <header class="modal-header">
                <h3 id="roomModalTitle">Create a new room</h3>
                <button class="icon-btn" data-close-modal aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>
            <form id="roomForm">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="room_id" value="">
                <div class="field">
                    <label for="roomName">Room name</label>
                    <input type="text" id="roomName" name="name" required maxlength="100" placeholder="e.g. Design Team">
                </div>
                <div class="field">
                    <label for="roomDesc">Description <span class="muted">(optional)</span></label>
                    <textarea id="roomDesc" name="description" rows="3" maxlength="500" placeholder="What's this room about?"></textarea>
                </div>
                <div class="form-error" id="roomError" role="alert"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close-modal>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Room Settings -->
    <div class="modal" id="settingsModal" hidden>
        <div class="modal-card modal-wide">
            <header class="modal-header">
                <h3>Room settings</h3>
                <button class="icon-btn" data-close-modal aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>

            <div class="tabs">
                <button class="tab active" data-tab="general">General</button>
                <button class="tab" data-tab="members">Members</button>
                <button class="tab" data-tab="appearance">Appearance</button>
                <button class="tab" data-tab="danger">Danger</button>
            </div>

            <div class="tab-body active" data-tab-body="general">
                <div class="field">
                    <label for="editRoomName">Room name</label>
                    <input type="text" id="editRoomName" maxlength="100">
                </div>
                <div class="field">
    <label for="disappearingSelect">Disappearing messages</label>
    <select id="disappearingSelect">
        <option value="0">Off</option>
        <option value="300">5 minutes</option>
        <option value="3600">1 hour</option>
        <option value="86400">24 hours</option>
        <option value="604800">7 days</option>
    </select>
</div>
                <div class="field">
                    <label for="editRoomDesc">Description</label>
                    <textarea id="editRoomDesc" rows="3" maxlength="500"></textarea>
                </div>
                <div class="form-error" id="editRoomError" role="alert"></div>
                <button class="btn-primary" id="saveRoomChanges">Save changes</button>
            </div>

            <div class="tab-body" data-tab-body="members">
                <div class="field">
                    <label>Add member</label>
                    <div class="add-member-row">
                        <input type="text" id="addMemberSearch" placeholder="Search by username or email">
                        <button class="btn-ghost" id="addMemberBtn">Add</button>
                    </div>
                    <div id="addMemberResults" class="add-member-results"></div>
                </div>
                <div class="members-list" id="membersList"></div>
            </div>

            <div class="tab-body" data-tab-body="appearance">
                <div class="field">
                    <label>Room image</label>
                    <div class="image-picker">
                        <div class="room-avatar-preview" id="roomImagePreview"></div>
                        <label class="btn-ghost" for="roomImageInput">Choose image</label>
                        <input type="file" id="roomImageInput" accept="image/*" hidden>
                    </div>
                </div>
                <div class="field">
                    <label>Chat wallpaper</label>
                    <div class="wallpaper-picker" id="wallpaperPicker">
    <button class="wallpaper-swatch" data-wallpaper="default"><span class="wp-default"></span>Default</button>
    <button class="wallpaper-swatch" data-wallpaper="aurora"><span class="wp-aurora"></span>Aurora</button>
    <button class="wallpaper-swatch" data-wallpaper="sunset"><span class="wp-sunset"></span>Sunset</button>
    <button class="wallpaper-swatch" data-wallpaper="ocean"><span class="wp-ocean"></span>Ocean</button>
    <button class="wallpaper-swatch" data-wallpaper="forest"><span class="wp-forest"></span>Forest</button>
    <button class="wallpaper-swatch" data-wallpaper="midnight"><span class="wp-midnight"></span>Midnight</button>
    <button class="wallpaper-swatch" data-wallpaper="lavender"><span class="wp-lavender"></span>Lavender</button>
    <button class="wallpaper-swatch" data-wallpaper="paper"><span class="wp-paper"></span>Paper</button>
    <button class="wallpaper-swatch" data-wallpaper="space"><span class="wp-space"></span>Space</button>
    <button class="wallpaper-swatch" data-wallpaper="nebula"><span class="wp-nebula"></span>Nebula</button>
</div>
                </div>
            </div>

            <div class="tab-body" data-tab-body="danger">
                <p class="danger-text">Deleting a room is permanent. All messages and files will be lost.</p>
                <button class="btn-danger" id="deleteRoomBtn">Delete room</button>
            </div>
        </div>
    </div>

    <!-- Profile / Settings -->
    <div class="modal" id="profileModal" hidden>
        <div class="modal-card modal-wide">
            <header class="modal-header">
                <h3>Your profile</h3>
                <button class="icon-btn" data-close-modal aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>

            <div class="profile-header">
                <div class="profile-avatar" id="profileAvatar"></div>
                <label class="btn-ghost" for="avatarInput">Change photo</label>
                <input type="file" id="avatarInput" accept="image/*" hidden>
            </div>

            <div class="field">
                <label for="profileDisplay">Display name</label>
                <input type="text" id="profileDisplay" maxlength="100">
            </div>

            <div class="field">
                <label for="profileBio">Bio</label>
                <textarea id="profileBio" rows="2" maxlength="160"></textarea>
            </div>

            <div class="field">
                <label for="profileStatusMsg">Status message</label>
                <input type="text" id="profileStatusMsg" maxlength="120" placeholder="What's on your mind?">
            </div>

            <div class="field">
                <label>Presence</label>
                <div class="status-choices" id="statusChoices">
                    <button class="status-choice" data-status="online"><span class="dot dot-online"></span>Online</button>
                    <button class="status-choice" data-status="away"><span class="dot dot-away"></span>Away</button>
                    <button class="status-choice" data-status="busy"><span class="dot dot-busy"></span>Busy</button>
                    <button class="status-choice" data-status="offline"><span class="dot dot-offline"></span>Invisible</button>
                </div>
            </div>

            <div class="field switch-field">
                <label class="switch">
                    <input type="checkbox" id="profileShowLastSeen">
                    <span class="slider"></span>
                </label>
                <span>Show my last seen</span>
            </div>

            <div class="form-error" id="profileError" role="alert"></div>

            <div class="modal-actions">
                <button type="button" class="btn-ghost" data-close-modal>Cancel</button>
                <button type="button" class="btn-primary" id="saveProfileBtn">Save</button>
            </div>
        </div>
    </div>

    <!-- Video Call Modal -->
    <div class="modal call-modal" id="callModal" hidden>
        <div class="call-card">
            <div class="call-videos">
                <video id="remoteVideo" autoplay playsinline></video>
                <video id="localVideo" autoplay playsinline muted></video>
            </div>
            <div class="call-info">
                <div class="call-timer" id="callTimer">00:00</div>
                <div class="call-status" id="callStatus">Waiting…</div>
            </div>
            <div class="call-actions">
                <button class="call-btn mute" id="muteMicBtn" title="Mute">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
                </button>
                <button class="call-btn" id="toggleCamBtn" title="Camera">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                </button>
                <button class="call-btn end" id="endCallBtn" title="End call">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="23" y1="1" x2="1" y2="23"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Image viewer -->
    <div class="modal lightbox" id="lightbox" hidden>
        <img id="lightboxImg" src="" alt="">
    </div>

    <div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

    <script>
        (function () {
            var p = window.location.pathname;
            p = p.replace(/\/index\.php$/i, '');
            p = p.replace(/\/[^\/]*\.php$/i, '');
            p = p.replace(/\/public\/?$/i, '');
            p = p.replace(/\/+$/, '');
            var apiBase = window.location.origin + p + '/src';
            var publicBase = window.location.origin + p + '/public';
            window.APP_CONFIG = {
                apiBase: apiBase,
                publicBase: publicBase,
                wsUrl: (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8080'
            };
            console.log('[Converse] apiBase =', apiBase);
        })();
    </script>
    <script src="assets/js/websocket.js" defer></script>
    <script src="assets/js/ui.js" defer></script>
    <script src="assets/js/chat.js" defer></script>
    <div id="notifPanelWrap" style="position:relative;display:none;"></div>
    
</body>
</html>