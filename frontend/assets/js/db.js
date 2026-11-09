/* SmartBook local store (IndexedDB)
 * Stores the shop's working set on the device so the POS, product list and
 * customer list stay usable with the radio off. Writes are never applied
 * "optimistically then forgotten": every offline action also lands in `queue`
 * with a client_uuid, and sync.js replays that queue.
 */
const SBDB = (() => {
  const DB_NAME = 'smartbook';
  const DB_VERSION = 1;
  let dbp = null;

  function open() {
    if (dbp) return dbp;
    dbp = new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = (e) => {
        const db = e.target.result;
        const stores = ['products', 'customers', 'categories', 'suppliers', 'sales', 'expenses', 'meta', 'queue'];
        stores.forEach((name) => {
          if (!db.objectStoreNames.contains(name)) {
            const keyPath = name === 'meta' ? 'key' : 'client_uuid';
            const store = db.createObjectStore(name, { keyPath });
            if (name === 'products') {
              store.createIndex('by_name', 'name');
              store.createIndex('by_barcode', 'barcode');
            }
            if (name === 'queue') {
              store.createIndex('by_entity', 'entity');
              store.createIndex('by_status', 'status');
            }
          }
        });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return dbp;
  }

  async function tx(store, mode, fn) {
    const db = await open();
    return new Promise((resolve, reject) => {
      const t = db.transaction(store, mode);
      const os = t.objectStore(store);
      let result;
      try {
        result = fn(os);
      } catch (err) {
        reject(err);
        return;
      }
      t.oncomplete = () => resolve(result && result.__req ? result.__req.result : result);
      t.onerror = () => reject(t.error);
      t.onabort = () => reject(t.error);
    });
  }

  const put = (store, value) => tx(store, 'readwrite', (os) => os.put(value));
  const get = (store, key) => tx(store, 'readonly', (os) => ({ __req: os.get(key) }));
  const all = (store) => tx(store, 'readonly', (os) => ({ __req: os.getAll() }));
  const clear = (store) => tx(store, 'readwrite', (os) => os.clear());
  const del = (store, key) => tx(store, 'readwrite', (os) => os.delete(key));

  async function bulkPut(store, rows) {
    if (!rows || !rows.length) return 0;
    const db = await open();
    return new Promise((resolve, reject) => {
      const t = db.transaction(store, 'readwrite');
      const os = t.objectStore(store);
      rows.forEach((r) => {
        if (r && r.client_uuid) os.put(r);
      });
      t.oncomplete = () => resolve(rows.length);
      t.onerror = () => reject(t.error);
    });
  }

  /* ------------------------------------------------------------ queue */
  async function enqueue(item) {
    const row = Object.assign({
      client_uuid: idbUUID(),
      queued_at: new Date().toISOString(),
      status: 'pending',
      attempts: 0
    }, item);
    await put('queue', row);
    return row;
  }

  async function queueItems() {
    const rows = await all('queue');
    return rows.filter((r) => r.status === 'pending' || r.status === 'error').sort((a, b) => a.queued_at.localeCompare(b.queued_at));
  }

  async function markQueue(uuid, status, message) {
    const row = await get('queue', uuid);
    if (!row) return;
    if (status === 'done') {
      await del('queue', uuid);
    } else {
      row.status = status;
      row.attempts = (row.attempts || 0) + 1;
      row.last_error = message || null;
      row.last_attempt = new Date().toISOString();
      await put('queue', row);
    }
  }

  function idbUUID() {
    if (crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  /* -------------------------------------------------------- meta keys */
  const setMeta = (key, value) => put('meta', { key, value });
  const getMeta = async (key) => {
    const row = await get('meta', key);
    return row ? row.value : null;
  };

  /* ------------------------------------------------- cache from API */
  async function cachePull(data) {
    if (!data) return;
    await bulkPut('products', (data.products || []).map((p) => Object.assign({}, p, {
      client_uuid: p.client_uuid || ('srv-prod-' + p.id)
    })));
    await bulkPut('customers', (data.customers || []).map((c) => Object.assign({}, c, {
      client_uuid: c.client_uuid || ('srv-cust-' + c.id)
    })));
    await bulkPut('categories', (data.categories || []).map((c) => Object.assign({}, c, {
      client_uuid: c.client_uuid || ('srv-cat-' + c.id)
    })));
    await bulkPut('suppliers', (data.suppliers || []).map((s) => Object.assign({}, s, {
      client_uuid: s.client_uuid || ('srv-sup-' + s.id)
    })));
  }

  return { open, put, get, all, clear, del, bulkPut, enqueue, queueItems, markQueue, setMeta, getMeta, cachePull, idbUUID };
})();
