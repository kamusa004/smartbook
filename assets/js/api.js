/* SmartBook API client
 * - attaches the bearer token and CSRF header
 * - never throws on network failure: returns {ok:false, offline:true}
 *   so callers can fall back to IndexedDB instead of showing a dead end.
 */
const API = (() => {
  // Server address priority: page override > saved setting > auto-detected.
  // (The connection box UI was removed: window.SMARTBOOK_API_BASE is set by
  // index/admin.html and wins; a previously saved value is honoured second.)
  const BASE = (window.SMARTBOOK_API_BASE
    || localStorage.getItem('sb_server_url')
    || (location.pathname.includes('/public/') ? '/public/api' : './api')).replace(/\/$/, '');
  const TOKEN_KEY = 'sb_token';
  const CSRF_KEY = 'sb_csrf';

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || null,
    csrf: localStorage.getItem(CSRF_KEY) || null,
    user: null,
    shop: null,
    permissions: []
  };

  function setSession(data) {
    state.token = data.token || state.token;
    state.csrf = data.csrf || state.csrf;
    if (data.user) state.user = data.user;
    if (data.shop) state.shop = data.shop;
    if (data.permissions) state.permissions = data.permissions;
    if (state.token) localStorage.setItem(TOKEN_KEY, state.token);
    if (state.csrf) localStorage.setItem(CSRF_KEY, state.csrf);
    if (data.user) localStorage.setItem('sb_user', JSON.stringify(data.user));
    if (data.shop) localStorage.setItem('sb_shop', JSON.stringify(data.shop));
  }

  function clearSession() {
    [TOKEN_KEY, CSRF_KEY, 'sb_user', 'sb_shop'].forEach(k => localStorage.removeItem(k));
    state.token = state.csrf = state.user = state.shop = null;
    state.permissions = [];
  }

  function cachedSession() {
    if (state.user) return state;
    try {
      state.user = JSON.parse(localStorage.getItem('sb_user') || 'null');
      state.shop = JSON.parse(localStorage.getItem('sb_shop') || 'null');
    } catch (e) { /* ignore */ }
    return state;
  }

  async function request(method, path, body) {
    const headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (state.token) headers['Authorization'] = 'Bearer ' + state.token;
    if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;

    let res;
    try {
      res = await fetch(BASE + '/' + path.replace(/^\//, ''), {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body)
      });
    } catch (err) {
      return { ok: false, offline: true, error: { code: 'OFFLINE', message: 'No internet connection.' } };
    }

    let json;
    try {
      json = await res.json();
    } catch (err) {
      return { ok: false, error: { code: 'BAD_RESPONSE', message: 'The app could not reach the server properly. Check the connection and try again.' } };
    }
    if (res.status === 401) {
      clearSession();
      if (!location.hash.includes('login')) {
        location.hash = '#/login';
      }
    }
    json.status = res.status;
    return json;
  }

  const get = (path, params) => {
    if (params) {
      const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null)).toString();
      if (qs) path += (path.includes('?') ? '&' : '?') + qs;
    }
    return request('GET', path);
  };
  const post = (path, body) => request('POST', path, body || {});

  function can(permission) {
    if (!state.permissions || !state.permissions.length) {
      try {
        state.permissions = JSON.parse(localStorage.getItem('sb_perms') || '[]');
      } catch (e) { state.permissions = []; }
    }
    return state.permissions.includes('*') || state.permissions.includes(permission);
  }

  function rememberPerms(perms) {
    state.permissions = perms || [];
    localStorage.setItem('sb_perms', JSON.stringify(state.permissions));
  }

  return { BASE, state, get, post, setSession, clearSession, cachedSession, can, rememberPerms };
})();
