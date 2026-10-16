<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: chat.php');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0f172a">
    <title>Converse — Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body class="auth-body">
    <div class="auth-shell">
        <aside class="auth-brand" aria-hidden="true">
            <div class="brand-mark">
                <svg viewBox="0 0 48 48" fill="none"><path d="M8 20C8 13.373 13.373 8 20 8h8c6.627 0 12 5.373 12 12v8c0 6.627-5.373 12-12 12h-8c-6.627 0-12-5.373-12-12v-8z" stroke="currentColor" stroke-width="2.5"/><circle cx="18" cy="24" r="2.5" fill="currentColor"/><circle cx="24" cy="24" r="2.5" fill="currentColor"/><circle cx="30" cy="24" r="2.5" fill="currentColor"/></svg>
            </div>
            <h1 class="brand-title">Converse</h1>
            <p class="brand-tagline">Where conversations happen in real time.</p>
            <ul class="brand-features">
                <li><span></span>Instant message delivery</li>
                <li><span></span>Group rooms &amp; admins</li>
                <li><span></span>Files, media &amp; calls</li>
            </ul>
        </aside>

        <main class="auth-panel">
            <div class="auth-card" id="authCard">
                <div class="tab-switch" role="tablist">
                    <button class="tab-btn active" data-target="loginForm" role="tab" aria-selected="true">Sign In</button>
                    <button class="tab-btn" data-target="registerForm" role="tab" aria-selected="false">Create Account</button>
                    <span class="tab-indicator"></span>
                </div>

                <form id="loginForm" class="auth-form active" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="form-heading">
                        <h2>Sign in</h2>
                        <p>Enter your credentials to continue</p>
                    </div>
                    <div class="field">
                        <label for="login-identifier">Username or Email</label>
                        <input type="text" id="login-identifier" name="identifier" required autocomplete="username" spellcheck="false" placeholder="e.g. alex or alex@mail.com">
                    </div>
                    <div class="field">
                        <label for="login-password">Password</label>
                        <div class="password-wrap">
                            <input type="password" id="login-password" name="password" required autocomplete="current-password" placeholder="••••••••">
                            <button type="button" class="toggle-pass" data-target="login-password" aria-label="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-error" id="loginError" role="alert" aria-live="polite"></div>
                    <button type="submit" class="btn-primary">
                        <span class="btn-label">Sign In</span>
                        <span class="btn-spinner" aria-hidden="true"></span>
                    </button>
                </form>

                <form id="registerForm" class="auth-form" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="form-heading">
                        <h2>Create account</h2>
                        <p>Join the conversation in seconds</p>
                    </div>
                    <div class="field">
                        <label for="reg-display">Display Name</label>
                        <input type="text" id="reg-display" name="display_name" required autocomplete="name" maxlength="100" placeholder="How others will see you">
                    </div>
                    <div class="field">
                        <label for="reg-username">Username</label>
                        <input type="text" id="reg-username" name="username" required autocomplete="username" spellcheck="false" pattern="[a-zA-Z0-9_]{3,50}" maxlength="50" placeholder="lowercase, no spaces">
                    </div>
                    <div class="field">
                        <label for="reg-email">Email</label>
                        <input type="email" id="reg-email" name="email" required autocomplete="email" maxlength="100" placeholder="you@example.com">
                    </div>
                    <div class="field">
                        <label for="reg-password">Password</label>
                        <div class="password-wrap">
                            <input type="password" id="reg-password" name="password" required autocomplete="new-password" minlength="8" placeholder="Min. 8 characters">
                            <button type="button" class="toggle-pass" data-target="reg-password" aria-label="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="strength-meter" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                        <small class="strength-label" aria-live="polite"></small>
                    </div>
                    <div class="form-error" id="registerError" role="alert" aria-live="polite"></div>
                    <button type="submit" class="btn-primary">
                        <span class="btn-label">Create Account</span>
                        <span class="btn-spinner" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
            <p class="auth-footer">Protected by industry-standard encryption</p>
        </main>
    </div>

    <script>
        (function () {
            var p = window.location.pathname;
            p = p.replace(/\/index\.php$/i, '');
            p = p.replace(/\/[^\/]*\.php$/i, '');
            p = p.replace(/\/public\/?$/i, '');
            p = p.replace(/\/+$/, '');
            var apiBase = window.location.origin + p + '/src';
            window.APP_CONFIG = {
                apiBase: apiBase,
                wsUrl: (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8080'
            };
        })();
    </script>
    <script src="assets/js/auth.js" defer></script>
</body>
</html>