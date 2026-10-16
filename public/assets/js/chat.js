/* ==========================================================================
   Converse — Chat controller (final)
   ========================================================================== */
(function () {
    'use strict';

    const body = document.body;
    const csrf = body.dataset.csrf;
    const currentUserId = Number(body.dataset.userId);

    const state = {
        rooms: [],
        people: [],
        members: [],
        currentRoomId: null,
        messages: new Map(),
        onlineUsers: new Map(),
        typingUsers: new Map(),
        replyTo: null,
        localStream: null,
        peerConnection: null,
        callType: null,
        callStartTime: 0,
        callTimer: null,
        seenMessageIds: new Set(),
    };

    const $  = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

    const els = {
        roomList: $('#roomList'),
        peopleList: $('#peopleList'),
        roomSearch: $('#roomSearch'),
        peopleSearch: $('#peopleSearch'),
        sidebarBackdrop: $('#sidebarBackdrop'),
        menuToggle: $('#menuToggle'),
        newRoomBtn: $('#newRoomBtn'),
        userChip: $('#userChip'),
        logoutBtn: $('#logoutBtn'),
        roomTitle: $('#roomTitle'),
        roomSubtitle: $('#roomSubtitle'),
        roomAvatarHeader: $('#roomAvatarHeader'),
        connStatus: $('#connStatus'),
        audioCallBtn: $('#audioCallBtn'),
        videoCallBtn: $('#videoCallBtn'),
        roomSettingsBtn: $('#roomSettingsBtn'),
        notifBtn: $('#notifBtn'),
        notifBadge: $('#notifBadge'),
        messages: $('#messages'),
        emptyState: $('#emptyState'),
        appShell: $('#appShell'),
        composer: $('#composer'),
        messageInput: $('#messageInput'),
        sendBtn: $('#sendBtn'),
        attachBtn: $('#attachBtn'),
        fileInput: $('#fileInput'),
        emojiBtn: $('#emojiBtn'),
        stickerBtn: $('#stickerBtn'),
        replyPreview: $('#replyPreview'),
        replyName: $('#replyName'),
        replyText: $('#replyText'),
        closeReply: $('#closeReply'),
        roomModal: $('#roomModal'),
        roomForm: $('#roomForm'),
        roomModalTitle: $('#roomModalTitle'),
        roomError: $('#roomError'),
        settingsModal: $('#settingsModal'),
        editRoomName: $('#editRoomName'),
        editRoomDesc: $('#editRoomDesc'),
        editRoomError: $('#editRoomError'),
        saveRoomChanges: $('#saveRoomChanges'),
        membersList: $('#membersList'),
        addMemberSearch: $('#addMemberSearch'),
        addMemberResults: $('#addMemberResults'),
        deleteRoomBtn: $('#deleteRoomBtn'),
        roomImagePreview: $('#roomImagePreview'),
        roomImageInput: $('#roomImageInput'),
        profileModal: $('#profileModal'),
        profileAvatar: $('#profileAvatar'),
        avatarInput: $('#avatarInput'),
        profileDisplay: $('#profileDisplay'),
        profileBio: $('#profileBio'),
        profileStatusMsg: $('#profileStatusMsg'),
        profileShowLastSeen: $('#profileShowLastSeen'),
        statusChoices: $('#statusChoices'),
        saveProfileBtn: $('#saveProfileBtn'),
        profileError: $('#profileError'),
        callModal: $('#callModal'),
        localVideo: $('#localVideo'),
        remoteVideo: $('#remoteVideo'),
        callStatus: $('#callStatus'),
        callTimer: $('#callTimer'),
        muteMicBtn: $('#muteMicBtn'),
        toggleCamBtn: $('#toggleCamBtn'),
        endCallBtn: $('#endCallBtn'),
        lightbox: $('#lightbox'),
        lightboxImg: $('#lightboxImg'),
    };

    const API_BASE = (function () {
        if (window.APP_CONFIG?.apiBase) return window.APP_CONFIG.apiBase.replace(/\/+$/, '');
        var p = window.location.pathname
            .replace(/\/index\.php$/i, '')
            .replace(/\/[^\/]*\.php$/i, '')
            .replace(/\/public\/?$/i, '')
            .replace(/\/+$/, '');
        return window.location.origin + p + '/src';
    })();

    const PUBLIC_BASE = (function () {
        if (window.APP_CONFIG?.publicBase) return window.APP_CONFIG.publicBase.replace(/\/+$/, '');
        var p = window.location.pathname.replace(/\/chat\.php$/i, '').replace(/\/+$/, '');
        return window.location.origin + p;
    })();

    console.log('[Converse] API_BASE =', API_BASE, '| PUBLIC_BASE =', PUBLIC_BASE);

    /* ---------- HTTP helpers ---------- */
    async function apiGet(path, params = {}) {
        const qs = new URLSearchParams(params).toString();
        const url = `${API_BASE}/${path}` + (qs ? `?${qs}` : '');
        const res = await fetch(url, { credentials: 'same-origin' });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            const text = await res.text();
            console.error('[Converse] Non-JSON from', url, text.slice(0, 300));
            throw new Error(res.status === 404 ? 'Endpoint not found' : `Server error (${res.status})`);
        }
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.error || 'Request failed');
        return json;
    }

    async function apiPost(path, data) {
        const url = `${API_BASE}/${path}`;
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ ...data, csrf_token: csrf }),
        });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            const text = await res.text();
            console.error('[Converse] Non-JSON from', url, text.slice(0, 300));
            throw new Error(res.status === 404 ? 'Endpoint not found' : `Server error (${res.status})`);
        }
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.error || 'Request failed');
        return json;
    }

    async function apiUpload(file, kind = 'file') {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('kind', kind);
        fd.append('csrf_token', csrf);
        const url = `${API_BASE}/api/upload.php`;
        console.log('[Converse] uploading to', url, 'file:', file.name, 'size:', file.size);
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin', body: fd });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            const text = await res.text();
            console.error('[Converse] Upload non-JSON:', text.slice(0, 500));
            throw new Error('Upload failed (server error — check console).');
        }
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Upload failed');
        return json;
    }

    /* ---------- Connection status ---------- */
    function setConnection(s, label) {
        if (!els.connStatus) return;
        els.connStatus.dataset.state = s;
        const l = els.connStatus.querySelector('.label');
        if (l) l.textContent = label || s;
    }

    window.ChatSocket.on('status', ({ state: s }) => {
        switch (s) {
            case 'connecting':    setConnection('connecting', 'Connecting'); break;
            case 'connected':     setConnection('connecting', 'Authenticating'); break;
            case 'authenticated': setConnection('online', 'Live'); break;
            case 'disconnected':  setConnection('offline', 'Reconnecting'); break;
            case 'error':         setConnection('offline', 'Offline'); break;
            case 'auth_failed':   setConnection('offline', 'Session expired'); break;
            case 'fallback':      setConnection('offline', 'Polling mode'); startPolling(); break;
        }
    });

    /* ---------- Sidebar tabs ---------- */
    $$('.side-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            $$('.side-tab').forEach(b => b.classList.toggle('active', b === btn));
            $$('.panel').forEach(p => p.classList.toggle('active', p.id === btn.dataset.panel));
        });
    });

    /* ---------- Rooms ---------- */
    async function loadRooms() {
        try {
            const { rooms } = await apiGet('api/rooms.php');
            state.rooms = rooms || [];
            renderRooms();
        } catch (e) { UI.showToast(e.message, 'error', 'Rooms'); }
    }

    function renderRooms(filter = '') {
        if (!els.roomList) return;
        const term = filter.trim().toLowerCase();
        const filtered = term
            ? state.rooms.filter(r => r.name.toLowerCase().includes(term) || (r.description || '').toLowerCase().includes(term))
            : state.rooms;

        if (!filtered.length) {
            els.roomList.innerHTML = `<div class="empty-rooms">${state.rooms.length === 0 ? 'No rooms yet.' : 'No matching rooms.'}</div>`;
            return;
        }

        els.roomList.innerHTML = '';
        filtered.forEach(room => {
            const btn = document.createElement('button');
            btn.className = 'room-item' + (Number(room.id) === state.currentRoomId ? ' active' : '');
            btn.dataset.roomId = room.id;
            const initial = (room.name || '?').trim().charAt(0).toUpperCase();
            const preview = room.last_message ? truncate(room.last_message, 46)
                : (room.description ? truncate(room.description, 46) : 'No messages yet');
            btn.innerHTML = `
                <div class="room-avatar">
                    ${room.image_path
                        ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(room.image_path)}" alt="">`
                        : UI.escapeHtml(initial)}
                </div>
                <div class="room-meta">
                    <div class="name">${UI.escapeHtml(room.name)}</div>
                    <div class="preview">${UI.escapeHtml(preview)}</div>
                </div>
                ${room.is_admin ? '<span class="admin-tag">admin</span>' : ''}
            `;
            btn.addEventListener('click', () => selectRoom(room.id));
            els.roomList.appendChild(btn);
        });
    }

    function truncate(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

    /* ---------- People ---------- */
    async function loadPeople() {
        try {
            const { users } = await apiGet('api/users.php');
            state.people = users || [];
            renderPeople();
        } catch (e) {}
    }

    function renderPeople(filter = '') {
        if (!els.peopleList) return;
        const term = filter.trim().toLowerCase();
        const filtered = term
            ? state.people.filter(u => u.display_name.toLowerCase().includes(term) || u.username.toLowerCase().includes(term))
            : state.people;

        if (!filtered.length) { els.peopleList.innerHTML = '<div class="empty-rooms">No people found.</div>'; return; }

        els.peopleList.innerHTML = '';
        filtered.forEach(u => {
            const item = document.createElement('div');
            item.className = 'person-item';
            const status = state.onlineUsers.get(Number(u.id)) || u.status || 'offline';
            const initial = (u.display_name || '?').charAt(0).toUpperCase();
            item.innerHTML = `
                <div class="avatar" style="background:${UI.escapeHtml(u.avatar_color || '#6366f1')}">
                    ${u.avatar_path ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(u.avatar_path)}" alt="">` : UI.escapeHtml(initial)}
                </div>
                <div class="person-meta">
                    <div class="name">${UI.escapeHtml(u.display_name)}</div>
                    <div class="status-line"><span class="dot dot-${status}"></span>${UI.escapeHtml(u.status_message || status)}</div>
                </div>
            `;
            item.addEventListener('click', () => {
                UI.showToast('Tip: open Room Settings → Members to add this person.', 'info');
            });
            els.peopleList.appendChild(item);
        });
    }

    /* ---------- Room selection ---------- */
    async function selectRoom(roomId) {
        roomId = Number(roomId);
        if (!roomId || roomId === state.currentRoomId) { closeSidebar(); return; }

        if (state.currentRoomId) window.ChatSocket.leaveRoom(state.currentRoomId);
        state.currentRoomId = roomId;
        state.seenMessageIds.clear();
        window.ChatSocket.joinRoom(roomId);

        const room = state.rooms.find(r => Number(r.id) === roomId);
        if (room) {
            els.roomTitle.textContent = room.name;
            els.roomSubtitle.textContent = room.description || 'Public room';
            els.roomAvatarHeader.innerHTML = room.image_path
                ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(room.image_path)}" alt="">`
                : UI.escapeHtml((room.name || '?').charAt(0).toUpperCase());

            applyRoomAppearance(room);
        }

        els.messageInput.disabled = false;
        els.attachBtn.disabled = false;
        if (els.emojiBtn) els.emojiBtn.disabled = false;
        if (els.stickerBtn) els.stickerBtn.disabled = false;
        els.sendBtn.disabled = true;
        els.emptyState.classList.add('hidden');
        els.audioCallBtn.disabled = false;
        els.videoCallBtn.disabled = false;
        els.roomSettingsBtn.disabled = false;

        renderRooms(els.roomSearch.value);
        closeSidebar();
        els.messages.innerHTML = '<div class="empty-state"><p style="color:var(--text-500)">Loading messages…</p></div>';

        try {
            const { messages } = await apiGet('api/messages.php', { room_id: roomId, limit: 80 });
            state.messages.set(roomId, messages || []);
            messages.forEach(m => state.seenMessageIds.add(Number(m.id)));
            renderMessages(roomId);
            renderPinnedBar(roomId);
            scrollToBottom();
            setTimeout(() => els.messageInput.focus(), 100);
        } catch (e) { UI.showToast(e.message, 'error', 'Messages'); }
    }

    function applyRoomAppearance(room) {
        const main = document.querySelector('.chat-main');
        if (main) main.dataset.wallpaper = room.wallpaper || 'default';

        if (room.theme) document.body.dataset.theme = room.theme;
        if (room.font_family) document.body.dataset.font = room.font_family;
    }

    /* ---------- Rendering messages ---------- */
    function renderMessages(roomId) {
        const list = state.messages.get(roomId) || [];
        els.messages.innerHTML = '';
        let lastDayKey = null, lastUserId = null, lastTime = 0;

        list.forEach(msg => {
            const dk = UI.dayKey(msg.created_at);
            if (dk !== lastDayKey) {
                const sep = document.createElement('div');
                sep.className = 'day-sep';
                sep.innerHTML = `<span>${UI.escapeHtml(UI.formatDayLabel(msg.created_at))}</span>`;
                els.messages.appendChild(sep);
                lastDayKey = dk; lastUserId = null;
            }
            const el = buildMessageEl(msg, { lastUserId, lastTime });
            els.messages.appendChild(el);
            lastUserId = Number(msg.user_id);
            lastTime = UI.parseDate(msg.created_at)?.getTime() || 0;
        });
    }

    function buildMessageEl(msg, ctx = {}) {
        const isOwn = Number(msg.user_id) === currentUserId;
        const isSystem = msg.message_type === 'system';

        if (isSystem) {
            const wrap = document.createElement('div');
            wrap.className = 'day-sep system';
            wrap.innerHTML = `<span>${UI.escapeHtml(msg.content)}</span>`;
            return wrap;
        }

        const row = document.createElement('div');
        row.className = 'msg-row' + (isOwn ? ' own' : '') + (msg.is_pinned ? ' pinned' : '');
        row.dataset.messageId = msg.id;

        const continued = ctx.lastUserId === Number(msg.user_id)
            && (UI.parseDate(msg.created_at)?.getTime() - ctx.lastTime) < 5 * 60 * 1000
            && !isOwn;
        if (continued) row.classList.add('continued');

        const initial = (msg.display_name || '?').charAt(0).toUpperCase();
        const color = msg.avatar_color || '#6366f1';
        const avatarHtml = msg.avatar_path
            ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(msg.avatar_path)}" alt="">`
            : UI.escapeHtml(initial);

        let replyHtml = '';
        if (msg.attachment_meta && msg.attachment_meta.reply_to) {
            replyHtml = `
                <div class="msg-reply">
                    <strong>${UI.escapeHtml(msg.attachment_meta.reply_name || 'Reply')}</strong>
                    ${UI.escapeHtml((msg.attachment_meta.reply_text || '').slice(0, 100))}
                </div>`;
        }

        let bodyHtml = '';
        const meta = msg.attachment_meta || {};

        if (msg.is_deleted) {
            bodyHtml = '<span class="deleted">This message was deleted</span>';
        } else if (msg.message_type === 'image' && msg.attachment) {
            bodyHtml = `<img class="msg-image" src="${PUBLIC_BASE}/${UI.escapeHtml(msg.attachment)}" alt="image" loading="lazy">`;
        } else if (msg.message_type === 'video' && msg.attachment) {
            bodyHtml = `<video class="msg-video" src="${PUBLIC_BASE}/${UI.escapeHtml(msg.attachment)}" controls preload="metadata"></video>`;
        } else if (msg.message_type === 'audio' && msg.attachment) {
            bodyHtml = `<audio class="msg-audio" src="${PUBLIC_BASE}/${UI.escapeHtml(msg.attachment)}" controls preload="metadata"></audio>`;
        } else if (msg.message_type === 'file' && msg.attachment) {
            bodyHtml = `<a class="msg-file" href="${PUBLIC_BASE}/${UI.escapeHtml(msg.attachment)}" target="_blank" rel="noopener" download>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="file-info"><strong>${UI.escapeHtml(meta.filename || 'File')}</strong><small>${UI.formatBytes(meta.size || 0)}</small></span>
            </a>`;
        } else if (msg.message_type === 'sticker') {
            bodyHtml = `<div style="font-size:64px;line-height:1;padding:4px;">${UI.escapeHtml(msg.content)}</div>`;
        } else {
            bodyHtml = linkify(UI.escapeHtml(msg.content || ''));
        }

        const metaBits = [];
        if (msg.is_pinned) metaBits.push('<span class="pin-mark">📌 pinned</span>');
        if (msg.expires_at) metaBits.push('<span class="expire-mark">⏱ disappearing</span>');

        row.innerHTML = `
            <div class="msg-avatar" style="background:${UI.escapeHtml(color)}">${avatarHtml}</div>
            <div class="msg-content">
                <div class="msg-author">${UI.escapeHtml(msg.display_name)}<time>${UI.escapeHtml(UI.formatTime(msg.created_at))}</time></div>
                ${replyHtml}
                <div class="msg-bubble">${bodyHtml}</div>
                ${metaBits.length ? `<div class="msg-meta">${metaBits.join(' ')}</div>` : ''}
                ${isOwn ? `<div class="msg-time-own">${UI.escapeHtml(UI.formatTime(msg.created_at))}</div>` : ''}
                <div class="msg-actions">
                    <button class="msg-action-btn act-reply" title="Reply">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                    </button>
                    ${!msg.is_deleted ? `
                        <button class="msg-action-btn act-pin" title="${msg.is_pinned ? 'Unpin' : 'Pin'}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </button>
                        <button class="msg-action-btn danger act-delete" title="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;

        row.querySelector('.act-reply')?.addEventListener('click', () => setReply(msg));
        row.querySelector('.act-pin')?.addEventListener('click', async () => {
            try {
                const res = await apiPost('api/messages.php', { action: msg.is_pinned ? 'unpin' : 'pin', message_id: msg.id });
                msg.is_pinned = !msg.is_pinned;
                row.classList.toggle('pinned', msg.is_pinned);
                renderPinnedBar(state.currentRoomId);
                UI.showToast(msg.is_pinned ? 'Pinned' : 'Unpinned', 'success');
            } catch (e) { UI.showToast(e.message, 'error'); }
        });
        row.querySelector('.act-delete')?.addEventListener('click', async () => {
            if (!confirm('Delete this message?')) return;
            try {
                await apiPost('api/messages.php', { action: 'delete', message_id: msg.id });
                msg.is_deleted = true;
                const fresh = buildMessageEl(msg, {});
                row.replaceWith(fresh);
                UI.showToast('Deleted', 'success');
            } catch (e) { UI.showToast(e.message, 'error'); }
        });

        row.querySelectorAll('img.msg-image').forEach(img => {
            img.addEventListener('click', () => {
                els.lightboxImg.src = img.src;
                UI.openModal('lightbox');
            });
        });

        return row;
    }

    function renderPinnedBar(roomId) {
        const list = state.messages.get(roomId) || [];
        const pinned = list.filter(m => m.is_pinned && !m.is_deleted);
        let bar = document.querySelector('.pinned-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'pinned-bar';
            const header = document.querySelector('.chat-header');
            if (header) header.after(bar);
        }
        if (pinned.length === 0) {
            bar.classList.remove('active');
            return;
        }
        bar.classList.add('active');
        const last = pinned[pinned.length - 1];
        bar.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span class="pin-text">${UI.escapeHtml(last.display_name)}: ${UI.escapeHtml((last.content || '').slice(0, 100))}</span>
        `;
    }

    function linkify(escaped) {
        return escaped.replace(/(https?:\/\/[^\s<]+)/g, url => {
            const safe = url.replace(/"/g, '&quot;');
            return `<a href="${safe}" target="_blank" rel="noopener noreferrer nofollow">${url}</a>`;
        });
    }

    function appendMessage(msg) {
        const roomId = Number(msg.room_id);
        let list = state.messages.get(roomId);
        if (!list) { list = []; state.messages.set(roomId, list); }
        if (list.some(m => Number(m.id) === Number(msg.id))) return;
        list.push(msg);
        state.seenMessageIds.add(Number(msg.id));

        const room = state.rooms.find(r => Number(r.id) === roomId);
        if (room) {
            room.last_message = msg.content || '📎';
            room.last_at = msg.created_at;
            renderRooms(els.roomSearch.value);
        }
        if (roomId !== state.currentRoomId) return;

        const loading = els.messages.querySelector('.empty-state');
        if (loading) loading.remove();

        const list2 = state.messages.get(roomId) || [];
        const last = list2[list2.length - 2];
        const lastUserId = last ? Number(last.user_id) : null;
        const lastTime = last ? (UI.parseDate(last.created_at)?.getTime() || 0) : 0;
        const prevDay = last ? UI.dayKey(last.created_at) : null;
        const curDay = UI.dayKey(msg.created_at);
        if (prevDay !== curDay) {
            const sep = document.createElement('div');
            sep.className = 'day-sep';
            sep.innerHTML = `<span>${UI.escapeHtml(UI.formatDayLabel(msg.created_at))}</span>`;
            els.messages.appendChild(sep);
        }
        els.messages.appendChild(buildMessageEl(msg, { lastUserId, lastTime }));

        const nearBottom = els.messages.scrollHeight - els.messages.scrollTop - els.messages.clientHeight < 120;
        if (nearBottom || Number(msg.user_id) === currentUserId) scrollToBottom();
    }

    function scrollToBottom() {
        requestAnimationFrame(() => { els.messages.scrollTop = els.messages.scrollHeight; });
    }

    /* ---------- Send ---------- */
    els.composer?.addEventListener('submit', async e => {
        e.preventDefault();
        const content = els.messageInput.value.trim();
        if (!content || !state.currentRoomId) return;
        els.messageInput.value = '';
        autoGrow(els.messageInput);
        els.sendBtn.disabled = true;

        const payload = { message_type: 'text' };
        if (state.replyTo) {
            payload.attachment_meta = {
                reply_to: state.replyTo.id,
                reply_name: state.replyTo.display_name,
                reply_text: state.replyTo.content,
            };
        }

        if (window.ChatSocket.isConnected && window.ChatSocket.isAuthenticated) {
            const ok = window.ChatSocket.sendMessage(state.currentRoomId, content, payload);
            if (!ok) await sendViaHttp(content, payload);
            else clearReply();
        } else {
            await sendViaHttp(content, payload);
        }
        els.messageInput.focus();
    });

    async function sendViaHttp(content, extra = {}) {
        try {
            const payload = { room_id: state.currentRoomId, content, ...extra };
            if (state.replyTo && !payload.attachment_meta) {
                payload.attachment_meta = {
                    reply_to: state.replyTo.id,
                    reply_name: state.replyTo.display_name,
                    reply_text: state.replyTo.content,
                };
            }
            const res = await apiPost('api/messages.php', payload);
            if (res.message) appendMessage(res.message);
            clearReply();
        } catch (e) { UI.showToast(e.message, 'error', 'Send failed'); }
    }

    function autoGrow(el) { el.style.height = 'auto'; el.style.height = Math.min(160, el.scrollHeight) + 'px'; }

    els.messageInput?.addEventListener('input', () => {
        autoGrow(els.messageInput);
        els.sendBtn.disabled = els.messageInput.value.trim().length === 0;
        if (state.currentRoomId) {
            window.ChatSocket.setTyping(state.currentRoomId, true);
            clearTimeout(state.typingUsers.get('self'));
            state.typingUsers.set('self', setTimeout(() => window.ChatSocket.setTyping(state.currentRoomId, false), 1400));
        }
    });

    els.messageInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); els.composer.requestSubmit(); }
    });

    function setReply(msg) {
        state.replyTo = msg;
        if (els.replyName) els.replyName.textContent = 'Replying to ' + (msg.display_name || 'someone');
        if (els.replyText) els.replyText.textContent = (msg.content || '').slice(0, 80);
        els.replyPreview.classList.add('active');
        els.messageInput.focus();
    }
    function clearReply() {
        state.replyTo = null;
        els.replyPreview?.classList.remove('active');
    }
    els.closeReply?.addEventListener('click', clearReply);

    /* ---------- File upload ---------- */
    els.attachBtn?.addEventListener('click', () => els.fileInput.click());
    els.fileInput?.addEventListener('change', async () => {
        const file = els.fileInput.files[0];
        if (!file || !state.currentRoomId) return;
        els.fileInput.value = '';

        UI.showToast('Uploading ' + file.name + '…', 'info', 'Please wait');
        try {
            const res = await apiUpload(file, 'file');
            console.log('[Converse] upload success:', res);
            let type = 'file';
            if (res.mime.startsWith('image/')) type = 'image';
            else if (res.mime.startsWith('video/')) type = 'video';
            else if (res.mime.startsWith('audio/')) type = 'audio';

            await sendViaHttp('', {
                message_type: type,
                attachment: res.path,
                attachment_meta: { filename: res.filename, size: res.size, mime: res.mime },
            });
            UI.showToast('Uploaded', 'success');
        } catch (e) {
            console.error('[Converse] upload error:', e);
            UI.showToast(e.message, 'error', 'Upload failed');
        }
    });

    /* ---------- WS events ---------- */
    window.ChatSocket.on('message', msg => appendMessage(msg));

    window.ChatSocket.on('typing', ({ room_id, user_id, typing }) => {
        if (Number(room_id) !== state.currentRoomId || Number(user_id) === currentUserId) return;
        const map = state.typingUsers;
        const key = `r${room_id}_u${user_id}`;
        clearTimeout(map.get(key));
        let ind = document.getElementById('typingIndicator');
        if (typing) {
            if (!ind) {
                ind = document.createElement('div');
                ind.id = 'typingIndicator';
                ind.className = 'typing-indicator';
                ind.innerHTML = '<span class="dots"><span></span><span></span><span></span></span> typing…';
                els.messages.appendChild(ind);
                scrollToBottom();
            }
            map.set(key, setTimeout(() => ind?.remove(), 3000));
        } else ind?.remove();
    });

    window.ChatSocket.on('presence', ({ user_id, status }) => {
        state.onlineUsers.set(Number(user_id), status);
        renderPeople(els.peopleSearch.value);
    });

    /* ---------- Sidebar toggle ---------- */
    function openSidebar() { els.appShell.classList.add('sidebar-open'); els.sidebarBackdrop.style.display = 'block'; }
    function closeSidebar() {
        els.appShell.classList.remove('sidebar-open');
        setTimeout(() => { els.sidebarBackdrop.style.display = 'none'; }, 240);
    }
    els.menuToggle?.addEventListener('click', openSidebar);
    els.sidebarBackdrop?.addEventListener('click', closeSidebar);

    els.roomSearch?.addEventListener('input', () => renderRooms(els.roomSearch.value));
    els.peopleSearch?.addEventListener('input', () => renderPeople(els.peopleSearch.value));

    /* ---------- Room create modal ---------- */
    function openRoomModal(editRoom = null) {
        els.roomForm.reset();
        els.roomError.textContent = '';
        if (editRoom) {
            els.roomModalTitle.textContent = 'Rename room';
            els.roomForm.action.value = 'update';
            els.roomForm.room_id.value = editRoom.id;
            els.roomForm.name.value = editRoom.name;
            els.roomForm.description.value = editRoom.description || '';
        } else {
            els.roomModalTitle.textContent = 'Create a new room';
            els.roomForm.action.value = 'create';
            els.roomForm.room_id.value = '';
        }
        UI.openModal('roomModal');
        setTimeout(() => els.roomForm.name.focus(), 120);
    }
    els.newRoomBtn?.addEventListener('click', () => openRoomModal());

    $$('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal');
            if (modal) UI.closeModal(modal.id);
        });
    });
    $$('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) UI.closeModal(m.id); }));

    els.roomForm?.addEventListener('submit', async e => {
        e.preventDefault();
        els.roomError.textContent = '';
        const action = els.roomForm.action.value;
        const name = els.roomForm.name.value.trim();
        const description = els.roomForm.description.value.trim();
        const room_id = els.roomForm.room_id.value;
        if (name.length < 2) { els.roomError.textContent = 'Name too short.'; return; }

        const btn = els.roomForm.querySelector('button[type="submit"]');
        btn.disabled = true;

        try {
            if (action === 'update') {
                await apiPost('api/rooms.php', { action: 'update', room_id, name, description });
                UI.showToast('Room updated', 'success');
                UI.closeModal('roomModal');
                await loadRooms();
                if (room_id == state.currentRoomId) selectRoom(state.currentRoomId);
            } else {
                const res = await apiPost('api/rooms.php', { action: 'create', name, description });
                if (res.room) {
                    UI.closeModal('roomModal');
                    UI.showToast('Room created', 'success');
                    await loadRooms();
                    selectRoom(res.room.id);
                }
            }
        } catch (err) { els.roomError.textContent = err.message; }
        finally { btn.disabled = false; }
    });

    /* ---------- Settings modal ---------- */
    els.roomSettingsBtn?.addEventListener('click', async () => {
        if (!state.currentRoomId) return;
        const room = state.rooms.find(r => Number(r.id) === state.currentRoomId);
        if (!room) return;

        els.editRoomName.value = room.name;
        els.editRoomDesc.value = room.description || '';
        els.editRoomError.textContent = '';
        els.roomImagePreview.innerHTML = room.image_path
            ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(room.image_path)}" alt="">`
            : UI.escapeHtml((room.name || '?').charAt(0).toUpperCase());

        $$('.wallpaper-swatch').forEach(sw => sw.classList.toggle('active', sw.dataset.wallpaper === (room.wallpaper || 'default')));
        els.deleteRoomBtn.style.display = room.is_owner ? '' : 'none';

        // Sync disappearing dropdown if present
        const disappearingSel = $('#disappearingSelect');
        if (disappearingSel) disappearingSel.value = String(room.disappearing_seconds || 0);

        await loadMembers();
        UI.openModal('settingsModal');
    });

    $$('#settingsModal .tab').forEach(tab => {
        tab.addEventListener('click', () => {
            $$('#settingsModal .tab').forEach(t => t.classList.toggle('active', t === tab));
            $$('#settingsModal .tab-body').forEach(b => b.classList.toggle('active', b.dataset.tabBody === tab.dataset.tab));
        });
    });

    els.saveRoomChanges?.addEventListener('click', async () => {
        if (!state.currentRoomId) return;
        els.editRoomError.textContent = '';
        try {
            const payload = {
                action: 'update',
                room_id: state.currentRoomId,
                name: els.editRoomName.value.trim(),
                description: els.editRoomDesc.value.trim(),
            };
            const disappearingSel = $('#disappearingSelect');
            if (disappearingSel) payload.disappearing_seconds = parseInt(disappearingSel.value, 10) || 0;

            await apiPost('api/rooms.php', payload);
            UI.showToast('Room updated', 'success');
            await loadRooms();
            selectRoom(state.currentRoomId);
        } catch (e) { els.editRoomError.textContent = e.message; }
    });

    els.roomImageInput?.addEventListener('change', async () => {
        const file = els.roomImageInput.files[0];
        if (!file) return;
        els.roomImageInput.value = '';
        try {
            const res = await apiUpload(file, 'room');
            await apiPost('api/rooms.php', { action: 'update', room_id: state.currentRoomId, image_path: res.path });
            els.roomImagePreview.innerHTML = `<img src="${PUBLIC_BASE}/${UI.escapeHtml(res.path)}" alt="">`;
            UI.showToast('Room image updated', 'success');
            await loadRooms();
            selectRoom(state.currentRoomId);
        } catch (e) { UI.showToast(e.message, 'error', 'Upload failed'); }
    });

    $$('.wallpaper-swatch').forEach(sw => {
        sw.addEventListener('click', async () => {
            if (!state.currentRoomId) return;
            $$('.wallpaper-swatch').forEach(s => s.classList.toggle('active', s === sw));
            const main = document.querySelector('.chat-main');
            if (main) main.dataset.wallpaper = sw.dataset.wallpaper;
            try {
                await apiPost('api/rooms.php', { action: 'update', room_id: state.currentRoomId, wallpaper: sw.dataset.wallpaper });
                const r = state.rooms.find(r => Number(r.id) === state.currentRoomId);
                if (r) r.wallpaper = sw.dataset.wallpaper;
            } catch (e) { UI.showToast(e.message, 'error'); }
        });
    });

    els.deleteRoomBtn?.addEventListener('click', async () => {
        if (!state.currentRoomId) return;
        if (!confirm('Delete this room permanently?')) return;
        try {
            await apiPost('api/rooms.php', { action: 'delete', room_id: state.currentRoomId });
            UI.closeModal('settingsModal');
            UI.showToast('Room deleted', 'success');
            state.currentRoomId = null;
            els.roomTitle.textContent = 'Select a room';
            els.roomSubtitle.textContent = 'Choose a conversation to start messaging';
            els.messages.innerHTML = '';
            els.emptyState.classList.remove('hidden');
            els.messageInput.disabled = true;
            els.attachBtn.disabled = true;
            if (els.emojiBtn) els.emojiBtn.disabled = true;
            if (els.stickerBtn) els.stickerBtn.disabled = true;
            els.audioCallBtn.disabled = true;
            els.videoCallBtn.disabled = true;
            els.roomSettingsBtn.disabled = true;
            await loadRooms();
        } catch (e) { UI.showToast(e.message, 'error'); }
    });

    /* ---------- Members ---------- */
    async function loadMembers() {
        if (!state.currentRoomId) return;
        try {
            const { members } = await apiGet('api/members.php', { room_id: state.currentRoomId });
            state.members = members || [];
            renderMembers();
        } catch (e) {
            els.membersList.innerHTML = '<div class="empty-rooms">Could not load members.</div>';
        }
    }

    function renderMembers() {
        if (!els.membersList) return;
        if (!state.members.length) { els.membersList.innerHTML = '<div class="empty-rooms">No members.</div>'; return; }

        const room = state.rooms.find(r => Number(r.id) === state.currentRoomId);
        const meOwner = room?.is_owner;
        const meAdmin = room?.is_admin;

        els.membersList.innerHTML = '';
        state.members.forEach(m => {
            const el = document.createElement('div');
            el.className = 'member-row';
            const initial = (m.display_name || '?').charAt(0).toUpperCase();
            const status = m.status || 'offline';
            const lastSeen = m.show_last_seen && m.last_seen ? ` · ${UI.relativeTime(m.last_seen)}` : '';

            el.innerHTML = `
                <div class="avatar" style="background:${UI.escapeHtml(m.avatar_color || '#6366f1')}">
                    ${m.avatar_path ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(m.avatar_path)}" alt="">` : UI.escapeHtml(initial)}
                    <span class="presence-dot dot-${status}"></span>
                </div>
                <div class="name">
                    <div class="title">
                        ${UI.escapeHtml(m.display_name)}
                        ${m.role !== 'member' ? `<span class="role-tag role-${m.role}">${m.role}</span>` : ''}
                    </div>
                    <div class="sub">${UI.escapeHtml(m.status_message || status)}${lastSeen}</div>
                </div>
                <div class="actions"></div>
            `;

            const actions = el.querySelector('.actions');

            if (m.id !== currentUserId) {
                if (meAdmin && m.role !== 'owner') {
                    const rmv = document.createElement('button');
                    rmv.className = 'icon-btn small';
                    rmv.title = 'Remove';
                    rmv.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                    rmv.addEventListener('click', async () => {
                        if (!confirm(`Remove ${m.display_name}?`)) return;
                        try {
                            await apiPost('api/members.php', { action: 'remove', room_id: state.currentRoomId, user_id: m.id });
                            loadMembers();
                        } catch (e) { UI.showToast(e.message, 'error'); }
                    });
                    actions.appendChild(rmv);
                }

                if (meOwner) {
                    const roleBtn = document.createElement('button');
                    roleBtn.className = 'icon-btn small';
                    roleBtn.title = m.role === 'admin' ? 'Demote' : 'Promote to admin';
                    roleBtn.innerHTML = m.role === 'admin'
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>';
                    roleBtn.addEventListener('click', async () => {
                        try {
                            await apiPost('api/members.php', { action: m.role === 'admin' ? 'demote' : 'promote', room_id: state.currentRoomId, user_id: m.id });
                            loadMembers();
                        } catch (e) { UI.showToast(e.message, 'error'); }
                    });
                    actions.appendChild(roleBtn);
                }
            }
            els.membersList.appendChild(el);
        });
    }

    els.addMemberSearch?.addEventListener('input', () => {
        const q = els.addMemberSearch.value.trim().toLowerCase();
        if (q.length < 2) { els.addMemberResults.innerHTML = ''; return; }
        const matches = state.people.filter(u =>
            (u.username.toLowerCase().includes(q) || u.display_name.toLowerCase().includes(q))
            && !state.members.some(m => m.id === u.id)
        ).slice(0, 6);

        els.addMemberResults.innerHTML = '';
        if (!matches.length) { els.addMemberResults.innerHTML = '<div class="empty-rooms">No matching users.</div>'; return; }
        matches.forEach(u => {
            const row = document.createElement('button');
            row.className = 'add-result';
            row.innerHTML = `<span>${UI.escapeHtml(u.display_name)} <small>@${UI.escapeHtml(u.username)}</small></span><span>Add</span>`;
            row.addEventListener('click', async () => {
                try {
                    await apiPost('api/members.php', { action: 'add', room_id: state.currentRoomId, user_id: u.id });
                    els.addMemberSearch.value = ''; els.addMemberResults.innerHTML = '';
                    loadMembers(); UI.showToast('Member added', 'success');
                } catch (e) { UI.showToast(e.message, 'error'); }
            });
            els.addMemberResults.appendChild(row);
        });
    });

    /* ---------- Profile ---------- */
    els.userChip?.addEventListener('click', async () => {
        try {
            const { user } = await apiGet('api/profile.php');
            els.profileDisplay.value = user.display_name || '';
            els.profileBio.value = user.bio || '';
            els.profileStatusMsg.value = user.status_message || '';
            els.profileShowLastSeen.checked = !!user.show_last_seen;
            els.profileAvatar.innerHTML = user.avatar_path
                ? `<img src="${PUBLIC_BASE}/${UI.escapeHtml(user.avatar_path)}" alt="">`
                : UI.escapeHtml((user.display_name || '?').charAt(0).toUpperCase());
            els.profileAvatar.style.background = user.avatar_color || '#6366f1';
            els.profileError.textContent = '';
            $$('#statusChoices .status-choice').forEach(b => b.classList.toggle('active', b.dataset.status === user.status));
            UI.openModal('profileModal');
        } catch (e) { UI.showToast(e.message, 'error'); }
    });

    els.statusChoices?.addEventListener('click', e => {
        const btn = e.target.closest('.status-choice');
        if (!btn) return;
        $$('#statusChoices .status-choice').forEach(b => b.classList.toggle('active', b === btn));
    });

    els.avatarInput?.addEventListener('change', async () => {
        const file = els.avatarInput.files[0];
        if (!file) return;
        els.avatarInput.value = '';
        try {
            const res = await apiUpload(file, 'avatar');
            await apiPost('api/profile.php', { avatar_path: res.path });
            els.profileAvatar.innerHTML = `<img src="${PUBLIC_BASE}/${UI.escapeHtml(res.path)}" alt="">`;
            document.querySelector('#userAvatar').innerHTML = `<img src="${PUBLIC_BASE}/${UI.escapeHtml(res.path)}" alt="">`;
            UI.showToast('Profile picture updated', 'success');
        } catch (e) { UI.showToast(e.message, 'error', 'Upload failed'); }
    });

    els.saveProfileBtn?.addEventListener('click', async () => {
        els.profileError.textContent = '';
        const active = $('#statusChoices .status-choice.active');
        const payload = {
            display_name: els.profileDisplay.value.trim(),
            bio: els.profileBio.value.trim(),
            status_message: els.profileStatusMsg.value.trim(),
            show_last_seen: els.profileShowLastSeen.checked,
        };
        if (active) payload.status = active.dataset.status;
        try {
            await apiPost('api/profile.php', payload);
            if (payload.status) window.ChatSocket.setStatus(payload.status);
            UI.showToast('Profile saved', 'success');
            UI.closeModal('profileModal');
        } catch (e) { els.profileError.textContent = e.message; }
    });

    els.lightbox?.addEventListener('click', () => UI.closeModal('lightbox'));

    /* ---------- Calls ---------- */
    els.audioCallBtn?.addEventListener('click', () => startCall('audio'));
    els.videoCallBtn?.addEventListener('click', () => startCall('video'));

    async function startCall(type) {
        if (!state.currentRoomId) return;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            UI.showToast('Browser does not support calls, or page is not in a secure context.', 'error');
            return;
        }
        state.callType = type;
        state.callStartTime = Date.now();
        try {
            const constraints = type === 'video'
                ? { audio: true, video: { width: 640, height: 480 } }
                : { audio: true, video: false };
            state.localStream = await navigator.mediaDevices.getUserMedia(constraints);
            if (type === 'video') els.localVideo.srcObject = state.localStream;
            els.callStatus.textContent = type === 'video' ? 'Video call' : 'Audio call';
            UI.openModal('callModal');
            state.callTimer = setInterval(() => {
                const s = Math.floor((Date.now() - state.callStartTime) / 1000);
                const m = String(Math.floor(s / 60)).padStart(2, '0');
                const ss = String(s % 60).padStart(2, '0');
                els.callTimer.textContent = `${m}:${ss}`;
            }, 1000);
            window.ChatSocket.sendCallSignal(state.currentRoomId, { kind: 'invite' }, type, 'invite');
            UI.showToast('Call signal sent.', 'info');
        } catch (e) {
            UI.showToast('Could not access camera/mic: ' + e.message, 'error');
        }
    }

    els.endCallBtn?.addEventListener('click', endCall);
    function endCall() {
        if (state.localStream) { state.localStream.getTracks().forEach(t => t.stop()); state.localStream = null; }
        if (state.peerConnection) { try { state.peerConnection.close(); } catch {} state.peerConnection = null; }
        if (state.callTimer) { clearInterval(state.callTimer); state.callTimer = null; }
        els.localVideo.srcObject = null;
        els.remoteVideo.srcObject = null;
        els.callTimer.textContent = '00:00';
        UI.closeModal('callModal');
        if (state.currentRoomId) window.ChatSocket.sendCallSignal(state.currentRoomId, null, state.callType || 'audio', 'end');
    }

    els.muteMicBtn?.addEventListener('click', () => {
        if (!state.localStream) return;
        const t = state.localStream.getAudioTracks()[0];
        if (t) { t.enabled = !t.enabled; els.muteMicBtn.classList.toggle('active', !t.enabled); }
    });
    els.toggleCamBtn?.addEventListener('click', () => {
        if (!state.localStream) return;
        const t = state.localStream.getVideoTracks()[0];
        if (t) { t.enabled = !t.enabled; els.toggleCamBtn.classList.toggle('active', !t.enabled); }
    });

    window.ChatSocket.on('call-signal', async (data) => {
        if (data.from === currentUserId) return;
        if (data.action === 'invite') UI.showToast('Someone started a call.', 'info', 'Incoming call');
        else if (data.action === 'end' && state.callStartTime) endCall();
    });

    /* ---------- Logout ---------- */
    els.logoutBtn?.addEventListener('click', async () => {
        try {
            await fetch(`${API_BASE}/auth/logout.php`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch {}
        window.ChatSocket.disconnect();
        window.location.href = 'index.php';
    });

    /* ---------- Keyboard ---------- */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            $$('.modal:not([hidden])').forEach(m => UI.closeModal(m.id));
            closeSidebar();
            closePickers();
        }
    });

    /* ---------- Polling fallback ---------- */
    let pollTimer = null, lastSeenId = 0;
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(async () => {
            if (!state.currentRoomId) return;
            try {
                const { messages } = await apiGet('api/messages.php', { room_id: state.currentRoomId, limit: 30 });
                messages.forEach(m => {
                    if (Number(m.id) > lastSeenId) {
                        appendMessage(m);
                        lastSeenId = Math.max(lastSeenId, Number(m.id));
                    }
                });
            } catch {}
        }, 3000);
    }

    /* ---------- Notifications ---------- */
    let notifOpen = false;
    let notifPanel = null;

    async function loadNotifications() {
        try {
            const res = await apiGet('api/notifications.php');
            const list = res.notifications || [];
            const unread = res.unread || 0;
            if (els.notifBadge) {
                if (unread > 0) { els.notifBadge.hidden = false; els.notifBadge.textContent = unread > 9 ? '9+' : String(unread); }
                else els.notifBadge.hidden = true;
            }
            if (notifOpen && notifPanel) renderNotifications(notifPanel, list);
        } catch (e) {}
    }

    function renderNotifications(panel, list) {
        if (!list.length) { panel.innerHTML = '<div class="notif-empty">No notifications yet</div>'; return; }
        panel.innerHTML = '';
        list.forEach(n => {
            const el = document.createElement('div');
            el.className = 'notif-item' + (n.is_read ? '' : ' unread');
            el.innerHTML = `
                <div class="n-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    </svg>
                </div>
                <div class="n-body">
                    <strong>${UI.escapeHtml(n.title)}</strong>
                    ${n.body ? `<small>${UI.escapeHtml(n.body)}</small>` : ''}
                    <time>${UI.escapeHtml(UI.relativeTime(n.created_at))}</time>
                </div>
            `;
            el.addEventListener('click', async () => {
                try {
                    await apiPost('api/notifications.php', { action: 'read', id: n.id });
                    loadNotifications();
                } catch {}
            });
            panel.appendChild(el);
        });
    }

    els.notifBtn?.addEventListener('click', e => {
        e.stopPropagation();
        if (notifOpen) closeNotifPanel();
        else openNotifPanel();
    });

    function openNotifPanel() {
        const wrap = document.querySelector('.header-actions');
        if (!wrap) return;
        notifPanel = document.createElement('div');
        notifPanel.className = 'notif-panel';
        notifPanel.innerHTML = '<div class="notif-empty">Loading…</div>';
        wrap.style.position = 'relative';
        wrap.appendChild(notifPanel);
        setTimeout(() => document.addEventListener('click', handleOutsideNotifClick), 0);
        notifOpen = true;
        loadNotifications();
    }

    function closeNotifPanel() {
        if (notifPanel) { notifPanel.remove(); notifPanel = null; }
        document.removeEventListener('click', handleOutsideNotifClick);
        notifOpen = false;
    }

    function handleOutsideNotifClick(e) {
        if (notifPanel && !notifPanel.contains(e.target) && e.target !== els.notifBtn && !els.notifBtn?.contains(e.target)) closeNotifPanel();
    }

    setInterval(() => { if (!document.hidden) loadNotifications(); }, 30000);

    /* ---------- Emoji / Sticker pickers ---------- */
    const EMOJI_GROUPS = {
        Smileys: ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕'],
        Gestures: ['👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐','🖖','👋','🤝','🙏','💪','✊','👊','🤛','🤜'],
        Hearts:   ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','💌'],
        Animals:  ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜'],
        Food:     ['🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🥦','🥕','🌽','🌶','🥒','🥬','🧄','🧅','🥔','🍠','🥐','🥯','🍞','🥖','🧀','🥚','🍳','🥓','🥞','🧇'],
        Activities:['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸','🥌','🎿'],
        Travel:   ['🚗','🚕','🚙','🚌','🚎','🏎','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🏍','🛵','🚲','🛴','🚨','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆'],
        Symbols:  ['🔥','⭐','🌟','✨','⚡','💥','💫','💦','💨','🎉','🎊','🎈','🎁','🏆','🥇','🥈','🥉','🏅','🎖','🏵','🎗','🎫','🎟'],
    };

    const STICKERS = ['🎉','🎊','🎈','🎁','🏆','🥳','🎂','🍰','☕','🍕','🍔','🌮','🍦','🎮','🎯','🎨','🎭','🎬','🎤','🎧','🚀','⭐','🌈','🌞','🌙','❄️','🔥','💧','🌸','🍀','🦋','🐢'];

    let emojiPanel = null, stickerPanel = null;
    function closePickers() {
        if (emojiPanel) { emojiPanel.remove(); emojiPanel = null; }
        if (stickerPanel) { stickerPanel.remove(); stickerPanel = null; }
    }

    els.emojiBtn?.addEventListener('click', e => {
        e.stopPropagation();
        const wasOpen = !!emojiPanel;
        closePickers();
        if (wasOpen) return;
        emojiPanel = document.createElement('div');
        emojiPanel.className = 'emoji-panel';
        for (const [group, emojis] of Object.entries(EMOJI_GROUPS)) {
            const cat = document.createElement('div');
            cat.className = 'emoji-category';
            cat.textContent = group;
            emojiPanel.appendChild(cat);
            const grid = document.createElement('div');
            grid.className = 'emoji-grid';
            emojis.forEach(em => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = em;
                b.addEventListener('click', ev => {
                    ev.stopPropagation();
                    insertAtCursor(els.messageInput, em);
                    els.sendBtn.disabled = els.messageInput.value.trim().length === 0;
                });
                grid.appendChild(b);
            });
            emojiPanel.appendChild(grid);
        }
        els.composer.appendChild(emojiPanel);
        setTimeout(() => document.addEventListener('click', closePickers), 0);
    });

    els.stickerBtn?.addEventListener('click', e => {
        e.stopPropagation();
        const wasOpen = !!stickerPanel;
        closePickers();
        if (wasOpen) return;
        stickerPanel = document.createElement('div');
        stickerPanel.className = 'sticker-panel';
        STICKERS.forEach(st => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = st;
            b.style.fontSize = '34px';
            b.addEventListener('click', async ev => {
                ev.stopPropagation();
                if (!state.currentRoomId) return;
                closePickers();
                await sendViaHttp(st, { message_type: 'sticker' });
            });
            stickerPanel.appendChild(b);
        });
        els.composer.appendChild(stickerPanel);
        setTimeout(() => document.addEventListener('click', closePickers), 0);
    });

    function insertAtCursor(input, text) {
        if (!input) return;
        const s = input.selectionStart ?? input.value.length;
        const e = input.selectionEnd ?? input.value.length;
        input.value = input.value.slice(0, s) + text + input.value.slice(e);
        input.selectionStart = input.selectionEnd = s + text.length;
        input.focus();
    }

    /* ---------- Boot ---------- */
    async function boot() {
        await loadRooms();
        await loadPeople();
        await loadNotifications();

        if (window.innerWidth > 900 && state.rooms.length) {
            await selectRoom(state.rooms[0].id);
        }

        window.ChatSocket.connect();

        setInterval(() => { if (!document.hidden) { loadRooms(); loadPeople(); } }, 60000);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !window.ChatSocket.isConnected) window.ChatSocket.connect();
        });
    }

    document.addEventListener('DOMContentLoaded', boot);
    if (document.readyState !== 'loading') boot();
})();