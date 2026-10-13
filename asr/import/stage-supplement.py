"""Reproduce the separately reviewed public hospital-pin supplement. No network/DB."""
from pathlib import Path
from datetime import datetime,timezone
import argparse,hashlib,json,math,re

ORIGINAL_SHA='ce53af09b138807dbefb68de562d360b0ab0659726f0a628bfa1517e53912c2d'
def distance(a,b):
    lat1,lon1,lat2,lon2=map(math.radians,[a[0],a[1],b[0],b[1]])
    return round(6371000*2*math.asin(math.sqrt(math.sin((lat2-lat1)/2)**2+math.cos(lat1)*math.cos(lat2)*math.sin((lon2-lon1)/2)**2)))

def stage(original):
    official_coordinate_source='https://msidc.ap.nic.in/wingshtml/ftp/T.No.7.1B_ILL_Broadband.pdf'
    evidence=[
        {'institution_type':'AH','base_name':'Narsipatnam','name':'AH Narsipatnam','identity':'Government Area Hospital Narsipatnam',
         'collision_names':['Narsipatnam','AH Narsipatnam','Area Hospital Narsipatnam','Government Area Hospital Narsipatnam'],
         'latitude':17.6727848,'longitude':82.6107761,'address':'Beside Police Station, Chintapalli Road, Narsipatnam, Andhra Pradesh 531116',
         'google_url':'https://www.google.com/maps/place/AREA+HOSPITAL+Narsipatnam/@17.6727848,82.6107761,17z/data=!3m1!4b1!4m6!3m5!1s0x3a399253542be023:0xe5d5a3eece2f3791!8m2!3d17.6727848!4d82.6107761!16s%2Fg%2F11h9yd0f4y',
         'official_sources':[{'url':'https://qps.nhsrcindia.org/node/16357','supports':'National health-system register confirms facility identity.'},
            {'url':'https://cdnbbsr.s3waas.gov.in/s33dd13c7093362886b8ce5923426fdad4/uploads/2025/11/202511072033328578.pdf','supports':'NBEMS government-hosted seat matrix identifies hospital code700585 beside Police Station on Chintapalli Road.'}],
         'conflicts':[{'source':official_coordinate_source,'latitude':17.665,'longitude':82.614,'decision':'Not selected: government tender coordinate conflicts with the current named Google facility/building and police-station landmark in the official hospital address.'}],
         'confidence':'High facility identity; chosen Google building pin is corroborated by official landmark/address. Conflicting tender point retained. Not a survey or ambulance gate measurement.',
         'status':'proposed_verified_facility_pin'},
        {'institution_type':'PHC','base_name':'K.D.PETA','name':'PHC K.D.PETA','identity':'PRIMARY HEALTH CENTRE (PHC), Krishnadevipeta',
         'collision_names':['K.D.PETA','PHC K.D.PETA','PHC K D Peta','PHC Krishnadevipeta','Primary Health Centre Krishnadevipeta','Primary Health Center Krishnadevipeta','PRIMARY HEALTH CENTRE (PHC), Krishnadevipeta'],
         'latitude':17.6704188,'longitude':82.3856109,'address':'Narsipatnam-Devipatnam main road, Krishnadevipeta, Andhra Pradesh 531084',
         'google_url':'https://www.google.com/maps/place/PRIMARY+HEALTH+CENTRE+(PHC),+Krishnadevipeta/@17.6704188,82.3856109,17z/data=!3m1!4b1!4m6!3m5!1s0x3a39e500457f34a7:0x3ae0a7000759e7b!8m2!3d17.6704188!4d82.3856109!16s%2Fg%2F11lnsyl9m_',
         'official_sources':[{'url':'https://cfw.ap.nic.in/pdf/CAS%20-%20EXISTING%20VACANCIES%20LIST.pdf','supports':'AP public-health list identifies PHC K.D.Peta in Anakapalli.'},
            {'url':'https://nha.gov.in/img/resources/PMJAY-Hospital-List.pdf','supports':'National Health Authority hospital list identifies PHC-KRISHNADEVIPETA in historical Visakhapatnam district.'}],
         'conflicts':[], 'confidence':'Google K.D.Peta search resolves directly to the named Krishnadevipeta PHC; current facility/entrance photo and road pin inspected. K.D.Peta to Krishnadevipeta identity expansion is a corroborated inference, not a new source identity.',
         'status':'proposed_verified_facility_pin'},
        {'institution_type':'KGH','base_name':'KGH','name':'KGH','identity':'King George Hospital, Visakhapatnam',
         'collision_names':['KGH','King George Hospital','King George Hospital, Visakhapatnam'],
         'latitude':17.7087374,'longitude':83.3060385,'address':'KGH Down Road, Maharani Peta, Visakhapatnam, Andhra Pradesh 530002',
         'google_url':'https://www.google.com/maps/place/King+George+Hospital/@17.7087374,83.3060385,17z/data=!3m1!4b1!4m6!3m5!1s0x3a39430fd9c85ff5:0x968bce337f0096bc!8m2!3d17.7087374!4d83.3060385!16s%2Fm%2F0b6fw_1',
         'official_sources':[{'url':'https://visakhapatnam.ap.gov.in/public-utility/king-george-hospital/','supports':'District government identifies KGH opposite Collector Office in Maharanipeta, Visakhapatnam.'},
            {'url':'https://www.kghvisakhapatnam.com/','supports':'Hospital describes a large multi-building campus, rather than a single emergency entrance.'}],
         'conflicts':[], 'confidence':'High facility identity and campus pin confidence. Named Google hospital point, not emergency-gate or surveyed building boundary. Visakhapatnam external referral/base from source mapping; not an ASR-district hospital assertion.',
         'status':'proposed_verified_facility_pin'},
        {'institution_type':'PHC','base_name':'Nathavaram','name':'PHC Nathavaram','identity':'Primary Health Centre Nathavaram',
         'latitude':None,'longitude':None,'address':None,
         'google_url':'https://www.google.com/maps/place/PHC+Nathavaram/@17.5703025,82.4939936,17z/data=!3m1!4b1!4m6!3m5!1s0x3a39ea3451178ce1:0x320ef03faffa1781!8m2!3d17.5703025!4d82.4939936!16s%2Fg%2F12hl55kss',
         'official_sources':[{'url':'https://nqas.nhsrcindia.org/facilities/list/page/55?sortby=s_no','supports':'National health register confirms PHC Nathavaram, NIN7177648651 (historical district name Visakhapatnam).'}],
         'conflicts':[{'source':'Google Maps place listing','latitude':17.5703025,'longitude':82.4939936,'decision':'Not selected: Google explicitly displays Location may be incorrect.'},
            {'source':official_coordinate_source,'latitude':17.5431,'longitude':82.4749,'decision':'Not selected: no building corroboration and conflicts materially with Google pin.'}],
         'confidence':'Identity verified; building point unresolved. Source coordinates were N/A. Native places.location is POINT NOT NULL, so retain private pending identity without invented0,0 or schema changes.',
         'status':'unresolved_pending_verified_pin'},
    ]
    records=[]
    for row in evidence:
        match=[h for h in original['hospitals'] if h['institution_type']==row['institution_type'] and h['base_name']==row['base_name']]
        if len(match)!=1 or match[0]['status']!='deferred':raise ValueError('Original deferred identity mismatch')
        row['hospital_key']=match[0]['key'];row['source_rows']=match[0]['source_rows']
        if row['latitude'] is not None:
            pin=re.search(r'!3d(-?[\d.]+)!4d(-?[\d.]+)',row['google_url'])
            if not pin or [float(pin[1]),float(pin[2])]!=[row['latitude'],row['longitude']]:raise ValueError('Google place point mismatch')
            row['google_named_facility_pin_verified']=True;row['ground_surveyed']=False
            record={key:row[key] for key in ['hospital_key','institution_type','base_name','name','identity','latitude','longitude','address','google_url','official_sources','conflicts','confidence','source_rows','collision_names']}
            record['source_context']=match[0].get('context',[]);record['coordinate_semantics']='researched_hospital_facility_pin_not_live_gps';record['ground_surveyed']=False
            records.append(record)
        for conflict in row['conflicts']:
            if row['latitude'] is not None:conflict['distance_from_chosen_pin_m']=distance([row['latitude'],row['longitude']],[conflict['latitude'],conflict['longitude']])
    evidence_document={'contract':'mapa-hospital-location-evidence-v1','checked_at':datetime.now(timezone.utc).isoformat(),'original_plan_sha256':ORIGINAL_SHA,
        'method':'Read public official sources and Google Maps facility details/building previews. Use place !3d/!4d point, not viewport center. No private source data uploaded, no live GPS inferred.','hospitals':evidence}
    links=[{'vehicle_key':v['key'],'hospital_key':v['hospital_key']} for v in original['vehicles'] if v['hospital_key'] in [r['hospital_key'] for r in records] and v['status']=='create']
    plan={'contract':'asr-hospital-pin-supplement-v1','company_uuid':original['company_uuid'],'source_origin':original['source_origin'],'original_plan_sha256':ORIGINAL_SHA,
        'source_csv_sha256':original['fingerprints']['csv_sha256'],'mode':'review_before_apply','hospitals':records,'vehicle_links':links,
        'unresolved':[{'hospital_key':evidence[-1]['hospital_key'],'institution_type':'PHC','base_name':'Nathavaram','reason':'Google location warning and materially conflicting official coordinates; native non-null location cannot represent pending point safely.'}],
        'scope':'Create or replay exact3 researched hospital identities; link source-import inventory metadata only. No accounts, crew approval, assignments, status, duty or positions.',
        'summary':{'proposed_hospitals':len(records),'reviewed_inventory_metadata_links':len(links),'unresolved_hospitals':1}}
    return evidence_document,plan

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--original-plan',required=True);parser.add_argument('--evidence-output',required=True);parser.add_argument('--plan-output',required=True);args=parser.parse_args()
    raw=Path(args.original_plan).read_bytes()
    if hashlib.sha256(raw).hexdigest()!=ORIGINAL_SHA:raise ValueError('Immutable original reviewed plan required')
    evidence,plan=stage(json.loads(raw))
    evidence_raw=(json.dumps(evidence,ensure_ascii=False,indent=2)+'\n').encode('utf-8');plan['evidence_sha256']=hashlib.sha256(evidence_raw).hexdigest()
    for target,content in [(Path(args.evidence_output),evidence_raw),(Path(args.plan_output),(json.dumps(plan,ensure_ascii=False,indent=2)+'\n').encode('utf-8'))]:
        target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(content)
    print(json.dumps({'summary':plan['summary'],'plan_sha256':hashlib.sha256(Path(args.plan_output).read_bytes()).hexdigest(),'evidence_sha256':plan['evidence_sha256']}))
if __name__=='__main__':main()
