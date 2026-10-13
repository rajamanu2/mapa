const UPSTREAM = '__MAPA_UPSTREAM__';
const PUBLIC_ORIGIN = 'https://mapa-asr-health.pages.dev';
export default {
  async fetch(request) {
    const input = new URL(request.url);
    const unsafe = !['GET','HEAD','OPTIONS'].includes(request.method);
    const origin = request.headers.get('Origin');
    if (unsafe && origin && origin !== PUBLIC_ORIGIN) return new Response('Origin not permitted.',{status:403});
    const target = new URL(UPSTREAM);
    target.pathname=input.pathname; target.search=input.search;
    // Existing phones retain the Pages origin and reject API redirects. Move only
    // known browser screens to the permanent site's same-origin API and cookies.
    const mode = request.headers.get('Sec-Fetch-Mode');
    const destination = request.headers.get('Sec-Fetch-Dest');
    const documentNavigation = (mode === 'navigate' && destination === 'document')
      || (!mode && !destination && /(?:^|,)\s*text\/html(?:\s*;|\s*,|\s*$)/i.test(request.headers.get('Accept') || ''));
    const consoleScreen = input.pathname === '/' || input.pathname === '/index.html'
      || /^\/(?:auth|fleet-ops|iam|call-intake|notifications|account|settings|admin)(?:\/|$)/.test(input.pathname);
    if (UPSTREAM === 'https://app.itda.asrhealth.in' && ['GET','HEAD'].includes(request.method)
      && !request.headers.has('Upgrade') && documentNavigation && consoleScreen) {
      return new Response(null, {status:302, headers:{Location:target.toString(), 'Cache-Control':'no-store', 'X-Robots-Tag':'noindex, nofollow, noarchive'}});
    }
    const headers = new Headers(request.headers);
    for (const header of ['host','forwarded','x-forwarded-host','x-forwarded-for','x-http-method-override']) headers.delete(header);
    try {
      const upstream = await fetch(new Request(target, {
        method:request.method,headers,body:['GET','HEAD'].includes(request.method)?undefined:request.body,
        redirect:'manual',signal:request.headers.get('Upgrade')?.toLowerCase()==='websocket'?undefined:AbortSignal.timeout(60000),
      }), {cache:'no-store'});
      // Preserve the upgraded socket; administrator authentication happens before upgrade.
      if (upstream.status === 101) return upstream;
      const response=new Response(upstream.body,upstream);
      response.headers.set('Cache-Control','no-store');
      response.headers.set('X-Robots-Tag','noindex, nofollow, noarchive');
      const location=response.headers.get('Location');
      if (location) {
        const redirect=new URL(location,target);
        if (redirect.origin===target.origin) {redirect.host=input.host;response.headers.set('Location',redirect.toString());}
      }
      return response;
    } catch {
      return new Response('MAPA server is unavailable. Please contact the ASR Health administrator.',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8','Cache-Control':'no-store','Retry-After':'30'}});
    }
  }
};
