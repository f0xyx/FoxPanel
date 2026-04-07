// ============================================================
//  WebPanel Pro — Database, Cron, Process, Logs, Domain sections
// ============================================================

// ═══════════════════════════════════════════════════════════════
//  DATABASE MANAGER
// ═══════════════════════════════════════════════════════════════
registerSection('database', async () => {
    // Always auto-discover first — if only 1 wp-config → auto-login silently
    const discoverRes = await apiGet('database', { action: 'discover' });

    // Now load DB list (uses session creds if already set)
    const r = await apiGet('database', { action: 'list' });
    const dbs = r?.databases || [];
    const hasError = r?.error;

    const activeLabel = discoverRes?.current_active
        ? `<span class="badge badge-blue" style="font-size:11px"><i class="fas fa-plug"></i> ${escHtml(discoverRes.current_active)}</span>`
        : `<span class="badge badge-gray" style="font-size:11px">No active connection</span>`;

    const wpCount = discoverRes?.found?.length || 0;
    const singleAutoMsg = discoverRes?.auto_connected
        ? `<div class="card" style="border-color:rgba(16,185,129,0.3);margin-bottom:16px;padding:12px 16px;display:flex;align-items:center;gap:10px">
               <i class="fas fa-check-circle" style="color:#10b981;font-size:18px"></i>
               <div><div style="font-weight:600;font-size:13px">Auto-connected to WordPress database</div>
               <div style="font-size:12px;color:var(--text-muted)">${escHtml(discoverRes.found[0]?.file || '')}</div></div>
           </div>` : '';

    setContent(`
    <div class="page-title"><i class="fas fa-database" style="color:#0ea5e9"></i> MySQL Databases</div>
    <div class="page-subtitle">Manage MySQL / MariaDB — discovered from wp-config.php</div>
    ${singleAutoMsg}
    ${hasError ? `<div class="card" style="border-color:rgba(239,68,68,0.3);margin-bottom:16px;padding:12px 16px">
        <i class="fas fa-exclamation-circle" style="color:#ef4444"></i>
        <span style="color:#f87171;margin-left:8px">${escHtml(r.error)}</span>
    </div>` : ''}
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
        ${activeLabel}
        <button class="btn btn-primary btn-sm" onclick="discoverDb()">
            <i class="fas fa-search"></i> ${wpCount > 0 ? `Found ${wpCount} wp-config` + (wpCount > 1 ? 's — Pick one' : ' — Re-scan') : 'Auto-Discover DB'}
        </button>
        ${discoverRes?.current_active ? `<button class="btn btn-secondary btn-sm" onclick="clearDb()"><i class="fas fa-times-circle"></i> Disconnect</button>` : ''}
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-plus-circle" style="color:#10b981"></i> Create Database</div>
        <input id="newDbName" class="form-input" placeholder="database_name" style="margin-bottom:8px">
        <select id="newDbCharset" class="form-select" style="margin-bottom:12px">
          <option value="utf8mb4">utf8mb4 (recommended)</option>
          <option value="utf8">utf8</option>
          <option value="latin1">latin1</option>
        </select>
        <button class="btn btn-primary" onclick="createDb()" style="width:100%"><i class="fas fa-plus"></i> Create Database</button>
      </div>
      <div class="card">
        <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-user-plus" style="color:#8b5cf6"></i> Create User</div>
        <input id="newDbUser" class="form-input" placeholder="username" style="margin-bottom:8px">
        <input type="password" id="newDbPass" class="form-input" placeholder="password" style="margin-bottom:8px">
        <input id="newDbHost" class="form-input" placeholder="host (default: localhost)" style="margin-bottom:12px">
        <button class="btn btn-primary" onclick="createDbUser()" style="width:100%"><i class="fas fa-plus"></i> Create User</button>
      </div>
    </div>

    <div class="card" style="padding:0">
      <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.05);font-weight:700">
        Databases (${dbs.filter(d => !d.system).length} user / ${dbs.length} total)
      </div>
      <table class="data-table">
        <thead><tr><th>Database</th><th>Tables</th><th>Size</th><th>Type</th><th>Actions</th></tr></thead>
        <tbody>
        ${dbs.map(db => `<tr>
          <td><i class="fas fa-database" style="color:#0ea5e9;margin-right:8px"></i>${escHtml(db.name)}</td>
          <td>${db.tables || 0}</td>
          <td>${db.size_fmt || '—'}</td>
          <td>${db.system ? '<span class="badge badge-gray">System</span>' : '<span class="badge badge-blue">User</span>'}</td>
          <td>
            ${!db.system ? `
            <button class="btn btn-secondary btn-xs" onclick="grantDb('${escHtml(db.name)}')">Grant User</button>
            <button class="btn btn-danger btn-xs" onclick="dropDb('${escHtml(db.name)}')">Drop</button>` : '—'}
          </td>
        </tr>`).join('') || `<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">
            ${hasError ? 'Connect a database to view tables' : 'No databases found'}
        </td></tr>`}
        </tbody>
      </table>
    </div>`);

    window.createDb = async () => {
        const name = $('newDbName')?.value.trim();
        const charset = $('newDbCharset')?.value;
        if (!name) { toast('Name required', 'warning'); return; }
        const r = await apiPost('database', { action: 'create', name, charset });
        if (r?.success) { toast(r.message, 'success'); navigate('database'); }
        else toast(r?.error || 'Failed', 'error');
    };
    window.createDbUser = async () => {
        const user = $('newDbUser')?.value.trim();
        const pass = $('newDbPass')?.value;
        const host = $('newDbHost')?.value.trim() || 'localhost';
        if (!user || !pass) { toast('User and password required', 'warning'); return; }
        const r = await apiPost('database', { action: 'create_user', user, password: pass, host });
        if (r?.success) { toast(r.message, 'success'); }
        else toast(r?.error || 'Failed', 'error');
    };
    window.dropDb = async (name) => {
        if (!confirm(`Drop database "${name}"? This CANNOT be undone!`)) return;
        const r = await apiPost('database', { action: 'drop', name });
        if (r?.success) { toast(r.message, 'success'); navigate('database'); }
        else toast(r?.error || 'Failed', 'error');
    };
    window.grantDb = (db) => {
        openModal(`Grant User — ${db}`, `
          <div style="display:grid;gap:12px">
            <div><label class="form-label">MySQL User</label><input id="grantUser" class="form-input" placeholder="username@localhost"></div>
            <div><label class="form-label">Privileges</label>
              <select id="grantPrivs" class="form-select">
                <option value="ALL PRIVILEGES">ALL PRIVILEGES</option>
                <option value="SELECT,INSERT,UPDATE,DELETE">SELECT, INSERT, UPDATE, DELETE</option>
                <option value="SELECT">SELECT only</option>
              </select>
            </div>
          </div>
          <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="doGrant('${db}')">Grant</button>
          </div>`);
    };
    window.doGrant = async (db) => {
        const userHost = ($('grantUser')?.value || '').split('@');
        const user = userHost[0]; const host = userHost[1] || 'localhost';
        const privs = $('grantPrivs')?.value;
        const r = await apiPost('database', { action: 'grant', user, host, database: db, privileges: privs });
        if (r?.success) { toast(r.message, 'success'); closeModal(); }
        else toast(r?.error || 'Failed', 'error');
    };

    // ── Auto-Discover Modal ─────────────────────────────────────────
    window.discoverDb = async () => {
        openModal('Scanning for wp-config.php...', `
            <div class="loader"><div class="spinner"></div></div>
            <div style="text-align:center;margin-top:10px;color:var(--text-muted);font-size:12px">
                Scanning common server paths for WordPress installations...
            </div>`);

        const r = await apiGet('database', { action: 'discover' });

        if (!r?.success) {
            $('modalBody').innerHTML = `<div style="color:#f87171;padding:20px">Error: ${escHtml(r?.error || 'Unknown error')}</div>`;
            return;
        }

        if (r.found.length === 0) {
            $('modalBody').innerHTML = `
            <div style="text-align:center;padding:30px">
                <i class="fas fa-search" style="font-size:36px;color:var(--text-muted);margin-bottom:12px"></i>
                <div style="font-size:14px;font-weight:600;margin-bottom:6px">No wp-config.php Found</div>
                <div style="font-size:12px;color:var(--text-muted)">Scanned ${r.scanned?.toLocaleString()} entries across common server paths.</div>
            </div>`;
            return;
        }

        if (r.auto_connected && r.found.length === 1) {
            closeModal();
            toast('Auto-connected to WordPress database!', 'success');
            navigate('database');
            return;
        }

        // Multiple found → let user pick
        $('modalTitle').textContent = `Found ${r.found.length} WordPress Installation${r.found.length > 1 ? 's' : ''}`;
        let html = `<div style="color:var(--text-muted);font-size:12px;margin-bottom:12px">
            Scanned ${r.scanned?.toLocaleString()} entries. Select which database to connect:
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;max-height:420px;overflow-y:auto;padding-right:4px">`;

        r.found.forEach((db) => {
            const isActive = r.current_active && r.current_active === (db.user + '@' + db.host);
            html += `
            <div class="card" style="padding:14px 16px;display:flex;align-items:center;gap:12px;${isActive ? 'border-color:rgba(99,102,241,0.5)' : ''}">
                <div style="width:36px;height:36px;border-radius:8px;background:rgba(16,185,129,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fab fa-wordpress" style="color:#10b981;font-size:18px"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:13px;margin-bottom:2px">
                        ${escHtml(db.name)}
                        ${isActive ? '<span class="badge badge-blue" style="font-size:10px;margin-left:6px">Active</span>' : ''}
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(db.file)}</div>
                    <div style="font-size:11px;margin-top:3px">
                        <span style="color:#10b981;font-family:monospace">${escHtml(db.user)}</span>
                        <span style="color:var(--text-muted)"> @ </span>
                        <span style="color:#f59e0b;font-family:monospace">${escHtml(db.host)}</span>
                        <span style="color:var(--text-muted);margin-left:8px">prefix: ${escHtml(db.table_prefix || 'wp_')}</span>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="setDbCred('${escHtml(db.host)}','${escHtml(db.user)}','${escHtml(db.pass)}')">
                    ${isActive ? '<i class="fas fa-sync"></i> Reconnect' : '<i class="fas fa-plug"></i> Connect'}
                </button>
            </div>`;
        });

        html += `</div>`;
        $('modalBody').innerHTML = html;
    };

    window.setDbCred = async (host, user, pass) => {
        closeModal();
        const r = await apiPost('database', { action: 'set_db', host, user, pass });
        if (r?.success) { toast(r.message, 'success'); navigate('database'); }
        else { toast(r?.error || 'Failed to connect', 'error'); navigate('database'); }
    };

    window.clearDb = async () => {
        const r = await apiPost('database', { action: 'clear_db' });
        toast(r?.message || 'Disconnected', 'info');
        navigate('database');
    };
});


