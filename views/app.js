/**
 * ticket-scraper — Frontend JS
 *
 * Gestiona la búsqueda AJAX, el renderizado de resultados,
 * los filtros en tiempo real y la interacción de la UI.
 */

'use strict';

// ── DOM refs ────────────────────────────────────────────────────────────
const input   = document.getElementById('urlInput');
const btn     = document.getElementById('btnSearch');
const stateEl = document.getElementById('state');


// ── Helpers ─────────────────────────────────────────────────────────────

/** Escapa caracteres HTML para evitar XSS */
function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Formatea un número como precio en USD */
function fmt(n) {
  return '$' + Number(n).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

// ── State renderers ─────────────────────────────────────────────────────

function setLoading() {
  btn.disabled = true;
  stateEl.innerHTML = `
    <div class="loader">
      <div class="spinner"></div>
      <p>Consultando disponibilidad...</p>
    </div>`;
}

function setError(msg) {
  btn.disabled = false;
  stateEl.innerHTML = `
    <div class="error-box">
      <div class="icon">⚠️</div>
      <div>
        <h3>Error al obtener las entradas</h3>
        <p>${esc(msg)}</p>
      </div>
    </div>`;
}

function setIdle() {
  btn.disabled = false;
  stateEl.innerHTML = `
    <div class="state-idle">
      <div class="icon">🎭</div>
      <p>Introduce la URL de un evento para empezar</p>
    </div>`;
}

// ── Results renderer ────────────────────────────────────────────────────

function renderResults(data) {
  btn.disabled = false;

if (!data.total) {
    stateEl.innerHTML = '<div class="no-results">⚠ No se encontraron entradas disponibles.</div>';
    return;
  }

  stateEl.innerHTML = [
    renderHeader(data),
    data.stats ? renderStats(data.stats) : '',
    renderFilterBar(),
    '<div id="sectorsWrap">',
    renderSectors(data.grouped),
    '</div>',
  ].join('');

  attachFilterListeners();
}

function renderHeader(data) {
  return `
    <div class="results-header">
      <h2>Entradas disponibles</h2>
      <span class="platform-badge">${esc(data.platform)}</span>
      <span class="total-badge">${data.total} listings</span>
    </div>`;
}

function renderStats(stats) {
  return `
    <div class="stats-bar">
      <div class="stat-card min">
        <span class="label">Precio mínimo</span>
        <span class="value">${fmt(stats.min)}</span>
      </div>
      <div class="stat-card avg">
        <span class="label">Precio medio</span>
        <span class="value">${fmt(stats.avg)}</span>
      </div>
      <div class="stat-card max">
        <span class="label">Precio máximo</span>
        <span class="value">${fmt(stats.max)}</span>
      </div>
    </div>`;
}

function renderFilterBar() {
  return `
    <div class="filter-bar">
      <label>Filtrar</label>
      <input id="filterSector"   class="filter-input" placeholder="Sector..."     autocomplete="off">
      <input id="filterMaxPrice" class="filter-input" placeholder="Precio máx. $" type="number" min="0">
    </div>`;
}

function renderSectors(grouped) {
  return Object.entries(grouped).map(([sector, rows]) => {
    const sectorCount = Object.values(rows).reduce((s, ts) => s + ts.length, 0);
    const rowsHtml    = renderRows(rows);

    return `
      <div class="sector-block" data-sector="${esc(sector.toLowerCase())}">
        <div class="sector-header" onclick="toggleSector(this)">
          <div class="sector-dot"></div>
          <span class="sector-name">${esc(sector)}</span>
          <span class="sector-count">${sectorCount} listing${sectorCount !== 1 ? 's' : ''}</span>
          <span class="chevron">▾</span>
        </div>
        <div class="sector-body">
          <table>
            <thead>
              <tr>
                <th>Fila</th><th>Precio</th><th>Cantidad</th><th>Notas</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
      </div>`;
  }).join('');
}

function renderRows(rows) {
  return Object.entries(rows).map(([row, tickets]) => {
    const label    = row !== 'N/A' ? `Fila ${esc(row)}` : '(Sin fila)';
    const ticketRows = tickets.map(t => `
      <tr class="ticket-row" data-price="${t.price}">
        <td class="row-label">${label}</td>
        <td class="price">${fmt(t.price)}</td>
        <td class="qty">${t.quantity} ud${t.quantity !== 1 ? 's' : ''}.</td>
        <td class="notes">${esc(t.notes || '—')}</td>
      </tr>`).join('');

    return `<tr class="row-sep"><td colspan="4">${label}</td></tr>${ticketRows}`;
  }).join('');
}

// ── Filters ─────────────────────────────────────────────────────────────

function attachFilterListeners() {
  document.getElementById('filterSector')  ?.addEventListener('input', applyFilter);
  document.getElementById('filterMaxPrice')?.addEventListener('input', applyFilter);
}

function applyFilter() {
  const sectorQ  = document.getElementById('filterSector').value.toLowerCase().trim();
  const maxPrice = parseFloat(document.getElementById('filterMaxPrice').value) || Infinity;

  document.querySelectorAll('.sector-block').forEach(block => {
    const sectorMatch = !sectorQ || block.dataset.sector.includes(sectorQ);

    let visibleRows = 0;

    block.querySelectorAll('tr.ticket-row').forEach(tr => {
      const visible = sectorMatch && parseFloat(tr.dataset.price) <= maxPrice;
      tr.style.display = visible ? '' : 'none';
      if (visible) visibleRows++;
    });

    // Oculta separadores de fila sin tickets visibles
    block.querySelectorAll('tr.row-sep').forEach(sep => {
      let next = sep.nextElementSibling;
      let hasVisible = false;
      while (next && !next.classList.contains('row-sep')) {
        if (next.style.display !== 'none') { hasVisible = true; break; }
        next = next.nextElementSibling;
      }
      sep.style.display = hasVisible ? '' : 'none';
    });

    block.style.display = visibleRows > 0 ? '' : 'none';
  });
}

// ── Sector toggle ────────────────────────────────────────────────────────

function toggleSector(header) {
  header.closest('.sector-block').classList.toggle('collapsed');
}

// ── Fetch ────────────────────────────────────────────────────────────────

async function doSearch() {
  const url = input.value.trim();
  if (!url) { input.focus(); return; }

  setLoading();

  try {
    const fd = new FormData();
    fd.append('url', url);

    const res  = await fetch('api.php', {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body:    fd,
    });

    const data = await res.json();

    if (!res.ok || data.error) {
      setError(data.error || `HTTP ${res.status}`);
    } else {
      renderResults(data);
    }
  } catch (err) {
    setError('Error de red: ' + err.message);
  }
}

// ── Event listeners ──────────────────────────────────────────────────────

btn.addEventListener('click', doSearch);
input.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
