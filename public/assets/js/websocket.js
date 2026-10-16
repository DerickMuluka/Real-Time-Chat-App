/* ==========================================================================
   Converse — WebSocket client
   ========================================================================== */
(function (window) {
    'use strict';

    const state = {
        socket: null,
        connected: false,
        authenticated: false,
        reconnectAttempts: 0,
        maxReconnectDelay: 15000,
        heartbeatTimer: null,
        rooms: new Set(),
        listeners: new Map()
    };

    function emit(event, payload) {
        const set = state.listeners.get(event);
        if (!set) return;
        set.forEach(fn => { try { fn(payload); } catch (e) { console.error('[WS listener]', e); } });
    }

    function on(event, fn) {
        if (!state.listeners.has(event)) state.listeners.set(event, new Set());
        state.listeners.get(event).add(fn);
        return () => state.listeners.get(event)?.delete(fn);
    }

    function connect() {
        if (state.socket && (state.socket.readyState === WebSocket.OPEN || state.socket.readyState === WebSocket.CONNECTING)) return;

        const url = window.APP_CONFIG?.wsUrl;
        if (!url) { emit('status', { state: 'fallback' }); return; }

        emit('status', { state: 'connecting' });

        let socket;
        try { socket = new WebSocket(url); }
        catch (e) { scheduleReconnect(); return; }

        state.socket = socket;

        socket.addEventListener('open', () => {
            state.connected = true;
            state.reconnectAttempts = 0;
            emit('status', { state: 'connected' });
            const sid = getSessionId();
            if (sid) send({ type: 'auth', session_id: sid });
            state.rooms.forEach(id => send({ type: 'join_room', room_id: id }));
            startHeartbeat();
        });

        socket.addEventListener('message', (ev) => {
            let data; try { data = JSON.parse(ev.data); } catch { return; }
            handleServerMessage(data);
        });

        socket.addEventListener('close', () => {
            state.connected = false;
            state.authenticated = false;
            stopHeartbeat();
            emit('status', { state: 'disconnected' });
            scheduleReconnect();
        });

        socket.addEventListener('error', () => emit('status', { state: 'error' }));
    }

    function handleServerMessage(data) {
        switch (data.type) {
            case 'auth':
                if (data.success) { state.authenticated = true; emit('status', { state: 'authenticated' }); }
                else emit('status', { state: 'auth_failed', error: data.error });
                break;
            case 'message':       emit('message', data.message); break;
            case 'typing':        emit('typing', data); break;
            case 'presence':      emit('presence', data); break;
            case 'joined':        emit('joined', data); break;
            case 'left':          emit('left', data); break;
            case 'call-signal':   emit('call-signal', data); break;
            case 'pong': break;
            case 'error': console.warn('[WS server error]', data.error); emit('error', data); break;
        }
    }

    function send(payload) {
        if (!state.socket || state.socket.readyState !== WebSocket.OPEN) return false;
        try { state.socket.send(JSON.stringify(payload)); return true; }
        catch (e) { return false; }
    }

    function joinRoom(id) { id = Number(id); if (!id) return; state.rooms.add(id); send({ type: 'join_room', room_id: id }); }
    function leaveRoom(id) { id = Number(id); state.rooms.delete(id); send({ type: 'leave_room', room_id: id }); }
    function sendMessage(roomId, content, extra = {}) {
        return send({ type: 'message', room_id: Number(roomId), content: String(content), ...extra });
    }
    function setTyping(roomId, typing) { return send({ type: 'typing', room_id: Number(roomId), typing: !!typing }); }
    function setStatus(status) { return send({ type: 'status', status }); }
    function sendCallSignal(roomId, signal, callType, action) {
        return send({ type: 'call-signal', room_id: Number(roomId), signal, call_type: callType, action });
    }

    function startHeartbeat() {
        stopHeartbeat();
        state.heartbeatTimer = setInterval(() => send({ type: 'ping' }), 25000);
    }
    function stopHeartbeat() {
        if (state.heartbeatTimer) { clearInterval(state.heartbeatTimer); state.heartbeatTimer = null; }
    }
    function scheduleReconnect() {
        state.reconnectAttempts++;
        const delay = Math.min(state.maxReconnectDelay, 800 * Math.pow(1.6, state.reconnectAttempts));
        setTimeout(connect, delay);
    }
    function getSessionId() {
        const m = document.cookie.match(/(?:^|;\s*)PHPSESSID=([^;]+)/);
        return m ? m[1] : null;
    }
    function disconnect() {
        stopHeartbeat();
        if (state.socket) try { state.socket.close(); } catch {}
        state.socket = null; state.connected = false; state.authenticated = false;
    }

    window.ChatSocket = {
        connect, disconnect, on, send,
        joinRoom, leaveRoom, sendMessage, setTyping, setStatus, sendCallSignal,
        get isConnected() { return state.connected; },
        get isAuthenticated() { return state.authenticated; }
    };
})(window);