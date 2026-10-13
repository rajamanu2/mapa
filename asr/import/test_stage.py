"""Isolated DEMO fixture acceptance; imports no database/network code."""
import copy, unittest
from stage import COMPANY,CSV_FIELDS,coordinates,hospital_key,hospital_name,make_plan,phone,stage_drivers,stage_resources

def mapping(**changes):
    data=dict(zip(CSV_FIELDS,['1','PHC','DEMO Base','DEMO Secretariat','DEMO Mandal','TS09AB1001','Special','18.1','82.1','2026-09-30']))
    data.update(changes);return data
def driver(**changes):
    data={'source_id':'DEMO_1','name':'DEMO source crew','phone':'9000000001','hospital':'DEMO Base','vehicle_type':'Special','date_joined':'source date retained'}
    data.update(changes);return data
def snapshot(): return {'contract':'asr-native-import-snapshot-v1','company_uuid':COMPANY,'read_only':True,'vehicles':[],'places':[],'users':[],'drivers':[]}

class SourcePlanTests(unittest.TestCase):
    def test_creation_is_offline_and_never_uses_base_as_vehicle_gps(self):
        plan=make_plan([mapping()],[driver()],snapshot());vehicle=plan['vehicles'][0]
        self.assertEqual(vehicle['status'],'create');self.assertFalse(vehicle['native']['online'])
        self.assertNotIn('location',vehicle['native']);self.assertNotIn('driver_uuid',vehicle['native'])
        self.assertFalse(vehicle['meta']['asr_import']['operational_status_verified']);self.assertEqual(vehicle['meta']['asr_import']['import_scope'],'inventory_only')
        self.assertEqual(vehicle['native']['type'],'Special');self.assertEqual(plan['hospitals'][0]['latitude'],18.1)
        self.assertFalse(plan['driver_import_allowed']);self.assertEqual(plan['summary']['accounts_created'],0)
    def test_source_vehicle_type_is_preserved_without_capability_guess(self):
        plan=stage_resources([mapping(**{'Type of Vehicle':'Feeder/Bike'})],snapshot())
        self.assertEqual(plan['vehicles'][0]['native']['type'],'Feeder/Bike')
        self.assertNotIn('BLS',str(plan));self.assertNotIn('ALS',str(plan))
    def test_names_obey_native_150_character_limit_and_context_retained(self):
        invalid=stage_resources([mapping(**{'Type of Vehicle':'X'*100,'Vehicle Number':'A'*50})],snapshot())
        self.assertEqual(len(invalid['vehicles']),0);self.assertEqual(invalid['issues'][0]['code'],'invalid_vehicle_identity_or_type')
        invalid_hospital=stage_resources([mapping(**{'Hospital / Parking Base':'X'*148})],snapshot())
        self.assertEqual(len(invalid_hospital['hospitals']),0)
        result=stage_resources([mapping()],snapshot())
        self.assertEqual(result['vehicles'][0]['context'][0],{'secretariat':'DEMO Secretariat','mandal':'DEMO Mandal','date_mapped':'2026-09-30'})
        self.assertEqual(result['hospitals'][0]['context'],result['vehicles'][0]['context'])
    def test_missing_native_snapshot_blocks_create_readiness(self):
        plan=stage_resources([mapping()]);self.assertEqual(plan['vehicles'][0]['status'],'comparison_required');self.assertEqual(plan['hospitals'][0]['status'],'comparison_required')
    def test_wrong_company_or_foreign_snapshot_is_rejected(self):
        for modified in [{**snapshot(),'company_uuid':'DEMO_foreign'},{**snapshot(),'vehicles':[{'company_uuid':'DEMO_foreign'}]},{**snapshot(),'read_only':False}]:
            with self.assertRaises(ValueError):stage_resources([mapping()],modified)
    def test_normalized_plate_preserves_existing_exact_native_record(self):
        existing=snapshot();record={'company_uuid':COMPANY,'uuid':'DEMO_uuid','public_id':'DEMO_vehicle','plate_number':'ts 09-ab.1001','type':'existing actual type','online':True,'deleted_at':None}
        existing['vehicles']=[record];original=copy.deepcopy(existing)
        result=stage_resources([mapping()],existing)['vehicles'][0]
        self.assertEqual(result['status'],'preserve_existing');self.assertEqual(result['existing_id'],'DEMO_vehicle');self.assertTrue(result['source_type_differs']);self.assertEqual(existing,original)
    def test_existing_duplicate_or_deleted_plate_is_a_conflict(self):
        record={'company_uuid':COMPANY,'uuid':'DEMO_uuid','public_id':'DEMO_vehicle','plate_number':'TS09AB1001','deleted_at':'DEMO deleted'}
        for records in [[record],[{**record,'deleted_at':None},{**record,'public_id':'DEMO_other','deleted_at':None}]]:
            existing=snapshot();existing['vehicles']=records;self.assertEqual(stage_resources([mapping()],existing)['vehicles'][0]['status'],'conflict')
    def test_training_native_record_is_not_reused_as_live_resource(self):
        existing=snapshot();existing['vehicles']=[{'company_uuid':COMPANY,'uuid':'DEMO_uuid','public_id':'DEMO_vehicle','plate_number':'TS09AB1001','deleted_at':None,'demo':'true'}]
        self.assertEqual(stage_resources([mapping()],existing)['vehicles'][0]['status'],'conflict')
    def test_launcher_namespace_order_and_exact_shared_code(self):
        from apply import compose_body
        body=compose_body({'plan_base64':'DEMO test fixture'})
        self.assertLess(body.index('namespace ASR\\Import {'),body.index('$asrImportInput='))
        self.assertIn('ImportApply::apply($plan,$reviewedDigest)',body)
    def test_exact_source_mapping_duplicate_dedupes_safely(self):
        plan=stage_resources([mapping(),mapping()],snapshot());self.assertEqual(len(plan['vehicles']),1);self.assertEqual(len(plan['hospitals']),1)
    def test_same_plate_different_base_or_type_is_a_conflict(self):
        for changed in [{'Hospital / Parking Base':'DEMO Other'},{'Type of Vehicle':'Feeder/Bike'}]:
            self.assertEqual(stage_resources([mapping(),mapping(**changed)],snapshot())['vehicles'][0]['status'],'conflict')
    def test_hospital_key_includes_institution_type(self):
        self.assertNotEqual(hospital_key('PHC','DEMO Base'),hospital_key('AH','DEMO Base'))
        self.assertEqual(hospital_key('PHC',' DEMO   Base '),hospital_key('phc','demo base'))
    def test_hospital_display_prefix_is_clear_and_not_duplicated(self):
        self.assertEqual(hospital_name('PHC','DEMO Base'),'PHC DEMO Base');self.assertEqual(hospital_name('KGH','KGH'),'KGH')
        self.assertEqual(hospital_name('PHC','PHC-DEMO Base'),'PHC-DEMO Base')
    def test_distinct_tiers_with_same_base_name_remain_distinct(self):
        plan=stage_resources([mapping(),mapping(**{'Institute Type':'AH','Vehicle Number':'TS09AB1002'})],snapshot())
        self.assertEqual({p['native']['name'] for p in plan['hospitals']},{'AH DEMO Base','PHC DEMO Base'})
        self.assertEqual(len(plan['hospitals']),2)
    def test_missing_coordinates_stay_pending_without_invented_origin(self):
        result=stage_resources([mapping(Latitude='',Longitude='')],snapshot())['hospitals'][0]
        self.assertEqual(result['status'],'deferred');self.assertEqual(result['reason'],'missing_coordinates');self.assertNotIn('latitude',result)
    def test_invalid_nonfinite_or_zero_origin_coordinates_are_rejected(self):
        for lat,lng in [('NaN','82'),('18','Infinity'),('91','82'),('18','181'),('0','0')]:self.assertEqual(coordinates(lat,lng)[1],'invalid_coordinates')
    def test_same_hospital_conflicting_coordinates_remain_pending(self):
        hospital=stage_resources([mapping(),mapping(Latitude='18.2')],snapshot())['hospitals'][0]
        self.assertEqual(hospital['reason'],'coordinate_conflict');self.assertEqual(len(hospital['coordinate_candidates']),2);self.assertNotIn('native',hospital)
    def test_unknown_existing_hospital_name_is_not_merged(self):
        existing=snapshot();existing['places']=[{'company_uuid':COMPANY,'uuid':'DEMO_uuid','public_id':'DEMO_place','name':'DEMO Base','deleted_at':None}]
        hospital=stage_resources([mapping()],existing)['hospitals'][0];self.assertEqual(hospital['status'],'conflict')
    def test_exact_typed_existing_hospital_is_preserved(self):
        existing=snapshot();existing['places']=[{'company_uuid':COMPANY,'uuid':'DEMO_uuid','public_id':'DEMO_place','name':'DEMO Base','source_key':hospital_key('PHC','DEMO Base'),'latitude':18.3,'longitude':82.3,'deleted_at':None}]
        hospital=stage_resources([mapping()],existing)['hospitals'][0];self.assertEqual(hospital['status'],'preserve_existing');self.assertTrue(hospital['coordinates_preserved'])
    def test_driver_source_only_never_invents_accounts_or_assignments(self):
        row=stage_drivers([driver()],snapshot())[0];self.assertEqual(row['status'],'staged_only');self.assertEqual(set(row['source']),{'source_id','name','phone','hospital','vehicle_type','date_joined'})
        for field in ['email','password','vehicle_uuid','location','user_uuid','native']:self.assertNotIn(field,row)
    def test_duplicate_driver_id_changes_remain_conflicts(self):
        rows=stage_drivers([driver(),driver(name='DEMO changed')]);self.assertTrue(all('missing_or_conflicting_source_id' in row['reasons'] for row in rows))
    def test_exact_duplicate_driver_ids_are_recorded_without_merge_guess(self):
        rows=stage_drivers([driver(),driver()]);self.assertEqual(len(rows),2);self.assertTrue(all(row['exact_duplicate_source_rows']==[1,2] for row in rows))
    def test_shared_phone_across_source_ids_needs_review(self):
        rows=stage_drivers([driver(),driver(source_id='DEMO_2')]);self.assertTrue(all('shared_phone_multiple_source_ids' in row['reasons'] for row in rows))
    def test_existing_native_contact_is_not_silently_adopted(self):
        existing=snapshot();existing['users']=[{'company_uuid':COMPANY,'public_id':'DEMO_existing_user','phone':'+91 9000000001'}]
        row=stage_drivers([driver()],existing)[0];self.assertIn('existing_native_contact_requires_review',row['reasons'])
    def test_phone_formatting_normalizes_without_guessing_missing_digits(self):
        self.assertEqual(phone('+91 (900) 000-0001'),'+919000000001');self.assertIsNone(phone('DEMO unknown'));self.assertIsNone(phone('123'))
    def test_unexpected_csv_or_driver_fields_fail_closed(self):
        with self.assertRaises(ValueError):stage_resources([{**mapping(),'online':True}],snapshot())
        with self.assertRaises(ValueError):stage_drivers([{**driver(),'password':'DEMO forbidden'}])

if __name__=='__main__':unittest.main()
