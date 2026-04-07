<?php
// ============================================================
//  WebPanel Pro — Login Page
// ============================================================
require_once __DIR__ . '/config/auth.php';

$error   = '';
$expired = !empty($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attemptLogin($username, $password)) {
        header('Location: panel.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
        sleep(1); // brute-force delay
    }
}

if (isLoggedIn()) {
    header('Location: panel.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= PANEL_NAME ?></title>
    <meta name="description" content="Login to <?= PANEL_NAME ?> — Web Hosting Control Panel">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background: #0a0e1a;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
        }
        .bg-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(99,102,241,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99,102,241,0.06) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.35;
            animation: float 8s ease-in-out infinite;
        }
        .orb-1 { width: 600px; height: 600px; background: radial-gradient(circle, #4f46e5, transparent); top: -200px; left: -200px; }
        .orb-2 { width: 400px; height: 400px; background: radial-gradient(circle, #0ea5e9, transparent); bottom: -100px; right: -100px; animation-delay: -4s; }
        .orb-3 { width: 300px; height: 300px; background: radial-gradient(circle, #8b5cf6, transparent); top: 40%; right: 20%; animation-delay: -2s; }
        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.05); }
        }
        .glass-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 32px 64px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.08);
        }
        .input-field {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            color: #e2e8f0;
            transition: all 0.3s ease;
        }
        .input-field:focus {
            background: rgba(255,255,255,0.07);
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
            outline: none;
        }
        .input-field::placeholder { color: rgba(148,163,184,0.5); }
        .btn-login {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(99,102,241,0.4);
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(99,102,241,0.5); }
        .btn-login:active { transform: translateY(0); }
        .logo-icon {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            box-shadow: 0 8px 32px rgba(99,102,241,0.4);
        }
        .fade-in { animation: fadeIn 0.6s ease forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .error-shake { animation: shake 0.4s ease; }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }
        .particle {
            position: absolute;
            width: 2px; height: 2px;
            background: rgba(99,102,241,0.6);
            border-radius: 50%;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-grid"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="relative z-10 w-full max-w-md fade-in">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="logo-icon w-20 h-20 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-server text-white text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-white tracking-tight"><?= PANEL_NAME ?></h1>
            <p class="text-slate-400 mt-1 text-sm">Web Hosting Control Panel</p>
        </div>

        <!-- Card -->
        <div class="glass-card rounded-2xl p-8 <?= $error ? 'error-shake' : '' ?>">
            <?php if ($expired): ?>
            <div class="mb-5 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center gap-3">
                <i class="fas fa-clock text-amber-400"></i>
                <p class="text-amber-300 text-sm">Your session has expired. Please login again.</p>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="mb-5 p-3 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-red-400"></i>
                <p class="text-red-300 text-sm"><?= htmlspecialchars($error) ?></p>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Username</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500">
                                <i class="fas fa-user text-sm"></i>
                            </span>
                            <input type="text" name="username" id="username"
                                class="input-field w-full rounded-xl py-3 pl-11 pr-4 text-sm"
                                placeholder="Enter username"
                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                autocomplete="username" required autofocus>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" name="password" id="password"
                                class="input-field w-full rounded-xl py-3 pl-11 pr-12 text-sm"
                                placeholder="Enter password"
                                autocomplete="current-password" required>
                            <button type="button" id="togglePwd"
                                class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-slate-300 transition-colors">
                                <i class="fas fa-eye text-sm" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login w-full rounded-xl py-3.5 text-sm font-semibold text-white flex items-center justify-center gap-2">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In to Panel
                    </button>
                </div>
            </form>

            <div class="mt-6 pt-6 border-t border-white/5 flex items-center justify-between text-xs text-slate-500">
                <span>v<?= PANEL_VERSION ?></span>
                <span class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    System Online
                </span>
            </div>
        </div>

        <!-- Server info pills -->
        <div class="flex items-center justify-center gap-3 mt-6 flex-wrap">
            <?php
            $pills = [
                ['icon' => 'fa-php', 'label' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION],
                ['icon' => 'fa-server', 'label' => php_uname('n')],
                ['icon' => 'fa-linux', 'label' => php_uname('s')],
            ];
            foreach ($pills as $pill): ?>
            <div class="flex items-center gap-1.5 glass-card px-3 py-1.5 rounded-full text-xs text-slate-400">
                <i class="fab <?= $pill['icon'] ?> text-slate-500"></i>
                <?= htmlspecialchars($pill['label']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePwd').addEventListener('click', function() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.className = 'fas fa-eye-slash text-sm';
            } else {
                pwd.type = 'password';
                icon.className = 'fas fa-eye text-sm';
            }
        });

        // Loading state on submit
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type=submit]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
            btn.disabled = true;
        });

        // Particles
        function createParticle() {
            const p = document.createElement('div');
            p.className = 'particle';
            p.style.left = Math.random() * 100 + 'vw';
            p.style.top = Math.random() * 100 + 'vh';
            p.style.opacity = Math.random() * 0.5 + 0.1;
            p.style.transform = `scale(${Math.random() * 3 + 1})`;
            document.body.appendChild(p);
            setTimeout(() => p.remove(), 5000);
        }
        setInterval(createParticle, 300);
    </script>
</body>
</html>
