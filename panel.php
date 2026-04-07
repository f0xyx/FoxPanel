<?php
// ============================================================
//  WebPanel Pro — Main Panel Page
// ============================================================
require_once __DIR__ . '/config/auth.php';
requireLogin();
$csrf = getCsrfToken();
$username = $_SESSION['username'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= PANEL_NAME ?> — Control Panel</title>
    <meta name="description" content="<?= PANEL_NAME ?> Web Hosting Control Panel">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Code Editor -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/monokai.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/selection/active-line.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/brace-fold.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/xml-fold.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/comment/comment.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.css">

    <style>
        * { font-family: 'Inter', sans-serif; }

        :root {
            --bg-main: #0a0e1a;
            --bg-sidebar: #0d1225;
            --bg-card: rgba(255,255,255,0.03);
            --bg-card-hover: rgba(255,255,255,0.055);
            --border: rgba(255,255,255,0.07);
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #475569;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
        }

        html, body { height: 100%; margin: 0; overflow: hidden; background: var(--bg-main); color: var(--text-primary); }

        /* ── Scrollbar ────────────────────────────────────── */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.3); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.6); }

        /* ── Layout ───────────────────────────────────────── */
        #layout { display: flex; height: 100vh; }
        #sidebar {
            width: 260px; min-width: 260px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            overflow: hidden;
            transition: width 0.3s ease, min-width 0.3s ease;
        }
        #sidebar.collapsed { width: 68px; min-width: 68px; }
        #sidebar.collapsed .nav-label, #sidebar.collapsed .nav-section-title, #sidebar.collapsed .sidebar-brand-text { display: none; }
        #sidebar.collapsed .nav-item { justify-content: center; }
        #sidebar.collapsed #sidebarLogo { justify-content: center; padding: 0 16px; }
        #sidebar.collapsed .nav-badge { display: none; }

        #main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        /* ── Top Bar ──────────────────────────────────────── */
        #topbar {
            height: 60px; min-height: 60px;
            background: rgba(13,18,37,0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            padding: 0 24px; gap: 16px;
        }

        /* ── Content Area ─────────────────────────────────── */
        #content {
            flex: 1; overflow-y: auto; overflow-x: hidden;
            padding: 24px;
            background: var(--bg-main);
        }

        /* ── Sidebar Logo ─────────────────────────────────── */
        #sidebarLogo {
            height: 64px; min-height: 64px;
            display: flex; align-items: center; gap: 12px;
            padding: 0 16px 0 20px;
            border-bottom: 1px solid var(--border);
        }
        .logo-icon-sm {
            width: 36px; height: 36px; min-width: 36px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(99,102,241,0.4);
        }

        /* ── Nav Items ────────────────────────────────────── */
        #navList { overflow-y: auto; flex: 1; padding: 12px 10px; }
        .nav-section-title {
            font-size: 10px; font-weight: 600;
            color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em;
            padding: 12px 10px 4px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 10px;
            color: var(--text-secondary);
            cursor: pointer; text-decoration: none;
            font-size: 13.5px; font-weight: 500;
            transition: all 0.2s ease;
            position: relative; margin-bottom: 2px;
        }
        .nav-item:hover { background: var(--bg-card-hover); color: var(--text-primary); }
        .nav-item.active {
            background: rgba(99,102,241,0.12);
            color: #a5b4fc;
        }
        .nav-item.active::before {
            content: '';
            position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; background: var(--accent);
            border-radius: 0 3px 3px 0; left: -10px;
        }
        .nav-icon { width: 20px; text-align: center; font-size: 15px; }
        .nav-badge {
            margin-left: auto; font-size: 10px; font-weight: 600;
            padding: 1px 6px; border-radius: 99px;
        }

        /* ── Cards ────────────────────────────────────────── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.2s ease;
        }
        .card:hover { border-color: rgba(99,102,241,0.2); }
        .card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .card-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }

        /* ── Stat Cards ───────────────────────────────────── */
        .stat-card { position: relative; overflow: hidden; }
        .stat-card::after {
            content: '';
            position: absolute; bottom: -20px; right: -20px;
            width: 80px; height: 80px; border-radius: 50%;
            opacity: 0.05;
        }
        .stat-card.blue::after { background: #6366f1; }
        .stat-card.green::after { background: #10b981; }
        .stat-card.amber::after { background: #f59e0b; }
        .stat-card.sky::after { background: #0ea5e9; }

        /* ── Progress bar ─────────────────────────────────── */
        .prog-bar {
            height: 6px; background: rgba(255,255,255,0.06);
            border-radius: 99px; overflow: hidden;
        }
        .prog-fill { height: 100%; border-radius: 99px; transition: width 1s ease; }

        /* ── Buttons ──────────────────────────────────────── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 9px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s ease; border: none; }
        .btn-primary { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; box-shadow: 0 4px 12px rgba(99,102,241,0.3); }
        .btn-primary:hover { box-shadow: 0 6px 20px rgba(99,102,241,0.5); transform: translateY(-1px); }
        .btn-secondary { background: rgba(255,255,255,0.06); color: var(--text-secondary); border: 1px solid var(--border); }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); color: var(--text-primary); }
        .btn-danger { background: rgba(239,68,68,0.1); color: #fca5a5; border: 1px solid rgba(239,68,68,0.2); }
        .btn-danger:hover { background: rgba(239,68,68,0.2); }
        .btn-success { background: rgba(16,185,129,0.1); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.2); }
        .btn-success:hover { background: rgba(16,185,129,0.2); }
        .btn-sm { padding: 5px 11px; font-size: 12px; }
        .btn-xs { padding: 3px 8px; font-size: 11px; }

        /* ── Input ────────────────────────────────────────── */
        .form-input, .form-select, .form-textarea {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 9px; padding: 9px 13px;
            font-size: 13px; width: 100%;
            transition: all 0.2s ease;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
            outline: none;
            background: rgba(255,255,255,0.06);
        }
        .form-input::placeholder, .form-textarea::placeholder { color: var(--text-muted); }
        .form-select { appearance: none; cursor: pointer; }
        .form-label { display: block; font-size: 12.5px; font-weight: 500; color: var(--text-secondary); margin-bottom: 6px; }

        /* ── Table ────────────────────────────────────────── */
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th { padding: 10px 14px; text-align: left; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        .data-table td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.04); color: var(--text-secondary); vertical-align: middle; }
        .data-table tr:hover td { background: rgba(255,255,255,0.02); color: var(--text-primary); }
        .data-table tr:last-child td { border-bottom: none; }

        /* ── Badges ────────────────────────────────────────── */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
        .badge-green { background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.2); }
        .badge-red { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .badge-blue { background: rgba(99,102,241,0.12); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.2); }
        .badge-amber { background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .badge-gray { background: rgba(148,163,184,0.08); color: #94a3b8; border: 1px solid rgba(148,163,184,0.15); }

        /* ── Terminal ──────────────────────────────────────── */
        #terminal-output {
            background: #0a0a0f;
            color: #c8ff9e;
            font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace;
            font-size: 13px;
            line-height: 1.6;
            padding: 16px;
            border-radius: 12px;
            height: 420px;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.06);
        }
        #terminal-input {
            background: transparent;
            border: none; outline: none;
            color: #c8ff9e;
            font-family: inherit; font-size: 13px;
            flex: 1;
            caret-color: #c8ff9e;
        }
        .terminal-line-err { color: #f87171; }
        .terminal-line-cmd { color: #818cf8; }
        .terminal-line-info { color: #60a5fa; }
        .terminal-prompt { color: #34d399; font-weight: 600; }

        /* ── File Manager ──────────────────────────────────── */
        .file-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; border-radius: 9px;
            cursor: pointer; transition: all 0.15s ease;
            user-select: none;
        }
        .file-item:hover { background: var(--bg-card-hover); }
        .file-item.selected { background: rgba(99,102,241,0.1); }
        .file-icon { font-size: 18px; width: 24px; text-align: center; }

        /* ── Modal ─────────────────────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
            opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
        }
        .modal-overlay.open { opacity: 1; pointer-events: all; }
        .modal {
            background: #111827;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            width: 100%; max-width: 560px;
            transform: scale(0.95) translateY(10px);
            transition: transform 0.2s ease;
            max-height: 90vh; overflow-y: auto;
        }
        .modal-overlay.open .modal { transform: scale(1) translateY(0); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .modal-title { font-size: 17px; font-weight: 700; }
        .modal-close { width: 30px; height: 30px; border-radius: 8px; background: rgba(255,255,255,0.05); border: none; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .modal-close:hover { background: rgba(239,68,68,0.1); color: #f87171; }

        /* ── Toast ─────────────────────────────────────────── */
        #toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
        .toast {
            background: #1e2433; border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 16px;
            display: flex; align-items: center; gap: 10px;
            min-width: 280px; max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        .toast-success { border-left: 3px solid #10b981; }
        .toast-error { border-left: 3px solid #ef4444; }
        .toast-info { border-left: 3px solid #6366f1; }
        .toast-warning { border-left: 3px solid #f59e0b; }

        /* ── CodeMirror (VS Code style) ──────────────────── */
        .CodeMirror {
            height: 100% !important;
            font-size: 13px !important;
            border-radius: 0 0 10px 10px;
            font-family: 'Fira Mono', 'Cascadia Code', 'Consolas', monospace !important;
            line-height: 1.65 !important;
        }
        .CodeMirror-scroll { min-height: 300px; }
        
        /* Full editor modal */
        .modal-editor .modal { 
            width: 95vw; max-width: 1200px; 
            height: 90vh; max-height: 900px;
            display: flex; flex-direction: column;
        }
        .modal-editor .modal-header { flex-shrink: 0; }
        #editorWrap { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0; }
        #editorWrap .CodeMirror { flex: 1; height: 100% !important; border-radius: 0; border: none; border-top: 1px solid var(--border); }
        
        /* Editor tab bar */
        .editor-tabbar {
            background: #0d1117; display: flex; align-items: center;
            padding: 0 12px; gap: 2px; border-bottom: 1px solid var(--border); flex-shrink: 0;
        }
        .editor-tab {
            padding: 7px 14px; font-size: 12px; color: var(--text-muted);
            border-bottom: 2px solid transparent; cursor: pointer;
        }
        .editor-tab.active { color: #e2e8f0; border-bottom-color: #6366f1; }
        .editor-lang-badge {
            margin-left: auto; font-size: 11px; color: var(--text-muted);
            padding: 2px 8px; background: rgba(255,255,255,0.05); border-radius: 4px;
        }


        /* ── Loading ───────────────────────────────────────── */
        .loader { display: flex; justify-content: center; align-items: center; padding: 60px; }
        .spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,0.2); border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Section titles ────────────────────────────────── */
        .page-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .page-subtitle { font-size: 13.5px; color: var(--text-muted); margin-bottom: 24px; }

        /* ── Breadcrumb ────────────────────────────────────── */
        .breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted); text-decoration: none; }
        .breadcrumb a:hover { color: var(--accent); }

        /* ── Context menu ──────────────────────────────────── */
        .ctx-menu {
            position: fixed; z-index: 999;
            background: #1a2035; border: 1px solid var(--border);
            border-radius: 12px; padding: 4px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            min-width: 180px;
        }
        .ctx-item { padding: 8px 14px; border-radius: 8px; font-size: 13px; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.15s; }
        .ctx-item:hover { background: rgba(99,102,241,0.1); color: var(--text-primary); }
        .ctx-divider { height: 1px; background: var(--border); margin: 4px 0; }
        .ctx-danger { color: #f87171; }
        .ctx-danger:hover { background: rgba(239,68,68,0.1); color: #f87171; }

        /* ── Tooltip ───────────────────────────────────────── */
        [data-tooltip] { position: relative; }
        [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
            background: #1e2433; border: 1px solid var(--border);
            color: var(--text-primary); font-size: 11.5px;
            padding: 4px 10px; border-radius: 6px; white-space: nowrap;
            opacity: 0; pointer-events: none; transition: opacity 0.2s;
            z-index: 99;
        }
        [data-tooltip]:hover::after { opacity: 1; }

        /* ── Collapsible sidebar tooltip when collapsed ─────── */
        #sidebar.collapsed .nav-item [data-tooltip]::after { display: none; }

        /* ── Responsive ────────────────────────────────────── */
        @media (max-width: 768px) {
            #sidebar { position: fixed; left: -260px; z-index: 50; height: 100%; transition: left 0.3s; }
            #sidebar.mobile-open { left: 0; }
            #sidebar.collapsed { width: 260px; min-width: 260px; }
            #sidebar.collapsed .nav-label, #sidebar.collapsed .nav-section-title, #sidebar.collapsed .sidebar-brand-text { display: block; }
            #sidebar.collapsed .nav-item { justify-content: flex-start; }
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div id="toast-container"></div>

<!-- Modal: Generic -->
<div class="modal-overlay" id="genericModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">Modal</span>
            <button class="modal-close" onclick="closeModal('genericModal')"><i class="fas fa-times"></i></button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<!-- Modal: Full-screen Code Editor -->
<div class="modal-overlay modal-editor" id="editorModal">
    <div class="modal" style="padding:0;overflow:hidden">
        <div class="modal-header" style="padding:12px 16px;border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:10px;flex:1">
                <i class="fas fa-code" style="color:#6366f1"></i>
                <span class="modal-title" id="editorTitle" style="font-family:monospace;font-size:14px">editor</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <span id="editorSaveStatus" style="font-size:11px;color:var(--text-muted)"></span>
                <button class="btn btn-success btn-sm" onclick="saveCurrentFile()">
                    <i class="fas fa-save"></i> Save <span style="font-size:10px;opacity:0.7">(Ctrl+S)</span>
                </button>
                <button class="modal-close" onclick="closeModal('editorModal')"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="editor-tabbar">
            <div class="editor-tab active" id="editorTabName">file</div>
            <span class="editor-lang-badge" id="editorLangBadge">text</span>
        </div>
        <div id="editorWrap">
            <textarea id="editorArea" style="display:none"></textarea>
        </div>
    </div>
</div>

<!-- Context Menu -->
<div class="ctx-menu hidden" id="ctxMenu"></div>

<!-- Layout -->
<div id="layout">

    <!-- ═══════════════ SIDEBAR ═══════════════ -->
    <nav id="sidebar">
        <div id="sidebarLogo">
            <div class="logo-icon-sm">
                <i class="fas fa-server text-white text-sm"></i>
            </div>
            <div class="sidebar-brand-text">
                <div class="font-bold text-white text-sm"><?= PANEL_NAME ?></div>
                <div class="text-[10px] text-slate-500">v<?= PANEL_VERSION ?></div>
            </div>
        </div>

        <div id="navList">
            <!-- Main -->
            <div class="nav-section-title">Main</div>
            <a class="nav-item active" data-section="dashboard" href="#">
                <i class="nav-icon fas fa-th-large"></i>
                <span class="nav-label">Dashboard</span>
            </a>

            <!-- Files -->
            <div class="nav-section-title">Files</div>
            <a class="nav-item" data-section="files" href="#">
                <i class="nav-icon fas fa-folder-open"></i>
                <span class="nav-label">File Manager</span>
            </a>
            <a class="nav-item" data-section="backup" href="#">
                <i class="nav-icon fas fa-archive"></i>
                <span class="nav-label">Backup Manager</span>
            </a>

            <!-- Databases -->
            <div class="nav-section-title">Databases</div>
            <a class="nav-item" data-section="database" href="#">
                <i class="nav-icon fas fa-database"></i>
                <span class="nav-label">MySQL Databases</span>
            </a>

            <!-- Email -->
            <div class="nav-section-title">Email</div>
            <a class="nav-item" data-section="email" href="#">
                <i class="nav-icon fas fa-envelope"></i>
                <span class="nav-label">Email Accounts</span>
            </a>
            <a class="nav-item" data-section="ftp" href="#">
                <i class="nav-icon fas fa-exchange-alt"></i>
                <span class="nav-label">FTP Accounts</span>
            </a>

            <!-- Software -->
            <div class="nav-section-title">Software</div>
            <a class="nav-item" data-section="installer" href="#">
                <i class="nav-icon fas fa-download"></i>
                <span class="nav-label">App Installer</span>
                <span class="nav-badge badge badge-blue">NEW</span>
            </a>
            <a class="nav-item" data-section="gsocket" href="#">
                <i class="nav-icon fas fa-plug"></i>
                <span class="nav-label">GSocket</span>
            </a>
            <a class="nav-item" data-section="phpconfig" href="#">
                <i class="nav-icon fab fa-php"></i>
                <span class="nav-label">PHP Config</span>
            </a>

            <!-- Advanced -->
            <div class="nav-section-title">Advanced</div>
            <a class="nav-item" data-section="terminal" href="#">
                <i class="nav-icon fas fa-terminal"></i>
                <span class="nav-label">Terminal</span>
            </a>
            <a class="nav-item" data-section="cron" href="#">
                <i class="nav-icon fas fa-clock"></i>
                <span class="nav-label">Cron Jobs</span>
            </a>
            <a class="nav-item" data-section="process" href="#">
                <i class="nav-icon fas fa-microchip"></i>
                <span class="nav-label">Process Manager</span>
            </a>
            <a class="nav-item" data-section="logs" href="#">
                <i class="nav-icon fas fa-file-alt"></i>
                <span class="nav-label">Log Viewer</span>
            </a>

            <!-- Security -->
            <div class="nav-section-title">Security</div>
            <a class="nav-item" data-section="security" href="#">
                <i class="nav-icon fas fa-lock"></i>
                <span class="nav-label">Security</span>
            </a>

            <!-- Settings -->
            <div class="nav-section-title">Account</div>
            <a class="nav-item" data-section="settings" href="#">
                <i class="nav-icon fas fa-cog"></i>
                <span class="nav-label">Settings</span>
            </a>
            <a class="nav-item" href="logout.php">
                <i class="nav-icon fas fa-sign-out-alt"></i>
                <span class="nav-label">Logout</span>
            </a>
        </div>
    </nav>

    <!-- ═══════════════ MAIN ═══════════════ -->
    <div id="main">

        <!-- Top bar -->
        <div id="topbar">
            <button id="sidebarToggle" class="w-9 h-9 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 flex items-center justify-center transition-all text-slate-400 hover:text-white">
                <i class="fas fa-bars text-sm"></i>
            </button>

            <!-- Breadcrumb / Page title -->
            <div id="topBarTitle" class="text-sm font-semibold text-white flex-1">Dashboard</div>

            <div class="flex items-center gap-3 ml-auto">
                <!-- Server status pill -->
                <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400 bg-white/5 px-3 py-1.5 rounded-lg border border-white/5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span id="serverUptime">Loading...</span>
                </div>

                <!-- Notifications bell -->
                <button class="w-9 h-9 rounded-lg bg-white/5 hover:bg-white/10 border border-white/5 flex items-center justify-center text-slate-400 hover:text-white transition-all relative">
                    <i class="fas fa-bell text-sm"></i>
                    <span class="absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full bg-indigo-400"></span>
                </button>

                <!-- User avatar -->
                <div class="flex items-center gap-2 pl-2">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <div class="hidden sm:block">
                        <div class="text-sm font-semibold text-white leading-none"><?= htmlspecialchars($username) ?></div>
                        <div class="text-[11px] text-slate-500 mt-0.5">Administrator</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div id="content">
            <div class="loader"><div class="spinner"></div></div>
        </div>
    </div>
</div>

<!-- Hidden CSRF -->
<input type="hidden" id="csrfToken" value="<?= $csrf ?>">

<script src="assets/js/panel.js"></script>
<script src="assets/js/sections1.js"></script>
<script src="assets/js/sections2.js"></script>
<script src="assets/js/sections3.js"></script>
</body>
</html>
