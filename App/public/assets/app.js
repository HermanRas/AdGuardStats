// AdGuard Stats dashboard. All state lives in the URL; every chart click updates the URL, then the
// charts and the log reload together. Domain names are untrusted: only ever set via textContent.
(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const STATUS = { A: 'Allow', B: 'Block', D: 'Default' };
  const KEYS = ['preset', 'from', 'to', 'client', 'status', 'category', 'q'];
  const CATS = JSON.parse($('categories').textContent);
  const catBySlug = Object.fromEntries(CATS.map((c) => [c.slug, c]));
  const nf = new Intl.NumberFormat();
  const clients = new Map();

  let state = readState();
  let charts = {};
  let seq = 0;          // drops answers to superseded requests
  let nextBefore = null;

  // ---------- state <-> URL ----------
  function readState() {
    const p = new URLSearchParams(location.search);
    const s = {};
    for (const k of KEYS) if (p.get(k)) s[k] = p.get(k);
    if (!s.preset && !s.from && !s.to) s.preset = 'week';
    return s;
  }
  function query(extra = {}) {
    const p = new URLSearchParams();
    for (const k of KEYS) if (state[k]) p.set(k, state[k]);
    for (const [k, v] of Object.entries(extra)) if (v) p.set(k, v);
    return p.toString();
  }
  function go(changes, { replace = false } = {}) {
    state = { ...state, ...changes };
    for (const k of KEYS) if (state[k] === null || state[k] === undefined || state[k] === '') delete state[k];
    if (!state.preset && !state.from && !state.to) state.preset = 'week';
    const url = location.pathname + (query() ? '?' + query() : '');
    if (replace) history.replaceState(null, '', url); else history.pushState(null, '', url);
    refresh();
  }
  window.addEventListener('popstate', () => { state = readState(); refresh(); });

  // ---------- helpers ----------
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  function el(tag, props = {}, ...kids) {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'text') e.textContent = v;
      else if (k === 'class') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else if (k === 'style') Object.assign(e.style, v);
      else e.setAttribute(k, v);
    }
    for (const kid of kids) if (kid !== null && kid !== undefined) e.append(kid);
    return e;
  }
  const pct = (n, total) => (total ? (100 * n / total) : 0);
  const fmtPct = (v) => (v >= 10 || v === 0 ? v.toFixed(0) : v.toFixed(1)) + '%';
  async function getJson(path, extra) {
    const r = await fetch(path + '?' + query(extra), { headers: { Accept: 'application/json' } });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }
  const dayLabel = (d) => new Date(d + 'T12:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  const toggle = (key, val) => go({ [key]: state[key] === val ? null : val });

  // ---------- header controls ----------
  document.querySelectorAll('[data-preset]').forEach((b) =>
    b.addEventListener('click', () => go({ preset: b.dataset.preset, from: null, to: null })));
  for (const id of ['from', 'to']) {
    $(id).addEventListener('change', () => {
      let from = $('from').value || $('to').value;
      let to = $('to').value || $('from').value;
      if (!from) return;
      if (from > to) [from, to] = [to, from];
      go({ preset: null, from, to });
    });
  }
  $('client').addEventListener('change', (e) => go({ client: e.target.value }));
  let qTimer;
  $('q').addEventListener('input', (e) => {
    clearTimeout(qTimer);
    qTimer = setTimeout(() => go({ q: e.target.value.trim().toLowerCase() }, { replace: true }), 350);
  });
  $('more').addEventListener('click', () => loadLog(false));

  function syncControls() {
    document.querySelectorAll('[data-preset]').forEach((b) => b.classList.toggle('on', b.dataset.preset === state.preset));
    $('client').value = clients.has(state.client) ? state.client : '';
    if (document.activeElement !== $('q')) $('q').value = state.q || '';
  }

  async function loadClients() {
    try {
      const list = await getJson('/api/clients.php');
      const sel = $('client');
      for (const c of list) {
        clients.set(String(c.id), c);
        sel.append(el('option', { value: c.id, text: c.name === c.ip ? c.ip : `${c.name} (${c.ip})` }));
      }
      syncControls();
    } catch { /* selector stays at "All clients" */ }
  }

  // ---------- chips ----------
  function renderChips() {
    const box = $('chips');
    box.replaceChildren();
    const chips = [];
    if (state.status) chips.push(['status', 'Status: ' + STATUS[state.status]]);
    if (state.category) chips.push(['category', 'Category: ' + (catBySlug[state.category]?.name || state.category)]);
    if (state.client) {
      const c = clients.get(state.client);
      chips.push(['client', 'Client: ' + (c ? c.name : '#' + state.client)]);
    }
    if (state.q) chips.push(['q', 'Domain: ' + state.q]);
    if (state.from && state.from === state.to) chips.push(['day', 'Day: ' + state.from]);
    for (const [key, label] of chips) {
      const clear = key === 'day' ? { from: null, to: null, preset: 'week' } : { [key]: null };
      box.append(el('span', { class: 'chip' }, label,
        el('button', { type: 'button', 'aria-label': 'Remove filter ' + label, text: '×', onclick: () => go(clear) })));
    }
    if (chips.length > 1) {
      box.append(el('button', { type: 'button', class: 'chip-clear', text: 'Clear all',
        onclick: () => go({ status: null, category: null, client: null, q: null }) }));
    }
  }

  // ---------- charts ----------
  function baseOptions() {
    const grid = css('--border'), ink = css('--text-2');
    Chart.defaults.color = ink;
    Chart.defaults.font.family = css('--font');
    Chart.defaults.borderColor = grid;
    return { responsive: true, maintainAspectRatio: false, animation: { duration: 250 } };
  }

  function drawDays(series) {
    const opts = baseOptions();
    const labels = series.map((s) => dayLabel(s.day));
    const ds = ['A', 'B', 'D'].map((k) => ({
      label: STATUS[k], data: series.map((s) => s[k]), backgroundColor: css('--st-' + k),
      borderColor: css('--card'), borderWidth: { top: 2 }, borderSkipped: 'bottom',
      borderRadius: 2, stack: 's', maxBarThickness: 48,
    }));
    charts.days?.destroy();
    charts.days = new Chart($('c-days'), {
      type: 'bar',
      data: { labels, datasets: ds },
      options: {
        ...opts,
        interaction: { mode: 'nearest', intersect: true },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
          tooltip: { callbacks: { title: (it) => series[it[0].dataIndex].day, label: (c) => ` ${c.dataset.label}: ${nf.format(c.raw)}` } },
        },
        scales: {
          x: { stacked: true, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: css('--border') } },
        },
        onClick: (evt, els) => {
          if (!els.length) return;
          const day = series[els[0].index].day;
          const status = ['A', 'B', 'D'][els[0].datasetIndex];
          go({ preset: null, from: day, to: day, status });
        },
        onHover: (evt, els) => { evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
      },
    });
  }

  function drawRound(key, canvas, items, onPick) {
    const opts = baseOptions();
    charts[key]?.destroy();
    charts[key] = new Chart(canvas, {
      type: key === 'status' ? 'doughnut' : 'pie',
      data: {
        labels: items.map((i) => i.name),
        datasets: [{ data: items.map((i) => i.n), backgroundColor: items.map((i) => i.colour), borderColor: css('--card'),
          borderWidth: items.filter((i) => i.n > 0).length > 1 ? 2 : 0 }],
      },
      options: {
        ...opts,
        cutout: key === 'status' ? '62%' : 0,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => ` ${c.label}: ${nf.format(c.raw)} (${fmtPct(pct(c.raw, items.reduce((a, i) => a + i.n, 0)))})` } },
        },
        onClick: (evt, els) => { if (els.length) onPick(items[els[0].index]); },
        onHover: (evt, els) => { evt.native.target.style.cursor = els.length && items[els[0].index].pick ? 'pointer' : 'default'; },
      },
    });
  }

  function legend(ul, items, total, onPick) {
    ul.replaceChildren(...items.map((i) => el('li', {
      class: i.pick ? '' : 'static', title: i.title || '', onclick: () => i.pick && onPick(i),
    }, el('i', { class: 'sw', style: { background: i.colour } }), el('span', { class: 'name', text: i.name }),
    el('span', { class: 'num', text: `${nf.format(i.n)} · ${fmtPct(pct(i.n, total))}` }))));
  }

  async function loadSummary(my) {
    let s;
    try { s = await getJson('/api/summary.php'); } catch (e) { if (my === seq) $('log-info').textContent = 'Summary failed: ' + e.message; return; }
    if (my !== seq) return;
    if (state.preset) { $('from').value = s.filters.from; $('to').value = s.filters.to; }
    $('t-total').textContent = nf.format(s.total);
    for (const k of ['A', 'B', 'D']) {
      $('t-' + k).textContent = fmtPct(pct(s.status[k], s.total));
      $('t-' + k + '-n').textContent = nf.format(s.status[k]) + ' queries';
    }
    drawDays(s.series);

    const st = ['A', 'B', 'D'].map((k) => ({ key: k, name: STATUS[k], n: s.status[k], colour: css('--st-' + k), pick: true }));
    const pickStatus = (i) => toggle('status', i.key);
    drawRound('status', $('c-status'), st, pickStatus);
    legend($('l-status'), st, s.total, pickStatus);

    const cats = s.categories.map((c) => ({ ...c, pick: !!c.slug, title: c.slug ? '' : 'Smaller categories, combined' }));
    const pickCat = (i) => i.slug && toggle('category', i.slug);
    drawRound('cats', $('c-cats'), cats, pickCat);
    legend($('l-cats'), cats, s.total, pickCat);
  }

  // ---------- log ----------
  function logRow(r) {
    const [d, t] = r.time.split(' ');
    const sub = r.fqdn !== r.base && r.fqdn.endsWith('.' + r.base) ? r.fqdn.slice(0, -r.base.length) : '';
    const why = [r.reason, r.rule].filter(Boolean).join(' · ');
    return el('tr', { class: 'row-' + r.status },
      el('td', { class: 't' }, el('span', { class: 'd', text: d + ' ' }), t),
      el('td', { class: 'dom', title: `${r.fqdn} · ${r.qtype} · ${r.proto}` }, sub ? el('span', { class: 'sub', text: sub }) : null, r.base),
      el('td', { class: 'cl', title: r.client.ip }, el('a', { href: '?' + query({ client: r.client.id }), text: r.client.name,
        onclick: (e) => { e.preventDefault(); go({ client: String(r.client.id) }); } })),
      el('td', {}, el('span', { class: 'cat', title: r.category.source, onclick: () => toggle('category', r.category.slug) },
        el('i', { style: { background: r.category.colour } }), r.category.name)),
      el('td', {}, el('span', { class: 'pill pill-' + r.status, title: why, onclick: () => toggle('status', r.status), text: STATUS[r.status] })),
    );
  }

  async function loadLog(reset) {
    const my = seq;
    const body = $('log-body');
    $('more').hidden = true;
    let j;
    try {
      j = await getJson('/api/log.php', reset ? {} : { before: nextBefore });
    } catch (e) {
      if (my === seq) $('log-info').textContent = 'Log failed: ' + e.message;
      return;
    }
    if (my !== seq) return;
    if (reset) body.replaceChildren();
    body.append(...j.rows.map(logRow));
    if (!body.children.length) body.append(el('tr', {}, el('td', { colspan: 5, class: 'empty', text: 'No queries match these filters.' })));
    nextBefore = j.next_before;
    $('more').hidden = !nextBefore;
    const shown = body.querySelectorAll('tr:not(:has(.empty))').length;
    $('log-info').textContent = shown ? `Showing ${nf.format(shown)} newest` : '';
  }

  function refresh() {
    const my = ++seq;
    nextBefore = null;
    syncControls();
    // Presets show their resolved dates once the summary answers; custom ranges show as typed.
    if (state.from) { $('from').value = state.from; $('to').value = state.to || state.from; }
    renderChips();
    loadSummary(my);
    loadLog(true);
  }

  loadClients().then(renderChips);
  refresh();
  // Light/dark switch on the OS: redraw charts with the new palette.
  matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => refresh());
})();
