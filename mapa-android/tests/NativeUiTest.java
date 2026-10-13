package in.asr.ambulance;
import org.json.*;

/** Crew presentation must preserve the native data and fail visibly on stale state. */
public final class NativeUiTest {
  static int assertions;
  static void check(boolean ok,String label){assertions++;if(!ok)throw new AssertionError(label);}
  public static void main(String[] args)throws Exception{
    check("en".equals(NativeUi.language(null)),"English is the default");
    check("en".equals(NativeUi.language("fr")),"Unsupported locale falls back to English");
    check("te".equals(NativeUi.language("te")),"Telugu preference retained");
    check("Accept this call".equals(NativeUi.t("en","Accept this call")),"English crew action");
    check(!"Accept this call".equals(NativeUi.t("te","Accept this call")),"Telugu crew action available");
    check(!"End duty".equals(NativeUi.t("te","End duty")),"Telugu duty control available");
    String nativeLabel="Custom clinical handover label";
    check(nativeLabel.equals(NativeUi.t("te",nativeLabel)),"Unknown server action label remains exact");
    JSONObject created=new JSONObject("{\"id\":\"order_created\",\"status\":\"created\",\"can_acknowledge\":false}");
    JSONObject actionable=new JSONObject("{\"id\":\"order_dispatch\",\"can_acknowledge\":true}");
    JSONObject active=new JSONObject("{\"id\":\"order_active\",\"internal_id\":\"ASR TEST 42\",\"acknowledged\":true,\"status\":\"driver_enroute\"}");
    JSONArray input=new JSONArray().put(created).put(actionable).put(active);String original=input.toString();
    JSONArray ordered=NativeUi.orderedCalls(input);
    check("order_active".equals(ordered.getJSONObject(0).getString("id")),"Current accepted call first");
    check("order_dispatch".equals(ordered.getJSONObject(1).getString("id")),"Actionable new call before waiting dispatch");
    check(original.equals(input.toString()),"Crew display does not mutate native order state or identifiers");
    check("ASR TEST 42".equals(NativeUi.callName(active)),"Native call reference preserved exactly");
    check(!NativeUi.status("te",active).contains("Driver enroute"),"Recognized acknowledged status translated too");
    check("Waiting for dispatch".equals(NativeUi.status("en",created)),"Undispatched call explained without an acceptance claim");
    check(!"Waiting for dispatch".equals(NativeUi.status("te",created)),"Waiting state translated");
    JSONObject p=new JSONObject("{\"name\":\"CHC Paderu\",\"address\":\"Paderu, ASR District\"}");
    check("CHC Paderu\nPaderu, ASR District".equals(NativeUi.place(p)),"Place names and addresses remain native content");
    check("Paderu, ASR District".equals(NativeUi.navigationQuery(p)),"Navigation uses recorded address");
    p.put("address","");check("CHC Paderu".equals(NativeUi.navigationQuery(p)),"Empty address falls back to recorded place name");
    p.put("address",JSONObject.NULL);check("CHC Paderu".equals(NativeUi.navigationQuery(p)),"Null address never becomes a literal navigation target");
    p.put("name",JSONObject.NULL);check("".equals(NativeUi.place(p)),"Null place fields do not invent a visible address");
    check("".equals(NativeUi.navigationQuery(null)),"No invented navigation target");
    check("GPS sharing is off".equals(NativeUi.gpsState(false,true,true,1000,2000)),"Stopped service cannot claim GPS sharing");
    check("Phone location is switched off".equals(NativeUi.gpsState(true,false,true,1000,2000)),"Recent receipt cannot hide disabled phone GPS");
    check("Waiting for GPS".equals(NativeUi.gpsState(true,true,false,1000,2000)),"Recent receipt cannot hide stale phone fix");
    check("Waiting for GPS".equals(NativeUi.gpsState(true,true,true,0,2000)),"Phone fix alone is not server receipt");
    check("GPS is being shared".equals(NativeUi.gpsState(true,true,true,1000,2000)),"Fresh actual fix and recent receipt support sharing status");
    check("Location needs attention".equals(NativeUi.gpsState(true,true,true,1000,121000)),"Old receipt does not imply current transmission");
    check("Location needs attention".equals(NativeUi.gpsState(true,true,true,3000,2000)),"Clock reversal does not manufacture freshness");
    check(!NativeUi.translated().isEmpty(),"Translation catalogue available");
    check("Your session ended. Sign in again.".equals(NativeUi.failureMessage("en",true,true,"12:34","Token expired")),"Quiet expiry preserves sign-in guidance");
    check(!NativeUi.failureMessage("te",true,true,"12:34","Token expired").contains("12:34"),"Expired Telugu session never shows stale timestamp as sign-in guidance");
    check("Showing last update · 12:34".equals(NativeUi.failureMessage("en",false,true,"12:34","Network down")),"Background network failure labels cached data");
    JSONObject pin=new JSONObject("{\"name\":\"Caller landmark\",\"address\":\"Ambiguous place\",\"latitude\":18.1234567,\"longitude\":82.8765432}");
    check("18.123457,82.876543".equals(NativeUi.navigationQuery(pin)),"Verified coordinates take precedence over geocoding ambiguous address");
    pin.put("latitude",0).put("longitude",0);check("0.000000,0.000000".equals(NativeUi.navigationQuery(pin)),"Zero coordinates are valid rather than treated as missing");
    pin.put("latitude",91);check("Ambiguous place".equals(NativeUi.navigationQuery(pin)),"Out-of-range latitude cannot launch coordinate navigation");
    pin.put("latitude","18.1");check(!NativeUi.hasCoordinates(pin),"String latitude does not silently become a trusted pin");
    JSONObject snap=NativeContractTest.base();
    JSONObject received=new JSONObject("{\"latitude\":18.1,\"longitude\":82.7,\"recorded_at\":100000,\"received_at\":101000,\"accuracy_m\":12,\"source\":\"device_gps\",\"mock_location\":false,\"simulated\":false,\"driver_id\":\"driver_1\",\"vehicle_id\":\"vehicle_1\"}");
    JSONArray evidenceRows=new JSONArray().put(received);snap.put("travel_evidence",new JSONObject().put("points",evidenceRows).put("proof_of_travel",false));
    check(NativeTravel.points(snap).length()==1,"Current crew receives scoped device history");
    check(NativeTravel.fresh(received,102000),"Both device time and server receipt are recent");
    check(!NativeTravel.fresh(received,220000),"Fresh receipt does not make an old fix live");
    check(!NativeTravel.fresh(received,99000),"Future fix cannot be labeled live");
    String originalEvidence=snap.toString();NativeTravel.points(snap);check(originalEvidence.equals(snap.toString()),"History presentation never rewrites source observations");
    for(String key:new String[]{"driver_id","vehicle_id"}){JSONObject p2=new JSONObject(received.toString()).put(key,"other");snap.getJSONObject("travel_evidence").put("points",new JSONArray().put(p2));check(NativeTravel.points(snap).length()==0,"Other binding filtered from travel record: "+key);}
    for(String key:new String[]{"latitude","longitude","recorded_at","received_at","accuracy_m"}){JSONObject p2=new JSONObject(received.toString()).put(key,"10");snap.getJSONObject("travel_evidence").put("points",new JSONArray().put(p2));check(NativeTravel.points(snap).length()==0,"Non-numeric evidence rejected: "+key);}
    for(JSONObject invalid:new JSONObject[]{new JSONObject(received.toString()).put("mock_location",true),new JSONObject(received.toString()).put("source","manual"),new JSONObject(received.toString()).put("accuracy_m",101),new JSONObject(received.toString()).put("recorded_at",0),new JSONObject(received.toString()).put("simulated",JSONObject.NULL)}){snap.getJSONObject("travel_evidence").put("points",new JSONArray().put(invalid));check(NativeTravel.points(snap).length()==0,"Unknown/manual/mock/inaccurate evidence not presented as a device observation");}
    JSONObject later=new JSONObject(received.toString()).put("recorded_at",102000).put("received_at",103000).put("simulated",true);
    snap.getJSONObject("travel_evidence").put("points",new JSONArray().put(later).put(received));JSONArray chronological=NativeTravel.points(snap);check(chronological.length()==2&&chronological.getJSONObject(0).getLong("recorded_at")==100000,"History is oldest to newest regardless of response order");check(chronological.getJSONObject(1).getBoolean("simulated"),"Test marker is retained rather than relabeled as genuine travel");
    snap.getJSONObject("user").put("role","admin");check(NativeTravel.points(snap).length()==0,"Phone administrator never impersonates crew history");
    for(String label:new String[]{"Travel record","Phone fix","Server received","Mock location is not shared","Start sharing"})check(!label.equals(NativeUi.t("te",label)),"New crew label has Telugu: "+label);
    JSONObject dispatch=new JSONObject("{\"dispatch_details\":{\"reported_problem\":\"DEMO reported incident\",\"callback_number\":\"test-contact\",\"patient_count\":2,\"raw_transcript\":\"must not be displayed\",\"patient\":{\"private\":true},\"landmark\":null}}");
    JSONArray handover=NativeUi.dispatchDetails(dispatch);check(handover.length()==3,"Only approved handover fields are displayed");check(!handover.toString().contains("must not")&&!handover.toString().contains("private"),"Raw transcript and arbitrary patient data are never surfaced");check("2".equals(handover.getJSONObject(2).getString("value")),"Reported count is preserved");check(NativeUi.dispatchDetails(new JSONObject()).length()==0,"Existing calls without intake details still open");
    JSONObject reported=new JSONObject("{\"dispatch_details\":{\"consciousness\":\"unknown\",\"breathing\":\"not_normal\",\"scene_safety\":\"traffic hazard reported\"}}");
    JSONArray reports=NativeUi.dispatchDetails(reported);check(reports.length()==3,"All three caller-reported dispatch safety fields reach crew");
    for(int i=0;i<reports.length();i++)check(reports.getJSONObject(i).getString("label").startsWith("Caller report:"),"Condition is labeled as a caller report, not a diagnosis");
    check("unknown".equals(reports.getJSONObject(0).getString("value")),"Unknown consciousness never becomes a normal finding");
    for(String label:new String[]{"Caller report: consciousness","Caller report: breathing","Caller report: scene safety"})check(!label.equals(NativeUi.t("te",label)),"Caller report label has Telugu translation");
    JSONObject callback=new JSONObject().put("dispatch_details",new JSONObject());
    JSONObject contact=callback.getJSONObject("dispatch_details");
    contact.put("callback_number","+91 (98765) 43210");
    check("+919876543210".equals(NativeUi.callbackNumber(callback)),"Formatted synced callback opens exact number without country inference");
    contact.put("callback_number","09876543210");
    check("09876543210".equals(NativeUi.callbackNumber(callback)),"Local leading zero is preserved");
    for(Object invalid:new Object[]{JSONObject.NULL,1234567890,"","test-contact","tel:9876543210","+91;1234567890","*21*9876543210#","9876543210,123","12+34567890","123456","1234567890123456","9876543210\n123"}){
      contact.put("callback_number",invalid);check(NativeUi.callbackNumber(callback).isEmpty(),"Unsafe or malformed callback is not dialable: "+invalid);
    }
    check(NativeUi.callbackNumber(null).isEmpty(),"No order cannot invent caller number");
    check(NativeUi.callbackNumber(new JSONObject()).isEmpty(),"Older orders without handover still render");
    for(String label:new String[]{"Phone setup","Call caller","App permissions","Check server connection","Refresh checks"})check(!label.equals(NativeUi.t("te",label)),"New setup label translated: "+label);
    System.out.println("Native crew UI tests passed: "+assertions+" assertions.");
  }
}
