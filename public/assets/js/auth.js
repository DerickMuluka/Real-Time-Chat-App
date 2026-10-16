/* ==========================================================================
   Converse — Authentication
   ========================================================================== */
(function () {
    'use strict';

    const $  = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

    const API_BASE = (function () {
        if (window.APP_CONFIG && window.APP_CONFIG.apiBase) return window.APP_CONFIG.apiBase.replace(/\/+$/, '');
        var p = window.location.pathname;
        p = p.replace(/\/index\.php$/i, '').replace(/\/[^\/]*\.php$/i, '').replace(/\/public\/?$/i, '').replace(/\/+$/, '');
        return window.location.origin + p + '/src';
    })();

    const tabSwitch = $('.tab-switch');
    const tabBtns   = $$('.tab-btn');
    const forms     = { loginForm: $('#loginForm'), registerForm: $('#registerForm') };

    function setTab(target) {
        tabBtns.forEach(btn => {
            const active = btn.dataset.target === target;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        Object.entries(forms).forEach(([id, form]) => form && form.classList.toggle('active', id === target));
        if (tabSwitch) tabSwitch.dataset.active = target === 'registerForm' ? 'register' : 'login';
        const active = forms[target];
        if (active) { const f = active.querySelector('input:not([type=hidden])'); if (f) setTimeout(() => f.focus(), 60); }
    }
    tabBtns.forEach(btn => btn.addEventListener('click', () => setTab(btn.dataset.target)));

    $$('.toggle-pass').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
        });
    });

    const regPassword = $('#reg-password');
    const meter = $('.strength-meter');
    const strengthLabel = $('.strength-label');

    function score(pw) {
        if (!pw) return 0;
        let s = 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
        if (/\d/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return Math.min(4, s);
    }
    if (regPassword && meter) {
        regPassword.addEventListener('input', () => {
            const lvl = score(regPassword.value);
            meter.dataset.level = String(lvl);
            if (strengthLabel) {
                strengthLabel.textContent = regPassword.value
                    ? ['', 'Weak password', 'Fair password', 'Good password', 'Strong password'][lvl] : '';
                strengthLabel.style.color =
                    lvl >= 3 ? 'var(--success-500)' :
                    lvl === 2 ? 'var(--warning-500)' :
                    lvl === 1 ? 'var(--danger-500)' : '';
            }
        });
    }

    async function postJSON(path, data) {
        const url = path.startsWith('http') ? path : `${API_BASE}/${path}`;
        let res;
        try { res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: JSON.stringify(data) }); }
        catch { throw new Error('Network error — is Apache running?'); }

        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            const text = await res.text();
            console.error('Non-JSON from', url, text.slice(0, 300));
            throw new Error(res.status === 404 ? 'Endpoint not found.' : `Server error (${res.status})`);
        }
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.error || `Request failed (${res.status})`);
        return json;
    }

    function setLoading(form, on) {
        const btn = form.querySelector('.btn-primary'); if (!btn) return;
        btn.classList.toggle('loading', on); btn.disabled = on;
    }
    function showError(form, msg) { const el = form.querySelector('.form-error'); if (el) { el.textContent = msg || ''; el.style.color = ''; } }
    function showSuccess(form, msg) { const el = form.querySelector('.form-error'); if (el) { el.textContent = msg || ''; el.style.color = 'var(--success-500)'; } }

    const loginForm = $('#loginForm');
    loginForm?.addEventListener('submit', async e => {
        e.preventDefault();
        showError(loginForm, '');
        const identifier = loginForm.identifier.value.trim();
        const password   = loginForm.password.value;
        const csrf       = loginForm.csrf_token.value;
        if (!identifier) return showError(loginForm, 'Enter your username or email.');
        if (!password)   return showError(loginForm, 'Enter your password.');

        setLoading(loginForm, true);
        try {
            const res = await postJSON('auth/login.php', { identifier, password, csrf_token: csrf });
            if (res.success) window.location.href = 'chat.php';
            else showError(loginForm, res.error || 'Sign in failed.');
        } catch (err) { showError(loginForm, err.message); }
        finally { setLoading(loginForm, false); }
    });

    const registerForm = $('#registerForm');
    registerForm?.addEventListener('submit', async e => {
        e.preventDefault();
        showError(registerForm, '');
        const display_name = registerForm.display_name.value.trim();
        const username = registerForm.username.value.trim();
        const email    = registerForm.email.value.trim();
        const password = registerForm.password.value;
        const csrf     = registerForm.csrf_token.value;

        if (display_name.length < 2) return showError(registerForm, 'Display name too short.');
        if (!/^[a-zA-Z0-9_]{3,50}$/.test(username)) return showError(registerForm, 'Invalid username.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showError(registerForm, 'Invalid email.');
        if (password.length < 8) return showError(registerForm, 'Password must be 8+ characters.');

        setLoading(registerForm, true);
        try {
            const res = await postJSON('auth/register.php', { display_name, username, email, password, csrf_token: csrf });
            if (res.success) {
                registerForm.reset();
                if (meter) meter.dataset.level = '0';
                if (strengthLabel) strengthLabel.textContent = '';
                setTab('loginForm');
                if (loginForm?.identifier) loginForm.identifier.value = username;
                showSuccess(loginForm, res.message || 'Account created. Please sign in.');
                setTimeout(() => showError(loginForm, ''), 6000);
            } else showError(registerForm, res.error || 'Registration failed.');
        } catch (err) { showError(registerForm, err.message); }
        finally { setLoading(registerForm, false); }
    });

    const initial = $('.auth-form.active input:not([type=hidden])');
    if (initial) initial.focus();
})();