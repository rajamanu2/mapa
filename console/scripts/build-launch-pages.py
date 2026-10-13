"""Deterministic local draft pages; no deployment and no invented operator contact details."""
from pathlib import Path
from html import escape
root=Path(__file__).resolve().parents[1]/'public'
origin='https://app.itda.asrhealth.in'
pages={
 'privacy':('Privacy policy','How MAPA handles ambulance coordination, account, location and trip information.', '''
<p class="notice"><strong>Draft for operator review.</strong> ASR Health must confirm its legal entity, privacy contact, legal bases, retention periods and service providers before this notice is published as its final policy.</p>
<h2>What this service does</h2><p>MAPA is the ambulance operations software used by ASR Health. Authorized staff coordinate calls, ambulances, crews and hospital handovers. This notice concerns the command centre and its related crew and caller-location features.</p>
<h2>Information handled</h2><p>Depending on the feature used, records can include account and contact details, callback numbers, pickup and destination information, ambulance and device identifiers, GPS positions and times, trip status, patient-condition notes, and crew-submitted photographs or reports. Only submit information needed for the response.</p>
<h2>Location sharing</h2><p>A caller link requests browser location permission and an explicit sharing confirmation. You can stop sharing using the Stop sharing control or your browser permissions. The link expires and sharing ends after pickup. Stopping sharing does not erase information already received. Crew phones and installed vehicle trackers have separate location-reporting workflows; a vehicle fix does not prove a crew member is on duty.</p>
<h2>Access and service providers</h2><p>Account and role checks restrict operational records. The command centre is hosted on a Hostinger VPS. Compatibility traffic may pass through Cloudflare. Map requests may contact OpenStreetMap or an enabled map provider. Messaging and push providers must be disclosed when activated; a planned integration should not be represented as already operating.</p>
<h2>Browser storage</h2><p>The application uses authentication/session storage and functional preferences to operate. Caller tracking uses a temporary trip capability. Do not forward a private trip link. Any additional analytics or advertising technology requires a separate review and appropriate controls before activation.</p>
<h2>Retention and security</h2><p>Access controls and HTTPS protect parts of the service, but no system guarantees absolute security. ASR Health must set and publish retention periods for call records, location history, uploaded evidence, logs and backups, including any legally required retention. No automatic deletion period is promised in this draft.</p>
<h2>Requests and questions</h2><p>The operator must provide a verified privacy contact before launch. Requests may concern access, correction, deletion or other applicable rights. Identity verification and lawful retention requirements can affect a request. Do not send patient records through an unverified contact channel.</p><p><a href="/data-rights/">Read the data-rights and GDPR information</a>.</p>'''),
 'terms':('Terms and conditions','Conditions for using the ASR Health MAPA command centre and caller tracking.', '''
<p class="notice"><strong>Draft for operator review.</strong> The contracting legal entity, service scope, contact details and applicable jurisdiction must be confirmed before final publication.</p>
<h2>Using MAPA</h2><p>The command centre is for authorized ambulance operations. Use your own approved account, protect its credentials and provide accurate information. Do not impersonate another person, access unrelated records or attempt to bypass access restrictions.</p>
<h2>Calls, assignments and tracking</h2><p>Saving a call, opening a tracking link or receiving a notification does not confirm that an ambulance has accepted an assignment. Operators and crews must verify the pickup, availability, destination and status through the established response process. Location may be delayed or unavailable because of GPS reception, device power or network conditions. A displayed position is not a guaranteed arrival time.</p>
<h2>Patient information</h2><p>Only authorized personnel should submit or view patient-related information necessary for a trip or handover. Verify the destination and recipient before sharing. Clinical assessment and treatment remain the responsibility of qualified professionals.</p>
<h2>Emergency use and outages</h2><p>If the website, tracking or alerts are unavailable, use ASR Health’s established telephone and operational fallback arrangements. Do not wait for an app notification when urgent assistance is required. The operator must publish the verified emergency and support contact details for its service.</p>
<h2>Availability and changes</h2><p>Maintenance, provider outages and connectivity issues can interrupt the software. These draft terms do not promise uninterrupted availability or a response-time guarantee. Any service commitments, charges, cancellation rules and liability provisions must be supplied and reviewed by the operator.</p>
<h2>Software and notices</h2><p>Third-party components retain their licenses. These service terms do not remove rights granted under an applicable open-source license. See the <a href="/mapa-notices.html">software notices</a> and <a href="/mapa-license.txt">license</a>.</p>
<h2>Privacy</h2><p>Read the <a href="/privacy/">privacy policy</a> for information about records and location sharing.</p>'''),
 'data-rights':('Data rights and GDPR','Information about privacy rights and the review required before claiming GDPR compliance.', '''
<p class="notice"><strong>Operator review pending.</strong> This information page is not a certification of GDPR compliance. Applicability, legal bases and the operator’s response process must be assessed before launch.</p>
<h2>When GDPR applies</h2><p>Whether GDPR applies depends on the organization’s establishment and activities, including relevant services offered to or monitoring of people in the EU. Hosting location or adding a policy page alone does not resolve that question.</p>
<h2>Rights where applicable</h2><p>Depending on the legal conditions, individuals may request information and access, correction, erasure, restriction, portability, or object to processing. Where processing relies on consent, that consent can be withdrawn. Individuals may also complain to the relevant supervisory authority. These rights have conditions and exceptions.</p>
<h2>Location and patient information</h2><p>Precise location and patient-condition information require particular care. The operator must document its processing purposes, appropriate legal bases, access restrictions and any additional conditions for health data. Browser location permission is not a substitute for that assessment.</p>
<h2>Before publication</h2><p>ASR Health must confirm the responsible legal entity and privacy contact; retention and deletion rules; processors and transfers; the process for verified rights requests; incident response; and whether a data-protection impact assessment or designated privacy role is required. The software must be tested against those decisions.</p>
<h2>Further information</h2><p>See the <a href="https://commission.europa.eu/law/law-topic/data-protection/information-individuals_en" rel="noopener noreferrer">European Commission’s explanation of individual rights</a> and the <a href="https://eur-lex.europa.eu/eli/reg/2016/679/oj" rel="noopener noreferrer">GDPR text</a>. Read our <a href="/privacy/">draft privacy policy</a> for the currently identified application data flows.</p>'''),
}

