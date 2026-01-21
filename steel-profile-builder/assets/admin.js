(function () {
  function safeJSON(str, fallback) {
    try { return JSON.parse(str); } catch (e) { return fallback; }
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function renderDimsTable() {
    const table = $('#spb-dims-table');
    const hidden = $('#spb_dims_json');
    if (!table || !hidden) return;

    let dims = safeJSON(hidden.value || '[]', []);
    if (!Array.isArray(dims)) dims = [];

    const tbody = table.querySelector('tbody');
    tbody.innerHTML = '';

    dims.forEach((d, idx) => {
      const tr = document.createElement('tr');

      const key = d.key || '';
      const type = (d.type === 'angle') ? 'angle' : 'length';
      const label = d.label || key;
      const min = (d.min ?? '');
      const max = (d.max ?? '');
      const def = (d.def ?? '');
      const dir = (String(d.dir || 'L').toUpperCase() === 'R') ? 'R' : 'L';
      const pol = (d.pol === 'outer') ? 'outer' : 'inner';

      tr.innerHTML = `
        <td><input type="text" data-k="key" value="${esc(key)}" style="width:100%"></td>
        <td>
          <select data-k="type" style="width:100%">
            <option value="length" ${type === 'length' ? 'selected' : ''}>length</option>
            <option value="angle" ${type === 'angle' ? 'selected' : ''}>angle</option>
          </select>
        </td>
        <td><input type="text" data-k="label" value="${esc(label)}" style="width:100%"></td>
        <td><input type="number" data-k="min" value="${esc(min)}" style="width:100%"></td>
        <td><input type="number" data-k="max" value="${esc(max)}" style="width:100%"></td>
        <td><input type="number" data-k="def" value="${esc(def)}" style="width:100%"></td>
        <td>
          <select data-k="dir" style="width:100%">
            <option value="L" ${dir === 'L' ? 'selected' : ''}>L</option>
            <option value="R" ${dir === 'R' ? 'selected' : ''}>R</option>
          </select>
        </td>
        <td>
          <select data-k="pol" style="width:100%" ${type === 'angle' ? '' : 'disabled'}>
            <option value="inner" ${pol === 'inner' ? 'selected' : ''}>Seest</option>
            <option value="outer" ${pol === 'outer' ? 'selected' : ''}>Väljast</option>
          </select>
        </td>
        <td><button type="button" class="button spb-del" data-i="${idx}">X</button></td>
      `;

      tbody.appendChild(tr);
    });

    // Update hidden JSON on changes
    tbody.oninput = tbody.onchange = function () {
      const rows = Array.from(tbody.querySelectorAll('tr'));
      dims = rows.map((tr) => {
        const get = (k) => tr.querySelector(`[data-k="${k}"]`);
        const type = get('type').value === 'angle' ? 'angle' : 'length';

        return {
          key: (get('key').value || '').trim(),
          type,
          label: (get('label').value || '').trim(),
          min: valOrNull(get('min').value),
          max: valOrNull(get('max').value),
          def: valOrNull(get('def').value),
          dir: (get('dir').value === 'R') ? 'R' : 'L',
          pol: (type === 'angle') ? (get('pol').value === 'outer' ? 'outer' : 'inner') : null,
        };
      }).filter(x => x.key);

      // enable/disable pol field based on type
      rows.forEach((tr) => {
        const type = tr.querySelector('[data-k="type"]').value;
        const polSel = tr.querySelector('[data-k="pol"]');
        if (polSel) polSel.disabled = (type !== 'angle');
      });

      hidden.value = JSON.stringify(dims);
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
    };

    // Delete row
    tbody.onclick = function (e) {
      const btn = e.target.closest('.spb-del');
      if (!btn) return;
      const i = Number(btn.dataset.i);
      dims.splice(i, 1);
      hidden.value = JSON.stringify(dims);
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      renderDimsTable();
    };
  }

  function renderMaterialsTable() {
    const table = $('#spb-materials-table');
    const hidden = $('#spb_materials_json');
    if (!table || !hidden) return;

    let mats = safeJSON(hidden.value || '[]', []);
    if (!Array.isArray(mats)) mats = [];

    const tbody = table.querySelector('tbody');
    tbody.innerHTML = '';

    mats.forEach((m, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><input type="text" data-k="key" value="${esc(m.key || '')}" style="width:100%"></td>
        <td><input type="text" data-k="label" value="${esc(m.label || '')}" style="width:100%"></td>
        <td><input type="number" step="0.01" data-k="eur_m2" value="${esc(m.eur_m2 ?? '')}" style="width:100%"></td>
        <td><button type="button" class="button spb-mdel" data-i="${idx}">X</button></td>
      `;
      tbody.appendChild(tr);
    });

    tbody.oninput = tbody.onchange = function () {
      const rows = Array.from(tbody.querySelectorAll('tr'));
      mats = rows.map((tr) => {
        const key = (tr.querySelector('[data-k="key"]').value || '').trim();
        if (!key) return null;
        return {
          key,
          label: (tr.querySelector('[data-k="label"]').value || '').trim(),
          eur_m2: Number(tr.querySelector('[data-k="eur_m2"]').value || 0),
        };
      }).filter(Boolean);

      hidden.value = JSON.stringify(mats);
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
    };

    tbody.onclick = function (e) {
      const btn = e.target.closest('.spb-mdel');
      if (!btn) return;
      const i = Number(btn.dataset.i);
      mats.splice(i, 1);
      hidden.value = JSON.stringify(mats);
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      renderMaterialsTable();
    };
  }

  function esc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function valOrNull(v) {
    if (v === '' || v == null) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderDimsTable();
    renderMaterialsTable();

    const addDim = $('#spb-add-dim');
    if (addDim) {
      addDim.addEventListener('click', function () {
        const hidden = $('#spb_dims_json');
        let dims = safeJSON(hidden.value || '[]', []);
        if (!Array.isArray(dims)) dims = [];
        dims.push({ key: 's' + (dims.length + 1), type: 'length', label: 's' + (dims.length + 1), min: 10, max: 500, def: 50, dir: 'L' });
        hidden.value = JSON.stringify(dims);
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        renderDimsTable();
      });
    }

    const addMat = $('#spb-add-material');
    if (addMat) {
      addMat.addEventListener('click', function () {
        const hidden = $('#spb_materials_json');
        let mats = safeJSON(hidden.value || '[]', []);
        if (!Array.isArray(mats)) mats = [];
        mats.push({ key: 'NEW', label: 'Uus materjal', eur_m2: 0 });
        hidden.value = JSON.stringify(mats);
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        renderMaterialsTable();
      });
    }
  });
})();
