package in.asr.ambulance;

import org.json.*;
import java.util.*;

/** Presentation only: never changes the native identifiers or activity codes sent to MAPA. */
final class NativeUi {
  private static final Map<String,String> TE=new LinkedHashMap<>();
  static {
    put("Live tracking","లైవ్ ట్రాకింగ్");put("Route and live tracking","మార్గం, లైవ్ ట్రాకింగ్");
    put("Follow me","నా స్థానాన్ని అనుసరించు");put("Fit map","మొత్తం మ్యాప్");put("Road route","రోడ్డు మార్గం");put("Show route","మార్గం చూపించు");
    put("Live phone location","ఫోన్ లైవ్ లొకేషన్");put("Waiting for a fresh precise GPS fix","తాజా ఖచ్చితమైన GPS స్థానం కోసం వేచి ఉంది");
    put("Recent server GPS receipt","ఇటీవల సర్వర్‌కు GPS అందింది");put("Server GPS receipt pending","సర్వర్ GPS నిర్ధారణ కోసం వేచి ఉంది");
    put("Background sharing is off; start duty from Home","నేపథ్యంలో షేరింగ్ ఆఫ్‌లో ఉంది; హోమ్ నుండి డ్యూటీ ప్రారంభించండి");
    put("Tap Road route for driving directions","డ్రైవింగ్ మార్గం కోసం రోడ్డు మార్గం నొక్కండి");put("Tracking this phone while this screen is open","ఈ స్క్రీన్ తెరిచి ఉన్నప్పుడు ఫోన్ స్థానం చూపబడుతుంది");
    put("Estimate without live traffic","లైవ్ ట్రాఫిక్ లేకుండా అంచనా");put("Route calculated","మార్గం లెక్కించిన సమయం");
    put("Destination pin missing. Ask control to confirm coordinates.","గమ్యం పిన్ లేదు. కోఆర్డినేట్లను నిర్ధారించమని కంట్రోల్‌ను అడగండి.");
    put("Calculating road route…","రోడ్డు మార్గం లెక్కిస్తోంది…");put("Route unavailable. Tap Road route to retry.","మార్గం అందుబాటులో లేదు. మళ్లీ ప్రయత్నించడానికి రోడ్డు మార్గం నొక్కండి.");
    put("Blue: road route · Teal: phone trail","నీలం: రోడ్డు మార్గం · ఆకుపచ్చ: ఫోన్ ప్రయాణ రేఖ");
    put("OSRM receives your phone and destination coordinates to calculate a driving route. No account or caller details are sent. Estimates exclude live traffic. This pilot service can be unavailable.","డ్రైవింగ్ మార్గం లెక్కించడానికి OSRMకు మీ ఫోన్, గమ్యం కోఆర్డినేట్లు పంపబడతాయి. ఖాతా లేదా కాలర్ వివరాలు పంపబడవు. అంచనాల్లో లైవ్ ట్రాఫిక్ ఉండదు. ఈ పైలట్ సేవ అందుబాటులో లేకపోవచ్చు.");
  }
  static {
    put("ASR Health is preselected. Sign in with your assigned crew account. Your administrator can provide a different server.","ASR Health ముందుగా ఎంపిక చేయబడింది. మీకు కేటాయించిన సిబ్బంది ఖాతాతో సైన్ ఇన్ చేయండి. వేరే సర్వర్ కోసం నిర్వాహకుడిని సంప్రదించండి.");
    put("Share this phone's location?","ఈ ఫోన్ లొకేషన్‌ను పంచుకోవాలా?");put("Start sharing","పంచుకోవడం ప్రారంభించండి");
    put("During duty, MAPA sends this phone's location, accuracy and timestamps to ASR Health. Control can inspect the received travel record. Sharing continues with a visible notification until you end duty or sign out. Keep the phone with the assigned crew.","డ్యూటీ సమయంలో MAPA ఈ ఫోన్ లొకేషన్, ఖచ్చితత్వం, సమయాలను ASR Healthకు పంపుతుంది. కంట్రోల్ సిబ్బంది అందిన ప్రయాణ రికార్డును చూడగలరు. డ్యూటీ ముగించే వరకు లేదా సైన్ అవుట్ చేసే వరకు నోటిఫికేషన్‌తో లొకేషన్ పంపబడుతుంది. ఫోన్‌ను కేటాయించిన సిబ్బంది వద్ద ఉంచండి.");
    put("Travel record","ప్రయాణ రికార్డు");put("Received device locations","అందిన ఫోన్ లొకేషన్లు");
    put("This is evidence of this phone's reported locations, not proof of who travelled or that a journey was completed.","ఇది ఈ ఫోన్ నివేదించిన లొకేషన్ల రికార్డు మాత్రమే. ఎవరు ప్రయాణించారో లేదా ప్రయాణం పూర్తయిందో నిరూపించదు.");
    put("Locations shown","చూపిన లొకేషన్లు");put("Last 24 hours","గత 24 గంటలు");put("Only the latest 200 received points are shown.","చివరిగా అందిన 200 లొకేషన్లు మాత్రమే చూపబడతాయి.");
    put("No received locations yet. Start duty outdoors and wait for an accurate GPS fix.","ఇంకా లొకేషన్లు అందలేదు. బయట డ్యూటీ ప్రారంభించి ఖచ్చితమైన GPS కోసం వేచి ఉండండి.");
    put("Recent device fix","ఇటీవల అందిన ఫోన్ లొకేషన్");put("Stored location · not live","భద్రపరిచిన లొకేషన్ · లైవ్ కాదు");put("DEMO vehicle · test record","DEMO అంబులెన్స్ · పరీక్ష రికార్డు");
    put("Phone fix","ఫోన్ లొకేషన్ సమయం");put("Server received","సర్వర్‌కు అందిన సమయం");put("Accuracy","ఖచ్చితత్వం");put("Mock location is not shared","నకిలీ లొకేషన్ పంపబడదు");
    put("Gaps over 2 minutes are not joined. Lines do not show a calculated road route.","2 నిమిషాలకు మించిన అంతరాలు కలపబడవు. గీతలు లెక్కించిన రహదారి మార్గం కాదు.");
    put("Recent observations","ఇటీవలి రికార్డులు");put("Phone fix time · server receipt time · accuracy","ఫోన్ లొకేషన్ సమయం · సర్వర్‌కు అందిన సమయం · ఖచ్చితత్వం");put("Test data","పరీక్ష డేటా");
    put("Caller report: consciousness","కాల్ చేసినవారు తెలిపిన స్పృహ స్థితి");put("Caller report: breathing","కాల్ చేసినవారు తెలిపిన శ్వాస స్థితి");put("Caller report: scene safety","కాల్ చేసినవారు తెలిపిన స్థల భద్రత");
    put("Dispatch handover","కేటాయింపు వివరాలు");put("Reported details from control. Confirm the pickup and access route with control if unclear.","కంట్రోల్ అందించిన వివరాలు. తీసుకునే స్థలం లేదా చేరుకునే దారి స్పష్టంగా లేకపోతే కంట్రోల్‌తో నిర్ధారించండి.");
    put("Reported problem","తెలిపిన సమస్య");put("Caller callback","కాల్ చేసిన వారి నంబర్");put("Landmark","గుర్తింపు ప్రదేశం");put("Village","గ్రామం");put("Mandal","మండలం");put("Access directions","చేరుకునే దారి");put("People needing help","సహాయం అవసరమైన వారి సంఖ్య");put("Dispatcher priority","కంట్రోల్ కేటాయించిన ప్రాధాన్యత");put("Ambulance requirement","అవసరమైన అంబులెన్స్");
    put("Your shift","మీ డ్యూటీ");put("Hospital","ఆసుపత్రి");put("Operations","కార్యకలాపాలు");
    put("Crew","సిబ్బంది");put("Hospital staff","ఆసుపత్రి సిబ్బంది");put("Administrator","నిర్వాహకుడు");put("Super administrator","ప్రధాన నిర్వాహకుడు");
    put("Home","హోమ్");put("Updates","నవీకరణలు");put("Account","ఖాతా");put("Help","సహాయం");put("Back","వెనుకకు");
    put("Current call","ప్రస్తుత కాల్");put("Other assigned calls","ఇతర కేటాయించిన కాల్స్");put("Assigned calls","కేటాయించిన కాల్స్");put("Ambulance call","అంబులెన్స్ కాల్");
    put("View call","కాల్ వివరాలు");put("Pickup","రోగిని తీసుకునే స్థలం");put("Destination","చేరవలసిన స్థలం");put("Not provided","వివరాలు అందుబాటులో లేవు");
    put("Accept this call","ఈ కాల్‌ను స్వీకరించండి");put("Call accepted","కాల్ స్వీకరించబడింది");put("Waiting for dispatch","కేటాయింపు కోసం వేచి ఉంది");
    put("Next action","తదుపరి చర్య");put("Confirm","నిర్ధారించండి");put("Cancel","రద్దు చేయండి");put("Close","మూసివేయండి");
    put("Navigate to pickup","రోగిని తీసుకునే స్థలానికి మార్గం");put("Navigate to destination","గమ్యస్థానానికి మార్గం");
    put("On duty","డ్యూటీలో ఉన్నారు");put("Off duty","డ్యూటీలో లేరు");put("Start duty","డ్యూటీ ప్రారంభించండి");put("End duty","డ్యూటీ ముగించండి");
    put("Resume location sharing","లొకేషన్ షేరింగ్ మళ్లీ ప్రారంభించండి");put("GPS sharing is off","లొకేషన్ షేరింగ్ ఆఫ్‌లో ఉంది");put("GPS is being shared","లొకేషన్ పంపబడుతోంది");
    put("Waiting for GPS","లొకేషన్ కోసం వేచి ఉంది");put("Location needs attention","లొకేషన్‌ను తనిఖీ చేయండి");put("Last received","చివరిగా అందిన సమయం");
    put("Connected to MAPA","MAPAకు కనెక్ట్ అయింది");put("Checking for updates…","నవీకరణల కోసం చూస్తోంది…");put("Showing last update","చివరి నవీకరణ చూపబడుతోంది");put("Updated","నవీకరించిన సమయం");
    put("Refresh","నవీకరించండి");put("Ready for your next call","తదుపరి కాల్ కోసం సిద్ధం");put("Your assigned calls will appear here. Keep MAPA open during your shift.","మీకు కేటాయించిన కాల్స్ ఇక్కడ కనిపిస్తాయి. డ్యూటీలో MAPA తెరిచి ఉంచండి.");
    put("No calls to show","చూపించడానికి కాల్స్ లేవు");put("Only calls allowed for your account are shown.","మీ ఖాతాకు అనుమతి ఉన్న కాల్స్ మాత్రమే కనిపిస్తాయి.");
    put("Ambulance not linked","అంబులెన్స్ అనుసంధానించబడలేదు");put("Ask control to link your account to a driver and ambulance.","మీ ఖాతాకు డ్రైవర్, అంబులెన్స్‌ను అనుసంధానించమని కంట్రోల్ సిబ్బందిని అడగండి.");
    put("Your hospital","మీ ఆసుపత్రి");put("Hospital not linked","ఆసుపత్రి అనుసంధానించబడలేదు");put("Ask your administrator to link your hospital.","మీ ఆసుపత్రిని అనుసంధానించమని నిర్వాహకుడిని అడగండి.");
    put("View only","చూడటానికి మాత్రమే");put("Manage this in the MAPA console.","దీన్ని MAPA కన్సోల్‌లో నిర్వహించండి.");put("Hospital responses are not available on this phone yet. Contact control.","ఈ ఫోన్‌లో ఆసుపత్రి స్పందన నమోదు ఇంకా అందుబాటులో లేదు. కంట్రోల్ సిబ్బందిని సంప్రదించండి.");
    put("No next action is available. Contact control for guidance.","తదుపరి చర్య అందుబాటులో లేదు. సూచనల కోసం కంట్రోల్ సిబ్బందిని సంప్రదించండి.");
    put("Confirm this update?","ఈ నవీకరణను నిర్ధారించాలా?");put("The change is confirmed only when MAPA receives it.","MAPAకు అందిన తర్వాత మాత్రమే మార్పు నిర్ధారించబడుతుంది.");
    put("Waiting to send","పంపడానికి వేచి ఉంది");put("Needs review","తనిఖీ అవసరం");put("Updates to send","పంపవలసిన నవీకరణలు");put("All current updates sent","ప్రస్తుత నవీకరణలు పంపబడ్డాయి");
    put("No pending changes for your current assignment.","మీ ప్రస్తుత కేటాయింపుకు పంపవలసిన మార్పులు లేవు.");put("Earlier updates are held","మునుపటి నవీకరణలు నిలిపి ఉంచబడ్డాయి");
    put("Earlier assignment actions are held and will not be sent. Contact control to review them.","మునుపటి కేటాయింపు నవీకరణలు నిలిపి ఉంచబడ్డాయి; అవి పంపబడవు. తనిఖీ కోసం కంట్రోల్ సిబ్బందిని సంప్రదించండి.");
    put("Try sending again","మళ్లీ పంపడానికి ప్రయత్నించండి");put("Remove saved update","భద్రపరిచిన నవీకరణను తొలగించండి");put("Keep","ఉంచండి");put("Remove","తొలగించండి");
    put("Check with control before removing. This does not undo an update already received by MAPA.","తొలగించే ముందు కంట్రోల్ సిబ్బందితో తనిఖీ చేయండి. MAPAకు ఇప్పటికే అందిన మార్పు దీనివల్ల రద్దు కాదు.");
    put("Sign in","సైన్ ఇన్");put("Welcome to MAPA","MAPAకు స్వాగతం");put("Use your staff account to see your assigned work.","మీకు కేటాయించిన పనిని చూడటానికి సిబ్బంది ఖాతాతో సైన్ ఇన్ చేయండి.");
    put("Server address","సర్వర్ చిరునామా");put("Email","ఇమెయిల్");put("Password","పాస్‌వర్డ్");put("Enter your email and password.","మీ ఇమెయిల్, పాస్‌వర్డ్ నమోదు చేయండి.");
    put("Your administrator provides the secure server address. It must be reachable by this phone.","మీ నిర్వాహకుడు సురక్షిత సర్వర్ చిరునామా ఇస్తారు. ఈ ఫోన్ నుండి దానికి కనెక్ట్ కావాలి.");
    put("Language","భాష");put("Connection details","కనెక్షన్ వివరాలు");put("Notification settings","నోటిఫికేషన్ సెట్టింగ్‌లు");put("About MAPA","MAPA గురించి");put("Sign out","సైన్ అవుట్");
    put("Sign out of this phone?","ఈ ఫోన్‌లో సైన్ అవుట్ చేయాలా?");put("GPS and alerts will stop. Your duty and calls stay open until control or an approved action ends them.","లొకేషన్, అలర్ట్‌లు ఆగిపోతాయి. కంట్రోల్ లేదా అనుమతించిన చర్యతో ముగించే వరకు మీ డ్యూటీ, కాల్స్ కొనసాగుతాయి.");
    put("Stay signed in","సైన్ ఇన్‌లో ఉండండి");put("End duty and stop GPS?","డ్యూటీ ముగించి లొకేషన్ పంపడం ఆపాలా?");put("A call in progress may prevent this. Keep sharing until MAPA confirms the change.","కాల్ కొనసాగుతుంటే ఇది అనుమతించబడకపోవచ్చు. MAPA నిర్ధారించే వరకు లొకేషన్ పంపడం కొనసాగించండి.");
    put("Keep sharing","పంపడం కొనసాగించండి");put("Enable phone location","ఫోన్ లొకేషన్ ఆన్ చేయండి");put("Enable location, return to MAPA, then start again.","లొకేషన్ ఆన్ చేసి, MAPAకు తిరిగి వచ్చి, మళ్లీ ప్రారంభించండి.");put("Location settings","లొకేషన్ సెట్టింగ్‌లు");
    put("Allow location to share your ambulance position.","మీ అంబులెన్స్ స్థానం పంపడానికి లొకేషన్ అనుమతించండి.");put("Allow MAPA notifications before starting duty.","డ్యూటీ ప్రారంభించే ముందు MAPA నోటిఫికేషన్‌లను అనుమతించండి.");
    put("Please wait for the current update.","ప్రస్తుత నవీకరణ పూర్తయ్యే వరకు వేచి ఉండండి.");put("Unable to complete this action. Contact control if urgent.","ఈ చర్య పూర్తి కాలేదు. అత్యవసరమైతే కంట్రోల్ సిబ్బందిని సంప్రదించండి.");
    put("Call no longer available. Refresh your assigned calls.","ఈ కాల్ ఇక అందుబాటులో లేదు. కేటాయించిన కాల్స్‌ను నవీకరించండి.");put("No address is available for navigation.","మార్గం చూపించడానికి చిరునామా అందుబాటులో లేదు.");put("Install a maps app to use navigation.","మార్గం కోసం మ్యాప్స్ యాప్‌ను ఇన్‌స్టాల్ చేయండి.");
    put("Phone location","ఫోన్ లొకేషన్");put("Last stored vehicle position","చివరిగా భద్రపరిచిన అంబులెన్స్ స్థానం");put("Location time is unknown; this is not live GPS.","లొకేషన్ సమయం తెలియదు; ఇది లైవ్ GPS కాదు.");
    put("Call reference","కాల్ గుర్తింపు");put("Safety and help","సహాయం, సూచనలు");put("Stop safely before using the phone. If a call is urgent or updates are not sending, contact control by phone.","ఫోన్ వాడే ముందు సురక్షితంగా ఆపండి. కాల్ అత్యవసరమైనా, నవీకరణలు పంపబడకపోయినా కంట్రోల్ సిబ్బందికి ఫోన్ చేయండి.");
    put("GPS and call alerts need the app open or duty sharing running. Force-stopping MAPA stops alerts.","GPS, కాల్ అలర్ట్‌లకు యాప్ తెరిచి ఉండాలి లేదా డ్యూటీ లొకేషన్ షేరింగ్ నడుస్తుండాలి. MAPAను బలవంతంగా ఆపితే అలర్ట్‌లు ఆగుతాయి.");
    put("Photo upload and proof-based completion are not available yet.","ఫోటో అప్‌లోడ్, ఆధారంతో కాల్ ముగింపు ఇంకా అందుబాటులో లేవు.");
    put("Location sharing","లొకేషన్ షేరింగ్");put("Phone location is switched off","ఫోన్ లొకేషన్ ఆఫ్‌లో ఉంది");put("Open MAPA to resume your shift","మీ డ్యూటీ కొనసాగించడానికి MAPA తెరవండి");
    put("Your session ended. Sign in again.","మీ సెషన్ ముగిసింది. మళ్లీ సైన్ ఇన్ చేయండి.");put("Driver enroute","డ్రైవర్ మార్గంలో ఉన్నారు");
    put("Open MAPA to accept your assigned call","మీకు కేటాయించిన కాల్‌ను స్వీకరించడానికి MAPA తెరవండి");
    put("Created","సృష్టించబడింది");put("Scheduled","షెడ్యూల్ చేయబడింది");put("Dispatched","కేటాయించబడింది");put("In progress","కొనసాగుతోంది");put("Completed","పూర్తయింది");put("Canceled","రద్దయింది");
    put("En route","మార్గంలో ఉన్నారు");put("Arrived at pickup","రోగిని తీసుకునే స్థలానికి చేరారు");put("Arrived at destination","గమ్యస్థానానికి చేరారు");
  }
  private static void put(String en,String te){TE.put(en,te);}
  static {
    put("Reconnect to MAPA","MAPAకు మళ్లీ కనెక్ట్ అవ్వండి");put("Your session is saved","మీ సెషన్ భద్రపరచబడింది");
    put("Your assigned calls could not be loaded. Check your connection and retry.","మీకు కేటాయించిన కాల్స్ లోడ్ కాలేదు. కనెక్షన్‌ను తనిఖీ చేసి మళ్లీ ప్రయత్నించండి.");put("Try again","మళ్లీ ప్రయత్నించండి");
    put("Phone setup","ఫోన్ సెటప్");put("Connection","కనెక్షన్");put("Internet","ఇంటర్నెట్");
    put("Internet available","ఇంటర్నెట్ అందుబాటులో ఉంది");put("Check Wi-Fi or mobile data","వై-ఫై లేదా మొబైల్ డేటాను తనిఖీ చేయండి");
    put("Staff account","సిబ్బంది ఖాతా");put("Signed in","సైన్ ఇన్ అయ్యారు");put("Sign in with your assigned account","మీకు కేటాయించిన ఖాతాతో సైన్ ఇన్ చేయండి");
    put("Server sync","సర్వర్ సమకాలీకరణ");put("No recent server confirmation","ఇటీవల సర్వర్ నిర్ధారణ లేదు");put("Check server connection","సర్వర్ కనెక్షన్ తనిఖీ చేయండి");
    put("Permissions","అనుమతులు");put("These checks do not start duty or share your location.","ఈ తనిఖీలు డ్యూటీని ప్రారంభించవు, మీ లొకేషన్‌ను పంపవు.");
    put("Location permission","లొకేషన్ అనుమతి");put("Precise location allowed","ఖచ్చితమైన లొకేషన్ అనుమతించబడింది");
    put("Approximate only: enable precise location","సుమారు స్థానం మాత్రమే: ఖచ్చితమైన లొకేషన్‌ను అనుమతించండి");put("Location permission needed","లొకేషన్ అనుమతి అవసరం");
    put("Enabled","ఆన్‌లో ఉంది");put("Disabled","ఆఫ్‌లో ఉంది");put("Call alerts","కాల్ అలర్ట్‌లు");
    put("Notifications enabled","నోటిఫికేషన్‌లు ఆన్‌లో ఉన్నాయి");put("Enable call notifications","కాల్ నోటిఫికేషన్‌లను ఆన్ చేయండి");
    put("Alert sound","అలర్ట్ శబ్దం");put("Silent: review notification settings","నిశ్శబ్దం: నోటిఫికేషన్ సెట్టింగ్‌లను తనిఖీ చేయండి");
    put("App permissions","యాప్ అనుమతులు");put("Shift readiness","డ్యూటీ సన్నద్ధత");
    put("Start duty from Home when you are ready to share location.","లొకేషన్ పంపడానికి సిద్ధంగా ఉన్నప్పుడు హోమ్ నుండి డ్యూటీ ప్రారంభించండి.");
    put("Ambulance assignment","అంబులెన్స్ కేటాయింపు");put("Driver and ambulance linked","డ్రైవర్, అంబులెన్స్ అనుసంధానించబడ్డారు");
    put("Running","నడుస్తోంది");put("Last GPS receipt","చివరిగా అందిన GPS");put("Battery saver","బ్యాటరీ సేవర్");
    put("Enabled: background delivery may be delayed","ఆన్‌లో ఉంది: నేపథ్యంలో నవీకరణలు ఆలస్యం కావచ్చు");
    put("Refresh checks","తనిఖీలను నవీకరించండి");put("Back to sign in","సైన్ ఇన్‌కు తిరిగి వెళ్ళండి");put("Call caller","కాలర్‌కు ఫోన్ చేయండి");
    put("No valid callback number. Contact control.","చెల్లుబాటు అయ్యే కాలర్ నంబర్ లేదు. కంట్రోల్‌ను సంప్రదించండి.");
    put("No phone dialer is available on this device.","ఈ పరికరంలో ఫోన్ డయలర్ అందుబాటులో లేదు.");
  }
  static String language(String value){return "te".equals(value)?"te":"en";}
  // Only the server-provided callback field may open the dialer. Never interpret
  // URI schemes, USSD, extensions or arbitrary text as a telephone number.
  static String callbackNumber(JSONObject order){
    JSONObject details=order==null?null:order.optJSONObject("dispatch_details");
    if(details==null||!(details.opt("callback_number") instanceof String))return "";
    String raw=details.optString("callback_number").trim();
    if(raw.length()>40||!raw.matches("\\+?[0-9 ()-]+"))return "";
    String number=raw.replaceAll("[ ()-]","");
    return number.matches("\\+?[0-9]{7,15}")?number:"";
  }
  static String t(String language,String english){return "te".equals(language)&&TE.containsKey(english)?TE.get(english):english;}
  static Set<String> translated(){return Collections.unmodifiableSet(TE.keySet());}
  static String role(String language,String role){return t(language,NativeProtocol.roleLabel(role));}
  static String callName(JSONObject order){String name=order.optString("internal_id");return name.isEmpty()?order.optString("id"):name;}
  static String status(String language,JSONObject order){
    if(order.optBoolean("acknowledged"))return t(language,"Call accepted")+" · "+t(language,readable(order.optString("status")));
    if(Boolean.TRUE.equals(order.opt("can_acknowledge")))return t(language,"Next action")+": "+t(language,"Accept this call");
    String value=order.optString("status");if("created".equals(value)||"scheduled".equals(value))return t(language,"Waiting for dispatch");
    return t(language,readable(value));
  }
  static String readable(String value){String cleaned=value.replace('_',' ').replace('-',' ').trim();return cleaned.isEmpty()?"Not provided":Character.toUpperCase(cleaned.charAt(0))+cleaned.substring(1);}
  static JSONArray orderedCalls(JSONArray input){
    ArrayList<JSONObject> list=new ArrayList<>();for(int i=0;i<input.length();i++){JSONObject row=input.optJSONObject(i);if(row!=null)list.add(row);}
    Collections.sort(list,(a,b)->Integer.compare(priority(a),priority(b)));JSONArray result=new JSONArray();for(JSONObject row:list)result.put(row);return result;
  }
  private static int priority(JSONObject order){return order.optBoolean("acknowledged")?0:Boolean.TRUE.equals(order.opt("can_acknowledge"))?1:2;}
  private static String field(JSONObject value,String key){Object data=value.opt(key);return data instanceof String?(String)data:"";}
  static String place(JSONObject value){if(value==null)return "";String name=field(value,"name"),address=field(value,"address");return name+(name.isEmpty()||address.isEmpty()?"":"\n")+address;}
  static String navigationQuery(JSONObject place){if(place==null)return "";if(hasCoordinates(place))return String.format(Locale.ROOT,"%.6f,%.6f",place.optDouble("latitude"),place.optDouble("longitude"));String address=field(place,"address").trim();return address.isEmpty()?field(place,"name").trim():address;}
  static boolean hasCoordinates(JSONObject place){return place!=null&&place.opt("latitude") instanceof Number&&place.opt("longitude") instanceof Number&&Double.isFinite(place.optDouble("latitude"))&&Double.isFinite(place.optDouble("longitude"))&&Math.abs(place.optDouble("latitude"))<=90&&Math.abs(place.optDouble("longitude"))<=180;}
  static JSONArray dispatchDetails(JSONObject order){
    JSONArray rows=new JSONArray();JSONObject details=order.optJSONObject("dispatch_details");if(details==null)return rows;
    String[][] fields={{"reported_problem","Reported problem"},{"consciousness","Caller report: consciousness"},{"breathing","Caller report: breathing"},{"scene_safety","Caller report: scene safety"},{"callback_number","Caller callback"},{"landmark","Landmark"},{"village","Village"},{"mandal","Mandal"},{"access_directions","Access directions"},{"patient_count","People needing help"},{"priority","Dispatcher priority"},{"vehicle_requirement","Ambulance requirement"}};
    for(String[] f:fields){Object value=details.opt(f[0]);if(!(value instanceof String)&&!(value instanceof Number))continue;String shown=value.toString().trim();if(shown.isEmpty())continue;try{rows.put(new JSONObject().put("label",f[1]).put("value",shown));}catch(JSONException ignored){}}
    return rows;
  }
  static String failureMessage(String language,boolean expired,boolean quiet,String lastUpdate,String message){
    if(expired)return t(language,"Your session ended. Sign in again.");
    if(quiet)return t(language,"Showing last update")+" · "+lastUpdate;
    return t(language,message==null?"Unable to complete this action. Contact control if urgent.":message);
  }
  static String gpsState(boolean running,boolean enabled,boolean freshFix,long receipt,long now){
    if(!running)return "GPS sharing is off";
    if(!enabled)return "Phone location is switched off";
    if(!freshFix||receipt<=0)return "Waiting for GPS";
    return now>=receipt&&now-receipt<120000?"GPS is being shared":"Location needs attention";
  }
}
