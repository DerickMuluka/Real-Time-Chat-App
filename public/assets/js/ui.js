/* ==========================================================================
   Converse — UI helpers
   ========================================================================== */
(function (window) {
    'use strict';

    const toastStack = () => document.getElementById('toastStack');

    function showToast(message, type = 'info', title = '') {
        const stack = toastStack();
        if (!stack) return;

        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `
            <div>
                ${title ? `<strong>${escapeHtml(title)}</strong>` : ''}
                <small>${escapeHtml(message)}</small>
            </div>
        `;
        stack.appendChild(el);

        setTimeout(() => {
            el.style.transition = 'opacity 240ms ease, transform 240ms ease';
            el.style.opacity = '0';
            el.style.transform = 'translateX(20px)';
            setTimeout(() => el.remove(), 260);
        }, 3600);
    }

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.hidden = false; requestAnimationFrame(() => m.classList.add('visible')); }
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('visible');
        setTimeout(() => { m.hidden = true; }, 200);
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function parseDate(iso) {
        if (!iso) return null;
        if (typeof iso === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(iso)) {
            return new Date(iso.replace(' ', 'T'));
        }
        const d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatTime(iso) {
        const d = parseDate(iso);
        if (!d) return '';
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function formatDayLabel(iso) {
        const d = parseDate(iso);
        if (!d) return '';
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        const sameDay = (a, b) =>
            a.getFullYear() === b.getFullYear() &&
            a.getMonth() === b.getMonth() &&
            a.getDate() === b.getDate();

        if (sameDay(d, today)) return 'Today';
        if (sameDay(d, yesterday)) return 'Yesterday';
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function dayKey(iso) {
        const d = parseDate(iso);
        if (!d) return '';
        return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
    }

    function formatBytes(n) {
        if (!n) return '0 B';
        const k = 1024, s = ['B','KB','MB','GB'];
        const i = Math.min(s.length - 1, Math.floor(Math.log(n) / Math.log(k)));
        return (n / Math.pow(k, i)).toFixed(1) + ' ' + s[i];
    }

    function relativeTime(iso) {
        const d = parseDate(iso);
        if (!d) return '';
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString();
    }

    window.UI = {
        showToast, openModal, closeModal,
        escapeHtml, parseDate, formatTime, formatDayLabel, dayKey,
        formatBytes, relativeTime,
    };
})(window);