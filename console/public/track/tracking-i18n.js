/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
 'use strict';
 // This module changes text only. The private link token remains in tracking.js memory.
 const te={
  'Private trip link':'వ్యక్తిగత ప్రయాణ లింక్',
  'ASR Health tracking':'ASR Health ట్రాకింగ్',
  'Journey progress':'ప్రయాణ పురోగతి',
  'Trip map':'ప్రయాణ మ్యాప్',
  'Assigned ambulance and current trip destination map':'కేటాయించిన అంబులెన్స్ మరియు ప్రస్తుత ప్రయాణ గమ్యం మ్యాప్',
  'Ambulance updates':'అంబులెన్స్ తాజా సమాచారం',
  'Close trip contacts':'ప్రయాణ నంబర్లను మూసివేయి',
  'Privacy and terms':'గోప్యత మరియు నిబంధనలు',
  'CONNECTED RESPONSE · MAPA':'అనుసంధానిత స్పందన · MAPA',
  'Your ambulance journey':'మీ అంబులెన్స్ ప్రయాణం',
  'This tracker link is no longer active':'ఈ ట్రాకర్ లింక్ ఇకపై పనిచేయదు',
  'For an active trip, reopen the original private link from your WhatsApp message. If it still does not work, ask the MAPA operator to send a new link.':'ప్రయాణం ఇంకా కొనసాగుతుంటే, మీ WhatsApp సందేశంలోని అసలు వ్యక్తిగత లింక్‌ను మళ్లీ తెరవండి. అది కూడా పనిచేయకపోతే, కొత్త లింక్ పంపమని MAPA ఆపరేటర్‌ను అడగండి.',
  'Checking your private trip link…':'మీ వ్యక్తిగత ప్రయాణ లింక్‌ను తనిఖీ చేస్తున్నాం…',
  'Arranging ambulance':'అంబులెన్స్ ఏర్పాటు చేస్తున్నారు',
  'Ambulance assigned':'అంబులెన్స్ కేటాయించారు',
  'To hospital':'ఆసుపత్రికి',
  'Trip complete':'ప్రయాణం పూర్తైంది',
  'The map appears when a location is available.':'స్థానం అందుబాటులో ఉన్నప్పుడు మ్యాప్ కనిపిస్తుంది.',
  'Show trip locations':'ప్రయాణ స్థానాలను చూపించు',
  'Ambulance':'అంబులెన్స్',
  'Pickup location':'పికప్ స్థలం',
  'Hospital / drop':'ఆసుపత్రి / దింపే స్థలం',
  'Your current phone position':'మీ ఫోన్ ప్రస్తుత స్థానం',
  'Patient pickup confirmed by you':'మీరు నిర్ధారించిన రోగి పికప్ స్థలం',
  'Planned road route':'ప్రణాళికలో ఉన్న రహదారి మార్గం',
  'Observed GPS trail':'నమోదైన GPS ప్రయాణ పథం',
  'Road map data ©':'రహదారి మ్యాప్ సమాచారం ©',
  'A planned road route appears when it is available for the confirmed trip.':'నిర్ధారించిన ప్రయాణానికి రహదారి మార్గం అందుబాటులో ఉన్నప్పుడు అది కనిపిస్తుంది.',
  'Observed GPS trail joins reported ambulance positions from this open page. It is history, not a road route, and gaps in reception are left disconnected.':'ఈ పేజీ తెరిచి ఉన్నప్పుడు అందిన అంబులెన్స్ GPS స్థానాలను పథంగా చూపిస్తాం. ఇది గత చరిత్ర మాత్రమే, రహదారి మార్గం కాదు. GPS అందని ఖాళీలను కలపము.',
  'YOUR ASSIGNED AMBULANCE':'మీకు కేటాయించిన అంబులెన్స్',
  'Awaiting assignment':'కేటాయింపు కోసం వేచి ఉంది',
  'Checking':'తనిఖీ చేస్తున్నాం',
  'HOSPITAL / DROP':'ఆసుపత్రి / దింపే స్థలం',
  'No ambulance position received yet.':'అంబులెన్స్ స్థానం ఇంకా అందలేదు.',
  'Updates refresh automatically while this page is open, normally every 5 seconds. Slow connections use fewer requests and reconnect automatically. A displayed road route is a plan, not a guaranteed arrival time; contact your operator for an update.':'ఈ పేజీ తెరిచి ఉన్నప్పుడు సమాచారం సాధారణంగా ప్రతి 5 సెకన్లకు మారుతుంది. నెమ్మదైన కనెక్షన్‌లో తక్కువ సార్లు తనిఖీ చేసి మళ్లీ అనుసంధానమవుతుంది. చూపిన మార్గం ప్రణాళిక మాత్రమే; చేరుకునే సమయానికి హామీ కాదు. తాజా సమాచారం కోసం ఆపరేటర్‌ను సంప్రదించండి.',
  'Refresh update':'తాజా సమాచారం చూపించు',
  'STAY CONNECTED':'సంప్రదింపులో ఉండండి',
  'Trip contacts':'ప్రయాణ సంప్రదింపు నంబర్లు',
  'Checking available trip contacts.':'అందుబాటులో ఉన్న నంబర్లను తనిఖీ చేస్తున్నాం.',
  'Call trip contacts':'ప్రయాణ నంబర్లకు కాల్ చేయండి',
  'Call a trip contact':'ప్రయాణ నంబర్‌కు కాల్ చేయండి',
  'Close':'మూసివేయి',
  'Choose a number to open your phone’s dialer. No call is placed automatically.':'మీ ఫోన్ డయలర్‌ను తెరవడానికి ఒక నంబర్ ఎంచుకోండి. కాల్ ఆటోమేటిక్‌గా చేయబడదు.',
  'Driver':'డ్రైవర్',
  'Unavailable':'అందుబాటులో లేదు',
  'Callback number':'తిరిగి కాల్ చేసే నంబర్',
  'Refresh contact numbers':'నంబర్లను తాజాగా చూపించు',
  'HELP THE CREW FIND YOU':'సిబ్బంది మిమ్మల్ని కనుగొనడంలో సహాయపడండి',
  'Share the pickup location':'పికప్ స్థలాన్ని పంచుకోండి',
  'Only share this phone’s location if you are beside the patient. The operator must confirm the pickup with you.':'మీరు రోగి పక్కన ఉన్నప్పుడు మాత్రమే ఈ ఫోన్ స్థానాన్ని పంచుకోండి. ఆపరేటర్ మీతో పికప్ స్థలాన్ని నిర్ధారించాలి.',
  'I am at the pickup and agree to share this phone’s location with ASR Health for this trip.':'నేను పికప్ స్థలంలో ఉన్నాను. ఈ ప్రయాణం కోసం ఈ ఫోన్ స్థానాన్ని ASR Health‌తో పంచుకోవడానికి అంగీకరిస్తున్నాను.',
  'Share pickup location':'పికప్ స్థలాన్ని పంచుకోండి',
  'Stop sharing':'స్థానం పంచుకోవడం ఆపు',
  'Location permission or GPS switched off?':'స్థాన అనుమతి లేదా GPS ఆఫ్‌లో ఉందా?',
  'Turn on Location / Location Services in your phone settings.':'మీ ఫోన్ సెట్టింగ్‌లలో Location / Location Services ఆన్ చేయండి.',
  'Allow this website to use your location in the browser’s site settings. Then return here and choose Share pickup location.':'బ్రౌజర్ సైట్ సెట్టింగ్‌లలో ఈ వెబ్‌సైట్‌కు స్థాన అనుమతి ఇవ్వండి. తర్వాత ఇక్కడికి వచ్చి పికప్ స్థలాన్ని పంచుకోండి ఎంచుకోండి.',
  'If your message app cannot request location, open this private link in your normal browser.':'మీ మెసేజ్ యాప్ స్థాన అనుమతి అడగలేకపోతే, ఈ వ్యక్తిగత లింక్‌ను మీ సాధారణ బ్రౌజర్‌లో తెరవండి.',
  'The website cannot switch on phone GPS or grant permission for you. If sharing still fails, tell the operator your address and a nearby landmark.':'ఈ వెబ్‌సైట్ మీ ఫోన్ GPS‌ను ఆన్ చేయలేదు లేదా మీ తరఫున అనుమతి ఇవ్వలేదు. స్థానం పంచుకోలేకపోతే, మీ చిరునామా మరియు దగ్గరలోని గుర్తును ఆపరేటర్‌కు చెప్పండి.',
  'CONFIRM THE PATIENT’S PICKUP':'రోగి పికప్ స్థలాన్ని నిర్ధారించండి',
  'Is this where the patient is waiting?':'రోగి ఇక్కడే వేచి ఉన్నారా?',
  'Check the orange phone pin on the map. Confirm only when you are beside the patient. GPS accuracy is an estimate; also tell the operator your address and a nearby landmark.':'మ్యాప్‌లోని నారింజ రంగు ఫోన్ గుర్తును చూడండి. మీరు రోగి పక్కన ఉన్నప్పుడు మాత్రమే నిర్ధారించండి. GPS ఖచ్చితత్వం ఒక అంచనా మాత్రమే; మీ చిరునామా, దగ్గరలోని గుర్తును కూడా ఆపరేటర్‌కు చెప్పండి.',
  'Confirm this is the patient pickup':'ఇదే రోగి పికప్ స్థలమని నిర్ధారించు',
  'Confirm updated patient pickup':'కొత్త రోగి పికప్ స్థలాన్ని నిర్ధారించు',
  'Your confirmation saves this exact point for operator review. Further phone movement does not move that confirmed point or automatically replace the crew’s pickup.':'మీ నిర్ధారణ ఈ ఖచ్చితమైన స్థానాన్ని ఆపరేటర్ పరిశీలన కోసం సేవ్ చేస్తుంది. తర్వాత ఫోన్ కదిలినా ఈ స్థానం మారదు; సిబ్బంది పికప్ స్థలం ఆటోమేటిక్‌గా మారదు.',
  'Location is not being shared.':'స్థానం పంచుకోవడం లేదు.',
  'Keep this page open and your phone awake while sharing. On a weak connection, only your newest fresh location is retried automatically while consent remains enabled. Browsers may pause updates when locked or in the background. If GPS is unavailable, tell your operator the address and a nearby landmark. Stopping sharing prevents new updates; it does not delete a location already sent.':'స్థానం పంచుకునేటప్పుడు ఈ పేజీని తెరిచి, ఫోన్‌ను మేల్కొని ఉంచండి. బలహీనమైన కనెక్షన్‌లో మీ అంగీకారం ఉన్నంతవరకు తాజా స్థానాన్ని మాత్రమే మళ్లీ పంపేందుకు ప్రయత్నిస్తాం. ఫోన్ లాక్ అయితే లేదా బ్రౌజర్ నేపథ్యంలో ఉంటే నవీకరణలు ఆగవచ్చు. GPS అందకపోతే చిరునామా, దగ్గరలోని గుర్తును ఆపరేటర్‌కు చెప్పండి. పంచుకోవడం ఆపితే కొత్త స్థానాలు పంపబడవు; ఇప్పటికే పంపిన స్థానం తొలగించబడదు.',
  'This link is private. Do not forward it. Pickup-only links end at pickup. Full-journey links continue to the assigned hospital or drop, ending when the trip completes or the link expires.':'ఈ లింక్ వ్యక్తిగతం. ఇతరులకు పంపవద్దు. పికప్‌కు మాత్రమే ఉన్న లింక్ పికప్ తర్వాత ముగుస్తుంది. పూర్తి ప్రయాణ లింక్ ఆసుపత్రి లేదా దింపే స్థలం వరకు కొనసాగి, ప్రయాణం ముగిసినప్పుడు లేదా లింక్ గడువు తీరినప్పుడు ఆగుతుంది.',
  'Privacy':'గోప్యత',
  'Terms':'నిబంధనలు',
  'No patient pickup has been confirmed through this link yet. Phone sharing alone does not change the crew’s pickup.':'ఈ లింక్ ద్వారా రోగి పికప్ స్థలం ఇంకా నిర్ధారించబడలేదు. ఫోన్ స్థానాన్ని పంచుకోవడం మాత్రమే సిబ్బంది పికప్ స్థలాన్ని మార్చదు.',
  'Share your location and wait for a fresh phone position before confirming.':'నిర్ధారించే ముందు మీ స్థానాన్ని పంచుకుని, ఫోన్ తాజా స్థానాన్ని పొందే వరకు వేచి ఉండండి.',
  'Sending the pickup point you selected. It is not confirmed as delivered yet.':'మీరు ఎంచుకున్న పికప్ స్థలాన్ని పంపుతున్నాం. అది చేరినట్లు ఇంకా నిర్ధారణ కాలేదు.',
  'This confirmation is old; share a fresh position and confirm again.':'ఈ నిర్ధారణ పాతది. తాజా స్థానాన్ని పంచుకుని మళ్లీ నిర్ధారించండి.',
  'The operator must review it before the crew’s pickup changes.':'సిబ్బంది పికప్ స్థలం మారే ముందు ఆపరేటర్ దీన్ని పరిశీలించాలి.',
  'Pickup is complete. Phone location sharing has stopped; ambulance tracking can continue for this trip.':'పికప్ పూర్తైంది. ఫోన్ స్థానం పంచుకోవడం ఆగింది; ఈ ప్రయాణానికి అంబులెన్స్ ట్రాకింగ్ కొనసాగవచ్చు.',
  'This trip link has ended. Contact numbers are no longer available.':'ఈ ప్రయాణ లింక్ ముగిసింది. సంప్రదింపు నంబర్లు ఇక అందుబాటులో లేవు.',
  'Contact numbers are unavailable. Use the operator number you already have.':'సంప్రదింపు నంబర్లు అందుబాటులో లేవు. మీ వద్ద ఉన్న ఆపరేటర్ నంబర్‌ను ఉపయోగించండి.',
  'Current trip numbers are available. Review a contact before calling.':'ప్రస్తుత ప్రయాణ నంబర్లు అందుబాటులో ఉన్నాయి. కాల్ చేయడానికి ముందు నంబర్‌ను తనిఖీ చేయండి.',
  'Numbers from the latest trip update. Your phone may ask you to confirm the call.':'ఇవి తాజా ప్రయాణ సమాచారంలోని నంబర్లు. కాల్‌ను ధృవీకరించమని మీ ఫోన్ అడగవచ్చు.',
  'Trip tracking ended.':'ప్రయాణ ట్రాకింగ్ ముగిసింది.',
  'Tracking ended':'ట్రాకింగ్ ముగిసింది',
  'Location sharing stopped.':'స్థానం పంచుకోవడం ఆగింది.',
  'Ended':'ముగిసింది',
  'Map unavailable. Location updates are shown below.':'మ్యాప్ అందుబాటులో లేదు. స్థాన సమాచారం కింద చూపబడుతుంది.',
  'Confirmed pickup':'నిర్ధారించిన పికప్ స్థలం',
  'Your shared pickup':'మీరు పంచుకున్న పికప్ స్థలం',
  'Operator-confirmed pickup':'ఆపరేటర్ నిర్ధారించిన పికప్ స్థలం',
  'Your shared pickup — operator confirmation required':'మీరు పంచుకున్న పికప్ స్థలం — ఆపరేటర్ నిర్ధారణ అవసరం',
  'Patient pickup confirmed by you — operator review required':'మీరు నిర్ధారించిన రోగి పికప్ స్థలం — ఆపరేటర్ పరిశీలన అవసరం',
  'Your current phone position — not a confirmed patient pickup':'మీ ఫోన్ ప్రస్తుత స్థానం — ఇది నిర్ధారించిన రోగి పికప్ స్థలం కాదు',
  'Road route unavailable. The map shows reported positions and confirmed trip locations; a straight line between pins is not a road route.':'రహదారి మార్గం అందుబాటులో లేదు. మ్యాప్ అందిన స్థానాలు మరియు నిర్ధారించిన ప్రయాణ స్థానాలను చూపుతుంది; గుర్తుల మధ్య సరళరేఖ రహదారి మార్గం కాదు.',
  'This trip link has expired. Ask your operator for a new link.':'ఈ ప్రయాణ లింక్ గడువు ముగిసింది. కొత్త లింక్ కోసం ఆపరేటర్‌ను అడగండి.',
  'Offline':'ఆఫ్‌లైన్',
  'Reconnecting':'మళ్లీ అనుసంధానిస్తున్నాం',
  'Delayed GPS':'GPS ఆలస్యం',
  'GPS received':'GPS అందింది',
  'Ambulance GPS':'అంబులెన్స్ GPS',
  'Crew phone GPS':'సిబ్బంది ఫోన్ GPS',
  'Device GPS':'పరికరం GPS',
  'This trip link has expired or is unavailable. Ask your operator for a new link.':'ఈ ప్రయాణ లింక్ గడువు ముగిసింది లేదా అందుబాటులో లేదు. కొత్త లింక్ కోసం ఆపరేటర్‌ను అడగండి.',
  'This trip link has ended, expired or was replaced. Ask your operator for a new link.':'ఈ ప్రయాణ లింక్ ముగిసింది, గడువు తీరింది లేదా కొత్త లింక్‌తో మార్చబడింది. కొత్త లింక్ కోసం ఆపరేటర్‌ను అడగండి.',
  'This private link could not be verified. Ask your operator to check it or send a new link.':'ఈ వ్యక్తిగత లింక్‌ను ధృవీకరించలేకపోయాం. దీన్ని తనిఖీ చేయమని లేదా కొత్త లింక్ పంపమని ఆపరేటర్‌ను అడగండి.',
  'Location sharing could not be verified. The tracker remains open; refresh its trip update and ask the operator for help if sharing still fails.':'స్థానం పంచుకోవడాన్ని ధృవీకరించలేకపోయాం. ట్రాకర్ తెరిచే ఉంది; ప్రయాణ సమాచారాన్ని తాజాగా తనిఖీ చేసి, ఇంకా సమస్య ఉంటే ఆపరేటర్ సహాయం కోరండి.',
  'Your location was not accepted. Wait for a fresh GPS fix or give the operator your address.':'మీ స్థానం అంగీకరించబడలేదు. తాజా GPS స్థానం కోసం వేచి ఉండండి లేదా మీ చిరునామాను ఆపరేటర్‌కు చెప్పండి.',
  'Tracking is temporarily unavailable.':'ట్రాకింగ్ తాత్కాలికంగా అందుబాటులో లేదు.',
  'Your confirmation could not be delivered while fresh. Check the newest phone position and confirm it again.':'మీ తాజా నిర్ధారణ చేరలేదు. ఫోన్ కొత్త స్థానాన్ని చూసి మళ్లీ నిర్ధారించండి.',
  'Waiting for a fresh location after the connection delay.':'కనెక్షన్ ఆలస్యం తర్వాత తాజా స్థానం కోసం వేచి ఉన్నాం.',
  'Pickup confirmation receipt is unavailable.':'పికప్ నిర్ధారణ రసీదు అందుబాటులో లేదు.',
  'Your selected patient pickup was delivered. Waiting for operator confirmation before the crew’s pickup changes.':'మీరు ఎంచుకున్న రోగి పికప్ స్థలం చేరింది. సిబ్బంది పికప్ స్థలం మారే ముందు ఆపరేటర్ నిర్ధారణ కోసం వేచి ఉన్నాం.',
  'This trip changed. Refreshing its current pickup-sharing permissions.':'ఈ ప్రయాణం మారింది. పికప్ స్థానం పంచుకునే అనుమతులను తాజాగా తనిఖీ చేస్తున్నాం.',
  'Confirmation delivery is uncertain. Retrying only the exact pickup point you selected; the crew’s pickup has not been changed by this page.':'నిర్ధారణ చేరిందో తెలియదు. మీరు ఎంచుకున్న ఖచ్చితమైన పికప్ స్థలాన్నే మళ్లీ పంపేందుకు ప్రయత్నిస్తున్నాం; ఈ పేజీ సిబ్బంది పికప్ స్థలాన్ని మార్చలేదు.',
  'Location not yet delivered. Retrying the newest fresh location when connected; no old location history is stored.':'స్థానం ఇంకా చేరలేదు. కనెక్షన్ వచ్చినప్పుడు తాజా స్థానాన్ని మాత్రమే మళ్లీ పంపేందుకు ప్రయత్నిస్తాం; పాత స్థానాల చరిత్ర నిల్వ చేయము.',
  'You are offline. Reconnect to receive ambulance updates.':'మీరు ఆఫ్‌లైన్‌లో ఉన్నారు. అంబులెన్స్ సమాచారం కోసం మళ్లీ ఇంటర్నెట్‌కు కనెక్ట్ అవండి.',
  'Refreshing trip contact numbers…':'ప్రయాణ సంప్రదింపు నంబర్లను తాజాగా తనిఖీ చేస్తున్నాం…',
  'Trip completed. Ambulance tracking for this link has ended.':'ప్రయాణం పూర్తైంది. ఈ లింక్‌లో అంబులెన్స్ ట్రాకింగ్ ముగిసింది.',
  'Pickup completed. Ambulance tracking for this link has ended.':'పికప్ పూర్తైంది. ఈ లింక్‌లో అంబులెన్స్ ట్రాకింగ్ ముగిసింది.',
  'Travelling to the assigned hospital or drop. Follow the ambulance’s reported location below.':'కేటాయించిన ఆసుపత్రి లేదా దింపే స్థలానికి వెళ్తున్నారు. అంబులెన్స్ అందించిన స్థానాన్ని కింద చూడండి.',
  'Your ambulance is assigned. Follow its reported location below.':'మీకు అంబులెన్స్ కేటాయించారు. అది అందించిన స్థానాన్ని కింద చూడండి.',
  'Your operator is arranging an ambulance.':'మీ ఆపరేటర్ అంబులెన్స్ ఏర్పాటు చేస్తున్నారు.',
  'Pickup completed':'పికప్ పూర్తైంది',
  'Your phone’s pickup-location sharing is finished. This page now shows read-only trip updates to the assigned hospital or drop.':'మీ ఫోన్ పికప్ స్థానం పంచుకోవడం ముగిసింది. ఈ పేజీ ఇప్పుడు కేటాయించిన ఆసుపత్రి లేదా దింపే స్థలం వరకు ప్రయాణ సమాచారాన్ని మాత్రమే చూపుతుంది.',
  'Destination awaiting confirmation':'గమ్యం నిర్ధారణ కోసం వేచి ఉంది',
  'Assigned hospital / drop':'కేటాయించిన ఆసుపత్రి / దింపే స్థలం',
  'Assigned destination. Contact your operator about any change.':'కేటాయించిన గమ్యం. ఏ మార్పు గురించైనా ఆపరేటర్‌ను సంప్రదించండి.',
  'Destination coordinates are unavailable. No destination pin is shown.':'గమ్యం నిర్దేశాంకాలు అందుబాటులో లేవు. మ్యాప్‌లో గమ్యం గుర్తు కనిపించదు.',
  'Assigned ambulance':'కేటాయించిన అంబులెన్స్',
  'No current ambulance location is available.':'అంబులెన్స్ ప్రస్తుత స్థానం అందుబాటులో లేదు.',
  'Waiting for GPS':'GPS కోసం వేచి ఉన్నాం',
  'Trip updates failed. Refresh successfully before calling.':'ప్రయాణ సమాచారం పొందలేకపోయాం. కాల్ చేసే ముందు విజయవంతంగా తాజాగా పొందండి.',
  'Updates interrupted. Retrying automatically; contact your operator if needed.':'సమాచారం తాత్కాలికంగా ఆగింది. ఆటోమేటిక్‌గా మళ్లీ ప్రయత్నిస్తున్నాం; అవసరమైతే ఆపరేటర్‌ను సంప్రదించండి.',
  'Confirm that you are at the pickup and agree to share.':'మీరు పికప్ స్థలంలో ఉన్నారని, స్థానాన్ని పంచుకోవడానికి అంగీకరిస్తున్నారని నిర్ధారించండి.',
  'This browser cannot share location. Open this link in your normal browser or give the operator your address.':'ఈ బ్రౌజర్ స్థానాన్ని పంచుకోలేదు. లింక్‌ను సాధారణ బ్రౌజర్‌లో తెరవండి లేదా మీ చిరునామాను ఆపరేటర్‌కు చెప్పండి.',
  'Allow location access when your phone asks. If GPS is off, turn on Location in phone settings; then try again.':'మీ ఫోన్ అడిగినప్పుడు స్థాన అనుమతి ఇవ్వండి. GPS ఆఫ్‌లో ఉంటే ఫోన్ సెట్టింగ్‌లలో Location ఆన్ చేసి మళ్లీ ప్రయత్నించండి.',
  'Waiting for a fresh, valid GPS fix. Give the operator your address if needed.':'తాజా, సరైన GPS స్థానం కోసం వేచి ఉన్నాం. అవసరమైతే మీ చిరునామాను ఆపరేటర్‌కు చెప్పండి.',
  'Location permission was denied. Turn on phone Location and allow location in this browser’s site settings, then try again. Or tell your operator the address and landmark.':'స్థాన అనుమతి నిరాకరించబడింది. ఫోన్ Location ఆన్ చేసి, ఈ బ్రౌజర్ సైట్ సెట్టింగ్‌లలో అనుమతి ఇచ్చి మళ్లీ ప్రయత్నించండి. లేకపోతే చిరునామా, దగ్గరలోని గుర్తును ఆపరేటర్‌కు చెప్పండి.',
  'GPS timed out. Check that phone Location is on, try again outdoors, or tell your operator the address and landmark.':'GPS సమయం ముగిసింది. ఫోన్ Location ఆన్‌లో ఉందో చూసి బయట మళ్లీ ప్రయత్నించండి, లేదా చిరునామా, దగ్గరలోని గుర్తును ఆపరేటర్‌కు చెప్పండి.',
  'GPS is unavailable. Turn on Location in phone settings and try again, or tell your operator the address and landmark.':'GPS అందుబాటులో లేదు. ఫోన్ సెట్టింగ్‌లలో Location ఆన్ చేసి మళ్లీ ప్రయత్నించండి, లేదా చిరునామా, దగ్గరలోని గుర్తును ఆపరేటర్‌కు చెప్పండి.',
  'Location sharing stopped. No new phone locations will be sent.':'స్థానం పంచుకోవడం ఆగింది. కొత్త ఫోన్ స్థానాలు పంపబడవు.',
  'Consent withdrawn. No new phone locations will be sent.':'అంగీకారం వెనక్కి తీసుకున్నారు. కొత్త ఫోన్ స్థానాలు పంపబడవు.',
  'Contact numbers are outdated. Refresh before calling.':'సంప్రదింపు నంబర్లు పాతవి. కాల్ చేయడానికి ముందు తాజా సమాచారం పొందండి.',
  'You are offline. Reconnect and refresh before calling.':'మీరు ఆఫ్‌లైన్‌లో ఉన్నారు. కాల్ చేయడానికి ముందు మళ్లీ కనెక్ట్ అయి తాజా సమాచారం పొందండి.',
  'Offline. Sharing will resume automatically after reconnecting while your consent remains enabled.':'ఆఫ్‌లైన్. మీ అంగీకారం కొనసాగితే, మళ్లీ కనెక్ట్ అయిన తర్వాత స్థానం పంచుకోవడం ఆటోమేటిక్‌గా ప్రారంభమవుతుంది.',
  'Offline. Location is not being shared.':'ఆఫ్‌లైన్. స్థానం పంచుకోవడం లేదు.',
  'Refresh trip contacts after returning to this page.':'ఈ పేజీకి తిరిగి వచ్చిన తర్వాత సంప్రదింపు నంబర్లను తాజాగా పొందండి.',
  'Phone location sharing remains stopped.':'ఫోన్ స్థానం పంచుకోవడం ఇంకా ఆగి ఉంది.',
  'Sharing is paused. Select Share pickup location to resume.':'స్థానం పంచుకోవడం తాత్కాలికంగా ఆగింది. మళ్లీ ప్రారంభించడానికి పికప్ స్థలాన్ని పంచుకోండి ఎంచుకోండి.',
  'This link is missing or invalid. Reopen the original private link from your message, or ask your operator for a new link.':'ఈ లింక్ లేదు లేదా చెల్లదు. మీకు వచ్చిన అసలు వ్యక్తిగత లింక్‌ను మళ్లీ తెరవండి, లేదా కొత్త లింక్ కోసం ఆపరేటర్‌ను అడగండి.'
 };

 const originals=new WeakMap(),originalAttributes=new WeakMap();
 let language=(navigator.language||'').toLowerCase().startsWith('te')?'te':'en';
 const patterns=[
  [/^Phone position: (.+) · accuracy about (\d+) m\.$/,m=>`ఫోన్ స్థానం: ${m[1]} · అంచనా ఖచ్చితత్వం ${m[2]} మీటర్లు.`],
  [/^You confirmed (.+) at (.+) \(accuracy about (\d+) m\)\. (.+)$/,m=>`మీరు ${m[1]} స్థానాన్ని ${m[2]}కు నిర్ధారించారు (అంచనా ఖచ్చితత్వం ${m[3]} మీటర్లు). ${translate(m[4])}`],
  [/^Planned road route to (.+) · ([\d.]+) km remaining\. This route does not include live traffic or a guaranteed arrival time\.$/,m=>`ప్రణాళికలోని రహదారి మార్గం ${m[1]==='the assigned hospital / drop'?'కేటాయించిన ఆసుపత్రి / దింపే స్థలానికి':'ఆపరేటర్ నిర్ధారించిన పికప్ స్థలానికి'} · ఇంకా ${m[2]} కి.మీ. ఈ మార్గంలో ప్రత్యక్ష ట్రాఫిక్ లేదు; చేరుకునే సమయానికి హామీ కాదు.`],
  [/^Private link expires at (.+)\.$/,m=>`వ్యక్తిగత లింక్ గడువు ${m[1]}కు ముగుస్తుంది.`],
  [/^Location shared at (.+) \(accuracy about (\d+) m\)\. Waiting for operator confirmation\.$/,m=>`${m[1]}కు స్థానం పంచుకున్నాం (అంచనా ఖచ్చితత్వం ${m[2]} మీటర్లు). ఆపరేటర్ నిర్ధారణ కోసం వేచి ఉన్నాం.`],
  [/^(Last known position — updates are delayed\. |Position received\. )(.+) · (\d+) (seconds|minutes) ago · (.+)$/,m=>`${m[1].startsWith('Last')?'చివరిగా తెలిసిన స్థానం — సమాచారం ఆలస్యమవుతోంది. ':'స్థానం అందింది. '}${m[2]} · ${m[3]} ${m[4]==='seconds'?'సెకన్లు':'నిమిషాలు'} క్రితం · ${translate(m[5])}`]
 ];
 function translate(value){
  const source=String(value||'');
  if(Object.prototype.hasOwnProperty.call(te,source))return te[source];
  for(const [pattern,render] of patterns){const match=source.match(pattern);if(match)return render(match);}
  return source;
 }
 function renderText(value){
  const trimmed=value.trim(),translated=translate(trimmed);
  return trimmed&&translated!==trimmed?value.replace(trimmed,translated):value;
 }
 function applyText(node){
  const raw=node.data,known=originals.get(node);
  if(known!==undefined&&raw===renderText(known)){if(language==='en')node.data=known;return;}
  originals.set(node,raw);
  if(language==='te')node.data=renderText(raw);
 }
 function walk(root){
  if(!root)return;
  if(root.nodeType===3){applyText(root);return;}
  if(root.nodeType!==1||['SCRIPT','STYLE','NOSCRIPT','TEXTAREA'].includes(root.tagName))return;
  let attributes=originalAttributes.get(root);
  if(!attributes){attributes={};originalAttributes.set(root,attributes);}
  for(const name of ['aria-label','title']){
   const raw=root.getAttribute(name);if(raw===null)continue;
   const known=attributes[name];
   if(known!==undefined&&raw===translate(known)){if(language==='en')root.setAttribute(name,known);continue;}
   attributes[name]=raw;
   if(language==='te'&&translate(raw)!==raw)root.setAttribute(name,translate(raw));
  }
  for(const child of root.childNodes)walk(child);
 }
 function setLanguage(next){
  language=next==='te'?'te':'en';document.documentElement.lang=language;
  document.getElementById('language-en')?.setAttribute('aria-pressed',String(language==='en'));
  document.getElementById('language-te')?.setAttribute('aria-pressed',String(language==='te'));
  document.title=language==='te'?'ASR Health · మీ అంబులెన్స్':'ASR Health · Your ambulance';
  walk(document.body);
 }
 function chooseLanguage(event,next){
  event?.preventDefault();
  const scrollY=typeof window!=='undefined'?window.scrollY:null;
  setLanguage(next);
  if(Number.isFinite(scrollY))window.scrollTo(0,scrollY);
 }
 document.getElementById('language-en')?.addEventListener('click',event=>chooseLanguage(event,'en'));
 document.getElementById('language-te')?.addEventListener('click',event=>chooseLanguage(event,'te'));
 const observer=new MutationObserver(records=>{
  for(const record of records){
   if(record.type==='characterData')applyText(record.target);
   else for(const node of record.addedNodes)walk(node);
  }
 });
 observer.observe(document.body,{subtree:true,childList:true,characterData:true});
 setLanguage(language);
})();