// ═══════════════════════════════════════════════════════════════
//  CRON JOBS
// ═══════════════════════════════════════════════════════════════
registerSection('cron', async () => {
    const [r, presets] = await Promise.all([
        apiGet('cron', { action:'list' }),
        apiGet('cron', { action:'presets' }),
    ]);
    const crons = r?.crons || [];
    const presetOpts = (presets?.presets||[]).map(p => `<option value="${p.value}">${p.label}</option>`).join('');

    setContent(`
    <div class="page-title"><i class="fas fa-clock" style="color:#8b5cf6"></i> Cron Jobs</div>
    <div class="page-subtitle">Schedule automatic tasks on your server</div>
    <div class="card" style="margin-bottom:20px">
      <div style="font-weight:700;margin-bottom:14px"><i class="fas fa-plus"></i> Add Cron Job</div>
      <div style="display:grid;grid-template-columns:repeat(5,1fr) 2fr;gap:8px;margin-bottom:12px">
        <div><label class="form-label">Minute</label><input id="crMin" class="form-input" value="*"></div>
        <div><label class="form-label">Hour</label><input id="crHour" class="form-input" value="*"></div>
        <div><label class="form-label">Day</label><input id="crDom" class="form-input" value="*"></div>
        <div><label class="form-label">Month</label><input id="crMon" class="form-input" value="*"></div>
        <div><label class="form-label">Weekday</label><input id="crDow" class="form-input" value="*"></div>
        <div><label class="form-label">Command</label><input id="crCmd" class="form-input" placeholder="/usr/bin/php /home/user/script.php"></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <select id="crPreset" class="form-select" style="max-width:220px" onchange="applyPreset(this.value)">
          <option value="">— Presets —</option>${presetOpts}
        </select>
        <button class="btn btn-primary" onclick="addCron()"><i class="fas fa-plus"></i> Add Job</button>
      </div>
    </div>
    <div class="card" style="padding:0">
      <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.05);font-weight:700">Active Cron Jobs (${crons.length})</div>
      <table class="data-table">
        <thead><tr><th>Schedule</th><th>Timing</th><th>Command</th><th>Actions</th></tr></thead>
        <tbody>
        ${crons.map(c => `<tr>
          <td><span class="badge badge-blue">${c.schedule}</span></td>
          <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">${c.minute} ${c.hour} ${c.dom} ${c.month} ${c.dow}</td>
          <td style="font-family:monospace;font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(c.command)}</td>
          <td><button class="btn btn-danger btn-xs" onclick="deleteCron_(${JSON.stringify(escHtml(c.raw))})">Delete</button></td>
        </tr>`).join('')||`<tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No cron jobs found</td></tr>`}
        </tbody>
      </table>
    </div>`);

    window.applyPreset = (val) => {
        if (!val) return;
        const parts = val.split(' ');
        $('crMin').value=parts[0]; $('crHour').value=parts[1]; $('crDom').value=parts[2]; $('crMon').value=parts[3]; $('crDow').value=parts[4];
    };
    window.addCron = async () => {
        const r = await apiPost('cron', { action:'add', minute:$('crMin').value, hour:$('crHour').value, dom:$('crDom').value, month:$('crMon').value, dow:$('crDow').value, command:$('crCmd').value });
        if (r?.success) { toast(r.message,'success'); navigate('cron'); }
        else toast(r?.error||'Failed','error');
    };
    window.deleteCron_ = async (raw) => {
        if (!confirm('Delete this cron job?')) return;
        const r = await apiPost('cron', { action:'delete', raw });
        if (r?.success) { toast(r.message,'success'); navigate('cron'); }
        else toast(r?.error||'Failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  PROCESS MANAGER
// ═══════════════════════════════════════════════════════════════
registerSection('process', async () => {
    const r = await apiGet('process', { action:'list' });
    const procs = r?.processes || [];
    setContent(`
    <div class="page-title"><i class="fas fa-microchip" style="color:#ec4899"></i> Process Manager</div>
    <div class="page-subtitle">View and manage running server processes</div>
    <div style="display:flex;gap:10px;margin-bottom:16px">
      <input type="text" id="procSearch" class="form-input" style="max-width:240px" placeholder="🔍 Search processes..." oninput="filterProcs(this.value)">
      <button class="btn btn-secondary" onclick="navigate('process')"><i class="fas fa-sync"></i> Refresh</button>
    </div>
    <div class="card" style="padding:0">
      <table class="data-table" id="procTable">
        <thead><tr><th>PID</th><th>User</th><th>CPU%</th><th>MEM%</th><th>Status</th><th>Time</th><th>Command</th><th>Actions</th></tr></thead>
        <tbody>
        ${procs.map(p => `<tr>
          <td style="font-family:monospace">${p.pid}</td>
          <td>${p.user}</td>
          <td style="color:${p.cpu>50?'#ef4444':p.cpu>20?'#f59e0b':'#10b981'}">${p.cpu}%</td>
          <td style="color:${p.mem>50?'#ef4444':p.mem>20?'#f59e0b':'#10b981'}">${p.mem}%</td>
          <td><span class="badge ${p.stat.startsWith('S')?'badge-green':p.stat.startsWith('R')?'badge-blue':'badge-gray'}">${p.stat}</span></td>
          <td style="font-family:monospace;font-size:12px">${p.time}</td>
          <td style="font-family:monospace;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(p.command)}">${escHtml(p.cmd_short)}</td>
          <td>
            <button class="btn btn-secondary btn-xs" onclick="killProc(${p.pid},15)">TERM</button>
            <button class="btn btn-danger btn-xs" onclick="killProc(${p.pid},9)">KILL</button>
          </td>
        </tr>`).join('')||'<tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No processes</td></tr>'}
        </tbody>
      </table>
    </div>`);

    window.filterProcs = (q) => {
        document.querySelectorAll('#procTable tbody tr').forEach(tr => {
            tr.style.display = tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    };
    window.killProc = async (pid, sig) => {
        if (!confirm(`Send signal ${sig} to PID ${pid}?`)) return;
        const r = await apiPost('process', { action:'kill', pid, signal:sig });
        if (r?.success) { toast(r.message,'success'); navigate('process'); }
        else toast(r?.error||'Failed','error');
    };
});

// ═══════════════════════════════════════════════════════════════
//  LOG VIEWER
// ═══════════════════════════════════════════════════════════════
registerSection('logs', async () => {
    const r = await apiGet('logs', { action:'list' });
    const logs = r?.logs || [];
    setContent(`
    <div class="page-title"><i class="fas fa-file-alt" style="color:#0ea5e9"></i> Log Viewer</div>
    <div class="page-subtitle">View server and application log files</div>
    ${logs.length === 0 ? '<div class="card"><p style="color:var(--text-muted)">No readable log files found</p></div>' : `
    <div style="display:grid;gap:8px;margin-bottom:20px">
      ${logs.map(l => `<div class="card" style="display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer" onclick="viewLog('${escHtml(l.path)}')">
        <i class="fas fa-file-alt" style="color:#0ea5e9;font-size:20px"></i>
        <div style="flex:1"><div style="font-weight:600">${l.name}</div><div style="font-size:12px;color:var(--text-muted)">${l.path}</div></div>
        <div style="text-align:right"><div style="font-size:13px">${l.size_fmt}</div><div style="font-size:11px;color:var(--text-muted)">${l.mtime_fmt}</div></div>
        <button class="btn btn-secondary btn-sm">View <i class="fas fa-chevron-right"></i></button>
      </div>`).join('')}
    </div>`}
    <div id="logContent" style="display:none">
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <button class="btn btn-secondary btn-sm" onclick="$('logContent').style.display='none'"><i class="fas fa-arrow-left"></i> Back</button>
        <button class="btn btn-danger btn-sm" id="btnClearLog" onclick="clearLog_()">Clear Log</button>
        <select id="logLines" class="form-select" style="width:140px" onchange="viewLog(currentLogPath,this.value)">
          <option value="100">Last 100 lines</option><option value="200" selected>Last 200 lines</option>
          <option value="500">Last 500 lines</option><option value="1000">Last 1000 lines</option>
        </select>
      </div>
      <div class="card" style="padding:0">
        <pre id="logPre" style="font-family:monospace;font-size:12px;line-height:1.6;padding:16px;color:#c8ff9e;background:#0a0a0f;border-radius:14px;overflow:auto;max-height:500px;margin:0;white-space:pre-wrap;word-break:break-all"></pre>
      </div>
    </div>`);

    let currentLogPath = '';
    window.viewLog = async (path, lines = 200) => {
        currentLogPath = path;
        window.currentLogPath = path;
        const r = await apiGet('logs', { action:'read', path, lines });
        $('logContent').style.display = 'block';
        $('logPre').textContent = r?.content || '(empty)';
        $('logPre').scrollTop = $('logPre').scrollHeight;
    };
    window.clearLog_ = async () => {
        if (!confirm('Clear this log file?')) return;
        const r = await apiPost('logs', { action:'clear', path:window.currentLogPath });
        if (r?.success) { toast(r.message,'success'); viewLog(window.currentLogPath); }
        else toast(r?.error||'Failed','error');
    };
});
