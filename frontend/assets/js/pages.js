/* SmartBook secondary screens: products, customers, reports, staff,
 * suppliers, expenses, analytics, subscription, settings, audit, sync,
 * backup, receipt. Every button here calls a real endpoint. */

const Pages = {
  /* ------------------------------------------------------- PRODUCTS */
  async products(el, filter) {
    await App.loadProducts();
    const cats = await API.get('products/categories');
    const catList = cats.ok ? cats.data : [];
    const f = filter || '';
    const rows = App.products.filter((p) => !f || p.stock_status === f);

    el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h1 class="h5 mb-0">Products</h1>
      <a href="#/product/new" class="btn btn-sb btn-sm">＋ Add product</a>
    </div>
    <div class="input-group mb-2">
      <input class="form-control" id="psearch" placeholder="Search name, SKU or barcode">
      <select class="form-select" id="pfilter" style="max-width:150px">
        <option value="">All stock</option>
        <option value="low" ${f === 'low' ? 'selected' : ''}>Low stock</option>
        <option value="out" ${f === 'out' ? 'selected' : ''}>Out of stock</option>
      </select>
    </div>
    <div class="sb-card p-0">
      <div class="sb-table-wrap"><table class="sb-table"><thead><tr>
        <th>Product</th><th class="num">Price</th><th class="num">Stock</th><th></th>
      </tr></thead><tbody id="ptable"></tbody></table></div>
    </div>
    <div class="sb-card">
      <h3>Categories</h3>
      <div class="d-flex flex-wrap gap-2 mb-2">
        ${catList.length ? catList.map((c) => `<span class="badge-soft bg-info-soft">${esc(c.name)} (${c.products})</span>`).join('') : '<span class="small text-muted">No categories yet.</span>'}
      </div>
      <form id="cat-form" class="d-flex gap-2">
        <input class="form-control form-control-sm" name="name" placeholder="New category" required>
        <button class="btn btn-sm btn-outline-secondary">Add</button>
      </form>
    </div>`;

    const draw = (needle) => {
      const n = (needle || '').toLowerCase();
      const list = rows.filter((p) => !n || p.name.toLowerCase().includes(n) || (p.sku || '').toLowerCase().includes(n) || (p.barcode || '').toLowerCase().includes(n));
      document.getElementById('ptable').innerHTML = list.length ? list.map((p) => `
        <tr onclick="location.hash='#/product/${p.id || p.client_uuid}'" style="cursor:pointer">
          <td><div class="fw-semibold">${esc(p.name)}</div>
            <div class="small text-muted">${esc(p.category || 'Uncategorised')}${p.barcode ? ' · ' + esc(p.barcode) : ''}</div></td>
          <td class="num">${money(p.selling_price)}</td>
          <td class="num"><span class="badge-soft ${p.stock_status === 'out' ? 'bg-out' : p.stock_status === 'low' ? 'bg-low' : 'bg-ok'}">
            ${Number(p.stock_qty)} ${esc(p.unit || '')}</span></td>
          <td class="num text-muted">›</td>
        </tr>`).join('') : `<tr><td colspan="4"><div class="empty">No products yet. Tap “Add product”.</div></td></tr>`;
    };
    draw('');
    document.getElementById('psearch').addEventListener('input', (e) => draw(e.target.value));
    document.getElementById('pfilter').addEventListener('change', (e) => { location.hash = e.target.value ? '#/products/' + e.target.value : '#/products'; });
    document.getElementById('cat-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const res = await API.post('products/categories', { name: e.target.name.value, client_uuid: SBDB.idbUUID() });
      if (res.ok) { App.toast('Category added.', 'ok'); Pages.products(el, filter); }
      else App.toast((res.error && res.error.message) || 'Could not add category.', 'err');
    });
  },

  async productForm(el, arg) {
    const isNew = arg === 'new' || !arg;
    let product = { is_active: 1, unit: 'pcs', min_stock: 0 };
    if (!isNew) {
      const res = await API.get('products/get', { id: arg });
      if (res.ok) product = res.data;
      else {
        const local = (await SBDB.all('products')).find((p) => String(p.id) === String(arg) || String(p.client_uuid) === String(arg));
        if (local) product = local;
      }
    }
    const cats = await API.get('products/categories');
    const catList = cats.ok ? cats.data : [];
    const movements = product.movements || [];

    el.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <a href="#/products" class="btn btn-sm btn-outline-secondary">Back</a>
      <h1 class="h5 mb-0">${isNew ? 'Add product' : esc(product.name)}</h1>
    </div>
    <form id="prod-form" class="sb-card">
      <div class="mb-2"><label class="form-label">Product name</label>
        <input class="form-control" name="name" value="${esc(product.name || '')}" required></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">Selling price</label>
          <input class="form-control" name="selling_price" type="number" step="0.01" min="0" value="${Number(product.selling_price || 0)}" required></div>
        <div class="col-6"><label class="form-label">Purchase cost</label>
          <input class="form-control" name="purchase_price" type="number" step="0.01" min="0" value="${Number(product.purchase_price || 0)}"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">${isNew ? 'Opening quantity' : 'Unit'}</label>
          ${isNew
            ? `<input class="form-control" name="opening_qty" type="number" step="0.001" value="0">`
            : `<input class="form-control" name="unit" value="${esc(product.unit || 'pcs')}">`}</div>
        <div class="col-6"><label class="form-label">${isNew ? 'Unit' : 'Minimum stock'}</label>
          ${isNew
            ? `<input class="form-control" name="unit" value="pcs">`
            : `<input class="form-control" name="min_stock" type="number" step="0.001" value="${Number(product.min_stock || 0)}">`}</div>
      </div>
      ${!isNew ? `<div class="row g-2 mb-2"><div class="col-6">
          <label class="form-label">Current stock</label>
          <input class="form-control" value="${Number(product.stock_qty)} ${esc(product.unit || '')}" disabled>
          <div class="form-text">Change stock with an adjustment below, so the movement history stays correct.</div>
        </div><div class="col-6"><label class="form-label">Minimum stock</label>
          <input class="form-control" name="min_stock" type="number" step="0.001" value="${Number(product.min_stock || 0)}"></div></div>` : ''}
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">Category</label>
          <select class="form-select" name="category_id">
            <option value="">None</option>
            ${catList.map((c) => `<option value="${c.id}" ${Number(product.category_id) === Number(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
          </select></div>
        <div class="col-6"><label class="form-label">Barcode</label>
          <input class="form-control" name="barcode" value="${esc(product.barcode || '')}"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">SKU / code</label>
          <input class="form-control" name="sku" value="${esc(product.sku || '')}"></div>
        <div class="col-6"><label class="form-label">Expiry date</label>
          <input class="form-control" name="expiry_date" type="date" value="${product.expiry_date || ''}"></div>
      </div>
      <div class="mb-2"><label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="2">${esc(product.description || '')}</textarea></div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" ${product.is_active !== 0 ? 'checked' : ''}>
        <label class="form-check-label small" for="is_active">Active (available at the till)</label>
      </div>
      <button class="btn btn-sb w-100 btn-lg-tap" type="submit">${isNew ? 'Save product' : 'Save changes'}</button>
      ${!isNew ? `<button class="btn btn-outline-danger w-100 mt-2" type="button" id="btn-del">Remove product</button>` : ''}
    </form>

    ${!isNew ? `<div class="sb-card">
      <h3>Adjust stock</h3>
      <form id="adj-form" class="row g-2">
        <div class="col-4"><input class="form-control" name="qty_change" type="number" step="0.001" placeholder="+ / -" required></div>
        <div class="col-8"><select class="form-select" name="movement_type">
          <option value="purchase">Stock received</option><option value="adjustment">Correction</option>
          <option value="loss">Damaged / lost</option><option value="return">Customer return</option>
        </select></div>
        <div class="col-12"><input class="form-control" name="reason" placeholder="Reason (e.g. expired, recounted)"></div>
        <div class="col-12"><button class="btn btn-sb-green w-100">Record adjustment</button></div>
      </form>
    </div>
    <div class="sb-card">
      <h3>Stock movement history</h3>
      ${movements.length ? `<table class="sb-table"><thead><tr><th>Type</th><th class="num">Change</th><th>Reason</th><th>When</th></tr></thead><tbody>
        ${movements.map((m) => `<tr><td>${esc(m.movement_type)}</td>
          <td class="num ${Number(m.qty_change) < 0 ? 'text-danger' : 'text-success'}">${Number(m.qty_change) > 0 ? '+' : ''}${Number(m.qty_change)}</td>
          <td class="small">${esc(m.reason || m.reference || '')}</td>
          <td class="small text-muted">${dt(m.created_at)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">No movements recorded yet.</div>'}
    </div>` : ''}`;

    document.getElementById('prod-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = { client_uuid: product.client_uuid || SBDB.idbUUID() };
      fd.forEach((v, k) => { payload[k] = v; });
      payload.is_active = e.target.is_active.checked;
      if (!isNew) payload.id = product.id;
      const res = await API.post('products', payload);
      if (res.ok) {
        await Pages.cacheProduct(res.data.product || res.data);
        App.toast('Product saved.', 'ok');
        location.hash = '#/products';
      } else if (res.offline) {
        await SBDB.put('products', Object.assign({ client_uuid: payload.client_uuid, id: product.id || null, stock_qty: Number(payload.opening_qty || product.stock_qty || 0) }, payload));
        await SBDB.enqueue({ entity: 'products', action: 'save', payload });
        App.toast('Saved on this phone. It will sync when online.', 'ok');
        location.hash = '#/products';
      } else {
        App.toast((res.error && res.error.message) || 'Could not save product.', 'err');
      }
    });

    const adj = document.getElementById('adj-form');
    if (adj) {
      adj.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = {
          client_uuid: SBDB.idbUUID(),
          product_id: product.id,
          qty_change: Number(fd.get('qty_change')),
          movement_type: fd.get('movement_type'),
          reason: fd.get('reason')
        };
        const res = await API.post('stock', payload);
        if (res.ok) { App.toast('Stock updated.', 'ok'); Pages.productForm(el, product.id); }
        else if (res.offline) {
          await SBDB.enqueue({ entity: 'stock_movements', action: 'create', payload });
          App.toast('Adjustment saved offline. It will sync.', 'ok');
        } else App.toast((res.error && res.error.message) || 'Could not adjust stock.', 'err');
      });
    }
    const del = document.getElementById('btn-del');
    if (del) {
      del.addEventListener('click', async () => {
        if (!confirm('Remove this product from the till? Past sales are kept.')) return;
        const res = await API.post('products/delete', { id: product.id });
        if (res.ok) { App.toast('Product removed.', 'ok'); location.hash = '#/products'; }
        else App.toast('Could not remove product.', 'err');
      });
    }
  },

  async cacheProduct(p) {
    if (p && p.client_uuid) await SBDB.put('products', p);
  },

  /* ------------------------------------------------------ CUSTOMERS */
  async customers(el, arg) {
    const res = await API.get('customers');
    const rows = res.ok ? res.data : [];
    const owing = rows.filter((c) => Number(c.balance) > 0);
    const total = owing.reduce((s, c) => s + Number(c.balance), 0);

    el.innerHTML = `
    <h1 class="h5 mb-2">Customers & credit</h1>
    <div class="grid-2 mb-2">
      <div class="sb-card sb-stat accent-red"><div class="label">Owed to you</div><div class="value sm">${money(total)}</div>
        <div class="sub">${owing.length} customer(s)</div></div>
      <div class="sb-card sb-stat accent-blue"><div class="label">Customers</div><div class="value sm">${rows.length}</div>
        <div class="sub">registered</div></div>
    </div>
    <button class="btn btn-sb w-100 mb-2" id="btn-new-cust">＋ Add customer</button>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Customer</th><th class="num">Owes</th><th></th></tr></thead><tbody>
      ${rows.length ? rows.map((c) => `<tr>
        <td><div class="fw-semibold">${esc(c.name)}</div><div class="small text-muted">${esc(c.phone || '')}</div></td>
        <td class="num ${Number(c.balance) > 0 ? 'text-danger fw-bold' : ''}">${money(c.balance)}</td>
        <td class="num"><button class="btn btn-sm btn-outline-secondary" data-ledger="${c.id}">Ledger</button></td>
      </tr>`).join('') : '<tr><td colspan="3"><div class="empty">No customers yet.</div></td></tr>'}
    </tbody></table></div></div>
    <div id="ledger-box"></div>`;

    document.getElementById('btn-new-cust').addEventListener('click', () => Pages.customerForm(el));
    el.querySelectorAll('[data-ledger]').forEach((b) => b.addEventListener('click', () => Pages.ledger(el, b.dataset.ledger)));
  },

  customerForm(el) {
    el.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <a href="#/customers" class="btn btn-sm btn-outline-secondary">Back</a><h1 class="h5 mb-0">Add customer</h1>
    </div>
    <form id="cust-form" class="sb-card">
      <div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="name" required></div>
      <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" inputmode="tel"></div>
      <div class="mb-2"><label class="form-label">Address / village</label><input class="form-control" name="address"></div>
      <div class="mb-3"><label class="form-label">Credit limit (optional)</label>
        <input class="form-control" name="credit_limit" type="number" step="1000" value="0"></div>
      <button class="btn btn-sb-green w-100 btn-lg-tap">Save customer</button>
    </form>`;
    document.getElementById('cust-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = { client_uuid: SBDB.idbUUID() };
      fd.forEach((v, k) => { payload[k] = v; });
      const res = await API.post('customers', payload);
      if (res.ok) { await SBDB.put('customers', res.data); App.toast('Customer saved.', 'ok'); location.hash = '#/customers'; }
      else if (res.offline) {
        await SBDB.put('customers', Object.assign({ balance: 0 }, payload));
        await SBDB.enqueue({ entity: 'customers', action: 'create', payload });
        App.toast('Saved offline.', 'ok'); location.hash = '#/customers';
      } else App.toast((res.error && res.error.message) || 'Could not save.', 'err');
    });
  },

  async ledger(el, id) {
    const res = await API.get('customers/ledger', { id });
    const box = document.getElementById('ledger-box');
    if (!res.ok) { box.innerHTML = '<div class="sync-banner offline">Ledger needs a connection.</div>'; return; }
    const c = res.data;
    box.innerHTML = `
    <div class="sb-card">
      <div class="d-flex justify-content-between align-items-start">
        <div><h3 class="mb-0">${esc(c.name)}</h3><div class="small text-muted">${esc(c.phone || '')}</div></div>
        <div class="text-end"><div class="small text-muted">Balance</div><div class="fw-bold fs-5 ${Number(c.balance) > 0 ? 'text-danger' : 'text-success'}">${money(c.balance)}</div></div>
      </div>
      <form id="pay-form" class="row g-2 mt-3">
        <div class="col-7"><input class="form-control" name="amount" type="number" step="100" placeholder="Amount paid" required></div>
        <div class="col-5"><button class="btn btn-sb-green w-100">Record payment</button></div>
        <div class="col-12"><input class="form-control form-control-sm" name="note" placeholder="Note (optional)"></div>
      </form>
      <div class="mt-3">
        <div class="sec-title">Ledger</div>
        ${(c.ledger || []).length ? `<table class="sb-table"><thead><tr><th>Date</th><th>Entry</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead><tbody>
          ${c.ledger.map((l) => `<tr><td class="small text-muted">${dt(l.occurred_at)}</td>
            <td class="small">${esc(l.entry_type)}${l.note ? ' · ' + esc(l.note) : ''}</td>
            <td class="num">${money(l.amount)}</td><td class="num">${money(l.balance_after)}</td></tr>`).join('')}
        </tbody></table>` : '<div class="small text-muted">No credit activity yet.</div>'}
      </div>
      <div class="mt-3 d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm flex-fill" href="https://wa.me/${encodeURIComponent((c.phone || '').replace(/\\D/g, ''))}?text=${encodeURIComponent('Hello ' + c.name + ', this is a friendly reminder that your balance is UGX ' + Number(c.balance).toLocaleString() + '. Thank you.')}" target="_blank" rel="noopener">WhatsApp reminder</a>
        <a class="btn btn-outline-secondary btn-sm flex-fill" href="sms:${esc(c.phone || '')}?body=${encodeURIComponent('Reminder: your balance is UGX ' + Number(c.balance).toLocaleString())}">SMS reminder</a>
      </div>
    </div>`;
    document.getElementById('pay-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = {
        client_uuid: SBDB.idbUUID(), customer_id: c.id,
        amount: Number(fd.get('amount')), note: fd.get('note') || 'Credit payment'
      };
      const r = await API.post('customers/pay', payload);
      if (r.ok) { App.toast('Payment recorded.', 'ok'); Pages.ledger(el, id); Pages.customersRefresh(); }
      else if (r.offline) {
        await SBDB.enqueue({ entity: 'credit_payments', action: 'create', payload: { client_uuid: payload.client_uuid, customer_id: c.id, amount: payload.amount, note: payload.note, paid_at: new Date().toISOString() } });
        App.toast('Payment saved offline.', 'ok');
      } else App.toast((r.error && r.error.message) || 'Could not record payment.', 'err');
    });
  },

  customersRefresh() {},

  /* -------------------------------------------------------- REPORTS */
  async reports(el) {
    const period = localStorage.getItem('sb_report_period') || 'month';
    const res = await API.get('reports', { period });
    if (!res.ok) { el.innerHTML = '<div class="sync-banner offline">Reports need a connection. Sales recorded offline are safe and will appear once synced.</div>'; return; }
    const r = res.data;
    const t = r.totals;
    const maxDay = Math.max(1, ...(r.by_day || []).map((d) => Number(d.revenue)));

    el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h1 class="h5 mb-0">Reports</h1>
      <a class="btn btn-sm btn-sb" href="${API.BASE}/reports/export?period=${period}">Download CSV</a>
    </div>
    <div class="btn-group w-100 mb-2" id="period-group">
      ${['today', 'week', 'month', 'year'].map((p) => `<input type="radio" class="btn-check" name="per" id="per-${p}" value="${p}" ${p === period ? 'checked' : ''}>
        <label class="btn btn-outline-secondary btn-sm" for="per-${p}">${p[0].toUpperCase() + p.slice(1)}</label>`).join('')}
    </div>
    <div class="small text-muted mb-2">${r.period.from} to ${r.period.to}</div>

    <div class="sb-card">
      <h3>Financial summary</h3>
      <table class="sb-table"><tbody>
        <tr><td>Sales revenue</td><td class="num fw-bold">${money(t.revenue)}</td></tr>
        <tr><td>Cash actually received</td><td class="num">${money(t.cash_received)}</td></tr>
        <tr><td>Credit given (unpaid)</td><td class="num text-danger">${money(t.credit_given)}</td></tr>
        <tr><td>Cost of goods sold</td><td class="num">${money(t.cogs)}</td></tr>
        <tr><td>Gross profit</td><td class="num text-success fw-bold">${money(t.gross_profit)}</td></tr>
        <tr><td>Operating expenses</td><td class="num text-danger">${money(t.expenses)}</td></tr>
        <tr class="border-top"><td class="fw-bold">Net profit</td><td class="num fw-bold ${t.net_profit >= 0 ? 'text-success' : 'text-danger'}">${money(t.net_profit)}</td></tr>
        <tr><td>Transactions</td><td class="num">${t.transactions}</td></tr>
      </tbody></table>
    </div>

    <div class="sb-card">
      <h3>Daily sales</h3>
      ${(r.by_day || []).length ? `<div class="sparkline">${r.by_day.map((d) => `<span style="height:${Math.max(4, (Number(d.revenue) / maxDay) * 56)}px" title="${d.day}: ${money(d.revenue)}"></span>`).join('')}</div>
        <table class="sb-table mt-2"><thead><tr><th>Day</th><th class="num">Revenue</th><th class="num">Sales</th></tr></thead><tbody>
        ${r.by_day.map((d) => `<tr><td class="small">${d.day}</td><td class="num">${money(d.revenue)}</td><td class="num">${d.transactions}</td></tr>`).join('')}
        </tbody></table>` : '<div class="empty">No sales in this period.</div>'}
    </div>

    <div class="sb-card">
      <h3>Top products</h3>
      ${(r.top_products || []).length ? `<table class="sb-table"><tbody>
        ${r.top_products.map((p) => `<tr><td>${esc(p.product_name)}</td><td class="num">${Number(p.qty)}</td><td class="num">${money(p.revenue)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">—</div>'}
    </div>

    <div class="sb-card">
      <h3>Payment methods</h3>
      ${(r.by_payment || []).length ? `<table class="sb-table"><tbody>
        ${r.by_payment.map((p) => `<tr><td>${esc(p.payment_method)}</td><td class="num">${p.transactions}</td><td class="num">${money(p.total)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">—</div>'}
    </div>

    <div class="sb-card">
      <h3>Outstanding debts</h3>
      ${(r.debts || []).length ? `<table class="sb-table"><tbody>
        ${r.debts.map((d) => `<tr><td>${esc(d.name)}<div class="small text-muted">${esc(d.phone || '')}</div></td><td class="num text-danger">${money(d.balance)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">Nobody owes you.</div>'}
    </div>`;

    document.querySelectorAll('input[name=per]').forEach((r2) => r2.addEventListener('change', (e) => {
      localStorage.setItem('sb_report_period', e.target.value);
      Pages.reports(el);
    }));
  },

  /* ------------------------------------------------------ ANALYTICS */
  async analytics(el) {
    const res = await API.get('analytics');
    if (!res.ok) { el.innerHTML = '<div class="sync-banner offline">Smart analytics needs a connection.</div>'; return; }
    const a = res.data;
    el.innerHTML = `
    <h1 class="h5 mb-2">Smart analytics</h1>
    <div class="sb-card">
      <h3>What to do next</h3>
      <ul class="mb-0 ps-3">${(a.recommendations || []).map((r) => `<li class="mb-1 small">${esc(r)}</li>`).join('')}</ul>
      <div class="small text-muted mt-2">Engine: ${esc(a.engine || 'python')} · window: ${a.window_days} days</div>
    </div>
    <div class="grid-2">
      <div class="sb-card sb-stat accent-blue"><div class="label">Next 7 days</div>
        <div class="value sm">${money(a.forecast_next_7d ? a.forecast_next_7d.next_7d : 0)}</div>
        <div class="sub">forecast revenue</div></div>
      <div class="sb-card sb-stat accent-green"><div class="label">Week on week</div>
        <div class="value sm">${a.weekly_change && a.weekly_change.percent !== null ? (a.weekly_change.percent > 0 ? '▲ ' : '▼ ') + Math.abs(a.weekly_change.percent) + '%' : '—'}</div>
        <div class="sub">${money(a.weekly_change ? a.weekly_change.this_week : 0)} this week</div></div>
    </div>
    <div class="sb-card">
      <h3>Products likely to run out</h3>
      ${(a.run_out_soon || []).length ? `<table class="sb-table"><thead><tr><th>Product</th><th class="num">Days left</th><th class="num">Order</th></tr></thead><tbody>
        ${a.run_out_soon.map((p) => `<tr><td>${esc(p.name)}<div class="small text-muted">${Number(p.stock_qty)} in stock</div></td>
          <td class="num"><span class="badge-soft ${p.days_left <= 2 ? 'bg-out' : 'bg-low'}">${p.days_left}</span></td>
          <td class="num fw-bold">${p.suggested_order}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">Nothing is about to run out.</div>'}
    </div>
    <div class="sb-card">
      <h3>Slow movers</h3>
      ${(a.slow_movers || []).length ? `<table class="sb-table"><tbody>
        ${a.slow_movers.map((p) => `<tr><td>${esc(p.name)}</td><td class="num">${Number(p.stock_qty || p.sold || 0)} in stock</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">Everything is moving.</div>'}
    </div>
    <div class="small text-muted">${esc(a.disclaimer || 'These are recommendations based on past sales, not guaranteed outcomes.')}</div>`;
  },

  /* -------------------------------------------------------- EMPLOYEES */
  async employees(el) {
    const res = await API.get('employees');
    const rows = res.ok ? res.data : [];
    el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h1 class="h5 mb-0">Employees</h1>
      <button class="btn btn-sm btn-sb" id="btn-new-emp">＋ Add employee</button>
    </div>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Name</th><th>Role</th><th>Status</th></tr></thead><tbody>
      ${rows.length ? rows.map((u) => `<tr>
        <td><div class="fw-semibold">${esc(u.full_name)}</div><div class="small text-muted">@${esc(u.username)}</div></td>
        <td class="small">${esc(u.role_name)}</td>
        <td>${u.is_active ? '<span class="badge-soft bg-ok">Active</span>' : '<span class="badge-soft bg-gray">Off</span>'}</td>
      </tr>`).join('') : '<tr><td colspan="3"><div class="empty">Only you so far.</div></td></tr>'}
    </tbody></table></div></div>
    <div id="emp-form-box"></div>`;
    document.getElementById('btn-new-emp').addEventListener('click', () => {
      const box = document.getElementById('emp-form-box');
      box.innerHTML = `
      <form id="emp-form" class="sb-card">
        <h3>New employee</h3>
        <div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Username</label><input class="form-control" name="username" autocapitalize="none" required></div>
          <div class="col-6"><label class="form-label">PIN / password</label><input class="form-control" name="password" type="password" minlength="4" required></div>
        </div>
        <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" inputmode="tel"></div>
        <div class="mb-3"><label class="form-label">Role</label>
          <select class="form-select" name="role">
            <option value="cashier">Cashier - records sales only</option>
            <option value="storekeeper">Storekeeper - products and stock</option>
            <option value="manager">Manager - most operations</option>
            <option value="accountant">Accountant - reports and expenses</option>
          </select></div>
        <button class="btn btn-sb-green w-100">Create employee</button>
      </form>`;
      attachPwToggles(box);
      document.getElementById('emp-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = {};
        fd.forEach((v, k) => { payload[k] = v; });
        const r = await API.post('employees', payload);
        if (r.ok) { App.toast('Employee created.', 'ok'); Pages.employees(el); }
        else App.toast((r.error && r.error.message) || 'Could not create employee.', 'err');
      });
    });
  },

  /* -------------------------------------------------------- SUPPLIERS */
  async suppliers(el) {
    const [sup, po] = await Promise.all([API.get('suppliers'), API.get('purchases')]);
    const rows = sup.ok ? sup.data : [];
    const orders = po.ok ? po.data : [];
    el.innerHTML = `
    <h1 class="h5 mb-2">Suppliers & purchases</h1>
    <div class="sb-card">
      <h3>Suppliers</h3>
      ${rows.length ? `<table class="sb-table"><tbody>
        ${rows.map((s) => `<tr><td><div class="fw-semibold">${esc(s.name)}</div>
          <div class="small text-muted">${esc(s.phone || '')}</div></td>
          <td class="num">${Number(s.balance) > 0 ? '<span class="text-danger">' + money(s.balance) + '</span>' : '<span class="text-muted">settled</span>'}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">No suppliers yet.</div>'}
      <form id="sup-form" class="row g-2 mt-2">
        <div class="col-7"><input class="form-control form-control-sm" name="name" placeholder="Supplier name" required></div>
        <div class="col-5"><input class="form-control form-control-sm" name="phone" placeholder="Phone"></div>
        <div class="col-12"><button class="btn btn-sm btn-sb w-100">Add supplier</button></div>
      </form>
    </div>
    <div class="sb-card">
      <h3>Stock purchases received</h3>
      ${orders.length ? `<table class="sb-table"><thead><tr><th>PO</th><th>Supplier</th><th class="num">Total</th></tr></thead><tbody>
        ${orders.map((o) => `<tr><td class="small">${esc(o.po_no)}<div class="text-muted">${dt(o.ordered_at)}</div></td>
          <td class="small">${esc(o.supplier_name || '—')}</td><td class="num">${money(o.total)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">No purchases recorded.</div>'}
      ${rows.length && App.products.length ? `<button class="btn btn-sb-green w-100 mt-2" id="btn-po">＋ Record a purchase</button>` : ''}
    </div>
    <div id="po-box"></div>`;
    document.getElementById('sup-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = { client_uuid: SBDB.idbUUID(), name: fd.get('name'), phone: fd.get('phone') };
      const r = await API.post('suppliers', payload);
      if (r.ok) { App.toast('Supplier added.', 'ok'); Pages.suppliers(el); }
      else App.toast((r.error && r.error.message) || 'Could not add supplier.', 'err');
    });
    const poBtn = document.getElementById('btn-po');
    if (poBtn) poBtn.addEventListener('click', () => Pages.poForm(el, rows));
  },

  poForm(el, suppliers) {
    const box = document.getElementById('po-box');
    box.innerHTML = `
    <form id="po-form" class="sb-card">
      <h3>Record a stock purchase</h3>
      <div class="mb-2"><label class="form-label">Supplier</label>
        <select class="form-select" name="supplier_id"><option value="">—</option>
        ${suppliers.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></div>
      <div class="mb-2"><label class="form-label">Product</label>
        <select class="form-select" name="product_id">${App.products.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">Quantity</label><input class="form-control" name="quantity" type="number" min="1" value="1" required></div>
        <div class="col-6"><label class="form-label">Unit cost</label><input class="form-control" name="unit_cost" type="number" min="0" step="0.01" required></div>
      </div>
      <button class="btn btn-sb-green w-100">Receive stock</button>
    </form>`;
    document.getElementById('po-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = {
        client_uuid: SBDB.idbUUID(),
        supplier_id: fd.get('supplier_id') || null,
        items: [{ product_id: Number(fd.get('product_id')), quantity: Number(fd.get('quantity')), unit_cost: Number(fd.get('unit_cost')) }]
      };
      const r = await API.post('purchases', payload);
      if (r.ok) { App.toast('Stock received.', 'ok'); Pages.suppliers(el); }
      else App.toast((r.error && r.error.message) || 'Could not record purchase.', 'err');
    });
  },

  /* --------------------------------------------------------- EXPENSES */
  async expenses(el) {
    const res = await API.get('expenses');
    const rows = res.ok ? res.data : [];
    const total = rows.reduce((s, e) => s + Number(e.amount), 0);
    el.innerHTML = `
    <h1 class="h5 mb-2">Expenses</h1>
    <div class="sb-card sb-stat accent-red"><div class="label">Total recorded</div><div class="value sm">${money(total)}</div></div>
    <form id="exp-form" class="sb-card">
      <h3>Record an expense</h3>
      <div class="row g-2 mb-2">
        <div class="col-7"><select class="form-select" name="category">
          <option value="rent">Rent</option><option value="transport">Transport</option>
          <option value="electricity">Electricity</option><option value="salaries">Salaries</option>
          <option value="internet">Internet / airtime</option><option value="water">Water</option>
          <option value="licences">Licences / taxes</option><option value="other">Other</option>
        </select></div>
        <div class="col-5"><input class="form-control" name="amount" type="number" min="1" step="100" placeholder="Amount" required></div>
      </div>
      <div class="mb-2"><input class="form-control" name="description" placeholder="What was it for?" required></div>
      <button class="btn btn-sb w-100">Save expense</button>
    </form>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Date</th><th>Description</th><th class="num">Amount</th></tr></thead><tbody>
      ${rows.length ? rows.map((e) => `<tr><td class="small text-muted">${dt(e.spent_at)}</td>
        <td class="small">${esc(e.description)}<div class="text-muted">${esc(e.category)}</div></td>
        <td class="num">${money(e.amount)}</td></tr>`).join('') : '<tr><td colspan="3"><div class="empty">No expenses yet.</div></td></tr>'}
    </tbody></table></div></div>`;
    document.getElementById('exp-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = { client_uuid: SBDB.idbUUID(), category: fd.get('category'), description: fd.get('description'), amount: Number(fd.get('amount')), spent_at: new Date().toISOString() };
      const r = await API.post('expenses', payload);
      if (r.ok) { App.toast('Expense saved.', 'ok'); Pages.expenses(el); }
      else if (r.offline) {
        await SBDB.enqueue({ entity: 'expenses', action: 'create', payload });
        App.toast('Expense saved offline.', 'ok');
      } else App.toast((r.error && r.error.message) || 'Could not save.', 'err');
    });
  },

  /* ------------------------------------------------------ SUBSCRIPTION */
  async subscription(el) {
    const res = await API.get('subscription');
    if (!res.ok) { el.innerHTML = '<div class="sync-banner offline">Open this while online to renew.</div>'; return; }
    const d = res.data;
    const sub = d.shop.subscription;
    el.innerHTML = `
    <h1 class="h5 mb-2">Subscription</h1>
    <div class="sb-card">
      <div class="d-flex justify-content-between">
        <div><div class="small text-muted">Current plan</div>
          <div class="fw-bold">${d.shop.plan ? esc(d.shop.plan.name) : 'Trial'}</div></div>
        <span class="badge-soft ${sub.writes_allowed ? 'bg-ok' : 'bg-out'}">${esc(sub.status.toUpperCase())}</span>
      </div>
      <div class="small text-muted mt-2">${esc(sub.message)}</div>
      ${sub.expires_at ? `<div class="small mt-1">Expires: <strong>${new Date(sub.expires_at.replace(' ', 'T')).toLocaleDateString('en-GB')}</strong></div>` : ''}
      <div class="small text-muted mt-2">Your records are never deleted, even after expiry.</div>
    </div>
    <div class="sb-card">
      <h3>Pay for a package</h3>
      <form id="plan-form">
        ${d.plans.map((p) => `<label class="d-flex align-items-center gap-2 border rounded p-2 mb-2">
          <input type="radio" name="plan_id" value="${p.id}" ${p.code === 'standard' ? 'checked' : ''}>
          <span><span class="fw-bold">${esc(p.name)}</span> — ${money(p.price_ugx)}/month
          <div class="small text-muted">Up to ${p.max_users} users</div></span></label>`).join('')}
        <div class="mb-2"><label class="form-label">Billing period</label>
          <select class="form-select" name="period_months">
            <option value="1">1 month</option><option value="3">3 months</option>
            <option value="6">6 months</option><option value="12">12 months (2 months free)</option>
          </select></div>
        <div class="mb-2"><label class="form-label">Pay with</label>
          <select class="form-select" name="method">
            <option value="mtn_momo">MTN Mobile Money</option>
            <option value="airtel_money">Airtel Money</option>
            <option value="bank">Bank transfer</option>
            <option value="cash">Cash to agent</option>
          </select></div>
        <div class="mb-2"><label class="form-label">Payment reference</label>
          <input class="form-control" name="reference" placeholder="e.g. MoMo transaction ID"></div>
        <button class="btn btn-sb-green w-100 btn-lg-tap">Submit payment for verification</button>
      </form>
      <div class="small text-muted mt-2">Send the money to the SmartBook merchant number, then enter the reference. The platform administrator confirms it and your subscription extends immediately.</div>
    </div>
    <div class="sb-card">
      <h3>Payment history</h3>
      ${(d.payments || []).length ? `<table class="sb-table"><tbody>
        ${d.payments.map((p) => `<tr><td class="small">${dt(p.created_at)}<div class="text-muted">${esc(p.method)} · ${esc(p.reference || '')}</div></td>
          <td class="num">${money(p.amount_ugx)}</td>
          <td class="num"><span class="badge-soft ${p.status === 'verified' ? 'bg-ok' : p.status === 'pending' ? 'bg-low' : 'bg-out'}">${esc(p.status)}</span></td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">No payments yet.</div>'}
    </div>`;
    document.getElementById('plan-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const r = await API.post('subscription', {
        plan_id: Number(fd.get('plan_id')), period_months: Number(fd.get('period_months')),
        method: fd.get('method'), reference: fd.get('reference'), client_uuid: SBDB.idbUUID()
      });
      if (r.ok) { App.toast('Payment submitted for verification.', 'ok'); Pages.subscription(el); }
      else App.toast((r.error && r.error.message) || 'Could not submit.', 'err');
    });
  },

  /* --------------------------------------------------------- SETTINGS */
  async settings(el) {
    const res = await API.get('settings');
    const s = res.ok ? res.data : (API.state.shop || {});
    el.innerHTML = `
    <h1 class="h5 mb-2">Settings</h1>
    <form id="set-form" class="sb-card">
      <h3>Business profile</h3>
      <div class="mb-2"><label class="form-label">Shop name</label><input class="form-control" name="name" value="${esc(s.name || '')}"></div>
      <div class="mb-2"><label class="form-label">Owner</label><input class="form-control" name="owner_name" value="${esc(s.owner_name || '')}"></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="${esc(s.phone || '')}"></div>
        <div class="col-6"><label class="form-label">Email</label><input class="form-control" name="email" value="${esc(s.email || '')}"></div>
      </div>
      <div class="mb-2"><label class="form-label">Location</label><input class="form-control" name="location" value="${esc(s.location || '')}"></div>
      <div class="mb-3"><label class="form-label">Shop ID</label><input class="form-control" value="${esc(s.shop_uid || '')}" disabled></div>
      <button class="btn btn-sb w-100">Save profile</button>
    </form>
    <div class="sb-card">
      <h3>Language</h3>
      <select class="form-select" id="lang-select">
        ${I18N.languages.map((l) => `<option value="${l}" ${I18N.lang === l ? 'selected' : ''}>${{ en: 'English', lg: 'Luganda', nyn: 'Runyankole' }[l]}</option>`).join('')}
      </select>
      <div id="lang-msg" class="small text-muted mt-2">Luganda and Runyankole translations are wired into the interface strings and can be extended by editing one dictionary file.</div>
    </div>
    <div class="sb-card">
      <h3>Data</h3>
      <div class="d-grid gap-2">
        <a class="btn btn-outline-secondary" href="#/backup">Backup & restore</a>
        <a class="btn btn-outline-secondary" href="#/sync">Sync status</a>
        <button class="btn btn-outline-danger" id="btn-logout">Sign out</button>
      </div>
    </div>`;
    document.getElementById('set-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const payload = {};
      fd.forEach((v, k) => { payload[k] = v; });
      const r = await API.post('settings', payload);
      if (r.ok) { API.setSession({ shop: r.data }); App.afterLoginUI(); App.toast('Profile saved.', 'ok'); }
      else App.toast((r.error && r.error.message) || 'Could not save.', 'err');
    });
    document.getElementById('lang-select').addEventListener('change', (e) => {
      I18N.set(e.target.value);
      I18N.apply(document);
      App.toast('Language changed.', 'ok');
    });
    document.getElementById('btn-logout').addEventListener('click', async () => {
      await API.post('auth/logout', {});
      API.clearSession();
      location.hash = '#/login';
      location.reload();
    });
  },

  /* ------------------------------------------------------------ AUDIT */
  async audit(el) {
    const res = await API.get('audit', { limit: 200 });
    const rows = res.ok ? res.data : [];
    el.innerHTML = `
    <h1 class="h5 mb-2">Activity log</h1>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>When</th><th>Who</th><th>Action</th></tr></thead><tbody>
      ${rows.length ? rows.map((r) => `<tr><td class="small text-muted">${dt(r.created_at)}</td>
        <td class="small">${esc(r.actor)}</td><td class="small">${esc(r.action)}</td></tr>`).join('')
        : '<tr><td colspan="3"><div class="empty">No activity recorded.</div></td></tr>'}
    </tbody></table></div></div>`;
  },

  /* ------------------------------------------------------------- SYNC */
  async syncStatus(el) {
    const items = await SBDB.queueItems();
    const cursor = await SBDB.getMeta('sync_cursor');
    const snap = SBSync.snapshot();
    el.innerHTML = `
    <h1 class="h5 mb-2">Sync status</h1>
    <div class="sb-card">
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Connection</span>
        <strong>${navigator.onLine ? 'Online' : 'Offline'}</strong></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Last sync</span>
        <strong>${snap.lastSync ? dt(snap.lastSync) : 'Not yet'}</strong></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pending on this phone</span>
        <strong>${items.length}</strong></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pull cursor</span>
        <strong class="small">${esc(cursor || '—')}</strong></div>
      ${snap.lastError ? `<div class="sync-banner error">${esc(snap.lastError)}</div>` : ''}
      <div class="d-grid gap-2 mt-2">
        <button class="btn btn-sb" id="btn-sync-now">Sync now</button>
        <button class="btn btn-outline-secondary" id="btn-repair">Re-check stock integrity</button>
      </div>
    </div>
    <div class="sb-card">
      <h3>Waiting to be sent</h3>
      ${items.length ? `<table class="sb-table"><thead><tr><th>Type</th><th>Queued</th><th>State</th></tr></thead><tbody>
        ${items.map((i) => `<tr><td class="small">${esc(i.entity)}</td><td class="small text-muted">${dt(i.queued_at)}</td>
          <td><span class="badge-soft ${i.status === 'error' ? 'bg-out' : 'bg-low'}">${esc(i.status)}</span></td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">Nothing waiting. Everything is on the server.</div>'}
    </div>
    <div class="sb-card">
      <h3>How it works</h3>
      <p class="small text-muted mb-0">Sales, stock changes, customers and expenses are saved on this phone first. When the internet returns they are uploaded with a unique ID, so the same record can never be counted twice. Stock is sent as a change (+3, −1) rather than a total, which is why two phones can both sell offline without overwriting each other.</p>
    </div>`;
    document.getElementById('btn-sync-now').addEventListener('click', async () => {
      await SBSync.fullSync();
      Pages.syncStatus(el);
    });
    document.getElementById('btn-repair').addEventListener('click', async () => {
      const r = await API.post('sync/repair', {});
      App.toast(r.ok ? 'Stock re-verified against the movement history.' : 'Needs a connection.', r.ok ? 'ok' : 'err');
      if (r.ok) Pages.syncStatus(el);
    });
  },

  /* ----------------------------------------------------------- BACKUP */
  async backup(el) {
    el.innerHTML = `
    <h1 class="h5 mb-2">Backup & restore</h1>
    <div class="sb-card">
      <h3>Download a backup</h3>
      <p class="small text-muted">A single JSON file with your products, customers, sales, credit and expenses. Keep a copy on a flash disk or send it to yourself.</p>
      <a class="btn btn-sb w-100" href="${API.BASE}/backup/download">Download backup</a>
    </div>
    <div class="sb-card">
      <h3>Restore from a backup</h3>
      <p class="small text-muted">Records are matched by their unique ID, so restoring the same file twice will not create duplicates. A backup from a different shop is refused.</p>
      <input class="form-control mb-2" type="file" id="backup-file" accept="application/json">
      <button class="btn btn-outline-danger w-100" id="btn-restore">Restore this backup</button>
    </div>`;
    document.getElementById('btn-restore').addEventListener('click', async () => {
      const file = document.getElementById('backup-file').files[0];
      if (!file) { App.toast('Choose a backup file first.', 'err'); return; }
      const text = await file.text();
      let parsed;
      try { parsed = JSON.parse(text); } catch (e) { App.toast('That file is not valid JSON.', 'err'); return; }
      const r = await API.post('backup', { backup: parsed });
      if (r.ok) App.toast('Restore complete: ' + JSON.stringify(r.data.restored), 'ok');
      else App.toast((r.error && r.error.message) || 'Restore failed.', 'err');
    });
  },

  /* ---------------------------------------------------------- RECEIPT */
  async receipt(el, id) {
    const res = await API.get('sales/get', { id });
    let sale;
    if (res.ok) sale = res.data;
    else {
      sale = (await SBDB.all('sales')).find((s) => String(s.id) === String(id));
    }
    if (!sale) { el.innerHTML = '<div class="sb-card">Receipt not found on this phone. It will appear once synced.</div>'; return; }
    const shop = API.state.shop || {};
    const lines = sale.items && sale.items.length ? sale.items : (sale.payment_method ? [] : []);
    el.innerHTML = `
    <div class="sb-card receipt" id="receipt-body">
      <div class="text-center mb-2">
        <div class="fw-bold">${esc(shop.name || 'SmartBook')}</div>
        <div class="small">${esc(shop.location || '')}</div>
        <div class="small">${esc(shop.phone || '')}</div>
      </div>
      <hr>
      <div class="d-flex justify-content-between small"><span>${esc(sale.sale_no)}</span><span>${dt(sale.sold_at)}</span></div>
      <div class="small">Cashier: ${esc(sale.cashier || (API.state.user && API.state.user.name) || '')}</div>
      ${sale.customer_name ? `<div class="small">Customer: ${esc(sale.customer_name)}</div>` : ''}
      <hr>
      ${lines.length ? lines.map((l) => `<div class="d-flex justify-content-between small">
        <span>${esc(l.product_name)} × ${Number(l.quantity)}</span><span>${money(l.line_total)}</span></div>`).join('')
        : (sale.Items || []).map(() => '').join('')}
      <hr>
      ${Number(sale.discount) > 0 ? `<div class="d-flex justify-content-between small"><span>Discount</span><span>-${money(sale.discount)}</span></div>` : ''}
      <div class="d-flex justify-content-between fw-bold"><span>TOTAL</span><span>${money(sale.total)}</span></div>
      <div class="d-flex justify-content-between small"><span>Paid (${esc(sale.payment_method)})</span><span>${money(sale.amount_paid)}</span></div>
      ${Number(sale.total) - Number(sale.amount_paid) > 0 ? `<div class="d-flex justify-content-between small text-danger"><span>On credit</span><span>${money(Number(sale.total) - Number(sale.amount_paid))}</span></div>` : ''}
      <hr>
      <div class="text-center small">Thank you. Your Business, Under Control.<br>Powered by SmartBook Solutions</div>
      ${sale.status === 'cancelled' ? '<div class="text-center fw-bold mt-2">*** CANCELLED ***</div>' : ''}
    </div>
    <div class="d-grid gap-2 no-print">
      <button class="btn btn-sb" onclick="window.print()">Print receipt</button>
      <button class="btn btn-outline-secondary" id="btn-share">Share via WhatsApp / SMS</button>
      <a class="btn btn-outline-secondary" href="#/pos">New sale</a>
    </div>`;
    document.getElementById('btn-share').addEventListener('click', () => {
      const text = `${shop.name || 'SmartBook'}\nReceipt ${sale.sale_no}\nTotal: ${money(sale.total)}\n${(lines || []).map((l) => `${l.product_name} x${l.quantity}`).join(', ')}\nThank you!`;
      if (navigator.share) navigator.share({ title: 'Receipt', text }).catch(() => {});
      else location.href = 'sms:?body=' + encodeURIComponent(text);
    });
  },

  /* ------------------------------------------------------------- MORE */
  async more(el) {
    const links = [
      ['employees', 'Employees', 'Add cashiers and managers'],
      ['suppliers', 'Suppliers & purchases', 'Stock in, reorder information'],
      ['expenses', 'Expenses', 'Rent, transport, salaries'],
      ['analytics', 'Smart analytics', 'Forecasts and recommendations'],
      ['reports', 'Reports', 'Daily, weekly, monthly, annual'],
      ['subscription', 'Subscription', 'Renew or change package'],
      ['sync', 'Sync status', 'What is waiting to be sent'],
      ['backup', 'Backup & restore', 'Keep a copy of your records'],
      ['audit', 'Activity log', 'Who did what'],
      ['settings', 'Settings', 'Business profile and language']
    ];
    el.innerHTML = `
    <div class="sb-card">
      <div class="d-flex align-items-center gap-2">
        <div class="sb-brand-mark" style="background:var(--sb-blue-soft);color:var(--sb-blue)">SB</div>
        <div><div class="fw-bold">${esc((API.state.user && API.state.user.name) || '')}</div>
          <div class="small text-muted">${esc((API.state.user && API.state.user.role_name) || '')} · ${esc((API.state.shop && API.state.shop.name) || '')}</div></div>
      </div>
    </div>
    <div class="sb-card p-0">
      ${links.map(([h, title, sub]) => `<a href="#/${h}" class="d-flex align-items-center gap-3 p-3 border-bottom text-decoration-none text-reset">
        <span class="flex-grow-1"><span class="fw-semibold d-block">${title}</span>
        <span class="small text-muted">${sub}</span></span><span class="text-muted">›</span></a>`).join('')}
    </div>
    <button class="btn btn-outline-danger w-100 mt-2" id="btn-logout2">Sign out</button>`;
    document.getElementById('btn-logout2').addEventListener('click', async () => {
      await API.post('auth/logout', {});
      API.clearSession();
      localStorage.clear();
      location.hash = '#/login';
      location.reload();
    });
  }
};
