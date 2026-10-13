/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const n=(tag,text='')=>{const el=document.createElement(tag);el.textContent=text;return el;};
    function open({request,row,action,onDone,onDenied}){
        let submitted=null,saving=false,uncertain=false,closed=false;
        const dialog=n('dialog');dialog.className='mapa-integration mapa-integration-editor';dialog.setAttribute('aria-label','Review ASR Health edit');
        const title=action==='ambulance.profile'?'Edit ambulance profile':action==='driver.transfer'?'Transfer driver':'Transfer ambulance';
        dialog.append(n('h2',title),n('p',String(row.name||row.vehicle_number)+' · ASR record '+row.id),n('p','This saves in ASR Health. Live dispatch and crew assignments in MAPA require a separate operational review.'));
        const form=n('form'),fields=n('fieldset'),status=n('p'),save=n('button','Save in ASR Health'),cancel=n('button','Cancel');status.setAttribute('role','status');save.type='submit';cancel.type='button';
        const inputs={};
        const field=(key,label,type='text')=>{const wrap=n('label',label),input=n(type==='select'?'select':'input');input.name=key;input.setAttribute('aria-label',label);if(type!=='select')input.type=type;inputs[key]=input;wrap.append(input);fields.append(wrap);return input;};
        if(action==='ambulance.profile'){
            const model=field('vehicle_model','Vehicle model');model.maxLength=100;model.value=row.vehicle_model||'';
            const year=field('model_year','Model year','number');year.min='1900';year.max=String(new Date().getFullYear()+1);year.step='1';year.value=row.model_year||'';
        }else{
            const institute=field('institute_type_id','New institute type','select');institute.required=true;institute.disabled=true;
            const destination=field('phc_id','New hospital / base','select');destination.required=true;destination.append(n('option','Loading verified hospitals…'));destination.disabled=true;save.disabled=true;
            (async()=>{
                try{
                    const hospitals=[];for(let page=1;page<=500;page++){const data=await request('hospitals',{page:String(page)});if(closed)return;hospitals.push(...data.rows);if(!data.has_more)break;if(page===500)throw new Error('Too many hospitals.');}
                    const types=[];for(let page=1;page<=50;page++){const data=await request('institute_types',{page:String(page)});if(closed)return;types.push(...data.rows);if(!data.has_more)break;if(page===50)throw new Error('Too many institute types.');}
                    institute.replaceChildren();const typePlaceholder=n('option','Select institute type');typePlaceholder.value='';institute.append(typePlaceholder);
                    for(const type of types){const option=n('option',type.type);option.value=String(type.id);institute.append(option);}
                    const populate=()=>{destination.replaceChildren();const placeholder=n('option','Select a hospital / base');placeholder.value='';destination.append(placeholder);for(const hospital of hospitals.filter(h=>String(h.type)===institute.value)){const option=n('option',hospital.name+' � '+hospital.phc_id);option.value=String(hospital.phc_id);option.dataset.institute=String(hospital.type);destination.append(option);}destination.disabled=!institute.value;};
                    institute.onchange=populate;institute.disabled=false;populate();save.disabled=false;
                }catch(e){if(closed)return;status.textContent='Hospital list is unavailable. Close and fetch the latest records before editing.';if([401,403].includes(e.status))onDenied();}
            })();
        }
        const consent=n('label'),confirm=n('input');confirm.type='checkbox';confirm.required=true;confirm.setAttribute('aria-label','I reviewed this change');consent.append(confirm,document.createTextNode(' I reviewed this change and want to save it in ASR Health.'));fields.append(consent);
        const actions=n('div');actions.className='mapa-integration-actions';actions.append(save,cancel);form.append(fields,actions,status);dialog.append(form);
        const leave=()=>{if(saving)return;closed=true;dialog.remove();onDone(false);};cancel.onclick=leave;dialog.addEventListener('cancel',e=>{e.preventDefault();leave();});
        form.onsubmit=async e=>{
            e.preventDefault();if(saving||(!submitted&&!form.reportValidity()))return;
            if(!submitted){const values=action==='ambulance.profile'?{vehicle_model:inputs.vehicle_model.value.trim(),model_year:inputs.model_year.value===''?null:Number(inputs.model_year.value)}:{phc_id:inputs.phc_id.value};if(action==='ambulance.transfer')values.institute_type_id=inputs.phc_id.selectedOptions[0].dataset.institute;
                submitted={request_id:crypto.randomUUID(),action,record_id:String(row.id),expected_revision:row.expected_revision,values};}
            saving=true;fields.disabled=true;save.disabled=true;cancel.disabled=true;status.textContent='Saving in ASR Health…';
            try{
                const result=await request('command',submitted);if(closed)return;
                if(result.outcome!=='applied'||result.request_id!==submitted.request_id||result.action!==action||result.record_id!==String(row.id))throw new Error('Unverified result');
                uncertain=false;status.textContent=result.refresh_status==='complete'?'Saved in ASR Health and refreshed.':'Saved in ASR Health. Fetch latest data to see the confirmed update.';
                save.hidden=true;cancel.textContent='Done';cancel.onclick=()=>{closed=true;dialog.remove();onDone(true);};
            }catch(error){
                if(closed)return;
                if([401,403].includes(error.status)){closed=true;dialog.remove();onDenied();return;}
                if([404,409,422].includes(error.status)){status.textContent=error.status===409?'ASR Health changed since you opened this record. Close, fetch latest data and review before saving.':'ASR Health rejected this edit. Close and review the record and destination.';save.hidden=true;}
                else{uncertain=true;status.textContent='Save outcome is not confirmed. Retry this same request; do not create another edit. Request: '+submitted.request_id;save.textContent='Retry same request';}
            }finally{saving=false;save.disabled=false;cancel.disabled=false;if(uncertain)cancel.textContent='Close · verify outcome in ASR';}
        };
        document.body.append(dialog);dialog.showModal();return {close:()=>{closed=true;dialog.remove();}};
    }
    window.MapaIntegrationEditor={open};
})();
