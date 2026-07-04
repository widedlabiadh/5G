(() => {
    const cfg = window.NetwonChatConfig || {};
    const form = document.getElementById('ragChatForm');
    if (!form || !cfg.streamEndpoint) {
        return;
    }

    const input = document.getElementById('ragChatInput');
    const messages = document.getElementById('ragChatMessages');
    const sendButton = form.querySelector('.chat-send');
    const conversationList = document.getElementById('conversationList');
    const searchInput = document.getElementById('conversationSearch');
    const newConversationBtn = document.getElementById('newConversationBtn');
    const clearConversationsBtn = document.getElementById('clearConversationsBtn');
    const exportConversationBtn = document.getElementById('exportConversationBtn');
    const insertContextBtn = document.getElementById('insertContextBtn');
    const conversationTitle = document.getElementById('conversationTitle');
    const conversationCount = document.getElementById('conversationCount');
    const messageCount = document.getElementById('messageCount');
    const modeButtons = document.querySelectorAll('.chat-mode[data-mode]');
    const stopButton = document.getElementById('stopGenerationBtn');
    const dynamicSuggestions = document.getElementById('dynamicSuggestions');

    const uid = cfg.userId ? '_' + cfg.userId : '';
    const storageKey = 'netwonRagConversations' + uid;
    const activeStorageKey = 'netwonRagActiveConversation' + uid;
    const modeStorageKey = 'netwonRagMode' + uid;
    const welcomeMessage = cfg.welcomeMessage || 'Bonjour. Je suis pret a analyser les donnees NetWON.';

    let selectedMode = localStorage.getItem(modeStorageKey) || 'Diagnostic reseau';
    let abortController = null;
    let syncTimer = null;
    let conversations = [];
    let activeConversationId = localStorage.getItem(activeStorageKey);

    const createId = () => `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    const escHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);

    const govLevel = (pct) => {
        if (pct > 5)  return { bg: '#fef2f2', border: '#fca5a5', dot: '#dc2626', label: 'Critique' };
        if (pct >= 3) return { bg: '#fff7ed', border: '#fed7aa', dot: '#d97706', label: 'Élevé'   };
        if (pct >= 1) return { bg: '#fefce8', border: '#fde68a', dot: '#ca8a04', label: 'Modéré'  };
        return           { bg: '#f0fdf4', border: '#bbf7d0', dot: '#16a34a', label: 'Normal'   };
    };

    const buildPrintHtml = (data, charts) => {
        const chartImg = (b64, alt, w) => b64
            ? `<img src="${b64}" style="width:${w ?? '100%'};border-radius:10px;display:block;" alt="${alt}">`
            : '';

        const sum      = data.summary  || {};
        const status   = data.status   || {};
        const tickets  = data.tickets  || {};
        const byAction = tickets.by_action || {};
        const actionLabels  = { swap_10g: 'Swap 10G', tf_balancing: 'TF Balancing', upgrade_capacite: 'Upgrade capacité', intervention_urgence: 'Intervention urgence' };
        const actionColors  = { swap_10g: ['#1e40af','#eff6ff'], tf_balancing: ['#d97706','#fffbeb'], upgrade_capacite: ['#059669','#ecfdf5'], intervention_urgence: ['#dc2626','#fef2f2'] };

        /* helpers */
        const sec = (title, icon, content) => `
            <div style="margin-bottom:28px;">
                <div style="display:flex;align-items:center;gap:8px;border-bottom:2px solid #ff6200;padding-bottom:6px;margin-bottom:16px;">
                    <span style="font-size:1rem;">${icon}</span>
                    <h3 style="margin:0;font-size:.9rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#1e293b;">${title}</h3>
                </div>
                ${content}
            </div>`;

        const statCard = (value, label, sub, color, bg) => `
            <div style="background:${bg};border-radius:12px;padding:16px 14px;text-align:center;border-top:3px solid ${color};flex:1;min-width:110px;">
                <div style="font-size:2rem;font-weight:900;color:${color};line-height:1;">${value}</div>
                <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:${color};margin-top:5px;">${label}</div>
                ${sub ? `<div style="font-size:.75rem;color:${color};opacity:.8;margin-top:3px;font-weight:700;">${sub}</div>` : ''}
            </div>`;

        const progressBar = (pct, color) => `
            <div style="background:#f1f5f9;border-radius:99px;height:8px;width:100%;overflow:hidden;">
                <div style="background:${color};height:8px;border-radius:99px;width:${Math.min(100, pct)}%;"></div>
            </div>`;

        /* gouvernorats top-risk visual cards (no table) */
        const govCards = (data.gouvernorats || [])
            .filter((g) => g.congestion > 0 || g.bridage > 0)
            .slice(0, 12)
            .map((g) => {
                const lv  = govLevel(g.congestion_pct);
                const total = Math.max(1, g.total);
                return `<div style="background:${lv.bg};border-radius:10px;padding:12px 14px;border-left:4px solid ${lv.dot};break-inside:avoid;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <strong style="font-size:.8rem;color:#1e293b;">${escHtml(g.name)}</strong>
                        <span style="font-size:.6rem;font-weight:800;color:${lv.dot};background:#fff;border:1px solid ${lv.border};padding:2px 8px;border-radius:99px;">${lv.label}</span>
                    </div>
                    <div style="display:flex;gap:8px;font-size:.72rem;margin-bottom:8px;color:#475569;">
                        <span>Total&nbsp;<strong style="color:#1e293b;">${g.total}</strong></span>
                        <span style="color:#059669;">OK&nbsp;<strong>${g.ok}</strong></span>
                        <span style="color:#d97706;">Cong.&nbsp;<strong>${g.congestion}</strong></span>
                        <span style="color:#dc2626;">Brid.&nbsp;<strong>${g.bridage}</strong></span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="font-size:.6rem;color:#64748b;width:50px;">OK ${g.ok_pct}%</span>
                            ${progressBar(g.ok_pct, '#10b981')}
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="font-size:.6rem;color:#64748b;width:50px;">Cong ${g.congestion_pct}%</span>
                            ${progressBar(g.congestion_pct, '#f59e0b')}
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="font-size:.6rem;color:#64748b;width:50px;">Brid ${g.bridage_pct}%</span>
                            ${progressBar(g.bridage_pct, '#ef4444')}
                        </div>
                    </div>
                </div>`;
            }).join('');

        /* action type stat pills */
        const actionPills = Object.entries(byAction).map(([k, v]) => {
            const [color, bg] = actionColors[k] || ['#475569', '#f8fafc'];
            const total = Math.max(1, tickets.total || 1);
            return `<div style="background:${bg};border-radius:12px;padding:14px 16px;text-align:center;border-top:3px solid ${color};flex:1;min-width:130px;">
                <div style="font-size:1.8rem;font-weight:900;color:${color};">${v}</div>
                <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;color:${color};margin:4px 0;">${escHtml(actionLabels[k] || k)}</div>
                ${progressBar(Math.round(v / total * 100), color)}
                <div style="font-size:.6rem;color:${color};margin-top:4px;opacity:.8;">${Math.round(v / total * 100)}% du total</div>
            </div>`;
        }).join('');

        /* cover */
        const coverPage = `
            <div style="page-break-after:always;display:flex;flex-direction:column;justify-content:center;align-items:center;min-height:100vh;background:linear-gradient(135deg,#fff7ed 0%,#fff 60%,#f0f9ff 100%);text-align:center;padding:48px;">
                <div style="background:#ff6200;color:#fff;font-size:2.2rem;font-weight:900;letter-spacing:.08em;padding:18px 42px;border-radius:14px;margin-bottom:40px;box-shadow:0 8px 24px rgba(255,98,0,.25);">Orange</div>
                <h1 style="font-size:2rem;color:#111827;margin:0 0 8px;font-weight:900;">Rapport Réseau Complet</h1>
                <p style="font-size:1rem;color:#64748b;margin:0 0 40px;">Analyse globale — NetWON &bull; Orange Tunisie</p>
                <div style="display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-bottom:40px;">
                    ${statCard(data.total_sites,             'Sites analysés',  null,                       '#ff6200', '#fff7ed')}
                    ${statCard(sum.ok?.count ?? 0,           'Sites OK',        `${sum.ok?.pct ?? 0}%`,     '#059669', '#ecfdf5')}
                    ${statCard(sum.congestion?.count ?? 0,   'Congestion',      `${sum.congestion?.pct ?? 0}%`,'#d97706','#fffbeb')}
                    ${statCard(status.critique ?? 0,         'Critiques',       null,                       '#dc2626', '#fef2f2')}
                    ${statCard(tickets.total ?? 0,           'Tickets',         null,                       '#1e40af', '#eff6ff')}
                </div>
                <div style="font-size:.78rem;color:#64748b;background:#f8fafc;border-radius:10px;padding:12px 24px;display:inline-block;">
                    <strong>Date :</strong> ${escHtml(data.date)} &nbsp;&bull;&nbsp; <strong>Période :</strong> ${escHtml(data.periode)}
                </div>
            </div>`;

        /* executive summary */
        const execBlock = data.executive_summary ? `
            <div style="background:linear-gradient(135deg,#fff7ed,#fff);border-left:4px solid #ff6200;padding:16px 18px;border-radius:0 10px 10px 0;margin-bottom:28px;">
                <div style="font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#ff6200;margin-bottom:6px;">💡 Résumé exécutif</div>
                <p style="margin:0;font-size:.85rem;color:#1e293b;line-height:1.65;">${escHtml(data.executive_summary)}</p>
            </div>` : '';

        return `<div style="font-family:'Segoe UI',system-ui,sans-serif;font-size:.82rem;color:#1e293b;">
            ${coverPage}

            <!-- PAGE 2 : Vue d'ensemble -->
            <div style="padding:36px 40px;max-width:800px;margin:0 auto;page-break-after:always;">
                <div style="border-bottom:3px solid #ff6200;padding-bottom:8px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:baseline;">
                    <h2 style="margin:0;font-size:1.1rem;font-weight:900;">Vue d'ensemble</h2>
                    <span style="font-size:.7rem;color:#94a3b8;">${escHtml(data.date)} &bull; ${escHtml(data.periode)}</span>
                </div>

                ${execBlock}

                ${sec('État des sites', '📶',`
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
                        ${statCard(sum.ok?.count ?? 0,        'OK',        `${sum.ok?.pct ?? 0}%`,                '#059669','#ecfdf5')}
                        ${statCard(sum.congestion?.count ?? 0,'Congestion',`${sum.congestion?.pct ?? 0}%`,        '#d97706','#fffbeb')}
                        ${statCard(sum.bridage?.count ?? 0,   'Bridage',   `${sum.bridage?.pct ?? 0}%`,           '#dc2626','#fef2f2')}
                        ${statCard(sum.a_verifier?.count ?? 0,'À vérifier',`${sum.a_verifier?.pct ?? 0}%`,       '#7c3aed','#faf5ff')}
                    </div>
                `)}

                ${sec('Criticité des sites', '🚦', `
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
                        ${statCard(status.critique         ?? 0,'Critique',        null,'#dc2626','#fef2f2')}
                        ${statCard(status.sous_observation ?? 0,'Sous observation',null,'#d97706','#fffbeb')}
                        ${statCard(status.securise         ?? 0,'Sécurisé',        null,'#059669','#ecfdf5')}
                    </div>
                `)}

                <div style="display:flex;gap:16px;margin-top:4px;">
                    <div style="flex:1;background:#f8fafc;border-radius:12px;padding:12px;">
                        <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:8px;text-align:center;">Répartition état</div>
                        ${chartImg(charts.etat, 'État')}
                    </div>
                    <div style="flex:1;background:#f8fafc;border-radius:12px;padding:12px;">
                        <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:8px;text-align:center;">Criticité</div>
                        ${chartImg(charts.statut, 'Criticité')}
                    </div>
                </div>
            </div>

            <!-- PAGE 3 : Gouvernorats -->
            <div style="padding:36px 40px;max-width:800px;margin:0 auto;page-break-after:always;">
                <div style="border-bottom:3px solid #ff6200;padding-bottom:8px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:baseline;">
                    <h2 style="margin:0;font-size:1.1rem;font-weight:900;">Analyse par gouvernorat</h2>
                    <span style="font-size:.7rem;color:#94a3b8;">${(data.gouvernorats || []).length} zones</span>
                </div>
                <div style="background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:20px;">
                    ${chartImg(charts.gov, 'Gouvernorats')}
                </div>
                <div style="display:flex;gap:6px;margin-bottom:14px;font-size:.62rem;font-weight:700;">
                    <span style="color:#dc2626;">● Critique &gt;5%</span>
                    <span style="color:#d97706;">● Élevé 3–5%</span>
                    <span style="color:#ca8a04;">● Modéré 1–3%</span>
                    <span style="color:#16a34a;">● Normal &lt;1%</span>
                </div>
                ${govCards ? `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    ${govCards}
                </div>` : ''}
            </div>

            <!-- PAGE 4 : Tickets -->
            <div style="padding:36px 40px;max-width:800px;margin:0 auto;">
                <div style="border-bottom:3px solid #ff6200;padding-bottom:8px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:baseline;">
                    <h2 style="margin:0;font-size:1.1rem;font-weight:900;">Tickets & Workflow</h2>
                    <span style="font-size:.7rem;color:#94a3b8;">${tickets.total ?? 0} tickets au total</span>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px;">
                    ${statCard(tickets.total          ?? 0,'Total',          null,       '#1e40af','#eff6ff')}
                    ${statCard(tickets.open           ?? 0,'Ouverts',        null,       '#f59e0b','#fffbeb')}
                    ${statCard(tickets.in_progress    ?? 0,'En cours',       null,       '#0ea5e9','#f0f9ff')}
                    ${statCard(tickets.pending        ?? 0,'En attente',     null,       '#7c3aed','#faf5ff')}
                    ${statCard(tickets.closed         ?? 0,'Clôturés',       null,       '#059669','#ecfdf5')}
                    ${statCard(tickets.overdue        ?? 0,'En retard',      null,       '#dc2626','#fef2f2')}
                    ${statCard(tickets.sites_corriges ?? 0,'Sites corrigés', null,       '#065f46','#d1fae5')}
                </div>
                ${Object.keys(byAction).length ? `
                <div style="background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:20px;">
                    ${chartImg(charts.tickets, 'Tickets par type')}
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    ${actionPills}
                </div>` : ''}
                <p style="margin-top:32px;font-size:.65rem;color:#94a3b8;text-align:center;border-top:1px solid #f1f5f9;padding-top:14px;">
                    NetWON — Rapport automatique &bull; Orange Tunisie &bull; ${data.total_sites} sites analysés &bull; ${escHtml(data.date)}
                </p>
            </div>
        </div>`;
    };

    const printReport = (data) => {
        /* Use stored Chart.js instances — toBase64Image() is always reliable */
        const ci = data._chartInstances || {};
        const getB64 = (inst) => {
            if (!inst) return null;
            try { return inst.toBase64Image('image/png', 1); } catch (e) { return null; }
        };
        const charts = {
            etat:    getB64(ci.etat),
            statut:  getB64(ci.statut),
            gov:     getB64(ci.gov),
            tickets: getB64(ci.tickets),
        };

        document.getElementById('netwon-print-report')?.remove();
        const printDiv = document.createElement('div');
        printDiv.id = 'netwon-print-report';
        printDiv.innerHTML = buildPrintHtml(data, charts);
        document.body.appendChild(printDiv);
        window.addEventListener('afterprint', () => printDiv.remove(), { once: true });
        window.print();
    };

    const downloadCsv = (data) => {
        const BOM = '﻿';
        const sep = ';';
        const sum     = data.summary  || {};
        const status  = data.status   || {};
        const tickets = data.tickets  || {};
        const byAction = tickets.by_action || {};
        const actionLabels = { swap_10g: 'Swap 10G', tf_balancing: 'TF Balancing', upgrade_capacite: 'Upgrade capacité', intervention_urgence: 'Intervention urgence' };

        const sections = [
            ['=== ÉTAT DES SITES ==='],
            ['Métrique', 'Valeur', 'Pourcentage'],
            ['OK',         sum.ok?.count ?? 0,        `${sum.ok?.pct ?? 0}%`],
            ['Congestion', sum.congestion?.count ?? 0, `${sum.congestion?.pct ?? 0}%`],
            ['Bridage',    sum.bridage?.count ?? 0,    `${sum.bridage?.pct ?? 0}%`],
            ['À vérifier', sum.a_verifier?.count ?? 0, `${sum.a_verifier?.pct ?? 0}%`],
            [],
            ['=== CRITICITÉ DES SITES ==='],
            ['Critique',         status.critique         ?? 0],
            ['Sous observation', status.sous_observation ?? 0],
            ['Sécurisé',         status.securise         ?? 0],
            [],
            ['=== TICKETS & WORKFLOW ==='],
            ['Total',          tickets.total          ?? 0],
            ['Ouverts',        tickets.open           ?? 0],
            ['En cours',       tickets.in_progress    ?? 0],
            ['En attente',     tickets.pending        ?? 0],
            ['Clôturés',       tickets.closed         ?? 0],
            ['En retard',      tickets.overdue        ?? 0],
            ['Sites corrigés', tickets.sites_corriges ?? 0],
            [],
            ['=== TICKETS PAR TYPE D\'ACTION ==='],
            ...Object.entries(byAction).map(([k, v]) => [actionLabels[k] || k, v]),
            [],
            ['=== GOUVERNORATS ==='],
            ['Gouvernorat', 'Total', 'OK', 'OK%', 'Congestion', 'Cong%', 'Bridage', 'Brid%', 'Niveau'],
            ...(data.gouvernorats || []).map((g) => {
                const lv = govLevel(g.congestion_pct);
                return [g.name, g.total, g.ok, g.ok_pct, g.congestion, g.congestion_pct, g.bridage, g.bridage_pct, lv.label];
            }),
        ];

        const csv = sections.map((r) => r.join(sep)).join('\n');
        const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `rapport-reseau-${(data.date || '').replace(/[/:]/g, '-').replace(/ /g, '_')}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const renderReportCard = (bubble, data) => {
        if (!bubble || !data || !data.gouvernorats) return;

        const id        = createId();
        const etatId    = `etat-${id}`;
        const statutId  = `stat-${id}`;
        const govId     = `gov-${id}`;
        const ticketsId = `tick-${id}`;
        data._charts = { etatId, statutId, govId, ticketsId };

        const sum      = data.summary  || {};
        const status   = data.status   || {};
        const tickets  = data.tickets  || {};
        const byAction = tickets.by_action || {};
        const actionLabels = { swap_10g: 'Swap 10G', tf_balancing: 'TF Balancing', upgrade_capacite: 'Upgrade capacité', intervention_urgence: 'Intervention urgence' };
        const actionColors  = ['#3b82f6', '#f59e0b', '#10b981', '#ef4444'];

        /* ── KPI card with mini progress bar ── */
        const kpiCard = (val, label, pct, color, bg) => `
            <div class="dash-kpi" style="border-top-color:${color};background:${bg};">
                <div class="dash-kpi-val" style="color:${color};">${val}</div>
                <div class="dash-kpi-label">${label}</div>
                ${pct !== null ? `<div class="dash-kpi-pct" style="color:${color};">${pct}%</div>
                <div class="dash-kpi-track"><div class="dash-kpi-fill" style="width:${Math.min(100, pct)}%;background:${color};"></div></div>` : ''}
            </div>`;

        /* ── Governor visual card with progress bars ── */
        const bar = (pct, color) => `<div class="dash-bar-track"><div class="dash-bar-fill" style="width:${Math.min(100, pct)}%;background:${color};"></div></div>`;
        const govCard = (g) => {
            const lv = govLevel(g.congestion_pct);
            return `<div class="dash-gov-card" style="border-left-color:${lv.dot};background:${lv.bg};">
                <div class="dash-gov-head">
                    <span class="dash-gov-name">${escHtml(g.name)}</span>
                    <span class="dash-gov-badge" style="color:${lv.dot};border-color:${lv.border};">${lv.label}</span>
                </div>
                <div class="dash-gov-count">${g.total} sites</div>
                <div class="dash-gov-bars">
                    <div class="dash-gov-bar-row">
                        <span class="dash-gov-bar-lbl" style="color:#059669;">OK&nbsp;${g.ok_pct}%</span>
                        ${bar(g.ok_pct, '#10b981')}
                    </div>
                    <div class="dash-gov-bar-row">
                        <span class="dash-gov-bar-lbl" style="color:#d97706;">Cong&nbsp;${g.congestion_pct}%</span>
                        ${bar(g.congestion_pct, '#f59e0b')}
                    </div>
                    <div class="dash-gov-bar-row">
                        <span class="dash-gov-bar-lbl" style="color:#dc2626;">Brid&nbsp;${g.bridage_pct}%</span>
                        ${bar(g.bridage_pct, '#ef4444')}
                    </div>
                </div>
            </div>`;
        };

        /* ── Action type pill ── */
        const total = Math.max(1, tickets.total || 1);
        const actionPill = (k, v, color) => `
            <div class="dash-action-pill" style="border-top-color:${color};">
                <div class="dash-action-val" style="color:${color};">${v}</div>
                <div class="dash-action-label">${escHtml(actionLabels[k] || k)}</div>
                <div class="dash-bar-track" style="margin-top:6px;">
                    <div class="dash-bar-fill" style="width:${Math.round(v / total * 100)}%;background:${color};"></div>
                </div>
                <div class="dash-action-pct" style="color:${color};">${Math.round(v / total * 100)}%</div>
            </div>`;

        const govCards = data.gouvernorats.slice(0, 10).map(govCard).join('');
        const actionPills = Object.entries(byAction).map(([k, v], i) => actionPill(k, v, actionColors[i % actionColors.length])).join('');

        const card = document.createElement('div');
        card.className = 'report-card';
        card.innerHTML = `
            <!-- ── HEADER ── -->
            <div class="report-header">
                <div class="report-header-left">
                    <i class="fas fa-chart-pie report-icon"></i>
                    <div>
                        <strong class="report-title">${escHtml(data.title)}</strong>
                        <span class="report-meta">${escHtml(data.date)}&ensp;·&ensp;${escHtml(data.periode)}</span>
                    </div>
                </div>
                <div class="report-actions">
                    <button type="button" class="report-pdf-btn"><i class="fas fa-file-pdf"></i> PDF</button>
                    <button type="button" class="report-csv-btn"><i class="fas fa-file-excel"></i> Excel</button>
                </div>
            </div>

            <!-- ── ÉTAT KPIs ── -->
            <div class="report-section">
                <h3 class="report-section-title"><i class="fas fa-tower-broadcast"></i> État des sites</h3>
                <div class="dash-kpi-row">
                    ${kpiCard(sum.ok?.count ?? 0,        'OK',        sum.ok?.pct        ?? 0, '#059669','#ecfdf5')}
                    ${kpiCard(sum.congestion?.count ?? 0,'Congestion',sum.congestion?.pct ?? 0, '#d97706','#fffbeb')}
                    ${kpiCard(sum.bridage?.count ?? 0,   'Bridage',   sum.bridage?.pct    ?? 0, '#dc2626','#fef2f2')}
                    ${kpiCard(sum.a_verifier?.count ?? 0,'À vérifier',sum.a_verifier?.pct ?? 0, '#7c3aed','#faf5ff')}
                </div>
            </div>

            <!-- ── CRITICITÉ + DONUTS ── -->
            <div class="report-section">
                <h3 class="report-section-title"><i class="fas fa-shield-halved"></i> Criticité &amp; répartition</h3>
                <div class="dash-two-col">
                    <div class="dash-stat-col">
                        ${kpiCard(status.critique         ?? 0, 'Critique',         null, '#dc2626','#fef2f2')}
                        ${kpiCard(status.sous_observation ?? 0, 'Sous observation',  null, '#d97706','#fffbeb')}
                        ${kpiCard(status.securise         ?? 0, 'Sécurisé',          null, '#059669','#ecfdf5')}
                    </div>
                    <div class="dash-chart-col">
                        <div class="dash-donut-box">
                            <div class="dash-donut-label">État</div>
                            <canvas id="${escHtml(etatId)}" height="160"></canvas>
                        </div>
                        <div class="dash-donut-box">
                            <div class="dash-donut-label">Criticité</div>
                            <canvas id="${escHtml(statutId)}" height="160"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── RÉSUMÉ EXÉCUTIF ── -->
            ${data.executive_summary ? `<div class="report-executive">
                <span class="report-exec-label"><i class="fas fa-brain"></i> Résumé exécutif</span>
                <p class="report-exec-text">${escHtml(data.executive_summary)}</p>
            </div>` : ''}

            <!-- ── GOUVERNORATS : chart + cartes ── -->
            <div class="report-section">
                <h3 class="report-section-title"><i class="fas fa-map-location-dot"></i> Gouvernorats (${data.gouvernorats.length} zones)</h3>
                <div class="report-chart-wrap">
                    <canvas id="${escHtml(govId)}" height="210"></canvas>
                </div>
                <div class="dash-legend">
                    <span style="color:#10b981;">● OK</span>
                    <span style="color:#f59e0b;">● Congestion</span>
                    <span style="color:#ef4444;">● Bridage</span>
                    <span style="color:#dc2626;">&bull; Critique &gt;5%</span>
                    <span style="color:#d97706;">&bull; Élevé 3–5%</span>
                    <span style="color:#ca8a04;">&bull; Modéré 1–3%</span>
                    <span style="color:#16a34a;">&bull; Normal &lt;1%</span>
                </div>
                <div class="dash-gov-grid">
                    ${govCards}
                </div>
            </div>

            <!-- ── TICKETS ── -->
            <div class="report-section report-tickets">
                <h3 class="report-section-title"><i class="fas fa-ticket"></i> Tickets &amp; Workflow</h3>
                <div class="dash-kpi-row dash-kpi-row--tickets">
                    ${kpiCard(tickets.total          ?? 0,'Total',          null,'#1e40af','#eff6ff')}
                    ${kpiCard(tickets.open           ?? 0,'Ouverts',        null,'#f59e0b','#fffbeb')}
                    ${kpiCard(tickets.in_progress    ?? 0,'En cours',       null,'#0ea5e9','#f0f9ff')}
                    ${kpiCard(tickets.pending        ?? 0,'En attente',     null,'#7c3aed','#faf5ff')}
                    ${kpiCard(tickets.closed         ?? 0,'Clôturés',       null,'#059669','#ecfdf5')}
                    ${kpiCard(tickets.overdue        ?? 0,'En retard',      null,'#dc2626','#fef2f2')}
                    ${kpiCard(tickets.sites_corriges ?? 0,'Sites corrigés', null,'#065f46','#d1fae5')}
                </div>
                <div class="report-chart-wrap" style="margin-top:10px;">
                    <canvas id="${escHtml(ticketsId)}" height="140"></canvas>
                </div>
                ${actionPills ? `<div class="dash-action-row">${actionPills}</div>` : ''}
            </div>

            <p class="report-total"><strong>${data.total_sites}</strong> sites analysés &ensp;·&ensp; ${escHtml(data.periode)}</p>`;

        bubble.appendChild(card);

        data._chartInstances = {};

        if (window.Chart) {
            /* animation:false → canvas painted immediately → toBase64Image() works on first call */
            const noAnim = { animation: false };

            const donutCfg = (labels, values, colors) => ({
                type: 'doughnut',
                data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, hoverOffset: 6 }] },
                options: { ...noAnim, responsive: true, cutout: '64%', plugins: { legend: { position: 'bottom', labels: { font: { size: 9 }, boxWidth: 10, padding: 8 } } } },
            });

            const etatCtx = card.querySelector(`#${etatId}`);
            if (etatCtx) data._chartInstances.etat = new window.Chart(etatCtx, donutCfg(
                ['OK', 'Congestion', 'Bridage', 'À vérifier'],
                [sum.ok?.count ?? 0, sum.congestion?.count ?? 0, sum.bridage?.count ?? 0, sum.a_verifier?.count ?? 0],
                ['#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
            ));

            const statutCtx = card.querySelector(`#${statutId}`);
            if (statutCtx) data._chartInstances.statut = new window.Chart(statutCtx, donutCfg(
                ['Critique', 'Sous observation', 'Sécurisé'],
                [status.critique ?? 0, status.sous_observation ?? 0, status.securise ?? 0],
                ['#ef4444', '#f59e0b', '#10b981']
            ));

            const gouvernorats = data.gouvernorats.slice(0, 16);
            const govCtx = card.querySelector(`#${govId}`);
            if (govCtx) data._chartInstances.gov = new window.Chart(govCtx, {
                type: 'bar',
                data: {
                    labels: gouvernorats.map((g) => g.name),
                    datasets: [
                        { label: 'OK',         data: gouvernorats.map((g) => g.ok),         backgroundColor: '#10b981', borderRadius: 4 },
                        { label: 'Congestion', data: gouvernorats.map((g) => g.congestion), backgroundColor: '#f59e0b', borderRadius: 4 },
                        { label: 'Bridage',    data: gouvernorats.map((g) => g.bridage),    backgroundColor: '#ef4444', borderRadius: 4 },
                    ],
                },
                options: {
                    ...noAnim, responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 40 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 9 } } } },
                },
            });

            const actionKeys = Object.keys(byAction);
            const ticketsCtx = card.querySelector(`#${ticketsId}`);
            if (ticketsCtx && actionKeys.length) {
                data._chartInstances.tickets = new window.Chart(ticketsCtx, {
                    type: 'bar',
                    data: {
                        labels: actionKeys.map((k) => actionLabels[k] || k),
                        datasets: [{ data: actionKeys.map((k) => byAction[k]), backgroundColor: actionColors.slice(0, actionKeys.length), borderRadius: 6 }],
                    },
                    options: {
                        ...noAnim, indexAxis: 'y', responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 9 } } }, y: { grid: { display: false }, ticks: { font: { size: 9 } } } },
                    },
                });
            }
        }

        card.querySelector('.report-pdf-btn')?.addEventListener('click', () => printReport(data));
        card.querySelector('.report-csv-btn')?.addEventListener('click', () => downloadCsv(data));
        window.setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 80);
    };

    const createConversation = () => {
        const now = new Date().toISOString();
        return {
            id: createId(),
            title: 'Nouvelle conversation',
            createdAt: now,
            updatedAt: now,
            messages: [{ id: createId(), role: 'assistant', content: welcomeMessage, system: true }],
        };
    };

    const loadConversationsLocal = () => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
            return Array.isArray(saved) ? saved : [];
        } catch {
            return [];
        }
    };

    const saveConversationsLocal = () => {
        localStorage.setItem(storageKey, JSON.stringify(conversations));
        localStorage.setItem(activeStorageKey, activeConversationId || '');
    };

    const getActiveConversation = () => conversations.find((c) => c.id === activeConversationId);

    const formatConversationDate = (value) => {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    };

    const syncMetrics = () => {
        conversationCount.textContent = String(conversations.length);
        messageCount.textContent = String(conversations.reduce((t, c) => t + c.messages.length, 0));
    };

    const syncModes = () => {
        modeButtons.forEach((btn) => btn.classList.toggle('active', btn.dataset.mode === selectedMode));
    };

    const scheduleServerSync = () => {
        window.clearTimeout(syncTimer);
        syncTimer = window.setTimeout(persistConversationToServer, 700);
    };

    const persistConversationToServer = async () => {
        const conversation = getActiveConversation();
        if (!conversation || !cfg.conversationsSaveUrl) {
            return false;
        }

        try {
            const response = await fetch(cfg.conversationsSaveUrl, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    id: conversation.id,
                    title: conversation.title,
                    messages: conversation.messages,
                }),
            });

            return response.ok;
        } catch {
            return false;
        }
    };

    const loadConversationsFromServer = async () => {
        if (!cfg.conversationsListUrl) return;

        try {
            const response = await fetch(cfg.conversationsListUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;

            const data = await response.json();
            if (!Array.isArray(data.conversations) || data.conversations.length === 0) return;

            conversations = data.conversations;
            if (!conversations.some((c) => c.id === activeConversationId)) {
                activeConversationId = conversations[0]?.id ?? null;
            }
            saveConversationsLocal();
        } catch {
            // garde le cache local
        }
    };

    const refreshDynamicSuggestions = async () => {
        if (!dynamicSuggestions || !cfg.suggestionsUrl) return;

        const conversation = getActiveConversation();
        const history = (conversation?.messages || [])
            .filter((m) => !m.system)
            .slice(-6)
            .map((m) => ({ role: m.role, content: m.content }));

        try {
            const url = `${cfg.suggestionsUrl}?history=${encodeURIComponent(JSON.stringify(history))}`;
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) return;

            const data = await response.json();
            const items = Array.isArray(data.suggestions) ? data.suggestions : [];
            dynamicSuggestions.innerHTML = '';

            if (items.length === 0) {
                dynamicSuggestions.hidden = true;
                return;
            }

            dynamicSuggestions.hidden = false;
            items.forEach((text) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'slash-badge';
                btn.textContent = text.length > 42 ? `${text.slice(0, 42)}...` : text;
                btn.title = text;
                btn.addEventListener('click', () => {
                    input.value = text;
                    form.requestSubmit();
                });
                dynamicSuggestions.appendChild(btn);
            });
        } catch {
            dynamicSuggestions.hidden = true;
        }
    };

    const sourceLabels = {
        fastpath: { className: 'fastpath', label: 'Reponse directe' },
        database: { className: 'database', label: 'Base de donnees' },
        ollama: { className: 'ollama', label: 'Synthese IA' },
        fallback: { className: 'fallback', label: 'Mode degrade' },
        local: { className: 'local', label: 'Assistant local' },
    };

    const renderSourceBadge = (source) => {
        const info = sourceLabels[source] || sourceLabels.ollama;
        const badge = document.createElement('span');
        badge.className = `source-badge ${info.className}`;
        badge.textContent = info.label;
        return badge;
    };

    const copyText = async (text, button) => {
        try {
            await navigator.clipboard.writeText(text);
            button.innerHTML = '<i class="fas fa-check"></i>';
            window.setTimeout(() => { button.innerHTML = '<i class="fas fa-copy"></i>'; }, 1200);
        } catch {
            button.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
        }
    };

    const appendMessage = (message, shouldScroll = true, isLoading = false) => {
        const role = message.role;
        const article = document.createElement('article');
        article.className = `chat-message ${role}`;
        article.dataset.messageId = message.id || '';

        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.innerHTML = role === 'user' ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>';

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';

        const topLine = document.createElement('div');
        topLine.className = 'message-topline';

        const label = document.createElement('span');
        label.className = 'message-meta';
        label.textContent = role === 'user' ? 'Vous' : 'Assistant NetWON';

        const actions = document.createElement('div');
        actions.className = 'message-actions';

        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'chat-copy';
        copyButton.setAttribute('aria-label', 'Copier');
        copyButton.innerHTML = '<i class="fas fa-copy"></i>';

        if (role === 'assistant' && !message.system) {
            const regenButton = document.createElement('button');
            regenButton.type = 'button';
            regenButton.className = 'chat-copy';
            regenButton.setAttribute('aria-label', 'Regenerer');
            regenButton.innerHTML = '<i class="fas fa-rotate-right"></i>';
            regenButton.addEventListener('click', () => regenerateFromMessage(message.id));
            actions.append(regenButton);
        }

        actions.append(copyButton);

        if (role === 'assistant' && message.source) {
            topLine.append(label, renderSourceBadge(message.source), actions);
        } else {
            topLine.append(label, actions);
        }

        const content = document.createElement('span');
        content.className = 'message-content';
        if (isLoading) {
            content.innerHTML = '<span class="typing-dots" aria-label="Analyse en cours"><span></span><span></span><span></span></span>';
        } else {
            content.textContent = message.content || '';
        }

        copyButton.addEventListener('click', () => copyText(content.textContent || message.content || '', copyButton));

        bubble.append(topLine, content);

        if (role === 'assistant' && message.rawData) {
            const raw = document.createElement('details');
            raw.className = 'message-raw-data';
            raw.innerHTML = `<summary>Donnees brutes (mode expert)</summary><pre></pre>`;
            raw.querySelector('pre').textContent = message.rawData;
            bubble.append(raw);
        }

        if (role === 'assistant' && message.report) {
            window.setTimeout(() => renderReportCard(bubble, message.report), 0);
        }

        article.append(avatar, bubble);
        messages.appendChild(article);

        if (shouldScroll) {
            article.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }

        return content;
    };

    const renderConversation = () => {
        const conversation = getActiveConversation();
        messages.innerHTML = '';

        if (!conversation) {
            conversationTitle.textContent = 'Nouvelle conversation';
            syncMetrics();
            refreshDynamicSuggestions();
            return;
        }

        conversationTitle.textContent = conversation.title;
        conversation.messages.forEach((msg) => appendMessage(msg, false));
        messages.lastElementChild?.scrollIntoView({ block: 'end' });
        syncMetrics();
        refreshDynamicSuggestions();
    };

    const renderConversationList = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filtered = conversations
            .filter((c) => [c.title, ...c.messages.map((m) => m.content)].join(' ').toLowerCase().includes(query))
            .sort((a, b) => new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime());

        conversationList.innerHTML = '';

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat-empty-state';
            empty.textContent = query ? 'Aucun resultat.' : 'Aucune conversation.';
            conversationList.appendChild(empty);
            syncMetrics();
            return;
        }

        filtered.forEach((conversation) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `chat-thread${conversation.id === activeConversationId ? ' active' : ''}`;
            button.innerHTML = `<span class="chat-thread-icon"><i class="fas fa-message"></i></span><span><span class="chat-thread-title"></span><span class="chat-thread-date"></span></span>`;
            button.querySelector('.chat-thread-title').textContent = conversation.title;
            button.querySelector('.chat-thread-date').textContent = formatConversationDate(conversation.updatedAt);
            button.addEventListener('click', () => {
                activeConversationId = conversation.id;
                saveConversationsLocal();
                renderConversationList();
                renderConversation();
                input.focus();
            });
            conversationList.appendChild(button);
        });

        syncMetrics();
    };

    const startNewConversation = async () => {
        const conversation = createConversation();
        conversations.unshift(conversation);
        activeConversationId = conversation.id;
        saveConversationsLocal();
        renderConversationList();
        renderConversation();
        input.value = '';
        input.focus();
        scheduleServerSync();
    };

    const setGenerating = (active) => {
        sendButton.disabled = active;
        stopButton?.classList.toggle('visible', active);
    };

    const parseSseBuffer = (buffer, onEvent) => {
        const events = [];
        const parts = buffer.split('\n\n');
        const rest = parts.pop() || '';
        parts.forEach((block) => {
            block.split('\n').forEach((line) => {
                if (!line.startsWith('data: ')) return;
                try { events.push(JSON.parse(line.slice(6))); } catch { /* ignore */ }
            });
        });
        events.forEach(onEvent);
        return rest;
    };

    const applyAssistantResult = (assistantMessage, assistantContent, topLine, answer, source, rawData) => {
        assistantContent.textContent = answer;
        assistantMessage.content = answer;
        assistantMessage.source = source;
        if (rawData) {
            assistantMessage.rawData = rawData;
            const bubble = assistantContent.closest('.message-bubble');
            if (bubble && !bubble.querySelector('.message-raw-data')) {
                const raw = document.createElement('details');
                raw.className = 'message-raw-data';
                raw.innerHTML = '<summary>Donnees brutes (mode expert)</summary><pre></pre>';
                raw.querySelector('pre').textContent = rawData;
                bubble.append(raw);
            }
        }
        if (topLine && !topLine.querySelector('.source-badge')) {
            const label = topLine.querySelector('.message-meta');
            topLine.insertBefore(renderSourceBadge(source), label?.nextSibling || null);
        }
    };

    const askAgent = async (message, options = {}) => {
        const { skipUserAppend = false } = options;
        const conversation = getActiveConversation();
        if (!conversation) {
            startNewConversation();
            return askAgent(message, options);
        }

        const expert = selectedMode === 'Mode expert';
        const finalMessage = `[Mode: ${selectedMode}] ${message}`;
        const now = new Date().toISOString();
        const historyPayload = conversation.messages
            .filter((m) => !m.system)
            .slice(-8)
            .map((m) => ({ role: m.role, content: m.content }));

        let userMessage = null;
        if (!skipUserAppend) {
            userMessage = { id: createId(), role: 'user', content: message };
            appendMessage(userMessage);
            conversation.messages.push(userMessage);
        }

        const assistantMessage = { id: createId(), role: 'assistant', content: '', source: 'ollama' };
        const assistantContent = appendMessage(assistantMessage, true, true);
        const topLine = assistantContent.closest('.message-bubble')?.querySelector('.message-topline');
        conversation.messages.push(assistantMessage);

        abortController = new AbortController();
        setGenerating(true);
        conversation.updatedAt = now;
        if (!skipUserAppend && conversation.title === 'Nouvelle conversation') {
            conversation.title = message.length > 48 ? `${message.slice(0, 48)}...` : message;
        }
        saveConversationsLocal();
        renderConversationList();
        conversationTitle.textContent = conversation.title;

        let answer = '';
        let source = 'ollama';
        let rawData = null;
        let reportData = null;

        const body = JSON.stringify({ message: finalMessage, history: historyPayload, expert });

        try {
            const response = await fetch(cfg.streamEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
                signal: abortController.signal,
            });

            if (response.status === 429) throw new Error('rate_limit');
            if (!response.ok || !response.body) throw new Error(`HTTP ${response.status}`);

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer = parseSseBuffer(buffer + decoder.decode(value, { stream: true }), (event) => {
                    if (event.type === 'chunk' && event.content) {
                        answer += event.content;
                        assistantContent.textContent = answer;
                        assistantContent.parentElement?.scrollIntoView({ behavior: 'smooth', block: 'end' });
                    }
                    if (event.type === 'done') {
                        answer = event.answer || answer;
                        source = event.source || source;
                        rawData = event.raw_data || null;
                        reportData = event.report || null;
                    }
                    if (event.type === 'error') throw new Error(event.message || 'stream_error');
                });
            }

            if (!answer) throw new Error('empty_answer');
            applyAssistantResult(assistantMessage, assistantContent, topLine, answer, source, rawData);
            if (reportData) {
                assistantMessage.report = reportData;
                renderReportCard(assistantContent.closest('.message-bubble'), reportData);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                applyAssistantResult(assistantMessage, assistantContent, topLine, answer || 'Generation interrompue.', 'local', null);
            } else if (error.message === 'rate_limit') {
                applyAssistantResult(assistantMessage, assistantContent, topLine, 'Trop de requetes. Patientez une minute.', 'local', null);
            } else {
                try {
                    const fallbackResponse = await fetch(cfg.endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body,
                        signal: abortController.signal,
                    });
                    if (!fallbackResponse.ok) throw new Error(`HTTP ${fallbackResponse.status}`);
                    const data = await fallbackResponse.json();
                    applyAssistantResult(
                        assistantMessage,
                        assistantContent,
                        topLine,
                        data.answer || 'Reponse indisponible.',
                        data.source || (data.fallback ? 'fallback' : 'ollama'),
                        data.raw_data || null
                    );
                    if (data.report) {
                        assistantMessage.report = data.report;
                        renderReportCard(assistantContent.closest('.message-bubble'), data.report);
                    }
                } catch (fallbackError) {
                    const msg = fallbackError.name === 'AbortError'
                        ? 'Generation interrompue.'
                        : 'Erreur de connexion avec l\'agent RAG.';
                    applyAssistantResult(assistantMessage, assistantContent, topLine, msg, 'local', null);
                }
            }
        } finally {
            abortController = null;
            setGenerating(false);
            conversation.updatedAt = new Date().toISOString();
            saveConversationsLocal();
            scheduleServerSync();
            renderConversationList();
            refreshDynamicSuggestions();
            input.focus();
        }
    };

    const regenerateFromMessage = async (assistantMessageId) => {
        const conversation = getActiveConversation();
        if (!conversation) return;

        const idx = conversation.messages.findIndex((m) => m.id === assistantMessageId);
        if (idx <= 0) return;

        const userMsg = conversation.messages[idx - 1];
        if (userMsg.role !== 'user') return;

        conversation.messages = conversation.messages.slice(0, idx);
        saveConversationsLocal();
        renderConversation();
        await askAgent(userMsg.content, { skipUserAppend: true });
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        input.value = '';
        askAgent(message);
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 130)}px`;
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    document.querySelectorAll('.chat-prompt[data-prompt]').forEach((card) => {
        card.addEventListener('click', () => {
            input.value = card.dataset.prompt || '';
            form.requestSubmit();
        });
    });

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            selectedMode = button.dataset.mode || selectedMode;
            localStorage.setItem(modeStorageKey, selectedMode);
            syncModes();
            input.focus();
        });
    });

    stopButton?.addEventListener('click', () => abortController?.abort());

    document.querySelectorAll('.slash-badge[data-slash]').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.slash || '';
            form.requestSubmit();
        });
    });

    insertContextBtn?.addEventListener('click', () => {
        const context = 'Contexte: supervision reseau NetWON. Objectif: ';
        input.value = input.value ? `${input.value}\n${context}` : context;
        input.focus();
        input.dispatchEvent(new Event('input'));
    });

    exportConversationBtn?.addEventListener('click', async () => {
        const conversation = getActiveConversation();
        if (!conversation?.id || !cfg.exportUrlTemplate) return;
        await persistConversationToServer();
        const url = cfg.exportUrlTemplate.replace('__ID__', encodeURIComponent(conversation.id));
        window.open(url, '_blank');
    });

    clearConversationsBtn?.addEventListener('click', async () => {
        if (!window.confirm('Vider tout l\'historique du chatbot (serveur et local) ?')) return;

        try {
            if (cfg.conversationsClearUrl) {
                await fetch(cfg.conversationsClearUrl, { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            }
        } catch { /* ignore */ }

        conversations = [];
        activeConversationId = null;
        localStorage.removeItem(storageKey);
        localStorage.removeItem(activeStorageKey);
        startNewConversation();
    });

    newConversationBtn?.addEventListener('click', startNewConversation);
    searchInput?.addEventListener('input', renderConversationList);

    const init = async () => {
        conversations = loadConversationsLocal();
        await loadConversationsFromServer();

        if (conversations.length === 0 || !conversations.some((c) => c.id === activeConversationId)) {
            const conversation = createConversation();
            conversations.unshift(conversation);
            activeConversationId = conversation.id;
            saveConversationsLocal();
            scheduleServerSync();
        }

        syncModes();
        renderConversationList();
        renderConversation();
    };

    init();
})();
