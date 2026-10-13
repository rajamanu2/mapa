// MAPA client demo relay. Only the isolated synthetic-data gateway is an origin.
export default {
  async fetch(request) {
    const input = new URL(request.url);
    const login = /^\/api\/int\/v1\/auth\/(login|logout)$/.test(input.pathname);
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method) && !(request.method === 'POST' && login)) {
      return new Response('This client preview is view-only.', { status: 405, headers: {'Cache-Control':'no-store'} });
    }
    const target = new URL('https://sapphire-both-cakes-entities.trycloudflare.com');
    target.pathname = input.pathname;
    target.search = input.search;
    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('forwarded');
    headers.delete('x-forwarded-host');
    headers.delete('x-forwarded-for');
    headers.delete('x-http-method-override');
    try {
      const upstream = await fetch(new Request(target, {
        method: request.method, headers,
        body: ['GET','HEAD'].includes(request.method) ? undefined : request.body,
        redirect: 'manual', signal: AbortSignal.timeout(60000)
      }), {cache: 'no-store'});
      const response = new Response(upstream.body, upstream);
      response.headers.set('Cache-Control','no-store');
      response.headers.set('X-Robots-Tag','noindex, nofollow, noarchive');
      const location = response.headers.get('Location');
      if (location) {
        const redirect = new URL(location, target);
        if (redirect.origin === target.origin) {
          redirect.host = input.host;
          response.headers.set('Location', redirect.toString());
        }
      }
      return response;
    } catch {
      return new Response('MAPA demo is temporarily offline. Please ask the presenter to start the demo PC and Docker.', {
        status: 503, headers: {'Content-Type':'text/plain; charset=utf-8','Cache-Control':'no-store','Retry-After':'60'}
      });
    }
  }
};
