/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const mapaIST = value => { const original = new Date(value); if (!Number.isFinite(original.getTime())) return 'Time unavailable'; const d = new Date(original.getTime() + 19800000), pad = n => String(n).padStart(2, '0'), h = d.getUTCHours(); return pad(d.getUTCDate()) + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()] + ' ' + d.getUTCFullYear() + ', ' + pad(h % 12 || 12) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' ' + (h < 12 ? 'AM' : 'PM') + ' IST'; };

    const labels={overview:'Overview',drivers:'Drivers',ambulances:'Ambulances',hospitals:'Hospitals & bases',attendance:'Attendance',daily_status:'On / off road',allocations:'Driver allocations',vehicle_statistics:'Vehicle trip totals',driver_statistics:'Driver trip totals',repairs:'Repairs',repair_items:'Repair components',trip_activity:'ASR trip activity',cad_incidents:'ASR dispatch calls',cad_dispatches:'ASR assignments',cad_status_updates:'ASR status timeline',connections:'Record connections',reconciliation:'Transfer / change review',changes:'Source changes',types:'Vehicle categories',institute_types:'Institute types'};
    const usage=[
        ['Ambulances','ASR vehicle profiles and base postings','Integration → Ambulances. Review the same vehicle in Resources before changing its operational assignment.'],
        ['Drivers','ASR roster and transfers','Integration → Drivers. Crew registration and approval connect a person to the crew APK and an actual ambulance. A matching phone number alone is not proof of identity.'],
        ['Hospitals & bases','ASR facility directory','Integration → Hospitals & bases. Operational destination pins and hospital accounts remain verified in MAPA.'],
        ['Attendance','ASR PHC daily-session rule','Integration → Attendance. A PHC session marks presence; it does not switch a crew member on duty.'],
        ['Road status & repairs','ASR maintenance records','Integration → On / off road and Repairs. Dispatch availability must still be reviewed in MAPA.'],
        ['Trips & allocation history','ASR historical reports','Integration → Vehicle / Driver trip totals and allocations. MAPA live trips, acknowledgements and GPS remain in Operations and the crew APK.']
    ];
    const csvCell=value=>{let text=value==null?'':String(value);if(/^[\s]*[=+@-]/.test(text))text="'"+text;return '"'+text.replaceAll('"','""')+'"';};
    const csv=rows=>{if(!rows.length)return '';const fields=Object.keys(rows[0]).filter(key=>!['meta','expected_revision'].includes(key));return [fields.map(csvCell).join(','),...rows.map(row=>fields.map(field=>csvCell(row[field])).join(','))].join('\r\n');};
    if(typeof document==='undefined'){module.exports={csv,csvCell};return;}
    const n=(tag,text='',cls='')=>{const el=document.createElement(tag);el.textContent=text;if(cls)el.className=cls;return el;};
    function view({mount,request,onClose}) {
        let selected='overview',profile=null,page=1,search='',filter={},generation=0,fetching=false,info=null,closed=false,editor=null,extension=null,capabilities={enabled:false,actions:[]};
        const hub='fleet-ops-control-hub';
        const dialog=n('section','',hub+' mapa-integration-workspace mapa-source-hub');dialog.id='mapa-asr-integration';dialog.tabIndex=-1;dialog.setAttribute('aria-label','ASR Health Integration');
        const header=n('header','',hub+'-header'),intro=n('div','',hub+'-header-copy'),eyebrow=n('div','INTEGRATION',hub+'-eyebrow'),heading=n('h1','ASR Health Integration',hub+'-title');intro.append(eyebrow,heading,n('p','Review fleet records, coordinate source changes, and keep ASR Health connected to operations.',hub+'-subtitle'));
        const actions=n('div','',hub+'-header-actions'),fetchButton=n('button','Fetch latest data','mapa-source-button mapa-source-primary'),close=n('button','Back to Operations','mapa-source-button');fetchButton.type=close.type='button';fetchButton.disabled=true;
        close.onclick=()=>{closed=true;generation++;editor?.close();extension?.close();dialog.remove();onClose();};actions.append(fetchButton,close);header.append(intro,actions);dialog.append(header);
        const body=n('div','',hub+'-body');dialog.append(body);
        const status=n('p','Checking source connection…','mapa-source-status');status.setAttribute('role','status');body.append(status);
        const nav=n('nav','','mapa-integration-tabs');nav.setAttribute('aria-label','Integration sections');const buttons={};
        function select(key){extension?.close();extension=null;profile=null;selected=key;page=1;search='';filter={};dialog.scrollTop=0;load(true);}
        const tabPaths={drivers:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M13 3a4 4 0 0 1 0 8M20 21v-2a4 4 0 0 0-3-3.87M9 3a4 4 0 1 0 0 8',ambulances:'M3 6h12v12H3zM15 10h4l3 4v4h-7M6 18a2 2 0 1 0 4 0M16 18a2 2 0 1 0 4 0',hospitals:'M4 21V5h16v16M9 21v-5h6v5M9 9h6M12 6v6',reconciliation:'M3 7h18l-4-4M21 17H3l4 4',repairs:'M14 6a5 5 0 0 0-6 6L3 17l4 4 5-5a5 5 0 0 0 6-6l-4 4-4-4z',overview:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'};
        for(const [key,label] of Object.entries(labels)){const b=n('button','','mapa-source-button');b.type='button';const tile=n('span','','mapa-source-tab-icon');tile.setAttribute('aria-hidden','true');const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.7');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d',tabPaths[key]||'M5 3h14v18H5zM8 8h8M8 12h8M8 16h5');svg.append(path);tile.append(svg);b.append(tile,n('span',label));b.onclick=()=>select(key);nav.append(b);buttons[key]=b;}body.append(nav);
        const content=n('section','','mapa-integration-content');body.append(content);
        const setStatus=()=>{
            fetchButton.disabled=fetching||!info?.fetch_allowed;
            fetchButton.textContent=fetching?'Fetching from ASR…':'Fetch latest data';
            status.classList.toggle('is-delayed',!!info?.stale);
            status.textContent=!info?'Checking source connection…':!info.enabled?'Connection setup pending · no live source data is being fetched.':!info.has_snapshot?'Connected configuration · awaiting the first verified snapshot.':(info.stale?'Updates delayed':'Latest verified data')+' · '+mapaIST(info.received_at*1000)+' · Source: asrhealth.in';
        };
        function overview(){
            const count=key=>info?.has_snapshot&&info?.counts?.[key]!=null?String(info.counts[key]):'—';
            const icon=key=>{
                const paths={ambulances:'M3 6h11v12H3z M14 10h4l3 4v4h-7 M5 18a2 2 0 1 0 4 0 M16 18a2 2 0 1 0 4 0 M6 11h5 M8.5 8.5v5',drivers:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8a4 4 0 1 0 0-8 M17 4a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87',hospitals:'M4 21V5h16v16 M2 21h20 M9 21v-5h6v5 M9 9h6 M12 6v6',repairs:'M14 6a5 5 0 0 0-6 6L2 18l4 4 6-6a5 5 0 0 0 6-6l-4 4-4-4z',default:'M5 5h14v16H5z M9 3h6v4H9z M8 11h8 M8 15h8'};
                const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.8');svg.setAttribute('aria-hidden','true');const path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d',paths[key]||paths.default);svg.append(path);return svg;
            };
            const cards=n('section','',hub+'-kpis');
            for(const [key,caption,tone] of [['ambulances','Vehicle profiles and base postings.','green'],['drivers','Driver roster and place of working.','blue'],['hospitals','Source hospitals and operating bases.','green'],['repairs','Recorded repairs and service history.','blue']]){
                const card=n('button','',hub+'-kpi '+hub+'-tone-'+tone);card.type='button';const copy=n('div','',hub+'-kpi-copy');copy.append(n('div',labels[key],hub+'-kpi-label'),n('div',count(key),hub+'-kpi-value'),n('div',info?.has_snapshot?caption:'Waiting for verified source records.',hub+'-kpi-caption'),n('div','Open '+labels[key]+' →',hub+'-kpi-action'));const mark=n('div','',hub+'-kpi-icon');mark.append(icon(key));card.append(copy,mark);card.onclick=()=>select(key);cards.append(card);
            }content.append(cards);
            const grid=n('section','',hub+'-grid'),main=n('div','',hub+'-main'),side=n('aside','',hub+'-side');grid.append(main,side);content.append(grid);
            function panel(title,description){const el=n('div','',hub+'-panel'),head=n('div','',hub+'-panel-header'),copy=n('div');copy.append(n('h2',title),n('p',description));head.append(copy);el.append(head);return el;}
            for(const [title,description,items] of [
                ['Fleet & people','Review the source roster and facility records alongside operations.',[['ambulances','Vehicle profiles and base postings.'],['drivers','Driver details and authorized transfers.'],['hospitals','Hospitals, PHCs and operating bases.']]],
                ['Activity & history','Explore attendance, allocations and historical vehicle activity.',[['attendance','Daily attendance from source sessions.'],['daily_status','On-road and off-road history.'],['allocations','Recorded driver and vehicle allocations.'],['vehicle_statistics','Distance and trips by ambulance.'],['driver_statistics','Distance and trips by driver.'],['repairs','Repair details and maintenance history.']]],
                ['Source records','Review incoming changes and the categories used by ASR Health.',[['changes','New and updated source records.'],['types','Ambulance service categories.'],['institute_types','Facility classification reference.']]]]){
                const group=panel(title,description),links=n('div','',hub+'-link-grid');for(const [key,description] of items){const link=n('button','',hub+'-link');link.type='button';const mark=n('span','',hub+'-link-icon');mark.append(icon(key));const copy=n('span','',hub+'-link-copy');copy.append(n('span',labels[key],hub+'-link-title'),n('span',description,hub+'-link-description'));link.append(mark,copy);if(info?.counts?.[key]!=null&&info.has_snapshot)link.append(n('span',count(key),hub+'-link-count'));link.onclick=()=>select(key);links.append(link);}group.append(links);main.append(group);
            }
            const queue=panel('Action Queue','Connection status and records that need review.'),list=n('div','',hub+'-action-list');
            function action(title,description,key,tone=''){const el=n(key?'button':'div','',hub+'-action '+(tone?hub+'-tone-'+tone:''));if(key){el.type='button';el.onclick=()=>select(key);}el.append(icon(key));const text=n('span');text.append(n('strong',title),n('small',description));el.append(text);list.append(el);}
            action(!info?.enabled?'Connection setup pending':!info?.has_snapshot?'First snapshot pending':info.stale?'Source updates delayed':'Verified source records',!info?.has_snapshot?'Connect the source and fetch a verified snapshot.':info.stale?'Displayed records may be outdated. Fetch the latest data before reviewing changes.':'Last received '+mapaIST(info.received_at*1000)+'.',null,info?.has_snapshot&&!info.stale?'green':'amber');
            action('Review source changes','Inspect additions and transfers before updating operational assignments.','changes');
            action(capabilities.enabled?'Authorized edits available':'Write-back setup pending',capabilities.enabled?'Permitted profile edits and transfers save to ASR Health with conflict checks.':'Source editing controls appear when the restricted write-back connection is configured.');queue.append(list);side.append(queue);
            const guide=n('div','',hub+'-doc-note');guide.append(n('div','How records are used',hub+'-doc-note-heading'),n('p','ASR Health remains the main record system. Fetch latest data retrieves and verifies all supported datasets.',hub+'-doc-note-copy'));
            for(const [title,,description] of usage){const detail=n('details'),summary=n('summary',title);detail.append(summary,n('p',description));guide.append(detail);}guide.append(n('p','Refreshing source data does not approve crew, change duty or GPS, or dispatch an ambulance.',hub+'-doc-note-copy'));side.append(guide);
        }
        function filters(data){
            const controls=n('form','','mapa-integration-controls'),input=n('input');input.type='search';input.placeholder='Name, registration or mobile';input.setAttribute('aria-label','Search integration records');input.value=search;
            const submit=n('button','Search');submit.type='submit';controls.append(input,submit);controls.onsubmit=e=>{e.preventDefault();search=input.value.trim().slice(0,100);page=1;load(true);};
            if(['drivers','attendance'].includes(selected)){const category=n('select');category.setAttribute('aria-label','Vehicle category');for(const value of ['','104','108','Feeder/Bike','Special','Thalli Bidda Express']){const option=n('option',value||'All categories');option.value=value;category.append(option);}category.value=filter.vehicle_type||'';category.onchange=()=>{filter.vehicle_type=category.value;page=1;load(true);};controls.append(category);}
            const choices=info?.filter_options;
            function picker(field,label,rows,key,text){const select=n('select');select.setAttribute('aria-label',label);const all=n('option','All '+label.toLowerCase());all.value='';select.append(all);for(const row of rows||[]){const option=n('option',String(row[text]));option.value=String(row[key]);select.append(option);}select.value=filter[field]||'';select.onchange=()=>{filter[field]=select.value;page=1;load(true);};controls.append(select);}
            if(choices&&['drivers','ambulances','attendance','daily_status','hospitals'].includes(selected))picker('phc_id','Hospitals & bases',choices.hospitals,'phc_id','name');
            if(choices&&selected==='ambulances')picker('type_id','Vehicle categories',choices.types,'id','type');
            if(choices&&['drivers','ambulances','daily_status','hospitals','trip_activity','allocations'].includes(selected))picker('institute_type_id','Institute types',choices.institute_types,'id','type');
            if(['daily_status','trip_activity','allocations'].includes(selected)){for(const key of ['date_from','date_to']){const date=n('input');date.type='date';date.setAttribute('aria-label',key==='date_from'?'From date':'To date');date.value=filter[key]||'';date.onchange=()=>{filter[key]=date.value;page=1;load(true);};controls.append(date);}}
            const exportButton=n('button','Export displayed rows (CSV)');exportButton.type='button';exportButton.disabled=!data.rows.length;exportButton.onclick=()=>{const url=URL.createObjectURL(new Blob(['\ufeff'+csv(data.rows)],{type:'text/csv;charset=utf-8'}));const link=n('a');link.href=url;link.download='MAPA-ASR-'+selected+'-page-'+page+'.csv';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);};controls.append(exportButton);
            if(selected==='attendance'){const month=n('input');month.type='month';month.setAttribute('aria-label','Attendance month');month.value=filter.month||info?.source_date?.slice(0,7)||'';month.onchange=()=>{filter.month=month.value;page=1;load(true);};controls.append(month);}
            const reset=n('button','Clear filters');reset.type='button';reset.onclick=()=>{search='';filter={};page=1;load(true);};controls.append(reset);content.append(controls);
        }
        function renderProfile(data){
            const title=data.record.vehicle_number||data.record.name;
            content.replaceChildren(n('h2',title));
            if(profile.dataset==='ambulances') {
                const tile=n('section','',hub+'-panel'), tracking=data.tracking_tile;
                tile.append(n('h3','Ambulance & assigned driver location'));
                if(!tracking) tile.append(n('p','Link this ambulance to its verified MAPA record to see its ambulance and driver locations.'));
                else {
                    tile.append(n('p',(tracking.registration||title)+' · Driver: '+(tracking.driver_name||'Not assigned')));
                    const details=n('dl','','mapa-source-profile-details');
                    for(const [key,label] of [['ambulance_gps','Ambulance GPS'],['driver_phone','Driver phone GPS']]) {
                        const fix=tracking.locations?.[key];
                        const value=fix && Number.isFinite(Number(fix.latitude)) && Number.isFinite(Number(fix.longitude))
                            ? Number(fix.latitude).toFixed(6)+', '+Number(fix.longitude).toFixed(6)+' · '+(fix.stale?'Stale':'Recent')+' · '+mapaIST(Number(fix.recorded_at))
                            : 'No location report received';
                        details.append(n('dt',label),n('dd',value));
                    }
                    tile.append(details);
                }
                content.append(tile);
            }
            const back=n('button','Back to '+labels[profile.dataset],'mapa-source-button');back.type='button';back.onclick=()=>select(profile.dataset);content.append(back);
            for(const [action,label] of (profile.dataset==='drivers'?[['driver.transfer','Transfer driver']]:[['ambulance.profile','Edit profile'],['ambulance.transfer','Transfer ambulance']])){
                const button=n('button',label,'mapa-source-button');button.type='button';button.disabled=!capabilities.enabled||!capabilities.actions.includes(action);button.title=button.disabled?'Source write-back commissioning is required.':label;button.onclick=()=>{if(editor)return;generation++;editor=window.MapaIntegrationEditor.open({request,row:data.record,action,onDone:()=>{editor=null;load(true);},onDenied:()=>close.click()});};content.append(button);
            }
            if(profile.dataset==='ambulances'&&data.workflow_context&&window.MapaWorkflowEditor){
                const controls=n('div');content.append(controls);const actions=[['session.start','Start daily session'],['session.close','Close session'],['trip.record','Record completed trip'],['repair.complete','Complete repair']];
                const buttons=actions.map(([action,label])=>{const button=n('button',label,'mapa-source-button');button.type='button';button.disabled=true;button.title='Source workflow commissioning is required.';button.onclick=()=>{if(editor)return;generation++;editor=window.MapaWorkflowEditor.open({request,profile:data,action,onDone:()=>{editor=null;load(true);},onDenied:()=>close.click()});};controls.append(button);return {action,button};});
                const profileGeneration=generation;request('workflow-status').then(result=>{if(generation!==profileGeneration)return;for(const {action,button} of buttons)button.disabled=!result.enabled||!result.actions.includes(action);}).catch(()=>{});
            }
            if(window.MapaMasterEditor&&data.record.master_revision){const action=profile.dataset==='drivers'?'driver.delete':'ambulance.delete',button=n('button','Delete unused source record','mapa-source-button');button.type='button';button.disabled=true;const own=generation;request('master-status').then(result=>{if(generation===own)button.disabled=!result.enabled||!result.actions.includes(action);}).catch(()=>{});button.onclick=()=>{if(editor)return;generation++;editor=window.MapaMasterEditor.open({request,action,row:data.record,info,onDone:()=>{editor=null;load(true);},onDenied:()=>close.click()});};content.append(button);}
            if(data.record.assignment_origin)content.append(n('p',data.record.assignment_origin,'mapa-source-status'));
            const detail=n('dl','','mapa-source-profile-details');for(const [key,value] of Object.entries(data.record)){if(['expected_revision','master_revision','assignment_origin'].includes(key)||value===null)continue;detail.append(n('dt',key.replaceAll('_',' ')),n('dd',String(value)));}content.append(detail);
            const cards=n('section','',hub+'-kpis');for(const [key,label] of [['total_kms','Total kilometres'],['source_trip_rows','ASR trip rows'],['patients','Patients transported'],['deadbodies','Bodies transported']]){const card=n('div','',hub+'-kpi '+hub+'-tone-blue');card.append(n('div',label,hub+'-kpi-label'),n('div',String(data.kpis[key]),hub+'-kpi-value'));cards.append(card);}content.append(cards,n('p',data.statistics_unit));
            content.append(n('p','Delivery '+data.kpis.delivery+' · Accident '+data.kpis.accident+' · General emergency '+data.kpis.emergency));
            for(const [key,rows] of Object.entries(data.sections)){const group=n('details','',hub+'-panel');group.append(n('summary',(labels[key]||key.replaceAll('_',' '))+' · '+data.section_counts[key]));if(data.sections_truncated[key])group.append(n('p','Showing '+rows.length+' of '+data.section_counts[key]+' records.'));
                if(!rows.length)group.append(n('p','No source records.'));else{const table=n('table'),head=n('tr'),fields=Object.keys(rows[0]);for(const field of fields)head.append(n('th',field.replaceAll('_',' ')));const thead=n('thead');thead.append(head);table.append(thead);const tbody=n('tbody');for(const row of rows){const tr=n('tr');for(const field of fields)tr.append(n('td',row[field]===null?'Not recorded':String(row[field])));tbody.append(tr);}table.append(tbody);const scroll=n('div','','mapa-integration-scroll');scroll.append(table);group.append(scroll);}content.append(group);
            }
        }
        async function load(clear=false){
            if(closed||fetching||editor||document.hidden)return;const own=++generation;
            if(extension){extension.refresh();return;}
            nav.hidden=selected==='overview';content.classList.toggle('mapa-source-records',selected!=='overview');
            for(const [key,b] of Object.entries(buttons)){b.setAttribute('aria-current',key===selected?'page':'false');b.disabled=fetching;}
            if(clear)content.replaceChildren(n('p','Loading '+(profile?'profile':labels[selected].toLowerCase())+'…'));
            try{
                const current=await request('status');if(closed||own!==generation)return;info=current;setStatus();
                try{capabilities=await request('command-status');}catch(error){if([401,403].includes(error.status))throw error;capabilities={enabled:false,actions:[]};}if(closed||own!==generation)return;
                if(selected==='overview'){content.replaceChildren();overview();return;}
                if(!info.has_snapshot){content.replaceChildren(n('h3',labels[selected]),n('p','No verified records are available yet. Complete the source connection, then fetch the latest data.'));return;}
                if(profile){const data=await request('profile',profile);if(closed||own!==generation)return;renderProfile(data);return;}
                if(['connections','reconciliation'].includes(selected)&&window.MapaConnectionView){content.replaceChildren(n('h3',labels[selected]));extension=window.MapaConnectionView.view({mount:content,request,kind:selected});return;}
                if(['repair_items','trip_activity','cad_incidents','cad_dispatches','cad_status_updates'].includes(selected)&&!['mapa-asr-source-v2','mapa-asr-source-v3'].includes(info.contract)){content.replaceChildren(n('h3',labels[selected]),n('p','The expanded ASR source contract is not enabled. Original records remain on ASR Health.'));return;}
                const params={page:String(page),...(search?{search}:{}),...Object.fromEntries(Object.entries(filter).filter(([,v])=>v))};
                const data=await request(selected,params);if(closed||own!==generation)return;
                content.replaceChildren(n('h3',labels[selected]+' · '+data.total+' records'));filters(data);
                if(window.MapaMasterEditor&&['drivers','ambulances'].includes(selected)&&info.master_revisions?.[selected]){const dataset=selected,action=dataset==='drivers'?'driver.create':'ambulance.create',button=n('button',dataset==='drivers'?'Add source driver':'Add source ambulance','mapa-source-button');button.type='button';button.disabled=true;const current=generation;request('master-status').then(result=>{if(current===generation)button.disabled=!result.enabled||!result.actions.includes(action);}).catch(()=>{});button.onclick=()=>{if(editor)return;generation++;editor=window.MapaMasterEditor.open({request,action,info,onDone:()=>{editor=null;load(true);},onDenied:()=>close.click()});};content.append(button);}
                if(!data.rows.length){content.append(n('p','No records match these filters.'));return;}
                const table=n('table'),thead=n('thead'),tr=n('tr'),fields=Object.keys(data.rows[0]).filter(f=>!['meta','expected_revision'].includes(f));const editable=['drivers','ambulances'].includes(selected)&&capabilities.enabled;for(const f of fields){const th=n('th',f.replaceAll('_',' '));if(Array.isArray(data.sortable_fields)&&data.sortable_fields.includes(f)&&!['attendance','changes'].includes(selected)){const sort=n('button',f.replaceAll('_',' '));sort.type='button';sort.onclick=()=>{filter.direction=filter.sort===f&&filter.direction==='asc'?'desc':'asc';filter.sort=f;page=1;load(true);};th.replaceChildren(sort);th.setAttribute('aria-sort',filter.sort===f?(filter.direction==='desc'?'descending':'ascending'):'none');}tr.append(th);}if(editable)tr.append(n('th','Actions'));thead.append(tr);table.append(thead);const tbody=n('tbody');
                for(const row of data.rows){const line=n('tr');for(const f of fields){const td=n('td');if((selected==='drivers'&&f==='name')||(selected==='ambulances'&&f==='vehicle_number')){const link=n('button',String(row[f]??'Unknown'),'mapa-integration-record');link.type='button';link.onclick=()=>{profile={dataset:selected,id:String(row.id)};generation++;load(true);};td.append(link);}else if(f==='assignment_origin'){if(row[f])td.append(n('span',row[f],'mapa-source-assignment-origin'));}else td.textContent=row[f]===null?'Not recorded':Array.isArray(row[f])?row[f].join(', '):String(row[f]);line.append(td);}if(editable){const cell=n('td');for(const [action,label] of (selected==='drivers'?[['driver.transfer','Transfer']]:[['ambulance.profile','Edit profile'],['ambulance.transfer','Transfer']])){if(!capabilities.actions.includes(action))continue;const edit=n('button',label);edit.type='button';edit.disabled=!row.expected_revision;edit.onclick=()=>{if(editor)return;generation++;editor=window.MapaIntegrationEditor.open({request,row,action,onDone:()=>{editor=null;load(true);},onDenied:()=>close.click()});};cell.append(edit);}line.append(cell);}tbody.append(line);}table.append(tbody);const scroll=n('div','','mapa-integration-scroll');scroll.append(table);content.append(scroll);
                const pagination=n('div','','mapa-integration-controls');for(const [label,enabled,delta] of [['Previous',page>1,-1],['Next',data.has_more,1]]){const b=n('button',label);b.type='button';b.disabled=!enabled;b.onclick=()=>{page+=delta;load(true);};pagination.append(b);}pagination.append(n('span','Page '+page));content.append(pagination);
            }catch(e){if(closed||own!==generation)return;if([401,403].includes(e.status)){close.click();return;}status.textContent='Refresh unavailable · previously shown data may be outdated.';status.classList.add('is-delayed');fetchButton.disabled=!info?.fetch_allowed;if(clear)content.replaceChildren(n('p',e.message));}
        }
        fetchButton.onclick=async()=>{
            if(fetching||!info?.fetch_allowed)return;fetching=true;generation++;setStatus();for(const b of Object.values(buttons))b.disabled=true;
            try{info=await request('refresh');if(closed)return;fetching=false;setStatus();window.dispatchEvent(new Event('mapa-source-refreshed'));await load(true);}
            catch(e){if(closed)return;if([401,403].includes(e.status)){close.click();return;}fetching=false;
                if(e.status===429){await load(true);if(closed)return;if(info?.has_snapshot&&!info.stale){setStatus();status.textContent+=' · Latest verified records loaded; a recent refresh already completed.';window.dispatchEvent(new Event('mapa-source-refreshed'));}else{status.textContent='ASR refresh is already running · the previous records remain visible. Try again shortly.';status.classList.add('is-delayed');}}
                else{setStatus();status.textContent='Fetch failed · the last verified source records are retained. You can retry.';status.classList.add('is-delayed');}for(const b of Object.values(buttons))b.disabled=false;}
        };
        mount.append(dialog);dialog.focus({preventScroll:true});load(true);
        return {refresh:()=>load(),select,close:()=>close.click()};
    }
    window.MapaIntegrationView={view,labels};
})();
