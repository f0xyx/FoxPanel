// ============================================================
//  WebPanel Pro — Core Panel JS
// ============================================================

const CSRF = () => document.getElementById('csrfToken')?.value || '';
const $ = id => document.getElementById(id);

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, type = 'info', dur = 4000) {
    const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle', warning:'fa-exclamation-triangle' };
    const colors = { success:'#10b981', error:'#ef4444', info:'#6366f1', warning:'#f59e0b' };
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `<i class="fas ${icons[type]}" style="color:${colors[type]}"></i><span style="color:#e2e8f0;font-size:13px;flex:1">${msg}</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:#475569;cursor:pointer"><i class="fas fa-times"></i></button>`;
    $('toast-container').appendChild(el);
    setTimeout(() => el.remove(), dur);
}

// ── API helper ─────────────────────────────────────────────────
async function api(url, opts = {}) {
    try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, ...opts });
        if (res.status === 401) { location.href = 'cpanel.php?expired=1'; return null; }
        return await res.json();
    } catch (e) { toast('Network error: ' + e.message, 'error'); return null; }
}

async function apiGet(handler, params = {}) {
    const q = new URLSearchParams({ ...params });
    return api(`ajax/${handler}.php?${q}`);
}

async function apiPost(handler, data = {}) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    return api(`ajax/${handler}.php`, { method: 'POST', body: fd });
}

// ── Modal ──────────────────────────────────────────────────────
function openModal(title, bodyHtml, id = 'genericModal') {
    $('modalTitle').textContent = title;
    $('modalBody').innerHTML = bodyHtml;
    $(id)?.classList.add('open');
}
function closeModal(id = 'genericModal') { $(id)?.classList.remove('open'); }
window.closeModal = closeModal;

// ── Loading ────────────────────────────────────────────────────
function setContent(html) { $('content').innerHTML = html; }
function showLoader() { setContent('<div class="loader"><div class="spinner"></div></div>'); }

// ── Navigation ─────────────────────────────────────────────────
const sections = {};
function registerSection(name, fn) { sections[name] = fn; }

function navigate(section) {
    document.querySelectorAll('.nav-item').forEach(el => {
        el.classList.toggle('active', el.dataset.section === section);
    });
    const titles = {
        dashboard:'Dashboard', files:'File Manager', backup:'Backup Manager',
        database:'MySQL Databases', domain:'Domain Manager', subdomain:'Subdomains',
        dns:'DNS Zone Editor', ssl:'SSL / TLS', email:'Email Accounts',
        ftp:'FTP Accounts', installer:'App Installer', gsocket:'GSocket Tunnel',
        phpconfig:'PHP Configuration', terminal:'Terminal', cron:'Cron Jobs',
        process:'Process Manager', logs:'Log Viewer', security:'Security Tools',
        settings:'Settings'
    };
    $('topBarTitle').textContent = titles[section] || section;
    showLoader();
    if (sections[section]) sections[section]();
    else setContent(`<div class="card"><p style="color:var(--text-muted)">Section "${section}" coming soon.</p></div>`);
}

// Init nav
document.querySelectorAll('.nav-item[data-section]').forEach(el => {
    el.addEventListener('click', e => { e.preventDefault(); navigate(el.dataset.section); });
});

// Sidebar toggle
$('sidebarToggle').addEventListener('click', () => {
    $('sidebar').classList.toggle('collapsed');
});

// ── Context Menu close ─────────────────────────────────────────
document.addEventListener('click', () => $('ctxMenu').classList.add('hidden'));

// ── Uptime ticker ──────────────────────────────────────────────
async function refreshUptime() {
    const d = await apiGet('system', { action: 'info' });
    if (d?.data?.uptime) $('serverUptime').textContent = 'Up ' + d.data.uptime;
}

