/* SmartBook core shell: routing, session, dashboard, POS.
 * pages.js holds the secondary screens. No framework, no build step. */

const money = (n) => 'UGX ' + Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const dt = (s) => (s ? new Date(String(s).replace(' ', 'T')).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

const App = {
  products: [],
  cart: [],
  discount: 0,
  shop: null,
  user: null,
  lastDashboard: null,

  /* ------------------------------------------------------------ boot */
  async boot() {
    API.cachedSession();
    this.shop = API.state.shop;
    this.user = API.state.user;

    if ('serviceWorker' in navigator) {
      try { await navigator.serviceWorker.register('sw.js', { scope: './' }); } catch (e) {}
    }
    SBSync.init();
    window.addEventListener('hashchange', () => this.route());

    document.getElementById('btn-menu').addEventListener('click', () => (location.hash = '#/more'));
    document.addEventListener('click', (e) => {
      const nav = e.target.closest('.sb-bottomnav a');
      if (nav) {
        document.querySelectorAll('.sb-bottomnav a').forEach((a) => a.classList.remove('active'));
        nav.classList.add('active');
      }
    });

    if (!API.state.token) {
      if (location.hash && location.hash !== '#/login') {
        this.flashPending = location.hash;
      }
      location.hash = API.state.token ? '#/dashboard' : '#/login';
    }
    await this.route();
    if (API.state.token) {
      this.afterLoginUI();
      SBSync.flush();
      SBSync.pull();
    }
  },

  afterLoginUI() {
    const token = API.state.token;
    document.getElementById('topbar').hidden = !token;
    document.getElementById('bottomnav').hidden = !token;
    const shop = API.state.shop;
    if (shop) {
      document.getElementById('shop-name').textContent = `${shop.name}${shop.is_sample ? ' (sample data)' : ''}`;
      document.documentElement.dataset.sample = shop.is_sample ? '1' : '0';
    }
    const u = API.state.user;
    if (u) document.documentElement.dataset.role = u.role;
    I18N.apply(document);
  },

  toast(msg, kind) {
    const stack = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'sb-toast ' + (kind || '');
    el.textContent = msg;
    stack.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  },

  async route() {
    const hash = location.hash.replace(/^#\/?/, '') || (API.state.token ? 'dashboard' : 'login');
    const [name, arg] = hash.split('/');
    const view = document.getElementById('view');

    if (!API.state.token && !['login'].includes(name)) {
      location.hash = '#/login';
      return;
    }
    document.querySelectorAll('.sb-bottomnav a').forEach((a) => {
      a.classList.toggle('active', a.dataset.nav === name);
    });

    const map = {
      login: () => Views.login(view),
      dashboard: () => Views.dashboard(view),
      pos: () => Views.pos(view),
      products: () => Pages.products(view, arg),
      product: () => Pages.productForm(view, arg),
      customers: () => Pages.customers(view, arg),
      reports: () => Pages.reports(view),
      more: () => Pages.more(view),
      employees: () => Pages.employees(view),
      suppliers: () => Pages.suppliers(view),
      expenses: () => Pages.expenses(view),
      analytics: () => Pages.analytics(view),
      subscription: () => Pages.subscription(view),
      settings: () => Pages.settings(view),
      audit: () => Pages.audit(view),
      sync: () => Pages.syncStatus(view),
      backup: () => Pages.backup(view),
      receipt: () => Pages.receipt(view, arg)
    };
    view.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    try {
      await (map[name] || map.dashboard)();
    } catch (err) {
      view.innerHTML = `<div class="sb-card"><h3>Something went wrong</h3><p class="text-muted small mb-0">${esc(err.message || err)}</p></div>`;
    }
    I18N.apply(view);
  },

  onSynced() { this.toast('Saved to server. Everything is synced.', 'ok'); },
  onPull(products) { this.products = products; if (location.hash.includes('pos')) Views.renderPosGrid(); },

  async loadProducts() {
    const local = await SBDB.all('products');
    this.products = (local || []).filter((p) => !p.is_deleted).sort((a, b) => String(a.name).localeCompare(String(b.name)));
    return this.products;
  }
};

/* ==================================================================== */
/* PASSWORD SHOW / HIDE                                                  */
/* ==================================================================== */
/** Wrap every password input inside `root` with a Show / Hide button. */
function attachPwToggles(root) {
  (root || document).querySelectorAll('input[type="password"]:not(.pw-done)').forEach((inp) => {
    inp.classList.add('pw-done');
    const wrap = document.createElement('div');
    wrap.className = 'pw-group';
    inp.replaceWith(wrap);
    wrap.appendChild(inp);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.setAttribute('aria-label', 'Show password');
    btn.textContent = 'Show';
    btn.addEventListener('click', () => {
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(btn);
  });
}

/* ==================================================================== */
/* AUTH SCREENS                                                          */
/* ==================================================================== */
const Views = {
  login(el) {
    document.getElementById('topbar').hidden = true;
    document.getElementById('bottomnav').hidden = true;
    el.innerHTML = `
    <div class="auth-wrap">
      <div class="auth-card">
        <div class="auth-head">
          <div class="mark">SB</div>
          <h1>SmartBook Solutions</h1>
          <p>Your Business, Under Control.</p>
        </div>
        <div class="auth-body">
          <form id="login-form" novalidate>
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input class="form-control form-control-lg" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
              <label class="form-label">Password or PIN</label>
              <input class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" required>
            </div>
            <div id="login-err" class="small text-danger mb-2" hidden></div>
            <button class="btn btn-sb btn-lg-tap w-100" type="submit">Sign in</button>
          </form>
          <div class="text-center mt-3">
            <a href="admin.html" class="small text-muted">Platform administrator</a>
          </div>
        </div>
      </div>
    </div>`;

    attachPwToggles(el);
    document.getElementById('login-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = f.querySelector('button');
      const err = document.getElementById('login-err');
      err.hidden = true;
      btn.disabled = true;
      btn.textContent = 'Signing in…';
      const res = await API.post('auth/login', {
        username: f.username.value.trim(),
        password: f.password.value,
        device_id: SBSync.deviceId()
      });
      btn.disabled = false;
      btn.textContent = 'Sign in';
      if (!res.ok) {
        err.textContent = res.offline
          ? 'No internet connection. Sign in once while online, then the app works offline.'
          : (res.error && res.error.message) || 'Sign in failed.';
        err.hidden = false;
        return;
      }
      API.setSession(res.data);
      API.rememberPerms(res.data.permissions);
      location.hash = '#/dashboard';
      App.afterLoginUI();
      App.toast('Welcome back, ' + res.data.user.name.split(' ')[0], 'ok');
      SBSync.pull();
    });
  },

  /* Shop self-registration was removed: only the platform administrator
     creates shop accounts now (see admin.html). Anyone hitting #/register
     is simply sent back to the sign-in screen. */
  register() {
    location.hash = '#/login';
  },

  /* ================================================================== */
  /* DASHBOARD                                                          */
  /* ================================================================== */
  async dashboard(el) {
    const res = await API.get('dashboard');
    let d = res.ok ? res.data : null;
    if (!d) {
      el.innerHTML = this.offlineCard('dashboard');
      return;
    }
    App.lastDashboard = d;
    const sub = (API.state.shop && API.state.shop.subscription) || {};
    const p = d.profit;
    const trend = d.trend_14d || [];
    const max = Math.max(1, ...trend.map((t) => Number(t.revenue)));

    el.innerHTML = `
    ${sub.status && sub.status !== 'active' ? `<div class="sync-banner ${sub.writes_allowed ? 'offline' : 'error'}">
        <span>${esc(sub.message)}</span><a class="ms-auto btn btn-sm btn-light" href="#/subscription">Renew</a></div>` : ''}
    ${API.state.shop && API.state.shop.is_sample ? `<div class="sync-banner online"><span>Sample demonstration data - not real business records.</span></div>` : ''}
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div>
        <div class="text-muted small">${new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })}</div>
        <h1 class="h5 mb-0">Good day, ${esc((API.state.user && API.state.user.name || '').split(' ')[0])}</h1>
      </div>
      <a href="#/pos" class="btn btn-sb-green">＋ New sale</a>
    </div>

    <div class="grid-2">
      <div class="sb-card sb-stat accent-green">
        <div class="label">Sales today</div>
        <div class="value">${money(d.sales_today.revenue)}</div>
        <div class="sub">${d.sales_today.transactions} transaction(s)</div>
      </div>
      <div class="sb-card sb-stat accent-blue">
        <div class="label">This month</div>
        <div class="value">${money(d.sales_month.revenue)}</div>
        <div class="sub">${d.sales_month.transactions} transaction(s)</div>
      </div>
    </div>

    <div class="sb-card">
      <h3>Money picture this month</h3>
      <div class="grid-3">
        <div><div class="label text-muted small">Revenue</div><div class="fw-bold">${money(p.revenue)}</div></div>
        <div><div class="label text-muted small">Cost of goods</div><div class="fw-bold">${money(p.cogs)}</div></div>
        <div><div class="label text-muted small">Gross profit</div><div class="fw-bold text-success">${money(p.gross_profit)}</div></div>
        <div><div class="label text-muted small">Expenses</div><div class="fw-bold text-danger">${money(p.expenses)}</div></div>
        <div><div class="label text-muted small">Net profit</div><div class="fw-bold ${p.net_profit >= 0 ? 'text-success' : 'text-danger'}">${money(p.net_profit)}</div></div>
        <div><div class="label text-muted small">Owed by customers</div><div class="fw-bold">${money(d.outstanding_credit)}</div></div>
      </div>
      <div class="small text-muted mt-2">Sales revenue is what you sold. Cash received is what you actually collected; credit sales are counted as owed until paid.</div>
    </div>

    <div class="sb-card">
      <h3>Sales - last 14 days</h3>
      ${trend.length ? `<div class="sparkline">${trend.map((t) => `<span style="height:${Math.max(4, (Number(t.revenue) / max) * 56)}px" title="${t.day}: ${money(t.revenue)}"></span>`).join('')}</div>
      <div class="d-flex justify-content-between small text-muted mt-1"><span>${trend[0] ? trend[0].day : ''}</span><span>${trend.length ? trend[trend.length - 1].day : ''}</span></div>`
        : '<div class="empty">No sales recorded yet.</div>'}
    </div>

    <div class="grid-2">
      <a href="#/products" class="sb-card sb-stat accent-amber text-decoration-none">
        <div class="label">Low stock</div><div class="value">${d.counts.low_stock}</div>
        <div class="sub">products below minimum</div>
      </a>
      <a href="#/products" class="sb-card sb-stat accent-red text-decoration-none">
        <div class="label">Out of stock</div><div class="value">${d.counts.out_of_stock}</div>
        <div class="sub">products finished</div>
      </a>
    </div>

    <div class="sb-card">
      <h3>Needs attention</h3>
      ${(d.low_stock_list || []).length ? d.low_stock_list.map((p) => `
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <div><div class="fw-semibold">${esc(p.name)}</div>
            <div class="small text-muted">${Number(p.stock_qty)} ${esc(p.unit)} left · minimum ${Number(p.min_stock)}</div></div>
          <span class="badge-soft ${Number(p.stock_qty) <= 0 ? 'bg-out' : 'bg-low'}">${Number(p.stock_qty) <= 0 ? 'OUT' : 'LOW'}</span>
        </div>`).join('') : '<div class="empty">All products are above their minimum level.</div>'}
    </div>

    <div class="sb-card">
      <h3>Best sellers this month</h3>
      ${(d.best_sellers || []).length ? `<table class="sb-table"><tbody>
        ${d.best_sellers.map((b, i) => `<tr><td style="width:28px" class="text-muted">${i + 1}</td>
          <td>${esc(b.product_name)}</td><td class="num">${Number(b.qty)}</td>
          <td class="num">${money(b.revenue)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">No sales yet.</div>'}
    </div>

    <div class="sb-card">
      <h3>Recent transactions</h3>
      ${(d.recent_sales || []).length ? `<table class="sb-table"><thead><tr>
        <th>Receipt</th><th>Time</th><th>Method</th><th class="num">Total</th></tr></thead><tbody>
        ${d.recent_sales.map((s) => `<tr onclick="location.hash='#/receipt/${s.id}'" style="cursor:pointer">
          <td><span class="fw-semibold">${esc(s.sale_no)}</span>${s.status === 'cancelled' ? ' <span class="badge-soft bg-out">void</span>' : ''}</td>
          <td class="small text-muted">${dt(s.sold_at)}</td>
          <td class="small">${esc(s.payment_method)}</td>
          <td class="num">${money(s.total)}</td></tr>`).join('')}
      </tbody></table>` : '<div class="empty">Nothing recorded yet.</div>'}
    </div>`;
  },

  offlineCard(what) {
    const q = `
    <div class="sb-card">
      <h3>You are offline</h3>
      <p class="small text-muted">${esc(what)} couldn't be loaded from the server. Sales you record now are stored on this phone and sent automatically when the connection returns.</p>
      <div class="d-flex gap-2">
        <a href="#/pos" class="btn btn-sb-green flex-fill">Sell offline</a>
        <a href="#/sync" class="btn btn-outline-secondary flex-fill">Sync status</a>
      </div>
    </div>`;
    return q;
  },

  /* ================================================================== */
  /* POS - tap to sell                                                  */
  /* ================================================================== */
  async pos(el) {
    App.cart = [];
    App.discount = 0;
    await App.loadProducts();
    const customers = (await SBDB.all('customers')) || [];

    el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h1 class="h5 mb-0">Record a sale</h1>
      <span class="badge-soft bg-info-soft" id="cart-count">0 items</span>
    </div>
    <div class="pos-search">
      <input class="form-control form-control-lg" id="pos-search" placeholder="Search products or scan barcode" autocomplete="off">
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-outline-secondary btn-sm" id="btn-scan">Scan barcode</button>
        <button class="btn btn-outline-secondary btn-sm" id="btn-offline-sale">Add unlisted item</button>
      </div>
    </div>
    <div class="pos-grid" id="pos-grid"></div>
    <div class="sb-card mt-3" id="cart-card">
      <h3>Cart</h3>
      <div id="cart-lines"><div class="text-muted small">Tap a product above to add it. Three taps or fewer to finish a sale.</div></div>
      <div class="d-flex justify-content-between mt-3 pt-2 border-top">
        <span class="text-muted">Total</span><span class="cart-total" id="cart-total">UGX 0</span>
      </div>
      <div class="mt-3">
        <label class="form-label">Payment method</label>
        <div class="btn-group w-100" role="group" id="pay-group">
          <input type="radio" class="btn-check" name="pm" id="pm-cash" value="cash" checked><label class="btn btn-outline-secondary" for="pm-cash">Cash</label>
          <input type="radio" class="btn-check" name="pm" id="pm-mtn" value="mtn_momo"><label class="btn btn-outline-secondary" for="pm-mtn">MTN</label>
          <input type="radio" class="btn-check" name="pm" id="pm-airtel" value="airtel_money"><label class="btn btn-outline-secondary" for="pm-airtel">Airtel</label>
          <input type="radio" class="btn-check" name="pm" id="pm-credit" value="credit"><label class="btn btn-outline-secondary" for="pm-credit">Credit</label>
        </div>
      </div>
      <div class="mt-2" id="credit-box" hidden>
        <label class="form-label">Customer buying on credit</label>
        <select class="form-select" id="pos-customer">
          <option value="">Choose customer…</option>
          ${customers.map((c) => `<option value="${c.id}">${esc(c.name)} — owes ${money(c.balance)}</option>`).join('')}
        </select>
        <a href="#/customers/new" class="small">Add a new customer first</a>
      </div>
      <button class="btn btn-sb-green btn-lg-tap w-100 mt-3" id="btn-complete">Complete sale</button>
    </div>
    <div class="modal fade" id="scan-modal" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
      <div class="modal-content"><div class="modal-header"><h5 class="modal-title">Scan barcode</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><video id="scan-video" class="w-100 rounded" playsinline muted></video>
        <p class="small text-muted mt-2 mb-0" id="scan-hint">Point the camera at the product barcode.</p></div></div>
    </div></div>`;

    Views.renderPosGrid();
    document.getElementById('pos-search').addEventListener('input', (e) => Views.renderPosGrid(e.target.value));
    document.getElementById('pos-search').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        const needle = e.target.value.trim().toLowerCase();
        const hit = App.products.find((p) => (p.barcode || '').toLowerCase() === needle)
          || App.products.find((p) => p.name.toLowerCase().includes(needle));
        if (hit) { Views.addToCart(hit); e.target.value = ''; Views.renderPosGrid(); }
      }
    });
    document.getElementById('pos-grid').addEventListener('click', (e) => {
      const tile = e.target.closest('.pos-tile');
      if (!tile) return;
      const p = App.products.find((x) => String(x.id) === tile.dataset.id || x.client_uuid === tile.dataset.uuid);
      if (p) Views.addToCart(p);
    });
    document.getElementById('cart-lines').addEventListener('input', (e) => {
      const input = e.target.closest('input[data-qty]');
      if (!input) return;
      const line = App.cart.find((l) => l.key === input.dataset.qty);
      if (!line) return;
      line.qty = Math.max(0, Number(input.value) || 0);
      if (line.qty === 0) App.cart = App.cart.filter((l) => l.key !== input.dataset.qty);
      Views.renderCart();
    });
    document.getElementById('cart-lines').addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove]');
      if (rm) { App.cart = App.cart.filter((l) => l.key !== rm.dataset.remove); Views.renderCart(); }
    });
    document.querySelectorAll('input[name=pm]').forEach((r) => r.addEventListener('change', () => {
      document.getElementById('credit-box').hidden = document.getElementById('pm-credit').checked !== true;
    }));
    document.getElementById('btn-complete').addEventListener('click', () => Views.completeSale());
    document.getElementById('btn-scan').addEventListener('click', () => Views.startScan());
    document.getElementById('btn-offline-sale').addEventListener('click', () => {
      const name = prompt('Item name (for an unlisted product)');
      if (!name) return;
      const price = Number(prompt('Selling price (UGX)', '1000') || 0);
      if (!price) return;
      Views.addToCart({ id: null, name, selling_price: price, stock_qty: 999, unit: 'pcs', unlisted: true });
    });
  },

  renderPosGrid(filter) {
    const grid = document.getElementById('pos-grid');
    if (!grid) return;
    const f = (filter || '').toLowerCase().trim();
    const rows = App.products.filter((p) => p.is_active !== 0 && (!f || p.name.toLowerCase().includes(f) || (p.barcode || '').toLowerCase().includes(f) || (p.sku || '').toLowerCase().includes(f)));
    if (!rows.length) {
      grid.innerHTML = '<div class="empty" style="grid-column:1/-1">No products match. Add products first.</div>';
      return;
    }
    grid.innerHTML = rows.slice(0, 60).map((p) => {
      const out = Number(p.stock_qty) <= 0;
      return `<button class="pos-tile ${out ? 'out' : ''}" data-id="${p.id || ''}" data-uuid="${p.client_uuid || ''}" ${out ? 'data-out=1' : ''}>
        <span class="name">${esc(p.name)}</span>
        <span><span class="price d-block">${money(p.selling_price)}</span>
        <span class="stock">${out ? 'Out of stock' : Number(p.stock_qty) + ' ' + esc(p.unit || 'pcs')}</span></span>
      </button>`;
    }).join('');
  },

  addToCart(product) {
    if (Number(product.stock_qty) <= 0 && !product.unlisted) {
      App.toast('That item is out of stock. Record a purchase first.', 'err');
      return;
    }
    const key = product.client_uuid || ('p' + product.id);
    const existing = App.cart.find((l) => l.key === key);
    if (existing) {
      existing.qty += 1;
    } else {
      App.cart.push({
        key,
        product_id: product.id || null,
        client_uuid: product.client_uuid || null,
        name: product.name,
        unit_price: Number(product.selling_price),
        qty: 1
      });
    }
    Views.renderCart();
  },

  renderCart() {
    const box = document.getElementById('cart-lines');
    if (!box) return;
    if (!App.cart.length) {
      box.innerHTML = '<div class="text-muted small">Tap a product above to add it. Three taps or fewer to finish a sale.</div>';
    } else {
      box.innerHTML = App.cart.map((l) => `
        <div class="cart-line">
          <div class="flex-grow-1">
            <div class="fw-semibold small">${esc(l.name)}</div>
            <div class="small text-muted">${money(l.unit_price)} each</div>
          </div>
          <input class="form-control form-control-sm qty" type="number" min="0" step="1" value="${l.qty}" data-qty="${esc(l.key)}">
          <div class="fw-bold small" style="min-width:88px;text-align:right">${money(l.qty * l.unit_price)}</div>
          <button class="btn btn-sm btn-link text-danger p-0" data-remove="${esc(l.key)}">Remove</button>
        </div>`).join('');
    }
    const total = App.cart.reduce((s, l) => s + l.qty * l.unit_price, 0);
    document.getElementById('cart-total').textContent = money(total);
    const count = App.cart.reduce((s, l) => s + l.qty, 0);
    document.getElementById('cart-count').textContent = count + ' item' + (count === 1 ? '' : 's');
  },

  async completeSale() {
    if (!App.cart.length) { App.toast('Add at least one item first.', 'err'); return; }
    const method = (document.querySelector('input[name=pm]:checked') || {}).value || 'cash';
    const customerId = method === 'credit' ? Number(document.getElementById('pos-customer').value || 0) : 0;
    if (method === 'credit' && !customerId) { App.toast('Choose the customer buying on credit.', 'err'); return; }
    if (App.cart.some((l) => !l.product_id || !l.client_uuid)) {
      App.toast('Unlisted items can be sold offline only. Add the product to inventory first.', 'err');
      return;
    }

    const btn = document.getElementById('btn-complete');
    btn.disabled = true;
    const payload = {
      client_uuid: SBDB.idbUUID(),
      payment_method: method,
      customer_id: customerId || null,
      sold_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      device_id: SBSync.deviceId(),
      items: App.cart.map((l) => ({ product_id: l.product_id, quantity: l.qty, unit_price: l.unit_price }))
    };

    const res = await API.post('sales', payload);
    if (res.ok) {
      App.toast('Sale saved. ' + money(res.data.sale.total), 'ok');
      await App.applyLocalSale(payload, res.data.sale);
      btn.disabled = false;
      location.hash = '#/receipt/' + res.data.sale.id;
      return;
    }
    if (res.offline || (res.error && ['OFFLINE', 'SERVER_ERROR', 'BAD_RESPONSE'].includes(res.error.code))) {
      await SBDB.enqueue({ entity: 'sales', action: 'create', payload });
      await App.applyLocalSale(payload, null);
      SBSync.registerBackgroundSync();
      App.toast('Saved on this phone. It will sync automatically.', 'ok');
      btn.disabled = false;
      location.hash = '#/dashboard';
      return;
    }
    btn.disabled = false;
    App.toast((res.error && res.error.message) || 'Sale could not be saved.', 'err');
  },

  /** Reflect a sale in the local cache whether it was online or queued. */
  async applyLocalSale(payload, serverSale) {
    for (const line of payload.items) {
      const p = App.products.find((x) => Number(x.id) === Number(line.product_id));
      if (!p) continue;
      p.stock_qty = Number(p.stock_qty) - line.qty;
      await SBDB.put('products', p);
    }
    const total = payload.items.reduce((s, l) => s + l.qty * l.unit_price, 0);
    await SBDB.put('sales', {
      client_uuid: payload.client_uuid,
      id: serverSale ? serverSale.id : null,
      sale_no: serverSale ? serverSale.sale_no : 'OFFLINE-' + payload.client_uuid.slice(0, 6).toUpperCase(),
      total, amount_paid: payload.payment_method === 'credit' ? 0 : total,
      payment_method: payload.payment_method,
      status: payload.payment_method === 'credit' ? 'credit' : 'completed',
      sold_at: payload.sold_at,
      items: payload.items,
      pending_sync: !serverSale
    });
    App.cart = [];
    Views.renderCart();
    Views.renderPosGrid();
  },

  async startScan() {
    const modal = new bootstrap.Modal(document.getElementById('scan-modal'));
    modal.show();
    const hint = document.getElementById('scan-hint');
    const video = document.getElementById('scan-video');
    if (!('BarcodeDetector' in window)) {
      hint.textContent = 'Your browser cannot read barcodes directly. Type the barcode in the search box instead - everything else works the same.';
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = stream;
      await video.play();
      const detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'upc_a', 'upc_e', 'qr_code'] });
      const tick = async () => {
        if (!document.getElementById('scan-modal').classList.contains('show')) {
          stream.getTracks().forEach((t) => t.stop());
          return;
        }
        try {
          const codes = await detector.detect(video);
          if (codes.length) {
            const code = codes[0].rawValue;
            const hit = App.products.find((p) => String(p.barcode) === String(code));
            if (hit) {
              Views.addToCart(hit);
              hint.textContent = 'Added: ' + hit.name;
              stream.getTracks().forEach((t) => t.stop());
              setTimeout(() => modal.hide(), 500);
              return;
            }
            hint.textContent = 'Barcode ' + code + ' is not in your products yet.';
          }
        } catch (e) { /* keep scanning */ }
        requestAnimationFrame(tick);
      };
      tick();
    } catch (e) {
      hint.textContent = 'Camera not available. Allow camera access or type the barcode instead.';
    }
  }
};

document.addEventListener('DOMContentLoaded', () => App.boot());
