"""Stage only: verify archived source, emit original/patched files and SHA-256 manifest.
Never connects to a server, executes PHP, changes the input archive or applies to a live checkout.
"""
import argparse,hashlib,json,pathlib,re,zipfile
import manual_form_patch
p=argparse.ArgumentParser();p.add_argument('--archive',required=True);p.add_argument('--output',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parent;out=pathlib.Path(a.output).resolve()
if out.exists():raise SystemExit('Output must be a fresh staging directory; no overwrite permitted.')
z=zipfile.ZipFile(a.archive);entries={};patches={};manual_forms={}
targets=['ambulance_index.php','drivers.php','ambulance_running.php','ambulance_profile.php','off_road_ambulances.php','cad_save_dispatch.php','cad_complete_trip.php','cad_update_status.php','cad_save_incident.php','cad_dispatch.php','cad_incident_list.php','ambulance_analytics.php','setup_running_db.php','cad_db_init.php','self_heal_ambulance.php']
bootstraps={'setup_running_db.php','cad_db_init.php','self_heal_ambulance.php'}
for name in targets:
    raw=z.read(name);s=raw.decode('utf-8')
    if name in bootstraps:
        if name=='self_heal_ambulance.php':
            anchor='if (isset($pdo) && $pdo instanceof PDO) {'
            if s.count(anchor)!=1:raise SystemExit('Unexpected self-heal entry')
            at=s.index(anchor)
        else:
            matches=list(re.finditer(r"require_once[^;]+config\.php['\"];",s[:400]))
            if len(matches)!=1:raise SystemExit('Unexpected bootstrap config anchor '+name)
            at=matches[0].end()
        s=s[:at]+"\n$mapaBootstrapFile = __FILE__;\nrequire __DIR__ . '/_mapa_bridge/original-bootstrap-hook.php';\nif (!$mapaRunOriginalBootstrap) { return; }\n"+s[at:]
    else:
        matches=list(re.finditer(r"requirePermission\([^;]+\);",s[:1200]))
        if name=='ambulance_analytics.php':
            anchor='requireLogin();'
            if s.count(anchor)!=1:raise SystemExit('Missing analytics login anchor')
            at=s.index(anchor)+len(anchor)
        elif not matches:raise SystemExit('Missing reviewed permission anchor '+name)
        else:at=matches[0].end()
        s=s[:at]+"\nrequire_once __DIR__ . '/_mapa_bridge/original-writer-hook.php';\n"+s[at:]
    if name in ['ambulance_running.php','ambulance_analytics.php']:
        needle="WHERE status = 'On Road' AND is_closed = 0"
        if s.count(needle)!=1:raise SystemExit('Unexpected auto-close source')
        s=s.replace(needle,needle+"\n    AND NOT EXISTS (SELECT 1 FROM mapa_dispatch_reservations mr WHERE mr.ambulance_id=amb_daily_status.ambulance_id AND mr.terminal_at IS NULL)\n    AND NOT EXISTS (SELECT 1 FROM amb_trips mt WHERE mt.daily_status_id=amb_daily_status.id AND (mt.drop_date IS NULL OR mt.drop_time IS NULL))")
    if name=='ambulance_profile.php':
        # Profile selects the vehicle from GET; validate this exact selected source ID before POST UPDATE.
        needle="$vehicle_model = $_POST['vehicle_model'] ?? '';"
        if s.count(needle)!=1:raise SystemExit('Unexpected profile source')
        s=s.replace(needle,"\\MapaSource\\SourceDispatchCoordinator::assertNoMapaReservation($pdo, (string)$selected_amb_id);\n            "+needle)
    if name in ['drivers.php','off_road_ambulances.php']:
        # These pages contain extra inline self-heal ALTER/seed calls outside the shared bootstrap.
        # Preserve their original code for audit, but stop before it can execute in web operation.
        s=s.replace('$pdo->exec(', '\\MapaSource\\SourceCoordinationPolicy::denyWebReconciliation(); $pdo->exec(')
    s,count=manual_form_patch.wrap(name,s)
    if count:manual_forms[name]=count
    patches[name]=(raw,s.encode('utf-8'))
out.mkdir(parents=True)
for name,(before,after) in patches.items():
    for folder,data in [('original',before),('patched',after)]:
        path=out/folder/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
    entries[name]={'before_sha256':hashlib.sha256(before).hexdigest(),'after_sha256':hashlib.sha256(after).hexdigest()}
helpers=['SourceDispatchCoordinator.php','SourceOriginalWriterGuard.php','SourceCoordinationPolicy.php','SourceManualTransactions.php','SourceRepairPhotos.php','original-writer-hook.php','original-bootstrap-hook.php','SourceCommands.php','SourceSync.php']
for name in helpers:
    data=(root/name).read_bytes();path=out/'patched'/'_mapa_bridge'/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
    entries['_mapa_bridge/'+name]={'after_sha256':hashlib.sha256(data).hexdigest()}
mutations={}
for name in z.namelist():
    if not name.endswith('.php'):continue
    text=z.read(name).decode('utf-8',errors='replace');hits=[{'line':i+1,'operation':next(t for t in ['UPDATE ','INSERT INTO ','DELETE FROM ','ALTER TABLE ','CREATE TABLE '] if t in line)} for i,line in enumerate(text.splitlines()) if any(t in line for t in ['UPDATE ','INSERT INTO ','DELETE FROM ','ALTER TABLE ','CREATE TABLE '])]
    if hits:mutations[name]={'hooked':name in targets,'mode':'maintenance-only' if name in bootstraps else 'serialized-request','sql_occurrences':hits}
report={'status':'LOCAL_REVIEW_ONLY_NOT_COMMISSIONED','archive_sha256':hashlib.sha256(pathlib.Path(a.archive).read_bytes()).hexdigest(),'files':entries,'mutation_coverage':mutations,'unresolved':['Audit actual deployed source and all writers outside the supplied archive; attest reviewed schema before enabling.','MAPA dispatch reservation must precede local dispatch commit; durable retries/compensation must be wired.','Original manual multi-patient save/edit/delete lack atomic idempotency; hooks serialize but do not make those writes transactional.','Run full original HTTP/form regression and concurrent dual-dispatch tests against an isolated clone before enabling.']}
report['manual_forms_with_csrf_and_retry_identity']=manual_forms
report['unresolved'][2]='Immutable repair photos survive unknown commits; abandoned unreferenced files require reviewed reconciliation, never blind deletion. Original per-passenger edit/delete semantics have no durable journey grouping; do not infer groups for historical rows.'
(out/'manifest.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
print(json.dumps({'staged':str(out),'patched_original_files':len(targets),'commissioned':False}))
