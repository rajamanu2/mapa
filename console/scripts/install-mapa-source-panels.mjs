// Add scoped source projections alongside native fields; no native resource edits.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const scripts=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(process.argv[2]??'node_modules/@fleetbase/fleetops-engine');
for(const extension of ['js','hbs']){
    const file=path.join(root,'addon/components/mapa-source-panel.'+extension);
    fs.writeFileSync(file,fs.readFileSync(path.join(scripts,'mapa-source-panel.'+(extension==='js'?'js.txt':'hbs')),'utf8'));
}
const app=path.join(root,'app/components/mapa-source-panel.js');fs.mkdirSync(path.dirname(app),{recursive:true});
fs.writeFileSync(app,"export { default } from '@fleetbase/fleetops-engine/components/mapa-source-panel';\n");
for(const [component,dataset] of [['vehicle','ambulances'],['driver','drivers'],['place','hospitals']]){
    const file=path.join(root,'addon/components',component,'details.hbs');let template=fs.readFileSync(file,'utf8');
    const tag='<MapaSourcePanel @dataset="'+dataset+'" @resource={{@resource}} />';
    if(!template.includes(tag)){
        const anchor='<div class="details-wrapper" ...attributes>';
        if(template.split(anchor).length!==2)throw new Error('Native details anchor changed: '+file);
        template=template.replace(anchor,anchor+'\n    '+tag);fs.writeFileSync(file,template);
    }
}
console.log('ASR source panels installed in native resource details.');
for(const [relative,mode] of [['operations/orders/index.hbs','operations'],['management/index.hbs','resources'],['maintenance/index.hbs','maintenance'],['analytics/index.hbs','analytics']]){
    const file=path.join(root,'addon/templates',relative);let template=fs.readFileSync(file,'utf8');
    const tag='<MapaSourcePanel @mode="'+mode+'" />';
    const anchor=['resources','maintenance'].includes(mode)?'<div class="fleet-ops-control-hub-body">':mode==='analytics'?'<Spacer @height="300px" />':null;
    const positioned=anchor?(mode==='analytics'?tag+'\n    '+anchor:anchor+'\n        '+tag):tag;
    if(!template.includes(positioned)){
        // Move an earlier review panel into the native screen's own scroll container.
        if(template.includes(tag))template=template.replace(tag,'');
        if(anchor){
            if(template.split(anchor).length!==2)throw new Error('Native dashboard anchor changed: '+file);
            template=template.replace(anchor,mode==='analytics'?tag+'\n    '+anchor:anchor+'\n        '+tag);
        }else template+='\n'+tag+'\n';
        fs.writeFileSync(file,template);
    }
}
