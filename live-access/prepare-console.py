import pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2]
console=root/'private/live-access/console'
for script in ['fix-viewer-navigation.py','fix-osm-attribution.py']:
    subprocess.run([sys.executable,str(root/'fleetbase/client-preview'/script),'--console-root',str(console)],check=True)
bundle=console/'assets/@fleetbase/console.js'
text=bundle.read_text(encoding='utf-8')
changes={
 'Ready for your demo':'ASR Health operations',
 'Records named DEMO are fictional. Their map positions are simulated, not live GPS. Use the MAPA Demo launcher before presenting to refresh the sample fleet.':'Manage verified ambulances, crew, hospitals and calls in your ASR Health workspace. Records marked DEMO remain fictional training records.',
}
for old,new in changes.items():text=text.replace(old,new)
bundle.write_text(text,encoding='utf-8')
print('Configured full ASR Health console; existing training records remain clearly identified.')
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-account-recovery.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-operations-navigation.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-entry-branding.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/sync-legal-assets.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/sync-operator-assets.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/install-loading-assets.py'),'--console-root',str(console)],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-panel-layout.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-caller-phone.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-optional-call-sections.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-ai-branding.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-admin-branding.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-product-labels.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/fix-changelog.py')],check=True)
subprocess.run([sys.executable,str(root/'fleetbase/live-access/install-null-vehicle-console.py'),'--console-root',str(console),'--apply'],check=True)
