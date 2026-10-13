"""Validate source exports and produce a private resource-only review plan. No network/DB."""
import argparse, csv, hashlib, json, math, re, sys, unicodedata
from collections import Counter, defaultdict
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path

COMPANY='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'
ORIGIN='https://asrhealth.in'
CSV_FIELDS=['S.No','Institute Type','Hospital / Parking Base','Secretariat','Mandal','Vehicle Number','Type of Vehicle','Latitude','Longitude','Date Mapped']
DRIVER_FIELDS={'source_id','name','phone','hospital','vehicle_type','date_joined'}

def sha(data): return hashlib.sha256(data).hexdigest()
def text(value): return '' if value is None else str(value).strip()
def normal(value): return re.sub(r'\s+',' ',unicodedata.normalize('NFKC',text(value))).lower()
def plate(value): return re.sub(r'[^A-Z0-9]','',text(value).upper())
def hospital_key(institution,base): return sha((normal(institution)+'\0'+normal(base)).encode('utf-8'))
def hospital_name(institution,base):
    institution,base=text(institution),text(base)
    return base if re.match(r'^'+re.escape(institution)+r'(?:$|[\s-])',base,re.IGNORECASE) else institution+' '+base
def phone(value):
    raw=text(value)
    if not re.fullmatch(r'[+0-9 .()\-]+',raw): return None
    digits=re.sub(r'\D','',raw)
    if len(digits)==12 and digits.startswith('91'): digits=digits[2:]
    if len(digits)==11 and digits.startswith('0'): digits=digits[1:]
    return '+91'+digits if re.fullmatch(r'[6-9][0-9]{9}',digits) else None

def coordinates(latitude,longitude):
    if not text(latitude) or not text(longitude): return None,'missing_coordinates'
    try:
        lat,lng=Decimal(text(latitude)),Decimal(text(longitude))
        if not lat.is_finite() or not lng.is_finite() or not(-90<=lat<=90 and -180<=lng<=180) or (lat==0 and lng==0):
            return None,'invalid_coordinates'
        return (float(lat),float(lng)),None
    except (InvalidOperation,ValueError): return None,'invalid_coordinates'

def validate_snapshot(snapshot):
    if snapshot is None: return
    if snapshot.get('contract')!='asr-native-import-snapshot-v1' or snapshot.get('read_only') is not True or snapshot.get('company_uuid')!=COMPANY:
        raise ValueError('Expected an authorized same-company read-only native snapshot.')
    for collection in ['vehicles','places','users','drivers']:
        if not isinstance(snapshot.get(collection),list): raise ValueError('Snapshot collection missing: '+collection)
        if any(row.get('company_uuid')!=COMPANY for row in snapshot[collection]): raise ValueError('Foreign-company snapshot rows are forbidden.')

def source_meta(kind,key): return {'origin':ORIGIN,'kind':kind,'source_key':key}
def flagged(record): return any(record.get(key) in [True,1,'1','true','TRUE'] for key in ['demo','simulated'])

