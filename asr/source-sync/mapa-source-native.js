/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const CONTRACT='mapa-asr-source-v1';
    const datasets=['drivers','ambulances','hospitals','attendance','daily_status','allocations','vehicle_statistics','driver_statistics','repairs','repair_items','trip_activity','cad_incidents','cad_dispatches','cad_status_updates','changes','types','institute_types','secretariats','villages','mandals'];
    const same=(a,b)=>!!a&&!!b&&a.user===b.user&&a.company===b.company&&a.token===b.token;
    function dashboardMode(path){
        if(!/^\/fleet-ops(?:\/|$)/.test(path))return null;
        if(/\/(new|create|edit)(?:\/|$)/.test(path))return null;
        if(/\/maintenance(?:\/|$)/.test(path))return 'maintenance';
        if(/\/analytics(?:\/|$)/.test(path))return 'analytics';
        if(/\/(resources|drivers|vehicles|hospitals|fleets)(?:\/|$)/.test(path))return 'resources';
        if(path==='/fleet-ops'||path==='/fleet-ops/'||/\/operations(?:\/|$)/.test(path))return 'operations';
        return null;
    }
    function makeTransport(scope,headers,fetchImpl) {
        const initial=scope();
        return async (dataset,params={})=>{
            if(!initial||!same(initial,scope())) throw new Error('Sign in to ASR Health.');
            if(!['status','refresh','profile','dashboard','command-status','command','workflow-status','workflow','master-status','master','connections','connection-save','projection','reconciliation','reconciliation-ack',...datasets].includes(dataset)) throw new Error('Unsupported dataset.');
            if(dataset==='profile'&&(Object.keys(params).sort().join(',')!=='dataset,id'||!['ambulances','drivers'].includes(params.dataset)||!/^[1-9][0-9]{0,9}$/.test(params.id??'')))throw new Error('Invalid source profile.');
            if(dataset==='dashboard'&&(Object.keys(params).join(',')!=='mode'||!['operations','resources','maintenance','analytics'].includes(params.mode)))throw new Error('Invalid source dashboard.');
            if(dataset==='refresh' && Object.keys(params).length) throw new Error('Fetch takes no parameters.');
            const master=dataset==='master'||dataset==='master-status';const workflow=dataset==='workflow'||dataset==='workflow-status';const command=dataset==='command'||dataset==='command-status';
            const connection=['connections','connection-save','projection'].includes(dataset),reconciliation=dataset.startsWith('reconciliation');
            if(dataset==='projection'&&(!['ambulances','drivers','hospitals'].includes(params.dataset)||!/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(params.id??'')))throw new Error('Invalid native projection.');
            if(dataset==='reconciliation-ack'&&!/^[a-f0-9]{64}$/.test(params.id??''))throw new Error('Invalid review event.');
            const post=['refresh','command','workflow','master','connection-save','reconciliation-ack'].includes(dataset);
            if(['command-status','workflow-status','master-status'].includes(dataset)&&Object.keys(params).length) throw new Error('Status takes no parameters.');
            const h=headers();
            if(h.Authorization!=='Bearer '+initial.token) throw new Error('Session changed.');
            const safe={Authorization:h.Authorization,Accept:'application/json'};
            for(const k of ['Access-Console-Sandbox','Access-Console-Sandbox-Key']) if(h[k]!==undefined) safe[k]=h[k];
            if(post&&dataset!=='refresh')safe['Content-Type']='application/json';
            const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),['command','workflow','master'].includes(dataset)?65000:dataset==='refresh'?35000:15000);
            try {
                const url=dataset==='profile'?'/api/int/v1/mapa/source-profiles/'+params.dataset+'/'+params.id:dataset==='dashboard'?'/api/int/v1/mapa/source-dashboard/'+params.mode:master?'/api/int/v1/mapa/source-masters':workflow?'/api/int/v1/mapa/source-workflows':command?'/api/int/v1/mapa/source-commands':dataset==='projection'?'/api/int/v1/mapa/source-projections/'+params.dataset+'/'+params.id:connection?'/api/int/v1/mapa/source-connections'+(post?'':'?'+new URLSearchParams(params)):reconciliation?'/api/int/v1/mapa/source-reconciliation'+(post?'/'+params.id+'/acknowledge':'?'+new URLSearchParams(params)):'/api/int/v1/mapa/source-inventory/'+dataset+(dataset==='refresh'?'':'?'+new URLSearchParams(params));
                const response=await fetchImpl(url,{
                    method:post?'POST':'GET',...(['command','workflow','master','connection-save'].includes(dataset)?{body:JSON.stringify(params)}:dataset==='reconciliation-ack'?{body:JSON.stringify({reviewed:true})}:{}),headers:safe,credentials:'omit',cache:'no-store',redirect:'error',signal:controller.signal});
                if(!same(initial,scope())) throw new Error('Session changed.');
                if(!response.ok) throw Object.assign(new Error([401,403].includes(response.status)?'Access is no longer authorized.':response.status===409?'The source record changed. Fetch and review it before trying again.':'ASR source sync is not available.'),{status:response.status});
                const body=await response.json();
                const contracts=dataset==='dashboard'?['mapa-asr-connections-v1']:master?['mapa-asr-master-command-v1']:workflow?['mapa-asr-workflow-command-v1']:command?['mapa-asr-command-v1']:connection?['mapa-asr-connections-v1']:reconciliation?['mapa-asr-reconciliation-v1']:[CONTRACT,'mapa-asr-source-v2','mapa-asr-source-v3'];
                if(!same(initial,scope())||body.company_id!==initial.company||!contracts.includes(body.contract)) throw new Error('Source identity did not match.');
                return body;
            } finally {clearTimeout(timer);}
        };
    }
    if(typeof document==='undefined') {module.exports={same,makeTransport,datasets,dashboardMode};return;}
    if(typeof window.define!=='function') return;
    window.define('@fleetbase/console/instance-initializers/mapa-source-native',['exports','@ember/destroyable/index'],(exports,destroyable)=>{
        exports.default={name:'mapa-source-native',initialize(owner){
            const session=owner.lookup('service:session'),user=owner.lookup('service:current-user'),native=owner.lookup('service:fetch');
            if(!session||!user||!native?.getHeaders) return;
            const scope=()=>{const a=session.isAuthenticated&&session.data?.authenticated;return a?.token&&a?.user&&user.companyId?{user:a.user,company:user.companyId,token:a.token}:null;};
            const router=owner.lookup('service:router');
            let button=null,panel=null,identity=null,workspace=null,scrollTop=0,sourceSummary=null,summaryIdentity=null,summaryMode=null,summaryBusy=false,summaryEpoch=0,summaryNextAt=0;
            const restore=()=>{workspace?.classList.remove('mapa-integration-active');document.querySelector('.fleet-ops-sidebar-navigator')?.classList.remove('mapa-integration-selected');if(workspace?.isConnected)workspace.scrollTop=scrollTop;workspace=null;panel=null;identity=null;button?.setAttribute('aria-current','false');window.dispatchEvent(new Event('resize'));};
            const close=()=>{if(panel)panel.close();else if(workspace)restore();};
            function open(section='overview'){
                if(panel){close();return;}if(!window.MapaIntegrationView)return;
                const target=document.querySelector('section.next-view-section > .next-view-section-container');
                if(!target)return;
                identity=scope();if(!identity)return;
                workspace=target;scrollTop=workspace.scrollTop;workspace.classList.add('mapa-integration-active');
                panel=window.MapaIntegrationView.view({mount:workspace,request:makeTransport(scope,()=>native.getHeaders(),(...args)=>fetch(...args)),onClose:()=>{restore();button?.focus();}});
                if(section!=='overview')panel.select(section);
                button?.setAttribute('aria-current','page');
                button?.closest('.fleet-ops-sidebar-navigator')?.classList.add('mapa-integration-selected');
            }
            async function refreshSummary(){
                if(panel||document.hidden||summaryBusy||Date.now()<summaryNextAt)return;
                const now=scope(),mode=dashboardMode(location.pathname),target=document.querySelector('section.next-view-section > .next-view-section-container');
                if(!now||!mode||!target){sourceSummary?.remove();sourceSummary=null;return;}
                if(!same(summaryIdentity,now)||summaryMode!==mode||!sourceSummary?.isConnected){sourceSummary?.remove();sourceSummary=document.createElement('aside');sourceSummary.className='mapa-related-source-summary';sourceSummary.setAttribute('aria-label','ASR Health source records');target.prepend(sourceSummary);summaryIdentity=now;summaryMode=mode;}
                const epoch=++summaryEpoch;summaryBusy=true;summaryNextAt=Date.now()+30000;const own=sourceSummary;
                try{
                    const data=await makeTransport(scope,()=>native.getHeaders(),(...args)=>fetch(...args))('dashboard',{mode});
                    if(epoch!==summaryEpoch||!same(now,scope())||mode!==dashboardMode(location.pathname)||!own.isConnected)return;
                    own.replaceChildren();const title=document.createElement('strong');title.textContent='ASR Health source records';own.append(title);
                    const freshness=document.createElement('span');freshness.textContent=(data.stale?'Updates delayed':'Verified source')+' · '+new Date(data.received_at*1000).toLocaleString();own.append(freshness);
                    const links={asr_active_incidents:['ASR active calls','cad_incidents'],asr_dispatch_records:['ASR assignments','cad_dispatches'],asr_open_trip_rows:['ASR open trip rows','trip_activity'],asr_ambulances:['ASR ambulances','ambulances'],asr_drivers:['ASR drivers','drivers'],asr_hospitals:['ASR hospitals & bases','hospitals'],asr_repair_records:['ASR repairs','repairs'],asr_component_records:['ASR repair components','repair_items'],asr_vehicle_groups:['ASR vehicle totals','vehicle_statistics'],asr_driver_groups:['ASR driver totals','driver_statistics']};
                    for(const [key,count] of Object.entries(data.counts)){if(!links[key])continue;const [label,section]=links[key],link=document.createElement('button');link.type='button';link.textContent=label+' · '+count;link.onclick=()=>open(section);own.append(link);}
                    const note=document.createElement('small');note.textContent='Source history is shown alongside MAPA live operations. Source totals are not added to MAPA journeys.';own.append(note);
                }catch(error){if(epoch!==summaryEpoch||!own.isConnected)return;if([401,403].includes(error.status)){own.remove();sourceSummary=null;}else{own.replaceChildren();const note=document.createElement('small');note.textContent='ASR source summaries are unavailable. MAPA operations remain available.';own.append(note);}}
                finally{summaryBusy=false;}
            }
            let transferButton=null,transferSidebar=null,transferMenu=null,transferScope=null,transferNext=0,transferBusy=false;
            function clearTransfers(){transferButton?.remove();transferSidebar?.remove();transferMenu?.remove();transferButton=transferSidebar=transferMenu=null;transferScope=null;transferNext=0;}
            function transferControls(){
                const now=scope(),bar=document.getElementById('map-topbar-container');
                if(!now||!bar){clearTransfers();return;}
                if(transferScope&&!same(transferScope,now))clearTransfers();
                if(!transferButton){
                    transferScope=now;transferButton=document.createElement('button');transferButton.type='button';transferButton.className='mapa-transfer-button';transferButton.textContent='Transfer';transferButton.setAttribute('aria-expanded','false');
                    transferButton.onclick=()=>{if(transferMenu){transferMenu.remove();transferMenu=null;transferButton.setAttribute('aria-expanded','false');return;}
                        transferMenu=document.createElement('aside');transferMenu.className='mapa-transfer-menu';transferMenu.setAttribute('aria-label','Transfers and notifications');
                        const title=document.createElement('h3');title.textContent='Transfers & notifications';transferMenu.append(title);
                        for(const [label,section] of [['Driver transfer','drivers'],['Ambulance transfer','ambulances'],['Transfer / change notifications','reconciliation']]){
                            const item=document.createElement('button');item.type='button';item.textContent=label;item.onclick=()=>{if(window.mapaCloseIntake?.()===false)return;transferMenu?.remove();transferMenu=null;transferButton.setAttribute('aria-expanded','false');if(panel)close();open(section);};transferMenu.append(item);
                        }
                        const referral=document.createElement('button');referral.type='button';referral.textContent='New patient referral transfer';referral.onclick=()=>{transferMenu?.remove();transferMenu=null;transferButton.setAttribute('aria-expanded','false');window.mapaOpenIntake?.({referral:true});};transferMenu.append(referral);
                        const note=document.createElement('p');note.textContent='Posting changes need review. Patient referrals create a new intake draft; nothing is dispatched automatically.';transferMenu.append(note);bar.append(transferMenu);transferButton.setAttribute('aria-expanded','true');
                    };
                }
                if(transferButton.parentNode!==bar)bar.append(transferButton);
                const sidebar=document.querySelector('.fleet-ops-sidebar-navigator');
                if(sidebar&&button?.isConnected){
                    if(!transferSidebar){
                        transferSidebar=document.createElement('button');transferSidebar.type='button';transferSidebar.className='next-sidebar-navigator-item mapa-transfer-sidebar';
                        transferSidebar.onclick=()=>{transferButton.onclick();if(transferMenu){transferMenu.classList.add('is-sidebar');document.body.append(transferMenu);}transferSidebar.setAttribute('aria-expanded',String(!!transferMenu));};
                    }
                    if(transferSidebar.previousElementSibling!==button)button.insertAdjacentElement('afterend',transferSidebar);
                    transferSidebar.textContent='⇄  '+transferButton.textContent;
                    transferSidebar.title=transferButton.title;
                    transferSidebar.classList.toggle('has-pending-notifications',transferButton.classList.contains('has-pending-notifications'));
                }
                if(transferBusy||document.hidden||Date.now()<transferNext)return;
                transferBusy=true;transferNext=Date.now()+30000;const own=now;
                makeTransport(scope,()=>native.getHeaders(),(...args)=>fetch(...args))('reconciliation',{status:'pending',page:'1'}).then(data=>{
                    if(!same(own,scope())||!transferButton)return;
                    const count=Number.isSafeInteger(data.pending_count)?data.pending_count:0;
                    transferButton.classList.toggle('has-pending-notifications',count>0);transferButton.textContent='Transfer'+(count?' · '+count:'');transferButton.title=data.stale?'Source notifications delayed':count+' source changes awaiting review';
                    transferButton.setAttribute('aria-label','Transfer · '+count+' source changes awaiting review');
                }).catch(()=>{if(transferButton&&same(own,scope())){transferButton.textContent='Transfer · unavailable';transferButton.title='Transfer notifications could not be refreshed';}}).finally(()=>{transferBusy=false;});
            }
            function tick(){
                transferControls();
                const now=scope();if(identity&&!same(identity,now))close();
                if(summaryIdentity&&!same(summaryIdentity,now)){summaryEpoch++;sourceSummary?.remove();sourceSummary=null;summaryIdentity=null;summaryNextAt=0;}
                const sidebar=document.querySelector('.fleet-ops-sidebar-navigator');
                if(panel&&!workspace?.isConnected)close();
                if(!now||!sidebar){close();button?.remove();button=null;return;}
                if(!button?.isConnected){
                    button=document.createElement('button');button.type='button';button.className='next-sidebar-navigator-item mapa-integration-sidebar';button.title='ASR Health Integration';button.setAttribute('aria-controls','mapa-asr-integration');button.setAttribute('aria-current',panel?'page':'false');
                    const icon=document.createElement('span');icon.className='next-sidebar-navigator-item-icon';icon.textContent='⇄';icon.setAttribute('aria-hidden','true');
                    const content=document.createElement('span');content.className='next-sidebar-navigator-item-content';const label=document.createElement('span');label.className='next-sidebar-navigator-item-label';label.textContent='ASR Health Integration';content.append(label);button.append(icon,content);button.onclick=()=>open();
                    // Keep this root shortcut above the yielded Live Operations footer, even in nested menus.
                    const menu=sidebar.querySelector('.next-sidebar-navigator-viewport');
                    if(menu)menu.insertAdjacentElement('afterend',button);else sidebar.append(button);
                }
            }
            const navigate=e=>{if(panel&&e.target.closest('.fleet-ops-sidebar-navigator')&&!button?.contains(e.target)&&!transferSidebar?.contains(e.target))close();};
            document.addEventListener('click',navigate,true);
            const routeChange=()=>{summaryNextAt=0;summaryEpoch++;sourceSummary?.remove();sourceSummary=null;if(panel)close();};router?.on?.('routeWillChange',routeChange);
            const lifecycle=setInterval(()=>{tick();if(!sourceSummary?.isConnected)refreshSummary();},1000),refresh=setInterval(()=>{panel?.refresh();refreshSummary();},30000);tick();refreshSummary();
            destroyable?.registerDestructor?.(owner,()=>{summaryEpoch++;clearInterval(lifecycle);clearInterval(refresh);document.removeEventListener('click',navigate,true);router?.off?.('routeWillChange',routeChange);close();button?.remove();sourceSummary?.remove();clearTransfers();});
        }};
    });
})();

