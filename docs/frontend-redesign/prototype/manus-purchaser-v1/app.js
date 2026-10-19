const STORAGE_KEY = 'pettycash-purchaser-prototype-v1';

const icon = (name, size = 18) => {
  const paths = {
    wallet: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 8h18M16 14h2"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
    sun: '<circle cx="12" cy="12" r="3.5"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/>',
    moon: '<path d="M20.5 15.2A8.5 8.5 0 0 1 8.8 3.5 8.5 8.5 0 1 0 20.5 15.2Z"/>',
    arrowLeft: '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    arrowRight: '<path d="M5 12h14M12 5l7 7-7 7"/>',
    chevronDown: '<path d="m6 9 6 6 6-6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    close: '<path d="m6 6 12 12M18 6 6 18"/>',
    more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    filter: '<path d="M4 6h16M7 12h10M10 18h4"/>',
    calendar: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 9h18"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7h.01"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5M12 7v5l3 2"/>',
    file: '<path d="M6 3h8l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/>',
    folder: '<path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    tag: '<path d="m4 5 7-2 9 9-6 6-9-9zM8 8h.01"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>',
    edit: '<path d="m4 16-.8 4.8L8 20l11-11-4-4zM13 6l4 4"/>',
    lock: '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/>',
    external: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6"/>'
  };
  return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.info}</svg>`;
};

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const money = (value) => `$${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const clone = (value) => JSON.parse(JSON.stringify(value));
const makeDate = (j, g) => ({ j, g });
const dates = {
  d1: makeDate('1402/10/04', '2023-12-25'),
  d2: makeDate('1402/10/06', '2023-12-27'),
  d3: makeDate('1402/10/10', '2023-12-31'),
  d4: makeDate('1402/10/16', '2024-01-06'),
  d5: makeDate('1402/10/26', '2024-01-16')
};
const currentDate = (date) => date ? (state.calendar === 'jalali' ? date.j : date.g) : '—';
const statusClass = (status) => ({ OPEN:'open', AWAITING_COST_CENTER_REVIEW:'review', AWAITING_SENIOR_REVIEW:'review', AWAITING_FINANCE_REVIEW:'review', AWAITING_PURCHASER_CORRECTION:'returned', COMPLETED:'completed', COMPLETED_WITH_REJECTIONS:'rejected', DRAFT:'draft', APPROVED:'approved', REJECTED:'rejected', RETURNED:'returned', PENDING:'pending' }[status] || 'draft');
const sheetStatusLabel = (status) => ({ OPEN:'OPEN', AWAITING_COST_CENTER_REVIEW:'UNDER COST CENTER REVIEW', AWAITING_SENIOR_REVIEW:'UNDER SENIOR REVIEW', AWAITING_FINANCE_REVIEW:'UNDER FINANCE REVIEW', AWAITING_PURCHASER_CORRECTION:'AWAITING PURCHASER CORRECTION', COMPLETED:'COMPLETED', COMPLETED_WITH_REJECTIONS:'COMPLETED WITH REJECTIONS' }[status] || status);
const txStatusLabel = (status) => ({ DRAFT:'DRAFT', AWAITING_COST_CENTER_REVIEW:'AWAITING COST CENTER REVIEW', COST_CENTER_APPROVED:'APPROVED AT COST CENTER', COST_CENTER_REJECTED:'REJECTED AT COST CENTER', AWAITING_SENIOR_REVIEW:'AWAITING SENIOR REVIEW', SENIOR_APPROVED:'APPROVED AT SENIOR REVIEW', SENIOR_REJECTED:'REJECTED AT SENIOR REVIEW', AWAITING_FINANCE_REVIEW:'AWAITING FINANCE REVIEW', AWAITING_PURCHASER_CORRECTION:'RETURNED FOR CORRECTION', COMPLETED_APPROVED:'COMPLETED APPROVED', COMPLETED_REJECTED:'COMPLETED REJECTED' }[status] || status);
const decisionLabel = (decision) => ({ approved:'Approved', rejected:'Rejected', returned:'Returned', pending:'Pending' }[decision] || 'Pending');
const statusPill = (label, kind = 'draft') => `<span class="status-pill status-${kind}">${esc(label)}</span>`;
const txPill = (tx) => {
  const kind = tx.status === 'DRAFT' ? 'draft' : tx.finalDecision === 'approved' || tx.status === 'COMPLETED_APPROVED' ? 'approved' : tx.finalDecision === 'rejected' || tx.status === 'COMPLETED_REJECTED' ? 'rejected' : tx.status === 'AWAITING_PURCHASER_CORRECTION' ? 'returned' : tx.status.includes('APPROVED') ? 'approved' : tx.status.includes('REJECTED') ? 'rejected' : tx.status.includes('AWAITING') ? 'pending' : 'amber';
  return `<span class="tx-status ${kind}">${esc(txStatusLabel(tx.status))}</span>`;
};

function txBase(id, no, description, date, center, type, amount, status, decision = null, decisions = {}) {
  return { id, no, description, date, center, type, amount, currency: 'USD', status, finalDecision: decision, decisions: { costCenter: 'pending', senior: 'pending', finance: 'pending', ...decisions }, evidence: false, revisionCount: 1, revisions: [{ revision: 1, date, amount, description, center, type, status: status === 'DRAFT' ? 'DRAFT' : 'SUBMITTED', actor: 'John Doe' }], audit: [], returnReason: '' };
}
function openTransactions(prefix = 'o', count = 5) {
  const core = [
    ['Office stationery', dates.d2, 'Operations', 'Office Supplies', 25],
    ['Printer paper', dates.d3, 'Operations', 'Office Supplies', 18.5],
    ['Ink cartridges', dates.d4, 'IT Department', 'IT Equipment', 42],
    ['Desk organizer', dates.d1, 'Operations', 'Office Supplies', 15],
    ['USB flash drive', dates.d5, 'IT Department', 'IT Equipment', 25]
  ];
  return Array.from({ length: count }, (_, index) => {
    const item = core[index % core.length];
    const tx = txBase(`${prefix}-${index + 1}`, index + 1, item[0], item[1], item[2], item[3], item[4], 'DRAFT');
    tx.revisions[0].status = 'DRAFT';
    return tx;
  });
}
function reviewTransactions() {
  const txs = openTransactions('r');
  txs[0].status = 'COST_CENTER_APPROVED'; txs[0].finalDecision = 'approved'; txs[0].decisions = { costCenter: 'approved', senior: 'pending', finance: 'pending' }; txs[0].audit.push({ stage:'Cost Center Review', decision:'Approved', date:dates.d3, actor:'Operations Manager' });
  txs[1].status = 'AWAITING_COST_CENTER_REVIEW'; txs[1].decisions.costCenter = 'pending';
  txs[2].status = 'COST_CENTER_REJECTED'; txs[2].finalDecision = 'rejected'; txs[2].decisions = { costCenter: 'rejected', senior: 'pending', finance: 'pending' }; txs[2].audit.push({ stage:'Cost Center Review', decision:'Rejected', date:dates.d3, actor:'IT Manager' });
  txs[3].status = 'AWAITING_PURCHASER_CORRECTION'; txs[3].decisions = { costCenter:'returned', senior:'pending', finance:'pending' }; txs[3].returnReason = 'Description needs a clearer business purpose.'; txs[3].revisionCount = 2; txs[3].revisions.push({ revision:2, date:dates.d4, amount:15, description:'Desk organizer', center:'Operations', type:'Office Supplies', status:'RETURNED', actor:'Cost Center Reviewer' }); txs[3].audit.push({ stage:'Cost Center Review', decision:'Returned', date:dates.d4, actor:'Operations Manager' });
  txs[4].status = 'AWAITING_COST_CENTER_REVIEW';
  return txs;
}
function completedTransactions(rejected = false) {
  const txs = openTransactions(rejected ? 'cr' : 'c');
  txs.forEach((tx, index) => {
    const isRejected = rejected && index === 3;
    tx.status = isRejected ? 'COMPLETED_REJECTED' : 'COMPLETED_APPROVED';
    tx.finalDecision = isRejected ? 'rejected' : 'approved';
    tx.decisions = { costCenter: isRejected ? 'rejected' : 'approved', senior: isRejected ? 'rejected' : 'approved', finance: isRejected ? 'rejected' : 'approved' };
    tx.audit = [
      { stage:'Cost Center Review', decision: isRejected ? 'Rejected' : 'Approved', date:dates.d3, actor:'Operations Manager' },
      { stage:'Senior Review', decision: isRejected ? 'Rejected' : 'Approved', date:dates.d4, actor:'Senior Reviewer' },
      { stage:'Finance Review', decision: isRejected ? 'Rejected' : 'Approved', date:dates.d5, actor:'Finance Reviewer' }
    ];
  });
  return txs;
}
function sheet(id, title, status, account, txs, created, submitted = null, completed = null) {
  return { id, title, owner: 'John Doe', account, currency:'USD', status, createdAt:created, firstSubmittedAt:submitted, completedAt:completed, transactions:txs, lastCreatedTransactionId: txs.length ? txs[txs.length - 1].id : null };
}
function makeFixtures() {
  const bulk = openTransactions('b', 32);
  bulk.forEach((tx, i) => { tx.description = i % 2 ? 'Mobile receipt' : 'Office supplies'; tx.amount = i % 2 ? 8.5 : 12.5; tx.center = i % 3 ? 'Operations' : 'IT Department'; });
  return [
    sheet('PC-2401','Office Supplies','OPEN','Main Petty Cash',openTransactions('o'),dates.d1),
    sheet('PC-2398','Team Lunch','OPEN','Main Petty Cash',openTransactions('l',3).map((tx,i)=>({...tx, amount:[18.2,15,15][i]})),dates.d2),
    sheet('PC-2395','Client Meeting','AWAITING_COST_CENTER_REVIEW','Main Petty Cash',reviewTransactions().map((tx,i)=>({...tx, no:i+1})),dates.d1,dates.d2),
    sheet('PC-2388','Returned Equipment','AWAITING_PURCHASER_CORRECTION','Main Petty Cash',reviewTransactions().slice(3,4).map((tx)=>({...tx,no:1})),dates.d1,dates.d2),
    sheet('PC-2377','January Approved','COMPLETED','Main Petty Cash',completedTransactions(false),dates.d1,dates.d2,dates.d5),
    sheet('PC-2369','Mixed Terminal Results','COMPLETED_WITH_REJECTIONS','Main Petty Cash',completedTransactions(true),dates.d1,dates.d2,dates.d5),
    sheet('PC-2360','Receipt Archive','OPEN','Main Petty Cash',bulk,dates.d1)
  ];
}

const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
const state = {
  sheets: saved?.sheets || makeFixtures(),
  theme: saved?.theme || 'light',
  calendar: saved?.calendar || 'jalali',
  search: '', statusFilter: 'all', accountFilter: 'all', sort: 'submitted-desc', page: 1,
  selectedSheetId: null, tab: 'overview', txMode: null, txId: null, form: null, dirty: false, saveError: false, correctionSaved: false,
  demoKey: 'open'
};

document.documentElement.dataset.theme = state.theme;
const app = document.querySelector('#app');
const toastRegion = document.querySelector('#toast-region');
const confirmRegion = document.querySelector('#confirm-region');

function persist() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify({ sheets: state.sheets, theme: state.theme, calendar: state.calendar }));
}
function toast(message, kind = '') {
  const el = document.createElement('div'); el.className = `toast ${kind}`; el.textContent = message; toastRegion.appendChild(el);
  setTimeout(() => el.remove(), 2800);
}
function selectedSheet() { return state.sheets.find((s) => s.id === state.selectedSheetId); }
function selectedTx() { return selectedSheet()?.transactions.find((t) => t.id === state.txId); }
function totals(sheetData) {
  const current = sheetData.transactions.reduce((sum, tx) => sum + Number(tx.amount || 0), 0);
  const approved = sheetData.transactions.filter((tx) => tx.finalDecision === 'approved').reduce((sum, tx) => sum + Number(tx.amount || 0), 0);
  const rejected = sheetData.transactions.filter((tx) => tx.finalDecision === 'rejected').reduce((sum, tx) => sum + Number(tx.amount || 0), 0);
  const pending = current - approved - rejected;
  return { current, approved, rejected, pending };
}
function stageSummary(sheetData, stage) {
  const counts = { approved:0, rejected:0, pending:0, approvedAmount:0, rejectedAmount:0, pendingAmount:0 };
  sheetData.transactions.forEach((tx) => {
    const decision = tx.decisions?.[stage] || (tx.status === 'DRAFT' ? 'pending' : 'pending');
    const bucket = decision === 'approved' ? 'approved' : decision === 'rejected' ? 'rejected' : 'pending';
    counts[bucket] += 1; counts[`${bucket}Amount`] += Number(tx.amount || 0);
  });
  return counts;
}
function sheetOptions() {
  return state.sheets.map((s) => `<option value="${esc(s.id)}">${esc(s.title)} · ${esc(s.id)}</option>`).join('');
}
function statusForList(sheetData) { return statusPill(sheetStatusLabel(sheetData.status), statusClass(sheetData.status)); }
function demoSheetId(key) { return ({ open:'PC-2401', review:'PC-2395', correction:'PC-2388', completed:'PC-2377', rejected:'PC-2369' }[key] || 'PC-2401'); }
function filteredSheets() {
  const query = state.search.trim().toLowerCase();
  let items = state.sheets.filter((s) => !query || `${s.title} ${s.id}`.toLowerCase().includes(query));
  if (state.statusFilter !== 'all') items = items.filter((s) => s.status === state.statusFilter);
  if (state.accountFilter !== 'all') items = items.filter((s) => s.account === state.accountFilter);
  items.sort((a,b) => {
    const openA = a.status === 'OPEN' && !a.firstSubmittedAt; const openB = b.status === 'OPEN' && !b.firstSubmittedAt;
    if (openA !== openB) return openA ? -1 : 1;
    const ad = a.firstSubmittedAt?.g || ''; const bd = b.firstSubmittedAt?.g || '';
    return state.sort === 'submitted-asc' ? ad.localeCompare(bd) : bd.localeCompare(ad);
  });
  return items;
}
function renderHeader() {
  return `<header class="app-header">
    <div class="brand"><span class="brand-mark">${icon('wallet', 21)}</span><span class="brand-name">Pettycash</span></div>
    <div class="header-actions">
      <button class="select-button" data-action="toggle-calendar" aria-label="Toggle calendar preference">${state.calendar === 'jalali' ? 'Jalali dates' : 'Gregorian dates'}</button>
      <button class="icon-button" data-action="toggle-theme" aria-label="Toggle theme">${icon(state.theme === 'light' ? 'moon' : 'sun')}</button>
      <span class="avatar" aria-label="Signed in as John Doe">JD</span>
    </div>
  </header>`;
}
function renderList() {
  const all = filteredSheets();
  const pageCount = Math.max(1, Math.ceil(all.length / 5));
  state.page = Math.min(state.page, pageCount);
  const visible = all.slice((state.page - 1) * 5, state.page * 5);
  const statusOptions = [['all','All statuses'],['OPEN','OPEN'],['AWAITING_COST_CENTER_REVIEW','Cost Center review'],['AWAITING_PURCHASER_CORRECTION','Returned'],['COMPLETED','Completed'],['COMPLETED_WITH_REJECTIONS','Completed with rejections']];
  return `<main class="page" id="sheets-list" tabindex="-1">
    <div class="page-heading">
      <div><div class="eyebrow">Purchaser workspace · MOCK</div><h1>Sheets</h1><p class="subheading">Create and manage your petty-cash sheets.</p></div>
      <div class="demo-panel"><label for="demo-state">Demo state</label><select id="demo-state"><option value="open" ${state.demoKey==='open'?'selected':''}>OPEN sheet</option><option value="review" ${state.demoKey==='review'?'selected':''}>Mixed Cost Center review</option><option value="correction" ${state.demoKey==='correction'?'selected':''}>Returned correction</option><option value="completed" ${state.demoKey==='completed'?'selected':''}>Completed approved</option><option value="rejected" ${state.demoKey==='rejected'?'selected':''}>Completed with rejections</option></select><button class="primary" data-action="open-demo">${icon('external',15)} Open demo</button></div>
    </div>
    <div class="list-toolbar">
      <div class="field-shell">${icon('search')}<input id="sheet-search" aria-label="Search by sheet title or ID" placeholder="Search by sheet title or ID" value="${esc(state.search)}" /></div>
      <select id="status-filter" aria-label="Filter by status">${statusOptions.map(([v,l])=>`<option value="${v}" ${state.statusFilter===v?'selected':''}>${l}</option>`).join('')}</select>
      <select id="account-filter" aria-label="Filter by account"><option value="all">All accounts</option><option value="Main Petty Cash" ${state.accountFilter==='Main Petty Cash'?'selected':''}>Main Petty Cash</option></select>
      <select id="sort-order" aria-label="Sort by first submitted"><option value="submitted-desc" ${state.sort==='submitted-desc'?'selected':''}>First submitted · newest</option><option value="submitted-asc" ${state.sort==='submitted-asc'?'selected':''}>First submitted · oldest</option></select>
    </div>
    <div class="sheet-table-wrap">
      ${visible.length ? `<table class="sheet-table"><thead><tr><th>Sheet</th><th>Status</th><th>Transactions</th><th>Recorded amount</th><th>Latest approved amount</th><th>First submitted</th></tr></thead><tbody>${visible.map((s)=>{const t=totals(s);return `<tr class="sheet-row" data-action="open-sheet" data-sheet-id="${esc(s.id)}" tabindex="0" role="button" aria-label="Open ${esc(s.title)}"><td><div class="sheet-title">${esc(s.title)}</div><div class="sheet-id">${esc(s.id)}</div></td><td>${statusForList(s)}</td><td>${s.transactions.length}</td><td class="amount">${money(t.current)}</td><td class="amount">${money(t.approved)}</td><td>${s.firstSubmittedAt ? currentDate(s.firstSubmittedAt) : '—'}</td></tr>`;}).join('')}</tbody></table>` : `<div class="empty-state">No sheets match these filters.</div>`}
    </div>
    <div class="pagination"><span>Showing ${visible.length ? (state.page-1)*5+1 : 0}–${Math.min(state.page*5,all.length)} of ${all.length} sheets</span><div class="pagination-actions"><button class="secondary small-button" data-action="page-prev" ${state.page<=1?'disabled':''}>Previous</button><button class="secondary small-button" data-action="page-next" ${state.page>=pageCount?'disabled':''}>Next</button></div></div>
  </main>`;
}
function renderStageCard(label, stage, sheetData) {
  const m = stageSummary(sheetData, stage);
  return `<div class="stage-card"><div class="stage-card-head"><span>${label}</span><span>${icon(stage==='costCenter'?'folder':stage==='senior'?'check':'wallet',15)}</span></div><div class="stage-counts"><div class="metric approved"><strong>${m.approved}</strong><span>${money(m.approvedAmount)}</span></div><div class="metric rejected"><strong>${m.rejected}</strong><span>${money(m.rejectedAmount)}</span></div><div class="metric pending"><strong>${m.pending}</strong><span>${money(m.pendingAmount)}</span></div></div><div class="muted" style="margin-top:8px;font-size:10px">Approved · Rejected · Pending</div></div>`;
}
function renderSummary(sheetData) {
  const t = totals(sheetData);
  return `<div class="summary-grid"><div class="summary-card"><div class="summary-label">Recorded amount · current</div><div class="summary-value">${money(t.current)}</div><div class="summary-note">Sum of active transaction revisions</div></div><div class="summary-card"><div class="summary-label">Latest approved amount</div><div class="summary-value">${money(t.approved)}</div><div class="summary-note">Effective approved decisions only</div></div></div><div class="stage-rail">${renderStageCard('Cost Center Review','costCenter',sheetData)}${renderStageCard('Senior Review','senior',sheetData)}${renderStageCard('Finance Review','finance',sheetData)}</div>`;
}
function renderTransactions(sheetData) {
  return `<div class="section-heading"><h3>Transactions <small>(${sheetData.transactions.length})</small></h3>${sheetData.status==='OPEN' ? `<button class="primary small-button" data-action="start-add">${icon('plus',15)} Add transaction</button>` : ''}</div><div class="tx-table-wrap"><table class="tx-table"><thead><tr><th>#</th><th>Expense date / description</th><th>Cost Center</th><th>Expense Type</th><th>Amount</th><th>Status</th></tr></thead><tbody>${sheetData.transactions.map((tx)=>`<tr data-action="open-tx" data-tx-id="${esc(tx.id)}" tabindex="0"><td data-label="#">${tx.no}</td><td data-label="Transaction"><div class="tx-description">${esc(tx.description)}</div><div class="tx-sub">${currentDate(tx.date)}</div></td><td data-label="Cost Center">${esc(tx.center)}</td><td data-label="Expense Type">${esc(tx.type)}</td><td data-label="Amount" class="amount">${money(tx.amount)}</td><td data-label="Status">${txPill(tx)}</td></tr>`).join('')}</tbody></table></div>`;
}
function renderOverviewTab(sheetData) {
  const byCenter = {};
  sheetData.transactions.forEach((tx) => { byCenter[tx.center] ||= { count:0, amount:0, approved:0 }; byCenter[tx.center].count += 1; byCenter[tx.center].amount += tx.amount; if(tx.finalDecision==='approved') byCenter[tx.center].approved += tx.amount; });
  return `<div class="tab-panel">${renderSummary(sheetData)}<div class="section-heading"><h3>By Cost Center</h3><small>Aggregates reconcile to transaction rows</small></div><div class="card-grid">${Object.entries(byCenter).map(([center, v])=>`<div class="info-card"><h3>${esc(center)}</h3><dl class="kv-list"><div class="kv-row"><dt>Transactions</dt><dd>${v.count}</dd></div><div class="kv-row"><dt>Recorded</dt><dd>${money(v.amount)}</dd></div><div class="kv-row"><dt>Latest approved</dt><dd>${money(v.approved)}</dd></div></dl></div>`).join('')}</div>${renderTransactions(sheetData)}</div>`;
}
function renderApprovalsTab(sheetData) {
  return `<div class="tab-panel"><div class="section-heading"><h3>Transaction approvals</h3><small>One decision per transaction, revision and stage</small></div><div class="audit-list">${sheetData.transactions.map((tx)=>`<div class="audit-item"><span class="timeline-dot ${tx.finalDecision==='approved'?'green':tx.finalDecision==='rejected'?'red':tx.status==='AWAITING_PURCHASER_CORRECTION'?'red':'amber'}"></span><div class="audit-content"><strong>#${tx.no} · ${esc(tx.description)} · ${money(tx.amount)}</strong><span>Cost Center: ${decisionLabel(tx.decisions.costCenter)} · Senior: ${decisionLabel(tx.decisions.senior)} · Finance: ${decisionLabel(tx.decisions.finance)}</span>${tx.returnReason?`<span>Return reason: ${esc(tx.returnReason)}</span>`:''}</div></div>`).join('')}</div></div>`;
}
function renderHistoryTab(sheetData) {
  const items = sheetData.transactions.flatMap((tx)=>tx.audit.map((a)=>({ ...a, tx }))).sort((a,b)=>(a.date?.g||'').localeCompare(b.date?.g||''));
  return `<div class="tab-panel"><div class="section-heading"><h3>Revisions & audit history</h3><small>Per-transaction conceptual audit source</small></div><div class="revision-list">${items.length ? items.map((item)=>`<div class="revision-item"><span class="timeline-dot ${item.decision==='Approved'?'green':item.decision==='Rejected'||item.decision==='Returned'?'red':'amber'}"></span><div class="revision-content"><strong>#${item.tx.no} · ${esc(item.stage)} — ${esc(item.decision)}</strong><span>${esc(item.actor)} · ${currentDate(item.date)} · ${money(item.tx.amount)}</span></div></div>`).join('') : '<div class="info-card"><p>No sheet-level audit is invented. Open a transaction to inspect its revision history.</p></div>'}</div></div>`;
}
function renderWorkspace() {
  const sheetData = selectedSheet(); if (!sheetData) return '';
  if (state.txMode) return renderTransactionWorkspace(sheetData);
  const tabs = [['overview','Overview'],['approvals','Approvals'],['history','History']];
  const canEditSheet = sheetData.status === 'OPEN';
  return `<div class="overlay" role="presentation"><section class="workspace" role="dialog" aria-modal="true" aria-label="Sheet detail" tabindex="-1"><div class="workspace-head"><div class="workspace-topline"><div class="workspace-title"><button class="back-button" data-action="close-workspace" aria-label="Close sheet detail">${icon('arrowLeft')}</button><div><div class="eyebrow">Sheet detail · ${esc(sheetData.id)}</div><h2>${esc(sheetData.title)}</h2><div class="workspace-meta">${statusForList(sheetData)}<span class="muted">${esc(sheetData.account)} · ${esc(sheetData.currency)}</span></div></div></div><div class="workspace-actions">${canEditSheet ? `<button class="secondary small-button" data-action="delete-sheet">${icon('more',15)} <span class="button-label">More</span></button>` : ''}<button class="icon-button" data-action="close-workspace" aria-label="Close">${icon('close')}</button></div></div><div class="workspace-meta"><span class="muted">Created ${currentDate(sheetData.createdAt)}</span>${sheetData.firstSubmittedAt?`<span class="muted">· First submitted ${currentDate(sheetData.firstSubmittedAt)}</span>`:''}${sheetData.completedAt?`<span class="muted">· Completed ${currentDate(sheetData.completedAt)}</span>`:''}</div><nav class="workspace-tabs" aria-label="Sheet detail tabs">${tabs.map(([key,label])=>`<button class="tab-button ${state.tab===key?'active':''}" data-action="set-tab" data-tab="${key}">${label}</button>`).join('')}</nav></div><div class="workspace-body">${state.tab==='overview'?renderOverviewTab(sheetData):state.tab==='approvals'?renderApprovalsTab(sheetData):renderHistoryTab(sheetData)}</div><div class="workspace-footer"><button class="secondary" data-action="close-workspace">Close</button>${canEditSheet?`<button class="primary" data-action="submit-sheet">${icon('arrowRight',15)} Submit sheet</button>`:''}</div></section></div>`;
}
function formValue(field) { return esc(state.form?.[field] ?? ''); }
function renderTransactionWorkspace(sheetData) {
  const tx = selectedTx();
  if (!tx && state.txMode !== 'add') return '';
  if (state.txMode === 'view' || state.txMode === 'history') return renderReadonlyTransaction(sheetData, tx, state.txMode === 'history');
  const isAdd = state.txMode === 'add'; const isCorrection = state.txMode === 'correction';
  const title = isAdd ? 'Add transaction' : isCorrection ? 'Correct transaction' : 'Edit draft transaction';
  const subtitle = `${sheetData.title} · ${sheetData.id} · ${sheetData.status}`;
  const savedNote = isCorrection ? (state.correctionSaved ? 'Correction saved as a new revision. Resubmit is still a separate action.' : 'Update the current revision, save it, then resubmit when ready.') : isAdd ? 'Add an expense to this open sheet. Save creates one transaction only.' : 'Changes are saved to this draft transaction only.';
  return `<div class="overlay" role="presentation"><section class="workspace" role="dialog" aria-modal="true" aria-label="${title}" tabindex="-1"><div class="workspace-head"><div class="workspace-topline"><div class="workspace-title"><button class="back-button" data-action="back-to-sheet" aria-label="Back to sheet detail">${icon('arrowLeft')}</button><div><div class="eyebrow">Transaction workspace · MOCK</div><h2>${title}</h2><div class="workspace-meta"><span class="muted">${esc(subtitle)}</span>${isCorrection?statusPill('RETURNED FOR CORRECTION','returned'):''}</div></div></div><button class="icon-button" data-action="cancel-form" aria-label="Cancel">${icon('close')}</button></div></div><div class="workspace-body"><div class="form-shell">${state.saveError?`<div class="form-alert error">${icon('info',17)}<span>Mock save failed. The form remains open so no changes were lost.</span></div>`:`<div class="form-alert ${isCorrection?'warning':''}">${icon(isCorrection?'history':'info',17)}<span>${savedNote}</span></div>`}<div class="form-grid"><div class="form-field full"><label for="f-description">Description <em>*</em></label><input id="f-description" data-field="description" value="${formValue('description')}" /></div><div class="form-field"><label for="f-date">Expense Date <em>*</em></label><input id="f-date" data-field="date" value="${formValue('date')}" /></div><div class="form-field"><label for="f-center">Cost Center <em>*</em></label><select id="f-center" data-field="center"><option ${state.form.center==='Operations'?'selected':''}>Operations</option><option ${state.form.center==='IT Department'?'selected':''}>IT Department</option><option ${state.form.center==='Facilities'?'selected':''}>Facilities</option></select></div><div class="form-field"><label for="f-type">Expense Type <em>*</em></label><select id="f-type" data-field="type"><option ${state.form.type==='Office Supplies'?'selected':''}>Office Supplies</option><option ${state.form.type==='IT Equipment'?'selected':''}>IT Equipment</option><option ${state.form.type==='Meals'?'selected':''}>Meals</option></select></div><div class="form-field"><label for="f-amount">Amount <em>*</em></label><input id="f-amount" type="number" min="0.01" step="0.01" data-field="amount" value="${formValue('amount')}" /></div></div><div class="evidence-box"><div><strong>Supporting documents</strong><p>Save the transaction first; evidence can be added to the existing transaction or revision after saving.</p></div>${!isAdd && tx?.evidence ? statusPill('Attached','approved') : ''}</div><label class="demo-error"><input type="checkbox" data-field="simError" ${state.form.simError?'checked':''}/> Simulate a save error for this demo</label><div class="form-actions"><button class="secondary" data-action="cancel-form">Cancel</button><button class="secondary" data-action="save-form">${isCorrection?'Save correction':isAdd?'Save transaction':'Save changes'}</button>${isCorrection?`<button class="primary" data-action="resubmit-correction" ${state.correctionSaved?'':'disabled'}>${icon('arrowRight',15)} Resubmit transaction</button>`:''}</div></div></div></section></div>`;
}
function renderReadonlyTransaction(sheetData, tx, historyOnly) {
  const isHistory = historyOnly;
  return `<div class="overlay" role="presentation"><section class="workspace" role="dialog" aria-modal="true" aria-label="${isHistory?'Transaction history':'View transaction'}" tabindex="-1"><div class="workspace-head"><div class="workspace-topline"><div class="workspace-title"><button class="back-button" data-action="back-to-sheet" aria-label="Back to sheet detail">${icon('arrowLeft')}</button><div><div class="eyebrow">Transaction workspace · ${esc(sheetData.id)}</div><h2>${isHistory?'Transaction history':'View transaction'}</h2><div class="workspace-meta"><span class="muted">${esc(sheetData.title)} · #${tx.no}</span>${txPill(tx)}</div></div></div><button class="icon-button" data-action="close-workspace" aria-label="Close">${icon('close')}</button></div></div><div class="workspace-body"><div class="form-shell">${isHistory?renderTransactionHistory(tx):renderTransactionDetails(tx)}${!isHistory ? `<div class="form-actions"><button class="secondary" data-action="open-history">${icon('history',15)} Revisions / history</button>${tx.status !== 'COMPLETED_APPROVED' && tx.status !== 'COMPLETED_REJECTED' ? `<button class="secondary" data-action="attach-evidence">${icon('upload',15)} ${tx.evidence?'Replace evidence':'Attach evidence'}</button>${tx.status==='DRAFT'?`<button class="primary" data-action="edit-current">${icon('edit',15)} Edit draft</button>`:''}${tx.status==='AWAITING_PURCHASER_CORRECTION'?`<button class="primary" data-action="correct-current">${icon('edit',15)} Correct transaction</button>`:''}`:''}</div>`:''}</div></div><div class="workspace-footer"><button class="secondary" data-action="back-to-sheet">Back to sheet</button></div></section></div>`;
}
function renderTransactionDetails(tx) {
  return `<div class="form-alert">${icon('lock',17)}<span>Read-only transaction detail. Decisions and revisions are preserved as immutable history.</span></div><div class="readonly-card"><h3>Transaction ${tx.no}</h3><dl class="kv-list"><div class="kv-row"><dt>Description</dt><dd>${esc(tx.description)}</dd></div><div class="kv-row"><dt>Expense Date</dt><dd>${currentDate(tx.date)}</dd></div><div class="kv-row"><dt>Cost Center</dt><dd>${esc(tx.center)}</dd></div><div class="kv-row"><dt>Expense Type</dt><dd>${esc(tx.type)}</dd></div><div class="kv-row"><dt>Amount</dt><dd>${money(tx.amount)}</dd></div></dl></div><div class="readonly-card" style="margin-top:12px"><h3>Evidence</h3><p class="muted">${tx.evidence?'Evidence attached to the active transaction revision.':'No evidence attached.'}</p></div><div class="section-heading"><h3>Decision history</h3><small>Stage-specific</small></div><div class="audit-list">${tx.audit.length?tx.audit.map((a)=>`<div class="audit-item"><span class="timeline-dot ${a.decision==='Approved'?'green':a.decision==='Rejected'||a.decision==='Returned'?'red':'amber'}"></span><div class="audit-content"><strong>${esc(a.stage)} — ${esc(a.decision)}</strong><span>${esc(a.actor)} · ${currentDate(a.date)}</span></div></div>`).join(''):'<div class="info-card"><p>No decisions yet. This transaction is still a draft.</p></div>'}</div>`;
}
function renderTransactionHistory(tx) {
  return `<div class="form-alert">${icon('history',17)}<span>Historical revisions are immutable. The current revision is shown last.</span></div><div class="section-heading"><h3>Revision history</h3><small>${tx.revisionCount} revision${tx.revisionCount===1?'':'s'}</small></div><div class="revision-list">${tx.revisions.map((r, index)=>`<div class="revision-item"><span class="timeline-dot ${r.status==='RETURNED'?'red':index===tx.revisions.length-1?'green':'amber'}"></span><div class="revision-content"><strong>Revision ${r.revision}${index===tx.revisions.length-1?' (current)':''}</strong><span>${esc(r.actor)} · ${currentDate(r.date)} · ${money(r.amount)} · ${esc(r.status)}</span><span>${esc(r.description)} · ${esc(r.center)} · ${esc(r.type)}</span></div></div>`).join('')}</div><div class="section-heading"><h3>Decision audit</h3><small>Preserved across revisions</small></div><div class="audit-list">${tx.audit.length?tx.audit.map((a)=>`<div class="audit-item"><span class="timeline-dot ${a.decision==='Approved'?'green':a.decision==='Rejected'||a.decision==='Returned'?'red':'amber'}"></span><div class="audit-content"><strong>${esc(a.stage)} — ${esc(a.decision)}</strong><span>${esc(a.actor)} · ${currentDate(a.date)}</span></div></div>`).join(''):'<div class="info-card"><p>No decision audit entries yet.</p></div>'}</div>`;
}
function renderConfirm() {
  if (!state.confirm) { confirmRegion.innerHTML = ''; return; }
  confirmRegion.innerHTML = `<div class="overlay" style="z-index:60"><section class="confirm-card" role="dialog" aria-modal="true" aria-labelledby="confirm-title"><h2 id="confirm-title">${esc(state.confirm.title)}</h2><p>${esc(state.confirm.message)}</p><div class="confirm-actions"><button class="secondary" data-action="confirm-cancel">Continue editing</button><button class="danger-button" data-action="confirm-discard">Discard changes</button>${state.confirm.allowSave?'<button class="primary" data-action="confirm-save">Save changes</button>':''}</div></section></div>`;
}
function render() {
  app.innerHTML = `${renderHeader()}${renderList()}${state.selectedSheetId ? renderWorkspace() : ''}`;
  renderConfirm();
}
function openSheet(id, updateUrl = true) {
  state.selectedSheetId = id; state.tab = 'overview'; state.txMode = null; state.txId = null; state.form = null; state.dirty = false; state.saveError = false; state.correctionSaved = false;
  if (updateUrl) history.pushState({ sheet:id }, '', `/sheets/${encodeURIComponent(id)}`);
  render();
  setTimeout(() => document.querySelector('.workspace')?.focus(), 0);
}
function closeSheetImmediate() {
  state.selectedSheetId = null; state.txMode = null; state.txId = null; state.form = null; state.dirty = false; state.confirm = null;
  if (location.pathname !== '/sheets') history.pushState({ list:true }, '', '/sheets');
  render();
}
function requestClose(kind = 'close') {
  if (!state.dirty) { if(kind==='back' && state.txMode) { state.txMode=null; state.txId=null; state.form=null; state.saveError=false; state.correctionSaved=false; render(); } else closeSheetImmediate(); return; }
  state.confirm = { title:'Unsaved changes', message:'Your edits have not been saved. Continue editing, discard changes, or save this individual transaction before leaving?', allowSave: Boolean(state.form && !state.form.simError) , kind };
  render();
}
function enterForm(mode, tx = null) {
  const sheetData = selectedSheet();
  const source = tx || { description:'', date:state.calendar==='jalali'?'1402/10/06':'2023-12-27', center: sheetData?.transactions.find(t=>t.id===sheetData.lastCreatedTransactionId)?.center || '', type:'Office Supplies', amount:'', simError:false };
  state.txMode = mode; state.txId = tx?.id || null; state.form = { description:source.description, date:source.date?.j || source.date || '', center:source.center || '', type:source.type || 'Office Supplies', amount:source.amount ?? '', simError:false }; state.dirty=false; state.saveError=false; state.correctionSaved=false; render(); setTimeout(()=>document.querySelector('[data-field="description"]')?.focus(),0);
}
function saveForm() {
  const sheetData = selectedSheet(); const mode = state.txMode; if (!sheetData || !state.form) return;
  if (state.form.simError) { state.saveError=true; render(); return; }
  const amount = Number(state.form.amount); if (!state.form.description.trim() || !state.form.date.trim() || !state.form.center || !state.form.type || !amount || amount <= 0) { state.saveError=true; toast('Complete all required fields before saving.', 'error'); render(); return; }
  if (mode === 'add') {
    const id = `${sheetData.id.toLowerCase()}-new-${Date.now()}`; const newTx = txBase(id, sheetData.transactions.length + 1, state.form.description.trim(), makeDate(state.calendar==='jalali'?state.form.date:'1402/10/06', state.calendar==='gregorian'?state.form.date:'2023-12-27'), state.form.center, state.form.type, amount, 'DRAFT'); sheetData.transactions.push(newTx); sheetData.lastCreatedTransactionId = id; state.dirty=false; state.txMode=null; state.txId=null; state.form=null; persist(); toast('Transaction saved (MOCK). You are back on the sheet.', 'success'); render(); return;
  }
  const tx = selectedTx(); if (!tx) return;
  tx.description=state.form.description.trim(); tx.amount=amount; tx.center=state.form.center; tx.type=state.form.type; tx.date=makeDate(state.calendar==='jalali'?state.form.date:tx.date.j, state.calendar==='gregorian'?state.form.date:tx.date.g);
  if (mode === 'correction') { tx.revisionCount += 1; tx.revisions.push({ revision:tx.revisionCount, date:tx.date, amount, description:tx.description, center:tx.center, type:tx.type, status:'SAVED CORRECTION', actor:'John Doe' }); tx.status='AWAITING_PURCHASER_CORRECTION'; state.correctionSaved=true; state.dirty=false; state.saveError=false; persist(); toast('Correction saved as a new revision. Resubmit is separate.', 'success'); render(); return; }
  state.dirty=false; state.txMode=null; state.txId=null; state.form=null; state.saveError=false; persist(); toast('Draft transaction saved (MOCK).', 'success'); render();
}
function resubmitCorrection() {
  const tx = selectedTx(); if (!tx || !state.correctionSaved) return;
  tx.status='AWAITING_COST_CENTER_REVIEW'; tx.decisions.costCenter='pending'; tx.finalDecision=null; tx.audit.push({stage:'Cost Center Review', decision:'Resubmitted', date:dates.d4, actor:'John Doe'}); state.dirty=false; state.txMode=null; state.txId=null; state.form=null; state.correctionSaved=false; persist(); toast('Correction resubmitted (MOCK).', 'success'); render();
}
function confirmDiscard() {
  const kind = state.confirm?.kind; state.confirm=null; state.dirty=false; state.form=null; state.saveError=false; state.correctionSaved=false;
  if(kind==='back' && state.txMode){ state.txMode=null; state.txId=null; render(); } else closeSheetImmediate();
}
function confirmSave() { state.confirm=null; saveForm(); }
function attachEvidence() { const tx = selectedTx(); if(!tx) return; tx.evidence=true; persist(); toast('Evidence attached to the existing transaction (MOCK).', 'success'); render(); }
function submitSheet() { const sheetData=selectedSheet(); if(!sheetData || sheetData.status!=='OPEN') return; sheetData.status='AWAITING_COST_CENTER_REVIEW'; sheetData.firstSubmittedAt=dates.d2; sheetData.transactions.forEach((tx)=>{tx.status='AWAITING_COST_CENTER_REVIEW'; tx.decisions.costCenter='pending';}); persist(); toast('Sheet submitted (MOCK). Add transaction is now disabled.', 'success'); render(); }
function deleteSheet() { const sheetData=selectedSheet(); if(!sheetData) return; state.confirm = { title:'Delete sheet?', message:`Delete ${sheetData.title} from this local demo? This reversible demo action does not call a live API.`, allowSave:false, kind:'delete' }; render(); }
function resetDemo() { localStorage.removeItem(STORAGE_KEY); location.reload(); }

