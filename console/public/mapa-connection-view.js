/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const mapaIST = value => { const original = new Date(value); if (!Number.isFinite(original.getTime())) return 'Time unavailable'; const d = new Date(original.getTime() + 19800000), pad = n => String(n).padStart(2, '0'), h = d.getUTCHours(); return pad(d.getUTCDate()) + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()] + ' ' + d.getUTCFullYear() + ', ' + pad(h % 12 || 12) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' ' + (h < 12 ? 'AM' : 'PM') + ' IST'; };

    const n=(tag,text='',className='')=>{const el=document.createElement(tag);el.textContent=text;if(className)el.className=className;return el;};
    function view({mount,request,kind}){
        let closed=false,sequence=0,page=1,dataset='ambulances',search='',statusFilter='pending',editing=false;
        const shell=n('section','','mapa-source-connections'),status=n('p','','mapa-source-status'),content=n('div');shell.append(status,content);mount.append(shell);
        const button=(label,fn)=>{const b=n('button',label,'mapa-source-button');b.type='button';b.onclick=fn;return b;};
        function pager(data,refresh){const bar=n('div','','mapa-integration-controls');const prev=button('Previous',()=>{page--;refresh();}),next=button('Next',()=>{page++;refresh();});prev.disabled=page<=1;next.disabled=!data.has_more;bar.append(prev,n('span','Page '+page),next);content.append(bar);}
        async function review(row){
            if(editing)return;editing=true;const own=++sequence;content.replaceChildren();
            content.append(n('h3','Review source connection'),n('p',row.label+' · ASR ID '+row.source_id),n('p','Linking administrative records does not assign a driver, approve crew, move GPS or change an active trip.'));
            const form=n('form','','mapa-integration-controls'),query=n('input'),options=n('select'),reason=n('input'),confirm=n('input'),confirmLabel=n('label'),submit=n('button','Save reviewed link','mapa-source-button mapa-source-primary');
            query.type='search';query.placeholder='Search native name or registration';query.setAttribute('aria-label','Search native records');options.setAttribute('aria-label','Select native identity');reason.placeholder='Reason for this verified identity match';reason.required=true;reason.maxLength=500;reason.setAttribute('aria-label','Connection review reason');confirm.type='checkbox';confirm.required=true;confirmLabel.append(confirm,document.createTextNode(' I verified both record identities and the ambulance/base details.'));submit.type='submit';submit.disabled=true;
            let optionPage=0,optionSearch='',more=false,busy=false,optionsReady=false;
            const searchButton=button('Search native records',()=>loadOptions(false)),moreButton=button('Load more native records',()=>loadOptions(true));moreButton.disabled=true;
            async function loadOptions(append){
                if(busy)return;busy=true;submit.disabled=true;searchButton.disabled=true;moreButton.disabled=true;status.textContent='Loading eligible native identities…';
                const nextPage=append?optionPage+1:1,nextSearch=append?optionSearch:query.value.trim().slice(0,100);
                try{const data=await request('connections',{dataset,options:'1',search:nextSearch,page:String(nextPage)});if(closed||own!==sequence)return;
                    if(!append){options.replaceChildren();const blank=n('option','Choose a verified native record');blank.value='';options.append(blank);}
                    for(const item of data.rows){const o=n('option',item.label+' · '+item.public_id);o.value=item.uuid;options.append(o);}optionPage=nextPage;optionSearch=nextSearch;optionsReady=true;more=data.has_more;status.textContent='Review before saving. No match is selected automatically.';
                }catch(e){if(!closed&&own===sequence)status.textContent=e.message;}finally{busy=false;if(!closed&&own===sequence){searchButton.disabled=false;moreButton.disabled=!more;submit.disabled=!optionsReady;}}
            }
            form.append(query,searchButton,options,moreButton,reason,confirmLabel,submit);content.append(form,button('Cancel review',()=>{editing=false;load();}));
            form.onsubmit=async e=>{e.preventDefault();if(busy||!options.value||!confirm.checked||reason.value.trim().length<3)return;busy=true;submit.disabled=true;
                try{await request('connection-save',{dataset,source_id:row.source_id,native_uuid:options.value,source_revision:row.source_revision,expected_link_revision:row.link_revision,confirmed:true,reason:reason.value.trim()});if(closed||own!==sequence)return;editing=false;window.dispatchEvent(new Event('mapa-source-refreshed'));load();}
                catch(error){if(!closed&&own===sequence)status.textContent=error.status===409?'The source or connection changed. Cancel and fetch a new review.':error.message;}
                finally{busy=false;if(!closed&&own===sequence)submit.disabled=false;}
            };
            await loadOptions(false);
        }
        async function unlink(row){
            if(editing)return;editing=true;const own=++sequence;content.replaceChildren(n('h3','Review unlink'),n('p',row.label+' · ASR ID '+row.source_id),n('p','This removes the administrative link only. Native accounts, vehicles, assignments and trip history remain.'));
            const form=n('form'),reason=n('input'),submit=n('button','Confirm unlink','mapa-source-button');reason.required=true;reason.maxLength=500;reason.placeholder='Reason for unlink';reason.setAttribute('aria-label','Unlink reason');submit.type='submit';form.append(reason,submit);content.append(form,button('Cancel',()=>{editing=false;load();}));
            form.onsubmit=async e=>{e.preventDefault();if(reason.value.trim().length<3)return;submit.disabled=true;
                try{await request('connection-save',{action:'unlink',dataset,source_id:row.source_id,expected_link_revision:row.link_revision,confirmed:true,reason:reason.value.trim()});if(closed||own!==sequence)return;editing=false;window.dispatchEvent(new Event('mapa-source-refreshed'));load();}
                catch(error){if(!closed&&own===sequence)status.textContent=error.message;}finally{if(!closed&&own===sequence)submit.disabled=false;}
            };
        }
        async function load(){
            if(closed||editing||document.hidden)return;const own=++sequence;status.textContent='Checking source '+(kind==='connections'?'connections':'changes')+'…';
            try{const data=await request(kind==='connections'?'connections':'reconciliation',kind==='connections'?{dataset,search,page:String(page)}:{status:statusFilter,page:String(page)});if(closed||own!==sequence)return;
                content.replaceChildren();status.textContent=(kind==='reconciliation'&&!data.journal_available?'Source change history is not active yet':data.stale?'Updates delayed':'Verified source snapshot')+(kind==='reconciliation'?' · '+data.pending_count+' changes awaiting review':' · '+data.total+' records');
                const controls=n('form','','mapa-integration-controls'),select=n('select');select.setAttribute('aria-label',kind==='connections'?'Resource type':'Review state');
                for(const value of kind==='connections'?['ambulances','drivers','hospitals']:['pending','acknowledged','all']){const option=n('option',value.replaceAll('_',' '));option.value=value;select.append(option);}select.value=kind==='connections'?dataset:statusFilter;select.onchange=()=>{if(kind==='connections')dataset=select.value;else statusFilter=select.value;page=1;load();};controls.append(select);
                if(kind==='connections'){const q=n('input');q.type='search';q.value=search;q.placeholder='ASR name, registration or source ID';q.setAttribute('aria-label','Search source identities');const submit=n('button','Search','mapa-source-button');submit.type='submit';controls.append(q,submit);controls.onsubmit=e=>{e.preventDefault();search=q.value.trim().slice(0,100);page=1;load();};}content.append(controls);
                if(kind==='reconciliation')content.append(n('p',data.review_allowed
                    ?'Review acknowledges the observed change only. It does not start a transfer journey, change assignments or prove when ASR staff made the change.'
                    :'Source changes are read-only here. Review acknowledgements are not enabled. ASR trip operations remain independent.'));
                if(!data.rows.length)content.append(n('p','No records match this view.'));
                for(const row of data.rows){const card=n('article','','fleet-ops-control-hub-panel');
                    if(kind==='connections'){
                        card.append(n('h3',row.label),n('p','ASR ID '+row.source_id+' · '+row.connection.replaceAll('_',' ')),n('p',row.native_label?'MAPA: '+row.native_label:'No eligible native identity linked'));
                        if(row.changed_since_review)card.append(n('p','Source facts changed since this link was reviewed.'));
                        if(row.source_revision)card.append(button(row.native_uuid?'Review / relink':'Review identities and link',()=>review(row)));
                        if(row.native_uuid)card.append(button('Review unlink',()=>unlink(row)));
                    }else{
                        card.append(n('h3',row.dataset+' · ASR ID '+row.source_id+' · '+row.kind.replaceAll('_',' ')),n('p','Observed '+mapaIST(Number(row.observed_at)*1000)));
                        for(const side of ['before','after']){const data=row.observation?.[side];card.append(n('p',side+': '+(data?Object.entries(data).map(([key,value])=>key.replaceAll('_',' ')+': '+(value??'Not recorded')).join(' · '):'No record')));}
                        if(row.acknowledged_at)card.append(n('p','Reviewed '+mapaIST(row.acknowledged_at)));
                        else if(data.review_allowed){const check=n('input');check.type='checkbox';const label=n('label');label.append(check,document.createTextNode(' I reviewed this source change.'));const ack=button('Mark reviewed',async()=>{if(!check.checked)return;ack.disabled=true;try{await request('reconciliation-ack',{id:row.id});if(!closed&&own===sequence)load();}catch(e){if(!closed&&own===sequence){status.textContent=e.message;ack.disabled=false;}}});card.append(label,ack);}
                    }content.append(card);
                }pager(data,load);
            }catch(error){if(!closed&&own===sequence){status.textContent=error.message+' · No operational records were changed.';}}
        }
        load();return {refresh:load,close:()=>{closed=true;sequence++;shell.remove();}};
    }
    window.MapaConnectionView={view};
})();