def document(title,description,path,body):
    return f'''<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{escape(title)} · MAPA | ASR Health</title><meta name="description" content="{escape(description)}">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#101b2b">
<link rel="canonical" href="{origin}{path}"><link rel="icon" href="/mapa-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="/legal/legal.css">
<meta property="og:type" content="website"><meta property="og:title" content="MAPA | ASR Health"><meta property="og:description" content="Ambulance operations and connected response."><meta property="og:image" content="{origin}/images/mapa-social-preview.png"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta name="twitter:card" content="summary_large_image">
</head><body><a class="skip" href="#content">Skip to content</a><header><a href="/auth">MAPA · ASR Health</a><nav aria-label="Information"><a href="/privacy/">Privacy</a><a href="/terms/">Terms</a><a href="/data-rights/">Data rights</a></nav></header>
<main id="content"><p class="eyebrow">ASR HEALTH · CONNECTED RESPONSE</p><h1>{escape(title)}</h1>{body}</main>
<footer>MAPA ambulance operations · <a href="/auth">Sign in</a> · <a href="/mapa-notices.html">Software notices</a></footer></body></html>'''

if __name__=='__main__':
    for slug,(title,description,body) in pages.items():
        dest=root/slug/'index.html';dest.parent.mkdir(parents=True,exist_ok=True)
        dest.write_text(document(title,description,'/'+slug+'/',body),encoding='utf-8')
    (root/'404.html').write_text(document('Page not found','The requested MAPA page could not be found.','/404.html','<p>This address may be incomplete or the page may have moved.</p><p>If you opened a private trip link, ask the operator for a new link.</p><p><a class="action" href="/auth">Return to sign in</a></p>'),encoding='utf-8')
    print('Created local privacy, terms, data-rights drafts and custom 404. No publication performed.')
