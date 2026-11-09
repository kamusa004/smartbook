/* SmartBook synchronisation engine
 *
 * Five states the UI must show (per the brief):
 *   online | offline | syncing | synced | error
 *
 * Model
 *   - Offline writes go into the IndexedDB queue with a client_uuid.
 *   - On reconnect, the queue is pushed in dependency order.
 *   - The server replies per record: applied / duplicate / rejected.
 *     "duplicate" is a success (the record already landed), so the local
 *     queue entry is cleared - that is what prevents double sales.
 *   - "rejected" is kept with an error so the user can act on it.
 *   - A `since` cursor is kept for pulls.
 */
const SBSync = (() => {
  const listeners = [];
  let state = navigator.onLine ? 'online' : 'offline';
  let busy = false;
  let lastSync = null;
  let lastError = null;

  function on(fn) { listeners.push(fn); fn(snapshot()); }
  function emit() { listeners.forEach((fn) => { try { fn(snapshot()); } catch (e) {} }); }
  function snapshot() {
    return { state, busy, lastSync, lastError, online: navigator.onLine };
  }
  function setState(s, err) {
    state = s;
    if (err !== undefined) lastError = err;
    emit();
    const el = document.getElementById('sync-chip');
    if (el) {
      el.dataset.state = s;
      el.querySelector('.txt').textContent = label(s);
    }
  }
  function label(s) {
    return ({
      online: 'Online',
      offline: 'Offline - saving on phone',
      syncing: 'Syncing…',
      synced: 'All synced',
      error: 'Sync error'
    })[s] || s;
  }

  function deviceId() {
    let id = localStorage.getItem('sb_device');
    if (!id) {
      id = 'dev-' + Math.random().toString(36).slice(2, 10);
      localStorage.setItem('sb_device', id);
    }
    return id;
  }

  /* ----------------------------------------------------- the push */
  async function flush() {
    if (busy || !navigator.onLine || !API.state.token) return { skipped: true };
    const items = await SBDB.queueItems();
    if (!items.length) {
      setState('synced');
      return { pushed: 0 };
    }
    busy = true;
    setState('syncing');

    const grouped = { customers: [], categories: [], products: [], stock_movements: [], sales: [], credit_payments: [], expenses: [] };
    items.forEach((it) => { if (grouped[it.entity]) grouped[it.entity].push(it.payload); });

    const res = await API.post('sync/push', Object.assign({ device_id: deviceId() }, grouped));
    busy = false;

    if (res.offline) {
      setState('offline');
      return { offline: true };
    }
    if (!res.ok) {
      setState('error', (res.error && res.error.message) || 'Sync failed');
      return { error: res.error };
    }

    const results = (res.data && res.data.results) || [];
    const byUuid = {};
    results.forEach((r) => { byUuid[r.client_uuid] = r; });

    let failures = 0;
    for (const it of items) {
      const r = byUuid[it.client_uuid];
      if (!r) continue;
      if (r.result === 'applied' || r.result === 'duplicate') {
        await SBDB.markQueue(it.client_uuid, 'done');
      } else {
        failures++;
        await SBDB.markQueue(it.client_uuid, 'error', r.message || 'Rejected');
        lastError = r.message || 'A record was rejected';
      }
    }

    lastSync = new Date().toISOString();
    setState(failures ? 'error' : 'synced', failures ? lastError : null);
    if (typeof App !== 'undefined' && App.onSynced) App.onSynced();
    return { results, failures };
  }

  /* ----------------------------------------------------- the pull */
  async function pull() {
    if (!navigator.onLine || !API.state.token) return { offline: true };
    const since = (await SBDB.getMeta('sync_cursor')) || null;
    const res = await API.get('sync/pull', since ? { since } : {});
    if (!res.ok) return res;
    const data = res.data;
    await SBDB.cachePull(data.data);
    await SBDB.setMeta('sync_cursor', data.cursor);
    const pr = (window._productsCache = (await SBDB.all('products')) || [])
      .filter((p) => !p.is_deleted)
      .sort((a, b) => String(a.name).localeCompare(String(b.name)));
    if (typeof App !== 'undefined' && App.onPull) App.onPull(pr, data.data);
    return data;
  }

  async function fullSync() {
    const pushRes = await flush();
    await pull();
    return pushRes;
  }

  /* ------------------------------------------- connectivity plumbing */
  function init() {
    window.addEventListener('online', () => {
      setState('online');
      flush();
    });
    window.addEventListener('offline', () => setState('offline'));

    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'SMARTBOOK_FLUSH') flush();
      });
    }

    // Poll: light enough for a 2G connection, quick enough to feel automatic.
    setInterval(() => {
      if (navigator.onLine && !busy) flush();
    }, 20000);

    setInterval(() => {
      if (navigator.onLine && !busy) pull();
    }, 120000);

    setState(navigator.onLine ? 'online' : 'offline');
  }

  async function registerBackgroundSync() {
    if (!('serviceWorker' in navigator) || !('SyncManager' in window)) return false;
    try {
      const reg = await navigator.serviceWorker.ready;
      await reg.sync.register('smartbook-sync');
      return true;
    } catch (e) {
      return false;
    }
  }

  return { init, flush, pull, fullSync, on, snapshot, deviceId, registerBackgroundSync, get state() { return state; } };
})();
