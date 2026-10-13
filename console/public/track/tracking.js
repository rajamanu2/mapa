/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
 'use strict';
 // Capability stays in memory only: never put it in storage, map requests or logs.
 const token=location.hash.slice(1);history.replaceState(null,'',location.pathname);
 const $=id=>document.getElementById(id),note=$('location-status');
 let active=false,closed=false,suspended=false,watch=null,timer=null,tick=null,map=null;
 let lastSent=0,sending=false,loading=false,expiry=0,fix=null,disconnected=false,generation=0,postController=null;
 let contactTime=0,contactCount=0;
 let pendingFix=null,sendTimer=null,sendFailures=0,sendNotBefore=0,readFailures=0,readController=null,readGeneration=0;
 let stage='awaiting_assignment',pickupFinished=false,confirmedPickup=null,sharedPickup=null;
 let confirmationSupported=false,callerConfirmation=null,latestCandidate=null,pendingConfirmation=null,locationReviewRequired=false,confirmationVersion=0;
 let routeLayer=null,trailLayer=null,plannedRoute=null,trailContext='',trailSegments=[],lastObserved=null;
 let showConsentOnOpen=true,returnToJourneyTop=true;
 const markers={ambulance:null,pickup:null,destination:null,caller:null,callerConfirmed:null};
 const online=()=>navigator.onLine!==false;
 const numeric=v=>typeof v==='number'&&Number.isFinite(v);
 const lowData=()=>navigator.connection?.saveData||['slow-2g','2g'].includes(navigator.connection?.effectiveType);
 const interval=()=>document.hidden?30000:lowData()?10000:5000;
 const backoff=n=>Math.min(60000,5000*2**Math.min(n,4));
 function cancelRead(){readGeneration++;if(readController)readController.abort();readController=null;loading=false;}
 const valid=p=>p&&numeric(p.latitude)&&numeric(p.longitude)&&Math.abs(p.latitude)<=90&&Math.abs(p.longitude)<=180&&!(p.latitude===0&&p.longitude===0);
 const safeLabel=(v,fallback)=>typeof v==='string'&&v.trim()?v.slice(0,180):fallback;
 const sameFix=(a,b)=>!!a&&!!b&&['latitude','longitude','accuracy_m','recorded_at'].every(k=>a[k]===b[k]);
 // Server receipts use 7 decimal coordinate places and 3 accuracy places. Keep
 // queued/retried request bodies exact; allow only that documented rounding here.
 const sameConfirmedFix=(receipt,submitted)=>!!receipt&&!!submitted&&receipt.recorded_at===submitted.recorded_at&&
  [['latitude',7,.00000005000001],['longitude',7,.00000005000001],['accuracy_m',3,.00050000001]].every(([key,digits,tolerance])=>
   numeric(receipt[key])&&numeric(submitted[key])&&Number(receipt[key].toFixed(digits))===receipt[key]&&Math.abs(receipt[key]-submitted[key])<=tolerance);
 const goodFix=p=>valid(p)&&numeric(p.accuracy_m)&&p.accuracy_m>=0&&p.accuracy_m<=100&&numeric(p.recorded_at)&&p.recorded_at>0&&p.recorded_at<=Date.now()+30000;
 const candidateFresh=()=>goodFix(latestCandidate)&&Date.now()-latestCandidate.recorded_at<=60000;
 const confirmation=p=>goodFix(p)&&numeric(p.confirmed_at)&&p.confirmed_at>0&&p.confirmed_at<=Date.now()+30000&&p.source==='caller_confirmed'&&typeof p.stale==='boolean'?p:null;
 function confirmationUi(){
  const visible=confirmationSupported&&!closed&&!pickupFinished;
  $('pickup-confirmation').hidden=!visible;
  $('confirm-pickup').disabled=!visible||!active||watch===null||!$('consent').checked||!candidateFresh()||!online()||disconnected||locationReviewRequired||!!pendingConfirmation||sameConfirmedFix(callerConfirmation,latestCandidate);
  $('confirm-pickup').textContent=callerConfirmation?'Confirm updated patient pickup':'Confirm this is the patient pickup';
  $('phone-fix').textContent=candidateFresh()?'Phone position: '+latestCandidate.latitude.toFixed(6)+', '+latestCandidate.longitude.toFixed(6)+' · accuracy about '+Math.round(latestCandidate.accuracy_m)+' m.':'Share your location and wait for a fresh phone position before confirming.';
  $('pickup-confirmed').textContent=pendingConfirmation?'Sending the pickup point you selected. It is not confirmed as delivered yet.':callerConfirmation?'You confirmed '+callerConfirmation.latitude.toFixed(6)+', '+callerConfirmation.longitude.toFixed(6)+' at '+new Date(callerConfirmation.confirmed_at).toLocaleTimeString()+' (accuracy about '+Math.round(callerConfirmation.accuracy_m)+' m). '+(callerConfirmation.stale?'This confirmation is old; share a fresh position and confirm again.':'The operator must review it before the crew’s pickup changes.'):'No patient pickup has been confirmed through this link yet. Phone sharing alone does not change the crew’s pickup.';
 }
 function controls(){
  $('share').disabled=!active||!online()||watch!==null;
  $('stop').disabled=watch===null;$('consent').disabled=!active;
  $('refresh').disabled=closed||loading||!online();
  $('call-open').disabled=!contactsFresh();$('contact-refresh').disabled=closed||loading||!online();
  confirmationUi();
 }
 function contactsFresh(){return !closed&&!loading&&!disconnected&&online()&&contactCount>0&&contactTime>0&&Date.now()-contactTime>=0&&Date.now()-contactTime<30000&&(!expiry||Date.now()<expiry);}
 function clearContacts(message,close=false){
  contactTime=0;contactCount=0;
  for(const role of ['driver','caller','hospital']){const a=$('contact-'+role);a.removeAttribute('href');a.setAttribute('aria-disabled','true');a.setAttribute('tabindex','-1');a.textContent='Unavailable';}
  $('contacts-status').textContent=message;$('contact-sheet-status').textContent=message;$('call-open').disabled=true;
  if(close&&$('contact-sheet').open)$('contact-sheet').close();
 }
 function updateContacts(data){
  if(closed||(expiry&&Date.now()>=expiry)){clearContacts('This trip link has ended. Contact numbers are no longer available.',true);return;}
  clearContacts('Contact numbers are unavailable. Use the operator number you already have.');
  for(const role of ['driver','caller','hospital']){
   const phone=data?.[role]?.phone;
   if(typeof phone!=='string'||!/^\+[1-9][0-9]{7,14}$/.test(phone))continue;
   const a=$('contact-'+role);a.textContent=phone;a.setAttribute('href','tel:'+phone);a.removeAttribute('aria-disabled');a.removeAttribute('tabindex');contactCount++;
  }
  if(contactCount){contactTime=Date.now();$('contacts-status').textContent='Current trip numbers are available. Review a contact before calling.';$('contact-sheet-status').textContent='Numbers from the latest trip update. Your phone may ask you to confirm the call.';}
 }
 function badge(label,state=''){$('connection').textContent=label;$('connection').dataset.state=state;}
 function remove(which){
  if(markers[which]){markers[which].remove();markers[which]=null;}
  const any=Object.values(markers).some(Boolean);$('recenter').disabled=!any;$('map-empty').hidden=any;
 }
 function stop(){
  generation++;if(watch!==null)navigator.geolocation.clearWatch(watch);watch=null;
  pendingFix=null;pendingConfirmation=null;latestCandidate=null;clearTimeout(sendTimer);sendTimer=null;sendFailures=0;sendNotBefore=0;
  if(postController)postController.abort();postController=null;sending=false;controls();
 }
 function stopCollection(){
  pickupFinished=true;active=false;stop();$('consent').checked=false;sharedPickup=null;callerConfirmation=null;remove('caller');remove('callerConfirmed');confirmationUi();
  note.textContent='Pickup is complete. Phone location sharing has stopped; ambulance tracking can continue for this trip.';
 }
 function end(message){
  cancelRead();
  active=false;closed=true;stop();clearContacts('This trip link has ended. Contact numbers are no longer available.',true);clearTimeout(timer);clearInterval(tick);fix=null;
  clearRoute();clearTrail();Object.keys(markers).forEach(remove);if(map){map.remove();map=null;}
  confirmedPickup=null;sharedPickup=null;callerConfirmation=null;callerMarkers();$('route-status').textContent='Trip tracking ended.';$('destination-card').hidden=true;$('destination-name').textContent='';$('destination-note').textContent='';
  $('status').textContent=message;$('position').textContent='';$('registration').textContent='Tracking ended';$('expiry').textContent='';
  $('consent').checked=false;note.textContent='Location sharing stopped.';badge('Ended','ended');controls();
  $('inactive-link').hidden=false;
  $('pickup-controls').hidden=true;$('pickup-confirmation').hidden=true;$('pickup-guidance').hidden=true;
  $('refresh').hidden=true;$('call-open').hidden=true;
 }
 function progress(value){
  const steps=['step-wait','step-assigned','step-hospital','step-complete'];
  const current=['awaiting_assignment','to_pickup','to_hospital','completed'].indexOf(value);
  steps.forEach((id,i)=>{$(id).removeAttribute('aria-current');$(id).classList.toggle('done',i<current);if(i===current)$(id).setAttribute('aria-current','step');});
 }
 function frame(){
  const points=Object.values(markers).filter(Boolean).map(m=>m.getLatLng());if(!map||!points.length)return;
  if(points.length===1)map.setView(points[0],15,{animate:false});else map.fitBounds(points,{paddingTopLeft:[45,100],paddingBottomRight:[45,40],maxZoom:16,animate:false});
 }
 function plot(which,p,label){
  if(!window.L)return;
  try{
   if(!map){map=L.map('map').setView([p.latitude,p.longitude],15);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors',maxZoom:19}).addTo(map);}
   let marker=markers[which];const fresh=!marker;
   if(!marker){marker=L.circleMarker([p.latitude,p.longitude],{radius:10,color:'#fff',weight:3,fillColor:({ambulance:'#00796c',pickup:'#265ed4',destination:'#9a4da3',caller:'#d46a12',callerConfirmed:'#a24c00'})[which],fillOpacity:1}).addTo(map).bindTooltip(label);markers[which]=marker;}
   else{marker.setLatLng([p.latitude,p.longitude]);if(marker.setTooltipContent)marker.setTooltipContent(label);}
   $('map-empty').hidden=true;$('recenter').disabled=false;if(fresh)frame();
  }catch(_){$('map-empty').hidden=false;$('map-empty').textContent='Map unavailable. Location updates are shown below.';}
 }
 function pickupMarker(){
  const point=confirmedPickup||sharedPickup;
  $('pickup-legend-label').textContent=confirmedPickup?'Confirmed pickup':'Your shared pickup';
  if(point)plot('pickup',point,confirmedPickup?'Operator-confirmed pickup':'Your shared pickup — operator confirmation required');else remove('pickup');
  callerMarkers();
 }
 function callerMarkers(){
  const visible=confirmationSupported&&!closed&&!pickupFinished;
  if(visible&&callerConfirmation)plot('callerConfirmed',callerConfirmation,'Patient pickup confirmed by you — operator review required');else remove('callerConfirmed');
  if(visible&&candidateFresh()&&!sameConfirmedFix(callerConfirmation,latestCandidate))plot('caller',latestCandidate,'Your current phone position — not a confirmed patient pickup');else remove('caller');
  $('caller-legend').hidden=!markers.caller;$('caller-confirmed-legend').hidden=!markers.callerConfirmed;
 }
 function clearRoute(){if(routeLayer)routeLayer.remove();routeLayer=null;plannedRoute=null;$('route-legend').hidden=true;}
 function clearTrail(){if(trailLayer)trailLayer.remove();trailLayer=null;trailSegments=[];lastObserved=null;$('trail-legend').hidden=true;$('trail-note').hidden=true;}
 function routeStatus(){
  $('route-status').textContent=plannedRoute?'Planned road route to '+(stage==='to_hospital'?'the assigned hospital / drop':'the operator-confirmed pickup')+' · '+(plannedRoute.distance_m/1000).toFixed(1)+' km remaining. This route does not include live traffic or a guaranteed arrival time.':'Road route unavailable. The map shows reported positions and confirmed trip locations; a straight line between pins is not a road route.';
  $('trail-note').hidden=!trailLayer;
 }
 function routeDistance(a,b){const rad=Math.PI/180,lat=(a.latitude+b.latitude)*rad/2;return Math.hypot((a.latitude-b.latitude)*111195,(a.longitude-b.longitude)*111195*Math.cos(lat));}
 function routeValid(r,d,target){
  return r&&r.source==='private_osrm'&&r.leg===d.leg&&typeof d.revision==='string'&&r.context_revision===d.revision&&r.source_recorded_at===fix?.recorded_at&&numeric(r.generated_at)&&r.generated_at>0&&r.generated_at<=Date.now()+30000&&numeric(r.expires_at)&&r.expires_at>Date.now()&&r.expires_at<=r.generated_at+60000&&numeric(r.distance_m)&&r.distance_m>=0&&numeric(r.duration_s)&&r.duration_s>=0&&Array.isArray(r.coordinates)&&r.coordinates.length>=2&&r.coordinates.length<=4096&&r.coordinates.every(p=>Array.isArray(p)&&p.length===2&&valid({longitude:p[0],latitude:p[1]}))&&routeDistance(fix,{longitude:r.coordinates[0][0],latitude:r.coordinates[0][1]})<=300&&routeDistance(target,{longitude:r.coordinates.at(-1)[0],latitude:r.coordinates.at(-1)[1]})<=300;
 }
 function renderJourney(d){
  const target=stage==='to_hospital'?d.destination:d.pickup;
  const context=JSON.stringify([stage,d.ambulance?.registration||'',valid(target)?[target.latitude,target.longitude]:null]);
  if(context!==trailContext){clearTrail();trailContext=context;}
  clearRoute();
  const fresh=fix&&!fix.stale&&Date.now()-fix.recorded_at<=120000&&online()&&!disconnected;
  if(fresh&&valid(target)&&routeValid(d.planned_road_route,d,target)&&map&&window.L?.polyline){
   try{routeLayer=L.polyline(d.planned_road_route.coordinates.map(p=>[p[1],p[0]]),{color:'#265ed4',weight:5,opacity:.8}).addTo(map);plannedRoute=d.planned_road_route;$('route-legend').hidden=false;}catch(_){clearRoute();}
  }
  if(fresh&&(!lastObserved||fix.recorded_at>lastObserved.recorded_at)){
   const point=[fix.latitude,fix.longitude];
   if(!lastObserved||fix.recorded_at-lastObserved.recorded_at>120000)trailSegments.push([point]);
   else if(fix.latitude!==lastObserved.latitude||fix.longitude!==lastObserved.longitude)trailSegments.at(-1).push(point);
   lastObserved={latitude:fix.latitude,longitude:fix.longitude,recorded_at:fix.recorded_at};
   while(trailSegments.reduce((n,s)=>n+s.length,0)>100){trailSegments[0].shift();if(!trailSegments[0].length)trailSegments.shift();}
   if(trailLayer)trailLayer.remove();trailLayer=null;
   const lines=trailSegments.filter(s=>s.length>1);
   if(lines.length&&map&&window.L?.polyline){try{trailLayer=L.polyline(lines,{color:'#00796c',weight:3,dashArray:'3 7',opacity:.8}).addTo(map);}catch(_){trailLayer=null;}}
  }
  $('trail-legend').hidden=!trailLayer;routeStatus();
 }
 function freshness(){
  if(closed)return;
  if(expiry&&Date.now()>=expiry){end('This trip link has expired. Ask your operator for a new link.');return;}
  if(contactTime&&!contactsFresh()&&!loading)clearContacts('Contact numbers need a fresh trip update. Reconnect and refresh before calling.');
  confirmationUi();callerMarkers();
  if(plannedRoute&&(Date.now()>=plannedRoute.expires_at||!fix||fix.stale||Date.now()-fix.recorded_at>120000||disconnected||!online())){clearRoute();routeStatus();}
  if(!fix)return;
  const age=Date.now()-fix.recorded_at,stale=fix.stale||age>120000||age< -30000||disconnected||!online(),seconds=Math.max(0,Math.floor(age/1000));
  $('position').textContent=(stale?'Last known position — updates are delayed. ':'Position received. ')+new Date(fix.recorded_at).toLocaleTimeString()+' · '+(seconds<60?seconds+' seconds ago':Math.floor(seconds/60)+' minutes ago')+' · '+(fix.source==='hardware_gps'?'Ambulance GPS':fix.source==='crew_phone'?'Crew phone GPS':'Device GPS');
  badge(!online()?'Offline':disconnected?'Reconnecting':stale?'Delayed GPS':'GPS received',stale?'warning':'');
 }
 async function api(path,body){
  const controller=new AbortController();if(body)postController=controller;else readController=controller;
  const timeout=setTimeout(()=>controller.abort(),25000);
  try{
   const r=await fetch('/mapa/mobile/v1/tracking/'+path,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined,credentials:'omit',cache:'no-store',redirect:'error',referrerPolicy:'no-referrer',signal:controller.signal});
   if(controller.signal.aborted)throw new Error('Request cancelled');
   if(!r.ok){
    // Only a 410 means the server has ended this capability. A rejected or
    // missing GPS upload must not erase a still-valid tracker after permission.
    if(r.status===410)end('This trip link has ended, expired or was replaced. Ask your operator for a new link.');
    const error=new Error(r.status===422?'Your location was not accepted. Wait for a fresh GPS fix or give the operator your address.':'Tracking is temporarily unavailable.');
    error.status=r.status;
    const retry=Number(r.headers?.get('Retry-After'));
    error.retryAfter=Number.isFinite(retry)&&retry>0?Math.min(retry*1000,300000):0;
    throw error;
   }
   return await r.json();
  }finally{clearTimeout(timeout);if(body&&postController===controller)postController=null;if(!body&&readController===controller)readController=null;}
 }
 function scheduleSend(delay=0){
  clearTimeout(sendTimer);sendTimer=null;
  if((!pendingFix&&!pendingConfirmation)||watch===null||!active||closed||!online()||disconnected||locationReviewRequired||!$('consent').checked)return;
  sendTimer=setTimeout(sendLatest,Math.max(delay,sendNotBefore-Date.now(),interval()-(Date.now()-lastSent),0));
 }
 async function sendLatest(){
  sendTimer=null;freshness();
  if(sending||(!pendingFix&&!pendingConfirmation)||!active||closed||watch===null||!online()||disconnected||locationReviewRequired||!$('consent').checked)return;
  if(pendingConfirmation&&Date.now()-pendingConfirmation.recorded_at>300000){pendingConfirmation=null;note.textContent='Your confirmation could not be delivered while fresh. Check the newest phone position and confirm it again.';confirmationUi();}
  if(!pendingConfirmation&&pendingFix&&Date.now()-pendingFix.recorded_at>60000){pendingFix=null;note.textContent='Waiting for a fresh location after the connection delay.';return;}
  const body=pendingConfirmation||pendingFix;if(!body)return;
  const run=generation;sending=true;let delay=0;
  try{
   const receipt=await api('location',body);if(run!==generation||closed)return;
   if(body.confirm_pickup===true){const accepted=confirmation(receipt?.caller_pickup_confirmation);if(!accepted||!sameConfirmedFix(accepted,body)||receipt.pickup_requires_operator_confirmation!==true)throw new Error('Pickup confirmation receipt is unavailable.');callerConfirmation=accepted;confirmationVersion++;if(pendingConfirmation===body)pendingConfirmation=null;}
   lastSent=Date.now();sendFailures=0;sendNotBefore=0;if(pendingFix===body||sameFix(pendingFix,body))pendingFix=null;
   sharedPickup={latitude:body.latitude,longitude:body.longitude};pickupMarker();
   note.textContent=body.confirm_pickup===true?'Your selected patient pickup was delivered. Waiting for operator confirmation before the crew’s pickup changes.':'Location shared at '+new Date().toLocaleTimeString()+' (accuracy about '+Math.round(body.accuracy_m)+' m). Waiting for operator confirmation.';
  }catch(e){
   if(run!==generation||closed)return;
   if(e.status===409){if(pendingConfirmation===body)pendingConfirmation=null;if(pendingFix===body||sameFix(pendingFix,body))pendingFix=null;locationReviewRequired=true;note.textContent='This trip changed. Refreshing its current pickup-sharing permissions.';await refresh();}
   else if([401,403,404].includes(e.status)){
    stop();note.textContent='Location sharing could not be verified. The tracker remains open; refresh its trip update and ask the operator for help if sharing still fails.';
    await refresh();
   }
   else if(e.status===422||e.status===400){if(pendingConfirmation===body)pendingConfirmation=null;if(pendingFix===body||sameFix(pendingFix,body))pendingFix=null;if(body.confirm_pickup===true&&sameFix(latestCandidate,body))latestCandidate=null;callerMarkers();note.textContent=e.message;}
   else{delay=Math.max(backoff(++sendFailures),e.retryAfter||0);sendNotBefore=Date.now()+delay;note.textContent=body.confirm_pickup===true?'Confirmation delivery is uncertain. Retrying only the exact pickup point you selected; the crew’s pickup has not been changed by this page.':'Location not yet delivered. Retrying the newest fresh location when connected; no old location history is stored.';}
  }finally{if(run===generation){sending=false;controls();scheduleSend(delay);}}
 }
 async function refresh(){
  if(closed||loading)return;clearTimeout(timer);
  if(!online()){disconnected=true;badge('Offline','warning');$('status').textContent='You are offline. Reconnect to receive ambulance updates.';freshness();controls();return;}
  const readRun=++readGeneration,confirmationReadVersion=confirmationVersion;let retryAfter=0;
  loading=true;clearContacts('Refreshing trip contact numbers…');controls();
  try{
   const d=await api('view');if(closed||readRun!==readGeneration)return;
   const extended=typeof d.stage==='string';
   if(extended){
    if(!['awaiting_assignment','to_pickup','to_hospital','completed'].includes(d.stage)||!numeric(d.expires_at)||d.expires_at<=0)throw new Error('Invalid trip response');
    stage=d.stage;
   }else{
    if(!['awaiting_assignment','ambulance_assigned','pickup_complete'].includes(d.status))throw new Error('Invalid trip response');
    stage=d.status==='ambulance_assigned'?'to_pickup':d.status==='pickup_complete'?'completed':'awaiting_assignment';
   }
   disconnected=false;locationReviewRequired=false;readFailures=0;expiry=numeric(d.expires_at)?d.expires_at:0;
   confirmationSupported=Object.prototype.hasOwnProperty.call(d,'caller_pickup_confirmation');
   if(confirmationReadVersion===confirmationVersion)callerConfirmation=confirmationSupported?confirmation(d.caller_pickup_confirmation):null;
   progress(stage);
   if(stage==='completed'){end(extended?'Trip completed. Ambulance tracking for this link has ended.':'Pickup completed. Ambulance tracking for this link has ended.');return;}
   const hospital=stage==='to_hospital',assigned=stage!=='awaiting_assignment';
   if(hospital)stopCollection();
   active=!pickupFinished&&!hospital&&(!extended||d.caller_location_allowed===true);
   if(!active&&watch!==null)stop();
   $('status').textContent=hospital?'Travelling to the assigned hospital or drop. Follow the ambulance’s reported location below.':assigned?'Your ambulance is assigned. Follow its reported location below.':'Your operator is arranging an ambulance.';
   $('registration').textContent=assigned?safeLabel(d.ambulance?.registration,'Ambulance assigned'):'Awaiting assignment';
   $('expiry').textContent=expiry?'Private link expires at '+new Date(expiry).toLocaleTimeString()+'.':'';
   $('pickup-controls').hidden=hospital;$('pickup-guidance').hidden=hospital;
   $('pickup-title').textContent=hospital?'Pickup completed':'Share the pickup location';
   $('pickup-description').textContent=hospital?'Your phone’s pickup-location sharing is finished. This page now shows read-only trip updates to the assigned hospital or drop.':'Only share this phone’s location if you are beside the patient. The operator must confirm the pickup with you.';
   $('pickup-legend').hidden=hospital;$('destination-legend').hidden=!hospital;
   confirmedPickup=!hospital&&valid(d.pickup)?d.pickup:null;
   if(hospital){remove('pickup');$('destination-card').hidden=false;$('destination-name').textContent=safeLabel(d.destination?.label,'Destination awaiting confirmation');
    if(valid(d.destination)){plot('destination',d.destination,'Assigned hospital / drop');$('destination-note').textContent='Assigned destination. Contact your operator about any change.';}
    else{remove('destination');$('destination-note').textContent='Destination coordinates are unavailable. No destination pin is shown.';}
   }else{remove('destination');$('destination-card').hidden=true;$('destination-name').textContent='';$('destination-note').textContent='';pickupMarker();}
   const p=d.ambulance?.location;fix=valid(p)&&numeric(p.recorded_at)&&p.recorded_at>0&&p.recorded_at<=Date.now()+30000?p:null;
   if(fix){plot('ambulance',fix,'Assigned ambulance');freshness();}
   else{remove('ambulance');$('position').textContent='No current ambulance location is available.';badge(assigned?'Waiting for GPS':'Awaiting assignment','warning');}
   renderJourney(d);updateContacts(extended?d.contacts:null);freshness();scheduleSend();
  }catch(e){if(!closed&&readRun===readGeneration){
   if(e.status===401||e.status===403){end('This private link could not be verified. Ask your operator to check it or send a new link.');return;}
   readFailures++;retryAfter=e.retryAfter||0;disconnected=true;clearContacts('Trip updates failed. Refresh successfully before calling.',true);$('status').textContent='Updates interrupted. Retrying automatically; contact your operator if needed.';badge('Reconnecting','warning');freshness();
  }}
  finally{if(readRun===readGeneration){loading=false;controls();if(showConsentOnOpen&&active&&!closed&&!$('pickup-controls').hidden){showConsentOnOpen=false;$('consent').scrollIntoView({block:'center',behavior:'smooth'});}if(!closed&&online())timer=setTimeout(refresh,Math.max(readFailures?backoff(readFailures):interval(),retryAfter));}}
 }
 $('share').onclick=()=>{
  freshness();if(!active||!online()||watch!==null)return;
  if(!$('consent').checked){note.textContent='Confirm that you are at the pickup and agree to share.';return;}
  if(!navigator.geolocation){note.textContent='This browser cannot share location. Open this link in your normal browser or give the operator your address.';$('location-help').open=true;return;}
  const run=++generation;note.textContent='Allow location access when your phone asks. If GPS is off, turn on Location in phone settings; then try again.';
  watch=navigator.geolocation.watchPosition(p=>{
   freshness();if(run!==generation||!active||!$('consent').checked)return;
   const coords={latitude:p.coords.latitude,longitude:p.coords.longitude};
   if(!valid(coords)||!numeric(p.timestamp)||Date.now()-p.timestamp>120000||p.timestamp>Date.now()+30000||!numeric(p.coords.accuracy)||p.coords.accuracy<0||p.coords.accuracy>100){note.textContent='Waiting for a fresh, valid GPS fix. Give the operator your address if needed.';return;}
   const candidate={consent:true,...coords,accuracy_m:p.coords.accuracy,recorded_at:p.timestamp};
   if(latestCandidate&&candidate.recorded_at<=latestCandidate.recorded_at)return;
   latestCandidate=candidate;pendingFix=candidate;confirmationUi();callerMarkers();
   if(returnToJourneyTop){returnToJourneyTop=false;window.scrollTo({top:0,behavior:'smooth'});}
   if(!sending&&Date.now()-lastSent>=interval()&&!sendFailures)return sendLatest();
   if(!sendTimer&&!sending)scheduleSend();
  },e=>{
   if(run!==generation)return;if(e.code===1)stop();$('location-help').open=true;
   note.textContent=e.code===1?'Location permission was denied. Turn on phone Location and allow location in this browser’s site settings, then try again. Or tell your operator the address and landmark.':e.code===3?'GPS timed out. Check that phone Location is on, try again outdoors, or tell your operator the address and landmark.':'GPS is unavailable. Turn on Location in phone settings and try again, or tell your operator the address and landmark.';
  },{enableHighAccuracy:true,maximumAge:10000,timeout:30000});controls();
 };
 $('confirm-pickup').onclick=()=>{
  freshness();if($('confirm-pickup').disabled)return;
  pendingConfirmation={...latestCandidate,confirm_pickup:true};confirmationUi();
  if(!sending&&Date.now()>=sendNotBefore&&Date.now()-lastSent>=interval())return sendLatest();
  scheduleSend();
 };
 $('stop').onclick=()=>{stop();sharedPickup=null;pickupMarker();note.textContent='Location sharing stopped. No new phone locations will be sent.';};
 $('consent').onchange=()=>{if(!$('consent').checked){stop();sharedPickup=null;pickupMarker();note.textContent='Consent withdrawn. No new phone locations will be sent.';}};
 $('call-open').onclick=async()=>{if(!contactsFresh())return;await refresh();if(contactsFresh())$('contact-sheet').showModal();};
 $('call-close').onclick=()=>{$('contact-sheet').close();};$('contact-refresh').onclick=refresh;
 for(const role of ['driver','caller','hospital'])$('contact-'+role).onclick=e=>{if(!contactsFresh()){e.preventDefault();clearContacts('Contact numbers are outdated. Refresh before calling.');}};
 $('refresh').onclick=refresh;$('recenter').onclick=frame;
 addEventListener('offline',()=>{if(closed)return;cancelRead();disconnected=true;clearTimeout(sendTimer);sendTimer=null;clearContacts('You are offline. Reconnect and refresh before calling.',true);clearTimeout(timer);note.textContent=watch!==null?'Offline. Sharing will resume automatically after reconnecting while your consent remains enabled.':'Offline. Location is not being shared.';$('status').textContent='You are offline. Reconnect to receive ambulance updates.';badge('Offline','warning');freshness();controls();});
 addEventListener('online',()=>{if(!closed){controls();refresh();}});
 addEventListener('visibilitychange',()=>{if(!document.hidden&&!closed){freshness();refresh();}});
 addEventListener('pagehide',()=>{suspended=!closed;closed=true;active=false;cancelRead();stop();clearContacts('Refresh trip contacts after returning to this page.',true);clearTimeout(timer);clearInterval(tick);});
 addEventListener('pageshow',e=>{if(e.persisted&&suspended){suspended=false;closed=false;note.textContent=pickupFinished?'Phone location sharing remains stopped.':'Sharing is paused. Select Share pickup location to resume.';freshness();if(!closed){tick=setInterval(freshness,1000);refresh();}}});
 if(/^[a-f0-9]{64}$/.test(token)){tick=setInterval(freshness,1000);refresh();}else end('This link is missing or invalid. Reopen the original private link from your message, or ask your operator for a new link.');
})();