def stage_resources(rows,snapshot=None):
    validate_snapshot(snapshot)
    bases=defaultdict(list);vehicles=defaultdict(list);issues=[]
    for index,row in enumerate(rows,2):
        if set(row)!=set(CSV_FIELDS) or None in row or any(value is None for value in row.values()): raise ValueError('Malformed CSV structure at source row '+str(index))
        values={key:text(value) for key,value in row.items()}
        institution,base=values['Institute Type'],values['Hospital / Parking Base']
        if not institution or not base or len(base)>150 or len(institution)>50 or len(hospital_name(institution,base))>150:
            issues.append({'kind':'hospital','source_rows':[index],'code':'missing_or_oversized_hospital_identity'});key=None
        else:
            key=hospital_key(institution,base);point,reason=coordinates(values['Latitude'],values['Longitude'])
            bases[key].append({'row':index,'values':values,'point':point,'coordinate_issue':reason})
        registration=values['Vehicle Number'];canonical=plate(registration);vehicle_type=values['Type of Vehicle']
        if not re.fullmatch(r'[A-Za-z0-9 .\-]{1,50}',registration) or not canonical or canonical in {'0','NA','NONE','NULL','NOTMAPPED'} or not vehicle_type or len(vehicle_type)>100 or len(vehicle_type+' '+registration)>150:
            issues.append({'kind':'vehicle','source_rows':[index],'code':'invalid_vehicle_identity_or_type'});continue
        vehicles[canonical].append({'row':index,'values':values,'hospital_key':key})
    existing_vehicles=defaultdict(list);existing_places=[]
    if snapshot:
        for record in snapshot['vehicles']: existing_vehicles[plate(record.get('plate_number'))].append(record)
        existing_places=snapshot['places']
    hospitals=[]
    for key,items in sorted(bases.items()):
        first=items[0]['values'];points={item['point'] for item in items if item['point'] is not None}
        record={'key':key,'source_rows':[item['row'] for item in items],'institution_type':first['Institute Type'],'base_name':first['Hospital / Parking Base'],
            'context':[{'secretariat':item['values']['Secretariat'],'mandal':item['values']['Mandal'],'date_mapped':item['values']['Date Mapped']} for item in items]}
        invalid=any(item['coordinate_issue']=='invalid_coordinates' for item in items)
        if len(points)>1 or invalid or not points:
            record['status']='deferred';record['reason']='coordinate_conflict' if len(points)>1 else 'invalid_coordinates' if invalid else 'missing_coordinates'
            record['coordinate_candidates']=[{'latitude':pair[0],'longitude':pair[1]} for pair in sorted(points)]
        else:
            point=next(iter(points));record['latitude'],record['longitude']=point
            exact=[p for p in existing_places if p.get('source_key')==key or (normal(p.get('institution_type'))==normal(record['institution_type']) and normal(p.get('source_base_name'))==normal(record['base_name']))]
            display=hospital_name(record['institution_type'],record['base_name'])
            names=[p for p in existing_places if not p.get('source_key') and normal(p.get('name')) in {normal(record['base_name']),normal(display)} and p not in exact]
            if len(exact)>1 or any(p.get('deleted_at') or flagged(p) for p in exact):record['status']='conflict';record['reason']='duplicate_deleted_or_training_existing_hospital'
            elif exact:
                record['status']='preserve_existing';record['existing_id']=exact[0]['public_id'];record['existing_uuid']=exact[0]['uuid']
                record['coordinates_preserved']=True
            elif names:
                record['status']='conflict';record['reason']='existing_hospital_name_requires_institution_review';record['existing_ids']=[p['public_id'] for p in names]
            else:record['status']='create' if snapshot else 'comparison_required'
            record['native']={'name':display,'type':'hospital'}
            record['meta']={'asr_import':{**source_meta('hospital',key),'hospital_key':key,'institution_type':record['institution_type'],'base_name':record['base_name'],
                'coordinate_semantics':'source_hospital_or_parking_base_not_live_gps','coordinate_source':'official_mapping_export'}}
        hospitals.append(record)
    proposed_vehicles=[]
    for key,items in sorted(vehicles.items()):
        first=items[0];values=first['values'];identities={(item['hospital_key'],normal(item['values']['Type of Vehicle'])) for item in items}
        record={'key':key,'source_rows':[item['row'] for item in items],'hospital_key':first['hospital_key'],'registration':values['Vehicle Number'].upper(),
            'source_vehicle_type':values['Type of Vehicle'],'context':[{'secretariat':item['values']['Secretariat'],'mandal':item['values']['Mandal'],'date_mapped':item['values']['Date Mapped']} for item in items]}
        existing=existing_vehicles.get(key,[])
        if len(identities)>1:record['status']='conflict';record['reason']='source_plate_has_conflicting_type_or_base'
        elif len(existing)>1 or any(r.get('deleted_at') or flagged(r) for r in existing):record['status']='conflict';record['reason']='duplicate_deleted_or_training_existing_plate'
        elif existing:
            record['status']='preserve_existing';record['existing_id']=existing[0]['public_id'];record['existing_uuid']=existing[0]['uuid']
            record['existing_type_preserved']=True;record['source_type_differs']=normal(existing[0].get('type'))!=normal(record['source_vehicle_type'])
        else:record['status']='create' if snapshot else 'comparison_required'
        record['native']={'name':record['source_vehicle_type']+' '+record['registration'],'plate_number':record['registration'],'type':record['source_vehicle_type'],'online':False,'status':'available'}
        record['omit_native_fields']=['location','driver_uuid','vehicle_uuid','current_job_uuid']
        record['meta']={'asr_import':{**source_meta('vehicle',key),'vehicle_key':key,'hospital_key':record['hospital_key'],'source_vehicle_type':record['source_vehicle_type'],
            'coordinate_semantics':'no_vehicle_location_imported','import_scope':'inventory_only','operational_status_verified':False}}
        proposed_vehicles.append(record)
    return {'hospitals':hospitals,'vehicles':proposed_vehicles,'issues':issues}

