/* SmartBook - Super Administrator dashboard (platform level). */
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));
const money = (n) => 'UGX ' + Number(n || 0).toLocaleString('en-UG');
const fmtDate = (d) => d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-GB') : '';

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

const Admin = {
  view: null,

  toast(msg, kind) {
    const el = document.createElement('div');
    el.className = 'sb-toast ' + (kind || '');
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 4000);
  },

  login() {
    this.view.innerHTML = `
    <div class="auth-wrap">
      <div class="auth-card">
        <div class="auth-head">
          <div class="mark">SB</div><h1>Platform sign-in</h1><p>SmartBook Super Administrator</p>
        </div>
        <div class="auth-body">
          <form id="f">
            <div class="mb-3"><label class="form-label">Username or email</label>
              <input class="form-control form-control-lg" name="username" required autofocus></div>
            <div class="mb-3"><label class="form-label">Password</label>
              <input class="form-control form-control-lg" name="password" type="password" required></div>
            <div class="small text-danger mb-2" id="err" hidden></div>
            <button class="btn btn-sb btn-lg-tap w-100">Sign in</button>
          </form>
          <div class="text-center mt-3"><a class="small" href="index.html">Shop sign-in</a></div>
        </div>
      </div>
    </div>`;
    attachPwToggles(this.view);
    document.getElementById('f').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const res = await API.post('auth/admin-login', { username: fd.get('username'), password: fd.get('password') });
      if (!res.ok) {
        const err = document.getElementById('err');
        err.textContent = (res.error && res.error.message) || 'Sign in failed.';
        err.hidden = false;
        return;
      }
      API.setSession({ token: res.data.token, csrf: res.data.csrf });
      document.getElementById('btn-out').hidden = false;
      this.overview();
    });
  },

  async overview() {
    document.getElementById('btn-out').hidden = false;
    const res = await API.get('admin/overview');
    if (!res.ok) { this.view.innerHTML = '<div class="sb-card">Could not load. Is the database seeded?</div>'; return; }
    const d = res.data;
    this.view.innerHTML = `
    <h1 class="h5 mb-2">Platform overview</h1>
    <div class="grid-2">
      <div class="sb-card sb-stat accent-blue"><div class="label">Registered shops</div>
        <div class="value">${d.shops.total}</div><div class="sub">${d.shops.active} active</div></div>
      <div class="sb-card sb-stat accent-green"><div class="label">Revenue this month</div>
        <div class="value sm">${money(d.revenue.this_month)}</div><div class="sub">all time ${money(d.revenue.all_time)}</div></div>
    </div>
    <div class="grid-3">
      <div class="sb-card sb-stat"><div class="label">Suspended</div><div class="value sm">${d.shops.suspended}</div></div>
      <div class="sb-card sb-stat accent-orange"><div class="label">Expired subs</div><div class="value sm">${d.shops.lapsed}</div></div>
      <div class="sb-card sb-stat accent-red"><div class="label">Payments to verify</div><div class="value sm">${d.pending_payments}</div></div>
    </div>
    ${d.monthly_series.length ? `
    <div class="sb-card">
      <h3>Payments received (last 12 months)</h3>
      <div class="small text-muted mb-1">${d.monthly_series[0].month} to ${d.monthly_series[d.monthly_series.length - 1].month}</div>
      <div class="sparkline">${d.monthly_series.map((m) =>
        `<span style="height:${Math.round(56 * Math.max(0.04, m.total / Math.max(1, Math.max(...d.monthly_series.map((x) => x.total)))))}px" title="${esc(m.month)}: ${money(m.total)}"></span>`).join('')}</div>
    </div>` : '<div class="sb-card empty">No verified payments yet.</div>'}
    <div class="sb-card">
      <h3>Package mix</h3>
      <div class="sb-table-wrap"><table class="sb-table"><thead>
        <tr><th>Package</th><th>Shops</th><th class="num">Price / month</th></tr></thead><tbody>
        ${d.plan_mix.map((p) => `<tr><td>${esc(p.name)}</td><td>${p.shops}</td><td class="num">${money(p.price_ugx)}</td></tr>`).join('')}
      </tbody></table></div>
    </div>
    <div class="sb-card">
      <h3>Newest shops</h3>
      <div class="sb-table-wrap"><table class="sb-table"><thead>
        <tr><th>Shop</th><th>Owner</th><th>Status</th></tr></thead><tbody>
        ${d.recent_shops.map((s) => `<tr><td>${esc(s.name)}</td><td class="small">${esc(s.owner_name)}</td>
          <td><span class="badge-soft ${s.status === 'active' ? 'bg-ok' : 'bg-gray'}">${esc(s.status)}</span></td></tr>`).join('')}
      </tbody></table></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sb" id="btn-shops">All shops</button>
      <button class="btn btn-sb-green" id="btn-newshop">Create shop account</button>
      <button class="btn btn-outline-secondary" id="btn-payments">Payments</button>
      <button class="btn btn-outline-secondary" id="btn-plans">Packages</button>
      <button class="btn btn-outline-secondary" id="btn-announce">Announcements</button>
      <button class="btn btn-outline-secondary" id="btn-logs">Audit log</button>
    </div>`;
    document.getElementById('btn-shops').addEventListener('click', () => this.shops());
    document.getElementById('btn-newshop').addEventListener('click', () => this.createShop());
    document.getElementById('btn-payments').addEventListener('click', () => this.payments());
    document.getElementById('btn-plans').addEventListener('click', () => this.plans());
    document.getElementById('btn-announce').addEventListener('click', () => this.announce());
    document.getElementById('btn-logs').addEventListener('click', () => this.logs());
  },

  async createShop() {
    const plans = (await API.get('admin/plans'));
    const planRows = plans.ok ? plans.data.filter((p) => p.is_active).map((p) =>
      `<option value="${esc(p.code)}">${esc(p.name)} - UGX ${Number(p.price_ugx).toLocaleString('en-UG')} / month</option>`).join('') : '';
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Create shop account</h1>
    </div>
    <div class="sb-card">
      <p class="small text-muted">This creates a new shop with its own owner login and free trial.
      Hand the username and password to the shop - each shop then signs in at the same main page and sees only its own data.</p>
      <form id="mk">
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Shop / business name</label>
            <input class="form-control" name="name" required></div>
          <div class="col-6"><label class="form-label">Owner full name</label>
            <input class="form-control" name="owner_name" required></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Phone</label>
            <input class="form-control" name="phone" inputmode="tel" placeholder="0772123456" required></div>
          <div class="col-6"><label class="form-label">Email (optional)</label>
            <input class="form-control" name="email" type="email"></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Location</label>
            <input class="form-control" name="location" placeholder="Mile 4, Mbarara" required></div>
          <div class="col-6"><label class="form-label">Business category</label>
            <select class="form-select" name="business_category">
              <option>Retail shop</option><option>Grocery</option><option>Pharmacy</option>
              <option>Hardware</option><option>Restaurant</option><option>Salon</option><option>Other</option>
            </select></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Owner username</label>
            <input class="form-control" name="username" autocapitalize="none" required></div>
          <div class="col-6"><label class="form-label">Owner password</label>
            <input class="form-control" name="password" type="password" minlength="6" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Subscription package</label>
          <select class="form-select" name="plan">${planRows}</select></div>
        <div id="mk-err" class="small text-danger mb-2" hidden></div>
        <button class="btn btn-sb-green w-100">Create account</button>
      </form>
    </div>`;
    attachPwToggles(this.view);
    document.getElementById('mk').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = f.querySelector('button');
      const err = document.getElementById('mk-err');
      const payload = {};
      ['name', 'owner_name', 'phone', 'email', 'location', 'business_category', 'username', 'password', 'plan']
        .forEach((k) => { payload[k] = f[k].value.trim(); });
      btn.disabled = true;
      btn.textContent = 'Creating…';
      const res = await API.post('admin/create-shop', payload);
      btn.disabled = false;
      btn.textContent = 'Create account';
      if (!res.ok) {
        err.textContent = (res.error && res.error.message) || 'Could not create the shop.';
        err.hidden = false;
        return;
      }
      this.toast(`Shop "${res.data.name}" created. Login: ${res.data.username} (free trial until ${res.data.expires_at})`, 'ok');
      this.shops();
    });
  },

  async shops(search) {
    const res = await API.get('admin/shops', search ? { search } : {});
    const rows = res.ok ? res.data : [];
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Shops (${rows.length})</h1>
      <button class="btn btn-sm btn-sb-green ms-auto" id="btn-nshop" onclick="Admin.createShop()">Create shop account</button>
    </div>
    <input class="form-control mb-2" id="shop-search" placeholder="Search name, owner, phone or shop ID" value="${esc(search || '')}">
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Shop</th><th>Package</th><th>Expires</th><th>Status</th></tr></thead><tbody>
      ${rows.length ? rows.map((s) => `<tr>
        <td><div class="fw-semibold">${esc(s.name)}</div>
          <div class="small text-muted">${esc(s.shop_uid)} · ${esc(s.owner_name)} · ${esc(s.phone)}</div></td>
        <td class="small">${esc(s.plan_name || '—')}<div class="text-muted">${s.users} users</div></td>
        <td class="small">${fmtDate(s.subscription_expires_at)}</td>
        <td><span class="badge-soft ${s.status === 'active' ? 'bg-ok' : s.status === 'suspended' ? 'bg-out' : 'bg-gray'}">${esc(s.status)}</span>
          <button class="btn btn-sm btn-link p-0 ms-1" data-shop="${s.id}">open</button></td>
      </tr>`).join('') : '<tr><td colspan="4"><div class="empty">No shops registered yet.</div></td></tr>'}
    </tbody></table></div></div>`;
    const inp = document.getElementById('shop-search');
    let t;
    inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => this.shops(inp.value), 350); });
    this.view.querySelectorAll('[data-shop]').forEach((b) => b.addEventListener('click', () => this.shop(b.dataset.shop)));
  },

  async shop(id) {
    const res = await API.get('admin/shop', { id });
    if (!res.ok) { this.toast('Could not load shop.', 'err'); return; }
    const s = res.data;
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.shops()">Back</button>
      <h1 class="h5 mb-0">${esc(s.name)}</h1>
    </div>
    <div class="sb-card">
      <div class="row g-2 small">
        <div class="col-6"><span class="text-muted">Shop ID</span><div class="fw-bold">${esc(s.shop_uid)}</div></div>
        <div class="col-6"><span class="text-muted">Owner</span><div class="fw-bold">${esc(s.owner_name)}</div></div>
        <div class="col-6"><span class="text-muted">Phone</span><div>${esc(s.phone)}</div></div>
        <div class="col-6"><span class="text-muted">Location</span><div>${esc(s.location)}</div></div>
        <div class="col-6"><span class="text-muted">Package</span><div>${esc(s.plan_name || '—')}</div></div>
        <div class="col-6"><span class="text-muted">Expires</span><div>${s.subscription_expires_at ? new Date(s.subscription_expires_at.replace(' ', 'T')).toLocaleDateString('en-GB') : '—'}</div></div>
      </div>
      <div class="d-flex gap-2 mt-3 flex-wrap">
        <button class="btn btn-sm btn-sb-green" data-status="active">Activate</button>
        <button class="btn btn-sm btn-outline-warning" data-status="suspended">Suspend</button>
        <button class="btn btn-sm btn-outline-danger" data-status="deactivated">Close account</button>
      </div>
    </div>
    <div class="sb-card">
      <h3>Activity (aggregate only)</h3>
      <div class="grid-3">
        <div><div class="small text-muted">Products</div><div class="fw-bold">${s.activity.products}</div></div>
        <div><div class="small text-muted">Sales</div><div class="fw-bold">${s.activity.sales}</div></div>
        <div><div class="small text-muted">Employees</div><div class="fw-bold">${s.activity.employees}</div></div>
      </div>
      <div class="small text-muted mt-2">Individual sales, customers and stock are private to the shop and are never shown here.</div>
    </div>
    <div class="sb-card">
      <h3>Team</h3>
      <table class="sb-table"><tbody>
        ${(s.users || []).map((u) => `<tr><td>${esc(u.full_name)}<div class="small text-muted">@${esc(u.username)}</div></td>
          <td class="small">${esc(u.role_name)}</td><td>${u.is_active ? 'Active' : 'Off'}</td></tr>`).join('')}
      </tbody></table>
    </div>`;
    this.view.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', async () => {
      const label = b.dataset.status;
      if (!confirm(`Set "${s.name}" to ${label}? This takes effect for all its devices immediately.`)) return;
      const r = await API.post('admin/shop-status', { id: s.id, status: label });
      if (r.ok) { this.toast('Shop updated.', 'ok'); this.shops(); }
      else this.toast((r.error && r.error.message) || 'Could not update shop.', 'err');
    }));
  },

  async payments() {
    const res = await API.get('admin/payments');
    const rows = res.ok ? res.data.filter((p) => p.status === 'pending') : [];
    const all = res.ok ? res.data : [];
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Payments waiting (${rows.length})</h1>
    </div>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Shop</th><th>Amount</th><th>Reference</th><th>Submitted</th><th></th></tr></thead><tbody>
      ${rows.length ? rows.map((p) => `<tr>
        <td>${esc(p.shop_name)}<div class="small text-muted">${esc(p.plan_name || '')}, ${p.period_months || 1} month(s)</div></td>
        <td class="num">${money(p.amount_ugx)}</td>
        <td class="small">${esc(p.reference || '—')}</td>
        <td class="small">${fmtDate(p.created_at)}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-sb-green" data-v="${p.id}" data-a="1">Approve</button>
          <button class="btn btn-sm btn-outline-danger" data-v="${p.id}" data-a="0" data-note="1">Reject</button>
        </td></tr>`).join('') : '<tr><td colspan="5"><div class="empty">No payments waiting. Everything has been reviewed.</div></td></tr>'}
    </tbody></table></div></div>
    ${all.length ? `
    <h2 class="h6 mt-3">Recent history</h2>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Shop</th><th>Amount</th><th>Status</th><th>Verified by</th></tr></thead><tbody>
      ${all.slice(0, 30).map((p) => `<tr><td>${esc(p.shop_name)}</td><td class="num">${money(p.amount_ugx)}</td>
        <td><span class="badge-soft ${p.status === 'verified' ? 'bg-ok' : p.status === 'rejected' ? 'bg-out' : 'bg-low'}">${esc(p.status)}</span></td>
        <td class="small">${esc(p.verified_by_name || '—')}</td></tr>`).join('')}
    </tbody></table></div></div>` : ''}`;
    this.view.querySelectorAll('[data-v]').forEach((b) => b.addEventListener('click', async () => {
      const approve = b.dataset.a === '1';
      const note = b.dataset.note ? (prompt('Reason for rejection:') || '') : '';
      const r = await API.post('admin/verify-payment', { payment_id: Number(b.dataset.v), approve, note });
      if (r.ok) {
        this.toast(approve
          ? 'Payment approved and subscription extended.'
          : 'Payment rejected.', approve ? 'ok' : '');
        this.payments();
      } else this.toast((r.error && r.error.message) || 'Could not save decision.', 'err');
    }));
  },

  async plans() {
    const res = await API.get('admin/plans');
    const rows = res.ok ? res.data : [];
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Packages</h1>
    </div>
    ${rows.map((p) => `
    <div class="sb-card">
      <form data-plan="${p.id}" class="row g-2 align-end">
        <div class="col-4"><label class="form-label">Code</label><input class="form-control" name="code" value="${esc(p.code)}"></div>
        <div class="col-8"><label class="form-label">Name</label><input class="form-control" name="name" value="${esc(p.name)}"></div>
        <div class="col-4"><label class="form-label">Price UGX</label><input class="form-control" name="price_ugx" value="${p.price_ugx}" inputmode="numeric"></div>
        <div class="col-4"><label class="form-label">Max users</label><input class="form-control" name="max_users" value="${p.max_users}" inputmode="numeric"></div>
        <div class="col-4"><label class="form-label">Active?</label>
          <select class="form-select" name="is_active"><option value="1" ${p.is_active ? 'selected' : ''}>Yes</option><option value="0" ${p.is_active ? '' : 'selected'}>No</option></select></div>
        <div class="col-12"><label class="form-label">Features (comma separated)</label>
          <input class="form-control" name="features" value="${esc((p.features || []).join(', '))}"></div>
        <div class="col-12 text-end"><button class="btn btn-sm btn-sb">Save package</button>
          <span class="small text-muted ms-2">${p.shop_count} shop(s) on this package</span></div>
      </form>
    </div>`).join('')}
    <div class="sb-card">
      <h3>New package</h3>
      <form data-plan="0" class="row g-2">
        <div class="col-4"><label class="form-label">Code</label><input class="form-control" name="code" required></div>
        <div class="col-8"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
        <div class="col-4"><label class="form-label">Price UGX</label><input class="form-control" name="price_ugx" inputmode="numeric" required></div>
        <div class="col-4"><label class="form-label">Max users</label><input class="form-control" name="max_users" value="3" inputmode="numeric"></div>
        <div class="col-12"><label class="form-label">Features (comma separated)</label><input class="form-control" name="features"></div>
        <div class="col-12 text-end"><button class="btn btn-sm btn-sb-green">Add package</button></div>
      </form>
    </div>`;
    this.view.querySelectorAll('form[data-plan]').forEach((f) => f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(f);
      const payload = {
        id: Number(f.dataset.plan) || 0,
        code: fd.get('code'), name: fd.get('name'),
        price_ugx: Number(fd.get('price_ugx')), max_users: Number(fd.get('max_users')),
        is_active: fd.get('is_active') !== '0',
        features: String(fd.get('features') || '')
      };
      const r = await API.post('admin/plans', payload);
      if (r.ok) { this.toast('Package saved.', 'ok'); this.plans(); }
      else this.toast((r.error && r.error.message) || 'Could not save package.', 'err');
    }));
  },

  async announce() {
    const res = (await API.get('admin/announcements'));
    const rows = res.ok ? res.data : [];
    const sup = (await API.get('admin/support'));
    const supportRows = sup.ok ? sup.data : [];
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Announcements</h1>
    </div>
    <div class="sb-card">
      <form id="ann">
        <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
        <div class="mb-2"><label class="form-label">Message</label><textarea class="form-control" name="body" rows="3" required></textarea></div>
        <button class="btn btn-sb w-100">Send to every shop</button>
      </form>
    </div>
    ${rows.length ? `<div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Title</th><th>Message</th><th>When</th></tr></thead><tbody>
      ${rows.map((a) => `<tr><td class="fw-semibold">${esc(a.title)}</td>
        <td class="small">${esc(a.body)}</td><td class="small">${fmtDate(a.created_at)}</td></tr>`).join('')}
    </tbody></table></div></div>` : '<div class="sb-card empty">No announcements yet.</div>'}
    <h2 class="h6 mt-3">Support requests</h2>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>Shop</th><th>Subject</th><th>Message</th><th>Status</th></tr></thead><tbody>
      ${supportRows.length ? supportRows.map((r) => `<tr><td>${esc(r.shop_name || '—')}</td>
        <td class="small fw-semibold">${esc(r.subject || '')}</td>
        <td class="small">${esc(r.message || '')}</td>
        <td><span class="badge-soft ${r.status === 'closed' ? 'bg-gray' : 'bg-low'}">${esc(r.status)}</span></td></tr>`).join('')
      : '<tr><td colspan="4"><div class="empty">No support requests.</div></td></tr>'}
    </tbody></table></div></div>`;
    document.getElementById('ann').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const r = await API.post('admin/announcements', { title: fd.get('title'), body: fd.get('body') });
      if (r.ok) { this.toast('Announcement broadcast to all shops.', 'ok'); this.announce(); }
      else this.toast((r.error && r.error.message) || 'Could not send.', 'err');
    });
  },

  async logs() {
    const res = await API.get('admin/logs', { limit: 300 });
    const rows = res.ok ? res.data : [];
    this.view.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="Admin.overview()">Back</button>
      <h1 class="h5 mb-0">Audit log</h1>
    </div>
    <div class="sb-card p-0"><div class="sb-table-wrap"><table class="sb-table"><thead>
      <tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Shop</th><th>IP</th></tr></thead><tbody>
      ${rows.length ? rows.map((l) => `<tr>
        <td class="small">${esc(String(l.created_at).replace('T', ' ').slice(0, 19))}</td>
        <td class="small">${esc(l.actor || '')}</td>
        <td class="small fw-semibold">${esc(l.action)}${l.entity_id ? ` #${esc(l.entity_id)}` : ''}</td>
        <td class="small">${esc(l.entity || '')}</td>
        <td class="small">${esc(l.shop_name || '')}</td>
        <td class="small text-muted">${esc(l.ip_address || '')}</td></tr>`).join('')
      : '<tr><td colspan="6"><div class="empty">No log entries yet.</div></td></tr>'}
    </tbody></table></div></div>`;
  },
};

document.getElementById('btn-out').addEventListener('click', async () => {
  await API.post('auth/logout', {});
  API.clearSession();
  location.reload();
});

(async function bootAdmin() {
  Admin.view = document.getElementById('view');
  const me = await API.get('auth/admin-me');
  if (me.ok) {
    document.getElementById('btn-out').hidden = false;
    Admin.overview();
  } else {
    Admin.login();
  }
})();
