<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>تسجيل دخول - نظام أرشفة المرضى</title>
    @php
        $mobileApiUrl = rtrim(config('mobile.api_url'), '/');
        $mobileApiHost = parse_url($mobileApiUrl, PHP_URL_HOST) ?? null;
        $mobileApiPath = parse_url($mobileApiUrl, PHP_URL_PATH) ?: '/api';
        $mobileApiMeta = $mobileApiHost === request()->getHost() ? rtrim($mobileApiPath, '/') . '/v1' : $mobileApiUrl . '/v1';
    @endphp
    <meta name="mobile-api-url" content="{{ $mobileApiMeta }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563EB;
            --bg: #F8FAFC;
            --surface: #FFFFFF;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --input-bg: #FFFFFF;
        }

        * { box-sizing: border-box; }

        html, body {
            height: 100%;
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg);
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .login-main {
            width: 100%;
            max-width: 450px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 3rem;
            background: var(--surface);
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .login-icon {
            width: 80px;
            height: 80px;
            background: #EFF6FF;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
            width: 100%;
        }

        .login-header h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 0.5rem 0;
        }

        .login-header p {
            color: var(--text-muted);
            font-size: 1.1rem;
            margin: 0;
        }

        .login-form {
            width: 100%;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-main);
        }

        .input-icon-wrapper {
            position: relative;
        }

        .input-icon-wrapper i {
            position: absolute;
            right: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.2rem;
            pointer-events: none;
        }

        .input-icon-wrapper .form-control {
            padding-right: 3.5rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.2rem;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 1.1rem;
            background: var(--input-bg);
            color: var(--text-main);
            transition: 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .form-control:focus + i {
            color: var(--primary);
        }

        .error-alert {
            display: none;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-radius: 8px;
            padding: 1rem;
            color: #DC2626;
            font-size: 1rem;
            font-weight: bold;
            margin-bottom: 1.5rem;
            align-items: center;
            gap: 0.8rem;
        }

        .btn-login {
            width: 100%;
            padding: 1.2rem;
            border-radius: 8px;
            background: var(--primary);
            color: white;
            border: none;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .btn-login:hover {
            background: #1D4ED8;
            transform: translateY(-2px);
        }

        .login-footer {
            margin-top: 2rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        [dir="ltr"] .input-icon-wrapper i { left: 1.2rem; right: auto; }
        [dir="ltr"] .input-icon-wrapper .form-control { padding-left: 3.5rem; padding-right: 1.2rem; }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        /* Mobile Safe Area Insets */
        body {
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
    </style>
</head>
<body dir="rtl">
    <div class="login-page">
        <div class="login-main">
            <div class="login-icon">
                <i class="fa-solid fa-notes-medical"></i>
            </div>

            <div class="login-header">
                <h1>مرحباً بك 👋</h1>
                <p>سجّل دخولك لنظام الملفات الطبية</p>
            </div>

            <!-- Error alert -->
            <div class="error-alert" id="loginError">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>البريد الإلكتروني أو كلمة المرور غير صحيحة</span>
            </div>

            <form class="login-form" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>البريد الإلكتروني</label>
                    <div class="input-icon-wrapper">
                        <input type="email" id="username" class="form-control" required placeholder="admin@gmail.com" autocomplete="email">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>كلمة المرور</label>
                    <div class="input-icon-wrapper">
                        <input type="password" id="password" class="form-control" required placeholder="••••••••" autocomplete="current-password">
                        <i class="fa-solid fa-lock" id="pwIcon"></i>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; font-size: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-muted);">
                        <input type="checkbox" id="remember" style="accent-color: var(--primary); width: 18px; height: 18px;">
                        <span>تذكرني</span>
                    </label>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span>تسجيل الدخول</span>
                </button>
            </form>

            <div class="login-footer">
                <p>نظام أرشفة المرضى &copy; 2026</p>
            </div>
        </div>
    </div>

    <script>
        // Use system language preference if we want, or keep simple RTL
        let lang = localStorage.getItem('lang') || 'ar';
        if (lang === 'en') {
            document.documentElement.dir = 'ltr';
            document.body.dir = 'ltr';
            document.documentElement.lang = 'en';

            document.querySelector('.login-header h1').textContent = 'Welcome Back 👋';
            document.querySelector('.login-header p').textContent = 'Login to the Medical System';
            document.querySelectorAll('label')[0].textContent = 'Email Address';
            document.querySelectorAll('label')[1].textContent = 'Password';
            document.querySelector('span:last-of-type').textContent = 'Login';
            document.querySelector('.error-alert span').textContent = 'Invalid email or password';
            document.querySelector('label span').textContent = 'Remember me';
        }

        async function handleLogin(e) {
            e.preventDefault();

            const email    = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const remember = document.getElementById('remember').checked;
            const errorEl  = document.getElementById('loginError');
            const btn      = document.getElementById('loginBtn');
            const rawApiBase = document.querySelector('meta[name="mobile-api-url"]')?.content || '';
            const apiBase = normalizeApiBase(rawApiBase);
            const loginUrl = "{{ route('login.post') }}";

            function normalizeApiBase(base) {
                const trimmed = (base || '').trim().replace(/\/+$/, '');
                if (!trimmed) return '';
                try {
                    const url = new URL(trimmed, window.location.origin);
                    if (url.host === window.location.host) {
                        return url.pathname.replace(/\/+$/, '') || '';
                    }
                    if (window.location.protocol === 'https:' && url.protocol === 'http:' && url.host === window.location.host) {
                        url.protocol = 'https:';
                        return url.toString();
                    }
                    return url.toString();
                } catch (err) {
                    return trimmed;
                }
            }

            // Show loading state
            errorEl.style.display = 'none';
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
            btn.disabled  = true;

            try {
                console.info('[mobile-login] submit', {
                    localUrl: loginUrl,
                    configuredApiBase: apiBase,
                    remoteLoginUrl: `${apiBase}/auth/login`,
                    online: navigator.onLine,
                    email,
                });

                const startedAt = performance.now();
                const res = await fetch(loginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ email, password, remember }),
                });

                const data = await res.json();
                console.info('[mobile-login] response', {
                    status: res.status,
                    ok: res.ok,
                    durationMs: Math.round(performance.now() - startedAt),
                    mode: data.mode,
                    success: data.success,
                    message: data.message,
                });

                if (data.success) {
                    if (data.access_token) {
                        localStorage.setItem('api_token', data.access_token);
                    }
                    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                    setTimeout(() => {
                        window.location.href = data.redirect || "{{ url('/') }}";
                    }, 500);
                } else {
                    const msg = data.message || (lang === 'ar' ? 'البريد الإلكتروني أو كلمة المرور غير صحيحة.' : 'Invalid email or password.');
                    errorEl.querySelector('span').textContent = msg;
                    errorEl.style.display = 'flex';

                    const form = document.querySelector('.login-form');
                    form.style.animation = 'none';
                    void form.offsetWidth;
                    form.style.animation = 'shake 0.4s ease';

                    btn.innerHTML = `<i class="fa-solid fa-right-to-bracket"></i> <span>${lang === 'ar' ? 'تسجيل الدخول' : 'Login'}</span>`;
                    btn.disabled  = false;
                }
            } catch (err) {
                console.error('[mobile-login] exception', {
                    message: err?.message || String(err),
                    stack: err?.stack,
                    online: navigator.onLine,
                    configuredApiBase: apiBase,
                });
                errorEl.querySelector('span').textContent = lang === 'ar' ? 'حدث خطأ في الاتصال. حاول مرة أخرى.' : 'Connection error. Try again.';
                errorEl.style.display = 'flex';
                btn.innerHTML = `<i class="fa-solid fa-right-to-bracket"></i> <span>${lang === 'ar' ? 'تسجيل الدخول' : 'Login'}</span>`;
                btn.disabled  = false;
            }
        }
    </script>
</body>
</html>
