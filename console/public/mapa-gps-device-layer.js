/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const CONTRACT='mapa-gps-map-v1',PATH='/api/int/v1/mapa/crew-review/gps-map';
    const same=(a,b)=>!!a&&!!b&&a.user===b.user&&a.company===b.company&&a.token===b.token;
    const id=value=>typeof value==='string'&&/^[A-Za-z0-9_:-]{1,120}$/.test(value);
    const text=value=>typeof value==='string'?value.slice(0,180):'';
    function position(location,now){
        if(!location||typeof location!=='object')return null;
        const latitude=location.latitude,longitude=location.longitude,recorded=location.recorded_at;
        if(typeof latitude!=='number'||typeof longitude!=='number'||!Number.isFinite(latitude)||!Number.isFinite(longitude)||Math.abs(latitude)>90||Math.abs(longitude)>180||(latitude===0&&longitude===0)
            ||(recorded!==null&&recorded!==undefined&&(!Number.isSafeInteger(recorded)||recorded<=0||recorded>now+30000)))return null;
        return {...location,latitude,longitude,recorded_at:recorded??null,stale:location.stale===true||!recorded||now-recorded>180000};
    }
    function snapshot(feed,company){
        const now=feed?.server_time??feed?.generated_at;
        if(feed?.contract!==CONTRACT||feed.company_id!==company||!Number.isSafeInteger(now)||!Array.isArray(feed.devices)||feed.devices.length>1000)throw new Error('GPS map response did not match this organization.');
        const seen=new Set();const devices=feed.devices.map(row=>{
            if(!id(row?.id)||seen.has(row.id)||typeof row.mapped!=='boolean')throw new Error('GPS device identity is invalid.');seen.add(row.id);
            const rawLocation=row.location??{latitude:row.latitude,longitude:row.longitude,recorded_at:row.recorded_at,provider_received_at:row.provider_received_at,accuracy_m:row.accuracy_m??null};
            const location=position({...rawLocation,stale:rawLocation.stale===true||row.stale===true||feed.feed_stale===true},now);
            return {...row,label:text(row.provider_label||row.label||row.external_id)||row.id,registration:row.mapped?text(row.registration):'',location,
                state:location?(row.connected===false||row.gps_on===false||row.provider_status==='offline'?'offline':location.stale?'stale':'fresh'):'no_position'};
        });
        return {...feed,server_time:now,devices};
    }
    function bounds(devices){const points=devices.map(row=>row.location).filter(Boolean);return points.length?[[Math.min(...points.map(p=>p.latitude)),Math.min(...points.map(p=>p.longitude))],[Math.max(...points.map(p=>p.latitude)),Math.max(...points.map(p=>p.longitude))]]:null;}
    function trailSegments(points){const segments=[];let segment=[],last=0;
        for(const point of points||[]){if(!Number.isFinite(point?.latitude)||!Number.isFinite(point?.longitude)||Math.abs(point.latitude)>90||Math.abs(point.longitude)>180||!Number.isSafeInteger(point.recorded_at)||point.recorded_at<=0)continue;
            if(last&&(point.recorded_at<=last||point.recorded_at-last>600000)&&segment.length){segments.push(segment);segment=[];}
            segment.push([point.latitude,point.longitude]);last=point.recorded_at;}
        if(segment.length)segments.push(segment);return segments;}
    function aged(feed,elapsed){
        if(!feed)return null;const seconds=Math.max(0,Math.floor(elapsed/1000)),age=Number.isFinite(feed.snapshot_age_seconds)?feed.snapshot_age_seconds+seconds:null;
        return snapshot({...feed,server_time:feed.server_time+Math.max(0,Math.floor(elapsed)),snapshot_age_seconds:age,feed_stale:feed.feed_stale===true||(age!==null?age>120:seconds>120)},feed.company_id);
    }
    const escape=value=>String(value).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    function timestamp(value){
        if(!Number.isSafeInteger(value))return 'Time unavailable';
        // Render the explicit +05:30 clock without browser locale overrides.
        const date=new Date(value+19800000),pad=n=>String(n).padStart(2,'0'),hour=date.getUTCHours();
        return pad(date.getUTCDate())+' '+['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][date.getUTCMonth()]+' '+date.getUTCFullYear()+', '+pad(hour%12||12)+':'+pad(date.getUTCMinutes())+':'+pad(date.getUTCSeconds())+' '+(hour<12?'AM':'PM')+' IST';
    }
    function mapPadding(rect,dividers,viewportBottom){
        if(!rect)return [30,30];
        const horizontal=dividers.filter(d=>d.width>rect.width/2&&d.height<30&&d.top>rect.top+100&&d.top<rect.bottom);
        const bottom=Math.min(viewportBottom||rect.bottom,...horizontal.map(d=>d.top));
        return [30,Math.max(30,rect.bottom-bottom+30)];
    }
    function fleetName(row){return text(row.provider_label)||row.registration||row.label;}
    function matchingVehicle(devices,vehicleId){return typeof vehicleId==='string'&&vehicleId?devices.find(device=>device.mapped&&device.vehicle_id===vehicleId)||null:null;}
    function fleetRows(devices,query=''){
        const term=query.trim().toLowerCase();
        return devices.filter(row=>!term||[row.registration,row.label,row.external_id,row.id,row.mapped?'mapped':'unmapped',row.state].join(' ').toLowerCase().includes(term))
            .slice().sort((a,b)=>Number(b.mapped)-Number(a.mapped)||fleetName(a).localeCompare(fleetName(b)));
    }
    const pure={same,position,snapshot,bounds,trailSegments,aged,escape,timestamp,mapPadding,fleetRows,fleetName,matchingVehicle};
    if(typeof document==='undefined'){if(typeof module==='object'&&module.exports)module.exports=pure;return;}
    if(typeof window.define!=='function')return;
    const initialized=new WeakSet();
    // The map manager belongs to the lazy FleetOps engine. Looking it up while
    // the root console boots tries to resolve an engine module before it loads.
    for(const namespace of ['@fleetbase/fleetops-engine'])window.define(namespace+'/instance-initializers/mapa-gps-device-layer',['exports','@ember/destroyable/index'],(exports,destroyable)=>{
        exports.default={name:'mapa-gps-device-layer',initialize(owner){if(initialized.has(owner))return;initialized.add(owner);const state=initialize(owner);if(state)destroyable?.registerDestructor?.(owner,state.destroy);}};
    });
    function initialize(owner){
        const session=owner.lookup('service:session'),user=owner.lookup('service:current-user'),native=owner.lookup('service:fetch'),router=owner.lookup('service:host-router')||owner.lookup('service:router');
        const manager=owner.lookup('service:map-manager')||owner.lookup('service:leaflet-map-manager');
        if(!session||!user||!native?.getHeaders||!manager)return;
        const style=document.createElement('style');style.textContent='.mapa-gps-controls{display:flex;gap:6px;align-items:center;margin-left:auto}.mapa-gps-controls button,.mapa-gps-panel button{border:1px solid #cbd5e1;border-radius:7px;background:#f8fafc;color:#0f172a;padding:6px 10px;font:inherit;font-size:12px;cursor:pointer}.mapa-gps-panel{position:fixed;right:16px;top:104px;z-index:9000;width:min(480px,calc(100vw - 32px));max-height:70vh;overflow:auto;background:white;color:#0f172a;border:1px solid #cbd5e1;border-radius:12px;padding:16px;box-shadow:0 12px 36px #0003}.mapa-gps-panel h3{font-size:16px;font-weight:700;margin:0 0 10px}.mapa-gps-panel p{font-size:12px;margin:6px 0}.mapa-gps-panel article{border-top:1px solid #e2e8f0;padding:10px 0}.mapa-gps-panel strong{font-size:13px}.mapa-gps-panel .mapa-gps-actions{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.mapa-gps-detail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:12px 0}.mapa-gps-detail div{border:1px solid #e2e8f0;border-radius:7px;padding:8px;min-width:0}.mapa-gps-detail dt{font-size:11px;color:#64748b}.mapa-gps-detail dd{font-size:13px;font-weight:600;margin:3px 0 0;overflow-wrap:anywhere}.mapa-gps-detail-heading{font-weight:700;margin:14px 0 5px}.dark .mapa-gps-panel{background:#111827;color:#f8fafc;border-color:#475569}.dark .mapa-gps-controls button,.dark .mapa-gps-panel button{background:#1f2937;color:#f8fafc;border-color:#475569}.dark .mapa-gps-detail div{border-color:#475569}.dark .mapa-gps-detail dt{color:#cbd5e1}';document.head.append(style);
        let scope=null,data=null,lastAttempt=0,error='',destroyed=false,busy=false,controller=null,version=0,controls=null,panel=null,button=null,shown=false,selectedDeviceId=null,followDeviceId=null,journey=null,journeyError='',journeyController=null,journeyVersion=0,trailLayers=[],adapter=null,receivedAt=0,drawnStates='';
        const clock=()=>typeof performance!=='undefined'&&typeof performance.now==='function'?performance.now():Date.now();
        const displayed=()=>aged(data,Math.max(0,clock()-receivedAt));
        const states=feed=>JSON.stringify(feed?.devices.map(row=>[row.id,row.state])||[]);
        const pins=new Map();let legacyMap=null,legacyAdapter=null,drawGeneration=0,labelsVisible=true;
        let fleetPanel=null,fleetRoot=null,fleetBody=null,fleetSummary=null,fleetNotice=null,fleetScroll=null,fleetSearch=null,fleetMode='provider',fleetSignature='';
        const fleetStyle=document.createElement('style');fleetStyle.textContent=`
            #tabpanel-ambulances[data-mapa-gps-view="provider"] > :not(.mapa-gps-bottom){display:none!important}
            .mapa-gps-bottom{--gps-bg:#fff;--gps-text:#0f172a;--gps-muted:#475569;--gps-border:#e2e8f0;background:var(--gps-bg);color:var(--gps-text);display:flex;flex-direction:column;min-height:0;font-size:12px}
            .dark .mapa-gps-bottom{--gps-bg:#111827;--gps-text:#f1f5f9;--gps-muted:#cbd5e1;--gps-border:#374151}
            .mapa-gps-bottom-header{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--gps-border);flex:none}
            .mapa-gps-bottom button,.mapa-gps-bottom input{font:inherit;color:inherit;background:var(--gps-bg);border:1px solid var(--gps-border);border-radius:6px;padding:5px 9px}
            .mapa-gps-bottom button{cursor:pointer}.mapa-gps-bottom button:disabled{cursor:default;opacity:.55}
            .mapa-gps-bottom button[aria-pressed="true"]{background:#2563eb;color:white;border-color:#2563eb}
            .mapa-gps-bottom input{width:220px;max-width:100%}.mapa-gps-bottom-summary{font-weight:600}
            .mapa-gps-bottom-notice{padding:5px 12px;margin:0;color:var(--gps-muted);flex:none}
            .mapa-gps-bottom-scroll{overflow:auto;scrollbar-gutter:stable;min-height:0;flex:1;overscroll-behavior:contain}
            .mapa-gps-bottom table{width:100%;border-collapse:collapse;white-space:nowrap;text-align:left}
            .mapa-gps-bottom th{position:sticky;top:0;background:var(--gps-bg);z-index:1;font-weight:600}
            .mapa-gps-bottom th,.mapa-gps-bottom td{padding:8px 12px;border-bottom:1px solid var(--gps-border)}
            .mapa-gps-bottom small{display:block;color:var(--gps-muted);font-size:11px;margin-top:3px}
            .mapa-gps-bottom .gps-state{display:inline-block;padding:3px 6px;border-radius:5px;font-weight:600;background:#f1f5f9;color:#334155}
            .mapa-gps-bottom .gps-state-fresh{background:#dbeafe;color:#1e40af}.mapa-gps-bottom .gps-state-stale{background:#fef3c7;color:#92400e}
            .mapa-gps-bottom .gps-unmapped{color:#7c3aed}.dark .mapa-gps-bottom .gps-unmapped{color:#c4b5fd}
            .mapa-gps-bottom [hidden]{display:none!important}
            .mapa-gps-map-label{width:220px!important;max-width:220px;white-space:normal!important;text-align:center;font-size:10px!important;line-height:1.25!important;padding:4px 6px!important;color:#0f172a!important;background:#fff!important;border:1px solid #94a3b8!important;border-radius:5px!important;box-shadow:0 1px 4px #0003}
            .mapa-gps-map-label small{display:block;font-size:9px;color:#475569;margin-top:2px}
            .dark .mapa-gps-map-label{color:#f8fafc!important;background:#111827!important;border-color:#64748b!important}.dark .mapa-gps-map-label small{color:#cbd5e1}
            .mapa-gps-labels-hidden .mapa-gps-map-label{display:none!important}
            .mapa-provider-popup{margin:7px 0 0;padding:8px;border-top:1px solid #475569;color:#e2e8f0;font-size:11px;line-height:1.4}
            .mapa-provider-popup strong{display:block;color:#fff;margin-bottom:3px}.mapa-provider-popup span{display:block}
        `;document.head.append(fleetStyle);
        function currentAdapter(){
            if(manager.adapter&&manager.isReady!==false)return manager.adapter;
            const map=manager.map;
            if(!map||!window.L?.marker||typeof map.addLayer!=='function')return null;
            if(map!==legacyMap){legacyMap=map;const markers=new Map();legacyAdapter={
                addMarker(key,latitude,longitude,options){const marker=window.L.marker([latitude,longitude],{icon:window.L.icon({iconUrl:options.iconUrl,iconSize:[28,28],iconAnchor:[14,14]}),title:options.title}).addTo(map);marker.bindTooltip(options.tooltip,options.tooltipOptions);marker.on('click',options.onClick);markers.set(key,marker);return marker;},
                removeMarker(key){markers.get(key)?.remove();markers.delete(key);},fitBounds(box,options){map.fitBounds(box,options);},flyTo(latitude,longitude,zoom){map.flyTo([latitude,longitude],zoom);}
            };}return legacyAdapter;
        }
        const el=(tag,label)=>{const node=document.createElement(tag);if(label!==undefined)node.textContent=label;return node;};
        const time=timestamp;
        function syncNativePopup(){
            const popup=document.querySelector?.('.leaflet-popup-content');if(!popup)return;
            const label=Array.from(popup.querySelectorAll('div')).find(node=>node.children.length===0&&node.textContent.trim()==='Vehicle #');
            const vehicleId=label?.nextElementSibling?.textContent.trim();if(!vehicleId)return;
            const row=matchingVehicle(displayed()?.devices||[],vehicleId);
            const host=popup.firstElementChild?.firstElementChild;if(!host)return;
            let detail=host.querySelector('.mapa-provider-popup');
            if(!row){detail?.remove();return;}
            if(!detail){detail=el('section');detail.className='mapa-provider-popup';host.append(detail);}
            const fix=row.location;const speed=Number.isFinite(row.speed_kmh)?row.speed_kmh.toFixed(1)+' km/h':'Not reported';
            const heading=Number.isFinite(row.heading_degrees)?Math.round(row.heading_degrees)+'°':'Not reported';
            const signature=JSON.stringify([row.id,row.state,fix,row.speed_kmh,row.heading_degrees]);
            if(detail.dataset.signature===signature)return;detail.dataset.signature=signature;
            detail.replaceChildren(el('strong','Way4Track GPS · '+(row.state==='fresh'?'recent report':row.state==='offline'?'offline / last known':'stale / last known')),
                el('span','Speed at report: '+(fix?speed:'Not reported')+' · Heading: '+(fix?heading:'Not reported')),
                el('span','Position: '+(fix?fix.latitude.toFixed(6)+', '+fix.longitude.toFixed(6):'No valid coordinates')),
                el('span','GPS fix: '+time(fix?.recorded_at)),action('Open vehicle, patient and trip details',()=>showDevice(row)));
        }
        function readScope(){const auth=session.isAuthenticated&&session.data?.authenticated,company=user.companyId;return auth&&id(auth.user)&&id(company)&&typeof auth.token==='string'&&auth.token.length>=20?{user:auth.user,company,token:auth.token}:null;}
        function active(){return /^(?:console\.fleet-ops\.)?operations(?:\.|$)/.test(router?.currentRouteName||'')&&!!document.getElementById('map-topbar-container');}
        function clearPins(){drawGeneration++;for(const [key,entry] of pins){try{entry.adapter.removeMarker(key);}catch(_){entry.marker?.remove?.();entry.marker?.setMap?.(null);}}pins.clear();}
        function clearTrail(){for(const layer of trailLayers)try{layer.remove();}catch(_){}trailLayers=[];}
        function reset(){version++;controller?.abort();journeyController?.abort();journeyVersion++;journeyController=null;journey=null;journeyError='';followDeviceId=null;clearTrail();controller=null;busy=false;data=null;error='';lastAttempt=0;receivedAt=0;drawnStates='';clearPins();panel?.remove();panel=null;shown=false;selectedDeviceId=null;controls?.remove();controls=null;button=null;clearFleet();}
        function action(label,fn){const node=el('button',label);node.type='button';node.addEventListener('click',fn);return node;}
        function showDevice(row){if(selectedDeviceId!==row.id){clearTrail();followDeviceId=null;journey=null;journeyError='';journeyController?.abort();journeyVersion++;}selectedDeviceId=row.id;shown=true;renderPanel();if(panel)panel.scrollTop=0;if(row.mapped&&row.vehicle_uuid)loadJourney(row);}
        async function loadJourney(row){
            if(!/^[a-f0-9-]{36}$/i.test(row.vehicle_uuid||''))return;
            const own=readScope();if(!own)return;const serial=++journeyVersion;journeyController?.abort();const pending=new AbortController();journeyController=pending;
            try{const headers=native.getHeaders();if(headers.Authorization!=='Bearer '+own.token)throw new Error('MAPA session changed.');
                const response=await fetch(PATH+'/journey/'+encodeURIComponent(row.vehicle_uuid),{headers:{Authorization:headers.Authorization,Accept:'application/json'},credentials:'omit',cache:'no-store',redirect:'error',signal:pending.signal});
                if(!response.ok)throw new Error(response.status===404?'No linked vehicle journey was found.':'Trip details could not be loaded.');
                const result=await response.json();if(result?.contract!=='mapa-gps-journey-v1'||result.vehicle_id!==row.vehicle_id||!Array.isArray(result.trail)||result.trail.length>1000)throw new Error('Trip details did not match this ambulance.');
                if(serial!==journeyVersion||selectedDeviceId!==row.id||!same(own,readScope()))return;journey=result;journeyError='';renderPanel();
            }catch(e){if(serial===journeyVersion&&selectedDeviceId===row.id&&same(own,readScope())&&!pending.signal.aborted){journey=null;journeyError=e.message;renderPanel();}}
            finally{if(serial===journeyVersion)journeyController=null;}
        }
        function centerDevice(row){if(!row.location)return;const map=currentAdapter(),p=row.location;map?.flyTo?.(p.latitude,p.longitude,14);}
        function drawTripTrail(){
            clearTrail();const points=journey?.trail;if(!Array.isArray(points)||points.length<2){journeyError='No recorded GPS trail is available for this trip yet.';renderPanel();return;}
            const map=manager.map,L=window.L;if(!map||!L?.polyline||typeof map.addLayer!=='function'){journeyError='Open the MAPA map to view this trip trail.';renderPanel();return;}
            const segments=trailSegments(points);
            for(const coords of segments){if(coords.length>1)trailLayers.push(L.polyline(coords,{color:'#0f766e',weight:5,opacity:.85}).addTo(map));}
            const first=segments[0]?.[0],lastPoint=segments.at(-1)?.at(-1);
            if(first)trailLayers.push(L.circleMarker(first,{radius:7,color:'#047857',fillColor:'#10b981',fillOpacity:1}).bindTooltip('First recorded trip position').addTo(map));
            if(lastPoint)trailLayers.push(L.circleMarker(lastPoint,{radius:7,color:'#b91c1c',fillColor:'#ef4444',fillOpacity:1}).bindTooltip('Last recorded trip position').addTo(map));
            if(first&&lastPoint)map.fitBounds(L.latLngBounds([first,lastPoint,...segments.flat()]),{padding:[40,40],maxZoom:14});
            journeyError='';renderPanel();
        }
        function details(group,fields){panel.append(el('h4',group));const list=el('dl');list.className='mapa-gps-detail';for(const [label,value] of fields){const item=el('div');item.append(el('dt',label),el('dd',value===null||value===undefined||value===''?'Not recorded':String(value)));list.append(item);}panel.append(list);}
        function locateDevice(row){
            if(!row.location)return;shown=false;renderPanel();const map=currentAdapter(),p=row.location;
            const rect=document.querySelector?.('.leaflet-container')?.getBoundingClientRect(),dividers=Array.from(document.querySelectorAll?.('.gutter')||[]).map(n=>n.getBoundingClientRect());
            if(map?.fitBounds)map.fitBounds([[p.latitude,p.longitude],[p.latitude,p.longitude]],{paddingTopLeft:[30,90],paddingBottomRight:mapPadding(rect,dividers,window.innerHeight),maxZoom:14});
            else map?.flyTo?.(p.latitude,p.longitude,14);
        }
        function clearFleet(){fleetPanel?.removeAttribute('data-mapa-gps-view');fleetRoot?.remove();fleetPanel=fleetRoot=fleetBody=fleetSummary=fleetNotice=fleetScroll=fleetSearch=null;fleetSignature='';fleetMode='provider';}
        function mountFleet(){
            const target=document.getElementById('tabpanel-ambulances');if(!target)return false;
            if(target===fleetPanel&&fleetRoot?.isConnected)return true;
            clearFleet();fleetPanel=target;fleetRoot=el('section');fleetRoot.className='mapa-gps-bottom';fleetRoot.setAttribute('aria-label','Ambulance GPS fleet');
            const header=el('div');header.className='mapa-gps-bottom-header';
            const provider=action('All GPS ambulances',()=>{fleetMode='provider';fleetSignature='';renderFleet();});
            const nativeRecords=action('MAPA vehicle records',()=>{fleetMode='native';fleetSignature='';renderFleet();});
            fleetRoot.modeButtons=[provider,nativeRecords];header.append(provider,nativeRecords);
            fleetSearch=el('input');fleetSearch.type='search';fleetSearch.placeholder='Search registration or GPS device';fleetSearch.setAttribute('aria-label','Search ambulance GPS fleet');fleetSearch.addEventListener('input',()=>{fleetSignature='';renderFleet();});
            fleetSummary=el('span');fleetSummary.className='mapa-gps-bottom-summary';
            header.append(fleetSearch,fleetSummary,action('Refresh fleet',()=>refresh(true)));fleetRoot.append(header);
            fleetNotice=el('p');fleetNotice.className='mapa-gps-bottom-notice';fleetNotice.setAttribute('role','status');fleetRoot.append(fleetNotice);
            fleetScroll=el('div');fleetScroll.className='mapa-gps-bottom-scroll';fleetScroll.tabIndex=0;fleetScroll.setAttribute('aria-label','Scroll all ambulance GPS devices');
            const table=el('table');table.setAttribute('aria-label','All ambulance GPS devices');const head=el('thead'),tr=el('tr');
            for(const label of ['Ambulance / GPS device','GPS status','Last GPS fix (IST)','Speed','Power','Provider address','Coordinates','Map']){const th=el('th',label);th.setAttribute('scope','col');tr.append(th);}head.append(tr);fleetBody=el('tbody');table.append(head,fleetBody);fleetScroll.append(table);fleetRoot.append(fleetScroll);target.prepend(fleetRoot);return true;
        }
        function renderFleet(){
            if(!mountFleet())return;const view=displayed(),providerMode=fleetMode==='provider';
            fleetPanel.setAttribute('data-mapa-gps-view',fleetMode);fleetRoot.modeButtons.forEach((b,i)=>b.setAttribute('aria-pressed',String(providerMode===(i===0))));
            const box=fleetPanel.getBoundingClientRect(),parent=fleetPanel.parentElement?.getBoundingClientRect();
            fleetRoot.style.height=providerMode?Math.max(150,Math.min(parent?.bottom||box.bottom,window.innerHeight||box.bottom)-box.top)+'px':'auto';
            fleetScroll.hidden=fleetNotice.hidden=fleetSearch.hidden=fleetSummary.hidden=!providerMode;if(!providerMode)return;
            const rows=fleetRows(view?.devices||[],fleetSearch.value||''),mapped=(view?.devices||[]).filter(r=>r.mapped).length;
            fleetSummary.textContent=view?rows.length+' / '+view.devices.length+' devices · '+mapped+' mapped · '+(view.devices.length-mapped)+' unmapped':'Loading GPS fleet…';
            fleetNotice.textContent=error||(view?'Updated '+time(data.server_time)+' · '+(view.feed_stale?'Provider feed delayed. ':'')+'GPS status only; offline devices retain their last-known fix.':'Loading the full provider fleet…');
            const signature=JSON.stringify([rows.map(r=>[r.id,r.registration,r.label,r.state,r.location,r.speed_kmh,r.external_power_v,r.address]),error]);if(signature===fleetSignature)return;fleetSignature=signature;
            const scrollTop=fleetScroll.scrollTop;fleetBody.replaceChildren();
            for(const row of rows){const tr=el('tr');tr.setAttribute('data-gps-device',row.id);const name=el('td');name.append(action(fleetName(row),()=>showDevice(row)),el('small',(row.mapped?'Mapped · '+row.registration:'Unmapped device')+' · '+(row.external_id||row.id.replace(/^way4track:/,''))));if(!row.mapped)name.className='gps-unmapped';
                const status=el('td'),badge=el('span',row.state==='fresh'?'Recent GPS':row.state==='offline'?'Offline / GPS off':row.state==='stale'?'Stale GPS':'No GPS fix');badge.className='gps-state gps-state-'+row.state;status.append(badge);
                const fix=el('td',time(row.location?.recorded_at));fix.append(el('small','Received '+time(row.location?.provider_received_at)));
                const speed=el('td',Number.isFinite(row.speed_kmh)?row.speed_kmh.toFixed(1)+' km/h':'—');const power=el('td',Number.isFinite(row.external_power_v)?row.external_power_v.toFixed(1)+' V':'—');
                const address=el('td',row.address||'Not reported');const location=el('td',row.location?row.location.latitude.toFixed(6)+', '+row.location.longitude.toFixed(6):'No valid coordinates');const map=el('td');const locate=action('Locate',()=>locateDevice(row));locate.setAttribute('aria-label','Locate '+(row.registration||row.label));locate.disabled=!row.location;map.append(locate);tr.append(name,status,fix,speed,power,address,location,map);fleetBody.append(tr);
            }
            if(!rows.length){const tr=el('tr'),cell=el('td',view?'No GPS devices match this search.':'Waiting for provider data.');cell.colSpan=8;tr.append(cell);fleetBody.append(tr);}fleetScroll.scrollTop=scrollTop;
        }
        function fit(){const box=data&&bounds(data.devices),map=currentAdapter();if(!box){error='No device has a valid reported position yet.';renderPanel();return;}if(!map){error='The GPS list is ready. Open the MAPA Map view to plot these positions.';renderPanel();return;}
            shown=false;renderPanel();
            const rect=document.querySelector?.('.leaflet-container')?.getBoundingClientRect(),dividers=Array.from(document.querySelectorAll?.('.gutter')||[]).map(n=>n.getBoundingClientRect());
            map.fitBounds?.(box,{paddingTopLeft:[30,90],maxZoom:13,paddingBottomRight:mapPadding(rect,dividers,window.innerHeight)});
        }
        function deviceLabel(row){return fleetName(row)+(row.mapped?'':' · Unmapped GPS device');}
        function renderPanel(){
            const view=displayed();
            renderFleet();
            button&&(button.textContent='All GPS devices'+(data?' · '+data.devices.length+' ('+data.devices.filter(row=>!row.mapped).length+' unmapped)':''));
            if(!shown){panel?.remove();panel=null;return;}
            if(!panel){panel=el('section');panel.className='mapa-gps-panel';panel.setAttribute('aria-label','All GPS devices');document.body.append(panel);}const heading=el('h3','All GPS devices');panel.replaceChildren(heading);
            const tools=el('div');tools.className='mapa-gps-actions';tools.append(action('Refresh GPS',()=>refresh(true)),action('Show all GPS on map',fit),action('Close',()=>{shown=false;followDeviceId=null;renderPanel();}));panel.append(tools);
            const selected=view?.devices.find(row=>row.id===selectedDeviceId);
            if(selected){
                panel.setAttribute('aria-label','Vehicle details');heading.textContent='Vehicle details';
                tools.prepend(action('Back to all devices',()=>{selectedDeviceId=null;followDeviceId=null;clearTrail();journeyController?.abort();journeyVersion++;journey=null;renderPanel();}));
                panel.append(el('h4',fleetName(selected)),el('p',selected.mapped?'Linked to a MAPA ambulance':'GPS device awaiting ambulance mapping'));
                details('Ambulance',[['Registration',selected.registration],['MAPA name',selected.vehicle_name],['Vehicle ID',selected.vehicle_id],['Type',selected.vehicle_type],['Make / model',[selected.vehicle_make,selected.vehicle_model].filter(Boolean).join(' ')],['Year',selected.vehicle_year],['Color',selected.vehicle_color],['Call sign',selected.call_sign],['Seating capacity',selected.seating_capacity],['Operational status',selected.vehicle_status],['MAPA online',selected.vehicle_online===true?'Yes':selected.vehicle_online===false?'No':null],['Assigned driver',selected.driver_name],['Driver ID',selected.driver_id]]);
                details('Way4Track device',[['Way4Track label',selected.provider_label],['IMEI',selected.external_id],['Provider status',selected.provider_status],['Ignition',selected.ignition],['GPS power',selected.gps_on===true?'On':selected.gps_on===false?'Off':null],['Connection',selected.connected===true?'Connected':selected.connected===false?'Disconnected':null],['Report status',selected.state==='fresh'?'Recent':selected.state==='offline'?'Offline · last known':selected.state==='stale'?'Stale · last known':'No valid position'],['Speed at report',Number.isFinite(selected.speed_kmh)?selected.speed_kmh.toFixed(1)+' km/h':null],['Heading at report',Number.isFinite(selected.heading_degrees)?Math.round(selected.heading_degrees)+'°':null],['External power',Number.isFinite(selected.external_power_v)?selected.external_power_v.toFixed(2)+' V':null],['Internal battery',Number.isFinite(selected.battery_percent)?Math.round(selected.battery_percent)+'%':null],['GSM signal',Number.isFinite(selected.gsm_percent)?Math.round(selected.gsm_percent)+'%':null]]);
                const fix=selected.location;details('Last reported location',[['Provider address',selected.address],['Coordinates',fix?fix.latitude.toFixed(6)+', '+fix.longitude.toFixed(6):null],['GPS fix time',fix?time(fix.recorded_at):null],['Provider received',fix?time(fix.provider_received_at):null],['Accuracy','Not measured by provider']]);
                if(fix){const actions=el('div');actions.className='mapa-gps-actions';actions.append(action('Locate on map',()=>centerDevice(selected)),action(followDeviceId===selected.id?'Stop following':'Follow ambulance',()=>{followDeviceId=followDeviceId===selected.id?null:selected.id;if(followDeviceId)centerDevice(selected);renderPanel();}));panel.append(actions);}
                if(journey?.trip){details('Linked trip',[['Trip',journey.trip.order_id],['Stage',journey.trip.status],['Call created',time(journey.trip.created_at)],['Trip started',journey.trip.started_at?time(journey.trip.started_at):'Not recorded'],['Ended',journey.trip.ended_at?time(journey.trip.ended_at):'In progress'],['Pickup',journey.trip.pickup],['Destination',journey.trip.destination]]);
                    const patient=journey.patient;if(patient)details('Patient',[['Name',patient.name],['Age',patient.age],['Gender',patient.gender],['Incident',patient.incident],['Reported problem',patient.reported_problem]]);else panel.append(el('p','Patient details are not recorded for this linked trip.'));
                    const trail=el('div');trail.className='mapa-gps-actions';const show=action('Show recorded trip on map',drawTripTrail);show.disabled=journey.trail.length<2;trail.append(show,el('span',journey.trail.length+' recorded GPS points'+(journey.trail_truncated?' · most recent 1,000 only':'')));panel.append(trail);
                    panel.append(el('p','The line uses recorded positions from this trip. A gap over 10 minutes is left disconnected; it is not a planned road route.'));
                    if(journeyError)panel.append(el('p',journeyError));
                }else panel.append(el('p',journeyError||(journey?'No MAPA trip is linked to this ambulance.':selected.mapped?'Loading linked trip and patient details…':'No MAPA trip is linked to this unmapped device.')));
                panel.append(el('p','Provider GPS is location evidence only. It does not change ambulance duty or dispatch status.'));
                return;
            }
            selectedDeviceId=null;panel.setAttribute('aria-label','All GPS devices');
            panel.append(el('p',error||(!data?'Loading device data…':'Latest server data · '+time(data.server_time))));
            if(view)panel.append(el('p','Provider snapshot: '+(view.feed_stale?'stale':'recent')+(Number.isFinite(view.snapshot_age_seconds)?' · '+Math.round(view.snapshot_age_seconds)+' seconds old':'')));
            panel.append(el('p','Blue: recent report. Amber: stale. Grey: offline report. Purple: device not mapped to an ambulance. Devices without coordinates stay in this list; no position is invented.'));
            for(const row of view?.devices||[]){const card=el('article');card.append(action(deviceLabel(row),()=>showDevice(row)),el('p',row.mapped?'Mapped ambulance GPS device':'Ambulance mapping pending'));
                if(row.location){const fix=row.location;card.append(el('p',(row.state==='fresh'?'Recent reported position':row.state==='offline'?'GPS off / disconnected · last reported position':'Stale · last reported position')+' · '+time(fix.recorded_at)),el('p','Provider received · '+time(fix.provider_received_at)),el('p',fix.latitude.toFixed(6)+', '+fix.longitude.toFixed(6)+' · Provider-reported · Accuracy '+(typeof fix.accuracy_m==='number'&&fix.accuracy_m>0?'±'+Math.round(fix.accuracy_m)+' m':'unknown')));card.append(action('Locate device',()=>currentAdapter()?.flyTo?.(fix.latitude,fix.longitude,14)));}
                else card.append(el('p','No valid GPS position received'));
                panel.append(card);
            }
            panel.append(el('p','GPS display does not change driver duty, ambulance status or dispatch eligibility.'));
        }
        function icon(row){const color=!row.mapped?'#7c3aed':row.state==='offline'?'#64748b':row.state==='stale'?'#b45309':'#2563eb';return 'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28"><circle cx="14" cy="14" r="12" fill="'+color+'" stroke="white" stroke-width="2"/><text x="14" y="19" fill="white" text-anchor="middle" font-size="14" font-family="sans-serif">G</text></svg>');}
        async function draw(){
            const ready=currentAdapter(),view=displayed();if(!view||!active()||!ready)return;const own=version;clearPins();const generation=drawGeneration;adapter=ready;const drawAdapter=adapter;drawnStates=states(view);
            for(const row of view.devices){if(!row.location)continue;const p=row.location,key='mapa-provider-gps-'+generation+'-'+row.id;
                const label='<strong>'+escape(deviceLabel(row))+'</strong><small>'+escape(row.state==='fresh'?'Recent GPS':row.state==='offline'?'Offline · last-known position':'Stale · last-known position')+'</small>';
                const marker=await drawAdapter.addMarker(key,p.latitude,p.longitude,{iconUrl:icon(row),iconSize:[28,28],title:deviceLabel(row),tooltip:label,tooltipOptions:{html:true,permanent:true,direction:'top',offset:[0,-12],className:'mapa-gps-map-label'},onClick:()=>showDevice(row)});
                if(own!==version||generation!==drawGeneration||destroyed||!same(scope,readScope())){drawAdapter.removeMarker(key);return;}pins.set(key,{marker,adapter:drawAdapter});
            }
        }
        async function refresh(force=false){
            const own=readScope();if(!own||!active()||busy||(!force&&Date.now()-lastAttempt<30000))return;
            scope=own;busy=true;lastAttempt=Date.now();const epoch=++version;const ownController=new AbortController();controller=ownController;const timeout=setTimeout(()=>ownController.abort(),15000);
            try{const headers=native.getHeaders();if(headers.Authorization!=='Bearer '+own.token)throw new Error('GPS map session changed.');
                const response=await fetch(PATH,{headers:{Authorization:headers.Authorization,Accept:'application/json'},credentials:'omit',cache:'no-store',redirect:'error',signal:ownController.signal});
                if(!response.ok)throw Object.assign(new Error(response.status===401||response.status===403?'GPS map access requires an authorized MAPA session.':'GPS device refresh failed.'),{status:response.status});
                const result=snapshot(await response.json(),own.company);if(epoch!==version||!same(own,readScope()))return;data=result;receivedAt=clock();error='';renderPanel();await draw();if(followDeviceId){const followed=displayed()?.devices.find(row=>row.id===followDeviceId);if(followed?.location)centerDevice(followed);}
            }catch(e){if(epoch===version&&same(own,readScope())){if(e.status===401||e.status===403){clearPins();data=null;}error=e.message+(data?' Last successful data · '+time(data.server_time):'');renderPanel();}}
            finally{clearTimeout(timeout);if(epoch===version){busy=false;controller=null;}}
        }
        function tick(){
            if(destroyed)return;const now=readScope();if(!same(scope,now)){reset();scope=now;}
            if(!now||!active()){if(controls||panel||pins.size)reset();scope=now;return;}
            const bar=document.getElementById('map-topbar-container');
            if(!controls?.isConnected){controls=el('div');controls.className='mapa-gps-controls';button=action('All GPS devices',()=>{shown=!shown;renderPanel();refresh();});const labels=action(labelsVisible?'Hide map labels':'Show map labels',()=>{labelsVisible=!labelsVisible;document.documentElement?.classList.toggle('mapa-gps-labels-hidden',!labelsVisible);labels.textContent=labelsVisible?'Hide map labels':'Show map labels';});controls.append(button,action('Show all GPS',()=>{shown=true;renderPanel();if(data)fit();else refresh(true).then(fit);}),labels);bar.append(controls);renderPanel();}
            renderFleet();if(data&&(currentAdapter()!==adapter||states(displayed())!==drawnStates)){draw();renderPanel();}refresh();
            syncNativePopup();
        }
        const interval=setInterval(tick,1200);tick();
        return {destroy(){destroyed=true;clearInterval(interval);reset();style.remove();fleetStyle.remove();document.documentElement?.classList.remove('mapa-gps-labels-hidden');}};
    }
})();