// Global interactions.
document.addEventListener('click', (event) => {
  const target = event.target.closest('[data-action]'); if (!target) return;
  const action = target.dataset.action;
  if (action === 'toggle-theme') { state.theme=state.theme==='light'?'dark':'light'; document.documentElement.dataset.theme=state.theme; persist(); render(); }
  if (action === 'toggle-calendar') { state.calendar=state.calendar==='jalali'?'gregorian':'jalali'; persist(); render(); }
  if (action === 'open-demo') { const select=document.querySelector('#demo-state'); state.demoKey=select?.value || 'open'; openSheet(demoSheetId(state.demoKey)); }
  if (action === 'open-sheet') openSheet(target.dataset.sheetId);
  if (action === 'close-workspace') requestClose('close');
  if (action === 'back-to-sheet') requestClose('back');
  if (action === 'cancel-form') requestClose('back');
  if (action === 'confirm-cancel') { state.confirm=null; render(); }
  if (action === 'confirm-discard') { if(state.confirm?.kind==='delete'){ const id=state.selectedSheetId; state.sheets=state.sheets.filter(s=>s.id!==id); persist(); toast('Sheet deleted in local demo.', 'success'); closeSheetImmediate(); } else confirmDiscard(); }
  if (action === 'confirm-save') confirmSave();
  if (action === 'set-tab') { state.tab=target.dataset.tab; render(); }
  if (action === 'start-add') { if(selectedSheet()?.status==='OPEN') enterForm('add'); }
  if (action === 'open-tx') { const tx=selectedSheet()?.transactions.find(t=>t.id===target.dataset.txId); if(tx) enterForm(tx.status==='DRAFT'?'edit-draft':tx.status==='AWAITING_PURCHASER_CORRECTION'?'correction':'view', tx); }
  if (action === 'edit-current') { enterForm('edit-draft', selectedTx()); }
  if (action === 'correct-current') { enterForm('correction', selectedTx()); }
  if (action === 'open-history') { state.txMode='history'; state.dirty=false; state.saveError=false; render(); }
  if (action === 'save-form') saveForm();
  if (action === 'resubmit-correction') resubmitCorrection();
  if (action === 'attach-evidence') attachEvidence();
  if (action === 'submit-sheet') submitSheet();
  if (action === 'delete-sheet') deleteSheet();
  if (action === 'page-prev') { state.page=Math.max(1,state.page-1); render(); }
  if (action === 'page-next') { state.page+=1; render(); }
});
document.addEventListener('input', (event) => {
  if (event.target.id === 'sheet-search') { state.search=event.target.value; state.page=1; render(); const input=document.querySelector('#sheet-search'); input?.focus(); input?.setSelectionRange(state.search.length,state.search.length); }
  const field=event.target.dataset.field; if(field && state.form) { state.form[field]=event.target.value; state.dirty=true; state.saveError=false; }
});
document.addEventListener('change', (event) => {
  if(event.target.id==='status-filter'){state.statusFilter=event.target.value;state.page=1;render();}
  if(event.target.id==='account-filter'){state.accountFilter=event.target.value;state.page=1;render();}
  if(event.target.id==='sort-order'){state.sort=event.target.value;state.page=1;render();}
  if(event.target.id==='demo-state'){state.demoKey=event.target.value;}
  const field=event.target.dataset.field; if(field && state.form) { state.form[field]=event.target.type==='checkbox'?event.target.checked:event.target.value; state.dirty=true; state.saveError=false; }
});
document.addEventListener('keydown', (event) => {
  if(event.key==='Escape' && state.selectedSheetId && !state.confirm) { requestClose(state.txMode?'back':'close'); }
  if(event.key==='Enter' && event.target.classList.contains('sheet-row')) openSheet(event.target.dataset.sheetId);
});
window.addEventListener('beforeunload', (event) => { if(state.dirty){ event.preventDefault(); event.returnValue='Unsaved changes'; } });
window.addEventListener('popstate', () => { if(state.dirty){ state.confirm={title:'Unsaved changes',message:'Your edits have not been saved. Continue editing or discard changes?',allowSave:false,kind:'close'}; render(); } else { state.selectedSheetId=null; state.txMode=null; state.txId=null; render(); } });
const initialSheetMatch = location.pathname.match(/^\/sheets\/([^/]+)/);
if (initialSheetMatch) {
  const initialId = decodeURIComponent(initialSheetMatch[1]);
  if (state.sheets.some((s) => s.id === initialId)) state.selectedSheetId = initialId;
}
const demoPathMatch = location.pathname.match(/^\/demo\/([^/]+)/);
if (demoPathMatch) state.selectedSheetId = demoSheetId(decodeURIComponent(demoPathMatch[1]));
const demoParam = new URLSearchParams(location.search).get('demo');
if (demoParam && demoSheetId(demoParam)) state.selectedSheetId = demoSheetId(demoParam);
if(!history.state && !initialSheetMatch) history.replaceState({list:true}, '', '/sheets');
render();