// ═══════════════════════════════════════════════════════════════
//  DASHBOARD
// ═══════════════════════════════════════════════════════════════
registerSection('dashboard', async () => {
    setContent('<div class="loader"><div class="spinner"></div></div>');
    const d = await apiGet('system', { action: 'info' });
    if (!d?.data) { setContent('<div class="card"><p class="text-red-400">Failed to load system info.</p></div>'); return; }
    const s = d.data;
    const cpuColor = s.cpu_usage > 80 ? '#ef4444' : s.cpu_usage > 50 ? '#f59e0b' : '#10b981';
    const ramColor = s.memory?.percent > 80 ? '#ef4444' : s.memory?.percent > 50 ? '#f59e0b' : '#10b981';
    const diskColor = s.disk?.percent > 80 ? '#ef4444' : s.disk?.percent > 50 ? '#f59e0b' : '#10b981';

    setContent(`
    <div class="page-title">Dashboard</div>
    <div class="page-subtitle">Welcome back, <strong>${s.hostname}</strong> — ${new Date().toLocaleString()}</div>

    <!-- Stat Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px">
      ${statCard('CPU Usage', s.cpu_usage + '%', 'fa-microchip', '#6366f1', s.cpu_usage, cpuColor, `${s.cpu_cores} cores — ${s.cpu_model?.substring(0,30)||'N/A'}`)}
      ${statCard('Memory', s.memory?.used_fmt||'N/A', 'fa-memory', '#0ea5e9', s.memory?.percent||0, ramColor, `${s.memory?.used_fmt} / ${s.memory?.total_fmt}`)}
      ${statCard('Disk Space', s.disk?.used_fmt||'N/A', 'fa-hdd', '#8b5cf6', s.disk?.percent||0, diskColor, `${s.disk?.used_fmt} / ${s.disk?.total_fmt}`)}
      ${statCard('Load Avg', (s.load_avg?.[0]||0)+'', 'fa-chart-line', '#10b981', Math.min((s.load_avg?.[0]||0)*10,100), '#10b981', `${(s.load_avg||[0,0,0]).join(' / ')}`)}
    </div>

    <!-- Info grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:24px">
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:rgba(99,102,241,0.1);color:#818cf8"><i class="fas fa-server"></i></div>
          <div><div style="font-weight:700">Server Information</div></div>
        </div>
        ${infoRows([
          ['Hostname', s.hostname],['IP Address', s.ip_address],
          ['OS', s.os?.substring(0,50)],['Uptime', s.uptime],
          ['Server Software', (s.server_software||'').substring(0,40)],
        ])}
      </div>
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:rgba(139,92,246,0.1);color:#a78bfa"><i class="fab fa-php"></i></div>
          <div><div style="font-weight:700">PHP Environment</div></div>
        </div>
        ${infoRows([
          ['PHP Version', s.php_version],['SAPI', s.sapi||'N/A'],
          ['Memory Limit', s.memory_limit],['Max Upload', s.max_upload],
          ['Max Execution', s.max_execution + 's'],['exec()', s.exec_available?'✅ Available':'❌ Disabled'],
        ])}
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <div style="font-weight:700;margin-bottom:16px"><i class="fas fa-bolt" style="color:#f59e0b"></i> Quick Actions</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        ${quickBtn('File Manager','fa-folder-open','files','#6366f1')}
        ${quickBtn('Terminal','fa-terminal','terminal','#10b981')}
        ${quickBtn('Databases','fa-database','database','#0ea5e9')}
        ${quickBtn('Cron Jobs','fa-clock','cron','#8b5cf6')}
        ${quickBtn('Backup','fa-archive','backup','#f59e0b')}
        ${quickBtn('App Installer','fa-download','installer','#ec4899')}
        ${quickBtn('GSocket','fa-plug','gsocket','#14b8a6')}
        ${quickBtn('Security','fa-shield-alt','security','#ef4444')}
      </div>
    </div>
    `);
});

function statCard(title, value, icon, color, pct, barColor, sub) {
    return `<div class="card stat-card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
        <div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">${title}</div>
          <div style="font-size:26px;font-weight:800;color:var(--text-primary)">${value}</div>
        </div>
        <div style="width:42px;height:42px;border-radius:12px;background:${color}18;display:flex;align-items:center;justify-content:center;color:${color}">
          <i class="fas ${icon}"></i>
        </div>
      </div>
      <div class="prog-bar"><div class="prog-fill" style="width:${pct}%;background:${barColor}"></div></div>
      <div style="font-size:11.5px;color:var(--text-muted);margin-top:8px">${sub}</div>
    </div>`;
}

function infoRows(rows) {
    return rows.map(([k,v]) => `
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04);font-size:13px">
        <span style="color:var(--text-muted)">${k}</span>
        <span style="color:var(--text-primary);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${v||''}">${v||'—'}</span>
      </div>`).join('');
}

function quickBtn(label, icon, section, color) {
    return `<button onclick="navigate('${section}')" class="btn btn-secondary" style="gap:8px;border-color:rgba(255,255,255,0.06)">
      <i class="fas ${icon}" style="color:${color}"></i>${label}
    </button>`;
}