def stage_drivers(rows,snapshot=None):
    by_id=defaultdict(list)
    for index,row in enumerate(rows,1):
        if not isinstance(row,dict) or set(row)!=DRIVER_FIELDS: raise ValueError('Expected the six allowlisted driver fields at source row '+str(index))
        values={key:text(value) for key,value in row.items()}
        by_id[values['source_id']].append({'source_row':index,'source':values})
    staged=[];contacts=defaultdict(list);existing_contacts=defaultdict(list)
    if snapshot:
        for user in snapshot['users']:
            contact=phone(user.get('phone'))
            if contact:existing_contacts[contact].append(user['public_id'])
    for source_id,items in sorted(by_id.items()):
        variants={json.dumps(item['source'],sort_keys=True,ensure_ascii=False) for item in items}
        for item in items:
            item['status']='staged_only';item['reasons']=['identity_and_fresh_gps_onboarding_required'];item['key']=sha((ORIGIN+'\0'+source_id).encode())
            if not source_id or len(variants)>1:item['reasons'].append('missing_or_conflicting_source_id')
            elif len(items)>1:item['exact_duplicate_source_rows']=[entry['source_row'] for entry in items]
            if not item['source']['name'] or len(item['source']['name'])>150:item['reasons'].append('invalid_name')
            contact=phone(item['source']['phone']);item['normalized_phone']=contact
            if not contact:item['reasons'].append('invalid_or_missing_phone')
            else:
                contacts[contact].append(item)
                if existing_contacts[contact]:item['reasons'].append('existing_native_contact_requires_review');item['existing_user_ids']=existing_contacts[contact]
            staged.append(item)
    for group in contacts.values():
        if len({item['source']['source_id'] for item in group})>1:
            for item in group:item['reasons'].append('shared_phone_multiple_source_ids')
    return staged

def make_plan(csv_rows,driver_rows,snapshot=None):
    resources=stage_resources(csv_rows,snapshot)
    drivers=stage_drivers(driver_rows,snapshot)
    return {'contract':'asr-source-resource-plan-v1','mode':'dry_run','company_uuid':COMPANY,'source_origin':ORIGIN,'snapshot_compared':snapshot is not None,
        **resources,'drivers_staged':drivers,'driver_import_allowed':False,
        'summary':{'source_mapping_rows':len(csv_rows),'source_driver_rows':len(driver_rows),'unique_hospitals':len(resources['hospitals']),'unique_vehicles':len(resources['vehicles']),
            'hospital_statuses':dict(Counter(row['status'] for row in resources['hospitals'])),'vehicle_statuses':dict(Counter(row['status'] for row in resources['vehicles'])),
            'resource_issues':len(resources['issues']),'driver_conflicts':dict(Counter(reason for row in drivers for reason in row['reasons'] if reason!='identity_and_fresh_gps_onboarding_required')),
            'drivers_imported':0,'accounts_created':0,'live_positions_imported':0}}

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--csv',required=True);parser.add_argument('--drivers',required=True)
    parser.add_argument('--snapshot');parser.add_argument('--output',required=True)
    args=parser.parse_args();csv_bytes=Path(args.csv).read_bytes();driver_bytes=Path(args.drivers).read_bytes()
    with Path(args.csv).open(encoding='utf-8-sig',newline='') as handle:
        reader=csv.DictReader(handle)
        if reader.fieldnames!=CSV_FIELDS:raise ValueError('Unexpected official CSV header/order.')
        rows=list(reader)
    driver_rows=json.loads(driver_bytes)
    if not isinstance(driver_rows,list):raise ValueError('Expected a private driver row array.')
    snapshot_bytes=Path(args.snapshot).read_bytes() if args.snapshot else None
    plan=make_plan(rows,driver_rows,json.loads(snapshot_bytes) if snapshot_bytes else None)
    plan['staged_at']=datetime.now(timezone.utc).isoformat();plan['fingerprints']={'csv_sha256':sha(csv_bytes),'drivers_sha256':sha(driver_bytes),
        'snapshot_sha256':sha(snapshot_bytes) if snapshot_bytes else None,'staging_source_sha256':sha(Path(__file__).read_bytes())}
    output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True)
    data=(json.dumps(plan,ensure_ascii=False,indent=2,allow_nan=False)+'\n').encode('utf-8');output.write_bytes(data)
    print(json.dumps({'private_plan':str(output),'plan_sha256':sha(data),'dry_run':True,'summary':plan['summary']},ensure_ascii=False))
    return 0

if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,OSError) as error:print('Staging failed: '+str(error),file=sys.stderr);sys.exit(1)
