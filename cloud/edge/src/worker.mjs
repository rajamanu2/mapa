const METHODS = new Set(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
const SOCKET_PATHS = new Set(['/socketcluster', '/socketcluster/']);

/** @param {string | null} value */
function validClientIp(value) {
  if (!value) return false;
  if (/^(?:\d{1,3}\.){3}\d{1,3}$/.test(value)) return value.split('.').every(part => Number(part) <= 255);
  if (value.includes(':') && /^[0-9a-f:.]+$/i.test(value)) {
    try { new URL(`http://[${value}]/`); return true; } catch { return false; }
  }
  return false;
}

/** @param {string} path */
function blockedPath(path) {
  // Reject ambiguous forms before applying the same boundary to decoded names.
  // The gateway must enforce this independently, including its /api alias.
  if (/%(?:2e|2f|5c|25|0[0-9a-f]|1[0-9a-f]|7f)/i.test(path) || /[\\;\u0000-\u001f\u007f]/.test(path) || path.includes('//')) return true;
  let decoded;
  try { decoded = decodeURIComponent(path).toLowerCase(); } catch { return true; }
  if (/[\\;\u0000-\u001f\u007f]/.test(decoded) || decoded.split('/').some(segment => segment.startsWith('.'))) return true;
  const nativePath = decoded.startsWith('/api/') ? decoded.slice(4) : decoded;
  return /^\/mapa\/voice\/v1\/bridge(?:\/|$)/.test(nativePath)
    || /^\/media(?:\/|$)/.test(nativePath)
    || /^\/(?:health|ready|metrics)(?:\/|$)/.test(nativePath);
}

/** @param {string} message @param {number} status */
function unavailable(message, status) {
  return new Response(message, {
    status,
    headers: {
      'content-type': 'text/plain; charset=utf-8',
      'cache-control': 'no-store',
      'x-content-type-options': 'nosniff',
    },
  });
}

/**
 * Only the pre-registered VPC Service can receive traffic. A client URL, header
 * or redirect cannot select another origin. Native MAPA remains the auth boundary.
 * @param {Request} request
 * @param {Env} env
 * @returns {Promise<Response>}
 */
export async function proxy(request, env) {
  let publicOrigin;
  try {
    publicOrigin = new URL(env.PUBLIC_ORIGIN);
    if (publicOrigin.protocol !== 'https:' || publicOrigin.pathname !== '/' || publicOrigin.search || publicOrigin.hash || publicOrigin.username || publicOrigin.password) {
      return unavailable('MAPA is unavailable.', 503);
    }
  } catch { return unavailable('MAPA is unavailable.', 503); }

  const url = new URL(request.url);
  if (url.host !== publicOrigin.host) return unavailable('Unknown host.', 421);
  if (blockedPath(url.pathname)) return unavailable('Not found.', 404);
  if (!METHODS.has(request.method)) return unavailable('Method not allowed.', 405);
  if (url.protocol !== 'https:') {
    url.protocol = 'https:';
    return new Response(null, { status: 307, headers: { location: url.href, 'cache-control': 'no-store' } });
  }

  const upgrade = request.headers.get('upgrade');
  const websocket = upgrade?.toLowerCase() === 'websocket';
  if (upgrade && (!websocket || !SOCKET_PATHS.has(url.pathname) || request.method !== 'GET')) {
    return unavailable('Not found.', 404);
  }
  if (websocket && request.headers.has('origin') && request.headers.get('origin') !== publicOrigin.origin) {
    return unavailable('Origin not allowed.', 403);
  }

  const headers = new Headers(request.headers);
  // Ignore caller-supplied proxy identity and hop-by-hop routing instructions.
  for (const name of ['forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-real-ip', 'cf-connecting-ip', 'proxy-authorization', 'proxy-connection', 'connection', 'keep-alive', 'te', 'trailer', 'transfer-encoding']) {
    headers.delete(name);
  }
  headers.set('host', publicOrigin.host);
  headers.set('x-forwarded-host', publicOrigin.host);
  headers.set('x-forwarded-proto', 'https');
  headers.set('x-forwarded-port', '443');
  const clientIp = request.headers.get('cf-connecting-ip');
  if (clientIp && validClientIp(clientIp)) {
    headers.set('cf-connecting-ip', clientIp);
    headers.set('x-forwarded-for', clientIp);
  }
  if (websocket) headers.set('connection', 'Upgrade');

  // VPC routing uses the configured gateway and HTTP port, not this host.
  // Keeping the public host lets the native server generate correct URLs/cookies.
  url.protocol = 'http:';
  try {
    const upstreamRequest = new Request(new Request(url.href, request), {
      headers,
      redirect: 'manual',
    });
    const upstream = await env.MAPA_ORIGIN.fetch(upstreamRequest);
    // Preserve the Cloudflare upgrade Response/WebSocket; do not consume frames
    // or rebuild a 101 as an ordinary Fetch response.
    if (upstream.status === 101) {
      return websocket ? upstream : unavailable('MAPA is unavailable.', 502);
    }
    const responseHeaders = new Headers(upstream.headers);
    responseHeaders.delete('server');
    responseHeaders.delete('x-powered-by');
    responseHeaders.set('cache-control', 'no-store');
    responseHeaders.set('cdn-cache-control', 'no-store');
    responseHeaders.set('cloudflare-cdn-cache-control', 'no-store');
    responseHeaders.set('x-content-type-options', 'nosniff');
    responseHeaders.set('referrer-policy', 'same-origin');
    responseHeaders.set('strict-transport-security', 'max-age=31536000');
    // No automatic redirect follows: Authorization stays with this fixed service.
    const location = responseHeaders.get('location');
    if (location) {
      const target = new URL(location, publicOrigin);
      if (target.host === publicOrigin.host && target.protocol === 'http:') {
        target.protocol = 'https:';
        responseHeaders.set('location', target.href);
      }
    }
    return new Response(upstream.body, {
      status: upstream.status,
      statusText: upstream.statusText,
      headers: responseHeaders,
    });
  } catch {
    // Do not expose exception details, private origin addresses, headers or bodies.
    return unavailable('MAPA is temporarily unavailable. Please try again.', 502);
  }
}

export default { fetch: proxy };
