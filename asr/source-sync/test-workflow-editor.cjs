const assert=require('node:assert/strict');
const {values}=require('../../console/public/mapa-workflow-editor.js');
let checks=0;const check=(value)=>{assert.ok(value);checks++;};
check(values('session.start',{record_date:'2026-10-06',status:'Off Road',odo_reading:'bad'}).odo_reading===null);
check(values('session.close',{daily_status_id:'1',closing_odo:'1200.25'}).closing_odo==='1200.25');
for(const value of ['-1','1e3','NaN','1.234','']){assert.throws(()=>values('session.close',{closing_odo:value}));checks++;}
const draft={daily_status_id:'1',driver_id:'1',trip_type:'patient_village',pickup_date:'2026-10-06',drop_date:'2026-10-06',pickup_time:'11:00',drop_time:'12:00',distance_kms:'20.25',from_phc_id:'DEMO-A',to_phc_id:'DEMO-B',from_secretariat_id:'DEMO-S',from_village_id:'DEMO-V',patients:[{name:'DEMO patient',age:'0',gender:'Female',phone:'9000000000',purpose:'General Emergency'}]};
const trip=values('trip.record',draft);check(trip.pickup_time==='11:00:00'&&trip.patients[0].age===0&&trip.to_secretariat_id===null);
const other=values('trip.record',{...draft,trip_type:'other',to_secretariat_id:'DEMO-T',to_village_id:'DEMO-W'});check(other.patients[0].age===null&&other.patients[0].phone===''&&other.to_village_id==='DEMO-W');
assert.throws(()=>values('trip.record',{...draft,patients:[]}));checks++;
assert.throws(()=>values('trip.record',{...draft,patients:Array(11).fill(draft.patients[0])}));checks++;
assert.throws(()=>values('trip.record',{...draft,patients:[{...draft.patients[0],age:''}]}));checks++;
console.log(checks+' workflow payload checks passed.');
