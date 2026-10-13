import http from 'node:http';
import {randomBytes, timingSafeEqual} from 'node:crypto';
import {pathToFileURL} from 'node:url';

export function createAccessHandler({apiOrigin, company, administratorRoleId, customerUserId, fetchImpl = fetch, now = Date.now}) {
  const sessions = new Map();
  const cookieName = 'mapa_console_socket';
  const ttl = 30 * 60 * 1000;
  const cookieId = req => (req.headers.cookie || '').split(';').map(s => s.trim()).find(s => s.startsWith(cookieName + '='))?.slice(cookieName.length + 1);
  const deny = (res, code) => { res.writeHead(code, {'Cache-Control':'no-store'}); res.end(); };
  const same = (a, b) => a && b && a.length === b.length && timingSafeEqual(Buffer.from(a), Buffer.from(b));
  return async (req, res) => {
    if (req.url === '/healthz') return deny(res, 204);
    if (req.url !== '/authorize' || req.method !== 'GET') return deny(res, 404);
    const current = now();
    for (const [id, session] of sessions) if (session.expires <= current) sessions.delete(id);
    const socket = /^\/socketcluster(?:\/|\?|$)/.test(req.headers['x-original-uri'] || '');
    let authorization = req.headers.authorization;
    const id = cookieId(req);
    const session = id && sessions.get(id);
    if (socket) authorization = session && session.expires > current ? session.authorization : undefined;
    // A socket cookie never authorizes HTTP writes or native API reads.
    if (!authorization || !/^Bearer [A-Za-z0-9|._~-]{20,1024}$/.test(authorization)) return deny(res, 401);
    try {
      const upstream = await fetchImpl(apiOrigin + '/int/v1/users/me', {
        headers: {Authorization: authorization, Accept:'application/json'},
        redirect:'error', signal:AbortSignal.timeout(10000),
      });
      if (!upstream.ok) return deny(res, upstream.status === 401 || upstream.status === 403 ? upstream.status : 503);
      const payload = await upstream.json();
      const user = payload.user ?? payload;
      const role = user?.role;
      const companyAdministrator = Boolean(customerUserId && user?.uuid === customerUserId && administratorRoleId && role && String(role.id) === String(administratorRoleId)
        && role.name === 'Administrator' && (role.company_uuid === null || role.company_uuid === company));
      if (!user || user.company_uuid !== company || user.status !== 'active' || user.session_status !== 'active'
        || (user.is_admin !== true && !companyAdministrator)) return deny(res, 403);
      // Customer testing covers operations, not takeover of existing owner accounts.
      // Native company administrators lack consistent owner protection on these routes.
      const method = (req.headers['x-original-method'] || 'GET').toUpperCase();
      let originalPath;
      try {
        const decodedPath = decodeURIComponent((req.headers['x-original-uri'] || '').split('?')[0]).replace(/\/{2,}/g,'/');
        originalPath = new URL(decodedPath,'http://mapa-gateway.invalid').pathname;
      } catch { return deny(res,403); }
      if (user.is_admin !== true && !['GET','HEAD','OPTIONS'].includes(method) && /%[a-f0-9]{2}/i.test(originalPath)) return deny(res,403);
      if (user.is_admin !== true && !['GET','HEAD','OPTIONS'].includes(method)
        && /^\/api\/int\/v1\/(?:users|companies|roles|policies|permissions|settings|admin)(?:\/|$)/.test(originalPath)) return deny(res,403);
      const headers = {'Cache-Control':'no-store'};
      if (!socket) {
        let activeId = session && session.expires > current && same(session.authorization, authorization) ? id : undefined;
        if (!activeId) activeId = [...sessions.entries()].find(([, value]) => value.expires > current && same(value.authorization, authorization))?.[0];
        if (!activeId) {
          // Bound memory use; no credentials or session IDs are logged or persisted.
          if (sessions.size >= 1024) sessions.delete(sessions.keys().next().value);
          activeId = randomBytes(32).toString('base64url');
          sessions.set(activeId, {authorization, expires:current + ttl});
        }
        const remaining = Math.max(1, Math.floor((sessions.get(activeId).expires-current)/1000));
        headers['Set-Cookie'] = `${cookieName}=${activeId}; Path=/socketcluster/; HttpOnly; Secure; SameSite=Strict; Max-Age=${remaining}`;
      }
      res.writeHead(204, headers); res.end();
    } catch { return deny(res, 503); }
  };
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const apiOrigin = process.env.MAPA_API_ORIGIN;
  const company = process.env.MAPA_COMPANY_UUID;
  const administratorRoleId = process.env.MAPA_ADMINISTRATOR_ROLE_ID;
  const customerUserId = process.env.MAPA_CUSTOMER_USER_UUID;
  if (!apiOrigin || !company) throw new Error('Native API origin and ASR company are required.');
  http.createServer(createAccessHandler({apiOrigin,company,administratorRoleId,customerUserId})).listen(8790, '0.0.0.0');
}
