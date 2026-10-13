package in.asr.ambulance;
import org.json.*;

public final class NativeContractTest {
  static int assertions;static void check(boolean ok,String label){assertions++;if(!ok)throw new AssertionError(label);}
  static JSONObject base()throws Exception{return new JSONObject("{\"contract\":\"mapa-native-mobile-v1\",\"user\":{\"id\":\"user_1\",\"role\":\"crew\",\"company_id\":\"company_1\",\"driver_id\":\"driver_1\",\"vehicle_id\":\"vehicle_1\"},\"capabilities\":{\"duty\":true,\"gps\":true,\"orders\":true,\"acknowledge\":true,\"activities\":true,\"photo_upload\":false},\"driver\":{\"id\":\"driver_1\",\"vehicle_id\":\"vehicle_1\",\"online\":true},\"vehicle\":{\"id\":\"vehicle_1\"},\"orders\":[{\"id\":\"order_one\",\"revision\":\"rev-1\",\"next_activities\":[{\"code\":\"enroute\",\"label\":\"Transport in progress\"}]}],\"places\":[]}");}
  static void invalidOrigin(String value)throws Exception{boolean rejected=false;try{NativeConnection.normalize(value);}catch(Exception e){rejected=true;}check(rejected,"Invalid native origin "+value);}
  static class Memory implements ActionQueue.Store{JSONObject value=new JSONObject();public JSONObject read()throws Exception{return new JSONObject(value.toString());}public void write(JSONObject state)throws Exception{value=new JSONObject(state.toString());}}
  public static void main(String[] args)throws Exception{
    for(String path:new String[]{"/login","/logout","/snapshot","/capabilities","/duty","/gps","/orders/order_abc/ack","/orders/order_a-b_C/activity"})check(NativeProtocol.validPath(path),"Valid native path "+path);
    for(String path:new String[]{null,"","/api/mobile","/v1/drivers/login","//evil.test","/orders/../ack","/orders/order_1/activity?x=1","/orders/order_1/activity#x","/orders/order_1/complete","/orders/order_1/capture-photo","/orders/order_1%2fother/ack","https://evil.test"})check(!NativeProtocol.validPath(path),"Reject path "+path);
    for(String origin:new String[]{"","http://192.168.1.10:8000","https://localhost","https://127.0.0.1:8000","https://[::1]","https://test.localhost","https://0.0.0.0","https://mapa.example/fleet-ops","https://user:password@mapa.example","https://mapa.example?token=secret"})invalidOrigin(origin);
    check("https://192.168.1.10:8443".equals(NativeConnection.normalize("https://192.168.1.10:8443/")),"Explicit reachable TLS server");
    check("https://mapa.example".equals(NativeConnection.normalize(" HTTPS://MAPA.EXAMPLE:443/ ")),"Canonical HTTPS");
    check("https://mapa-asr-health.pages.dev".equals(NativeConnection.normalize(NativeConnection.selected(null))),"New installation selects the currently operational ASR native origin");
    check(NativeConnection.DEFAULT_ORIGIN.equals(NativeConnection.selected("")),"Old blank server setting uses the approved default");
    check("https://mapa-asr-health.pages.dev".equals(NativeConnection.selected("https://mapa-asr-health.pages.dev")),"Existing phone retains its explicit origin until the operator reconnects it");
    check("https://hospital.example".equals(NativeConnection.selected("https://hospital.example")),"An explicitly selected deployment is not silently migrated");
    for(String path:new String[]{"/hospital/incoming","/orders/order_abc/patient-onboard","/hospital/handovers/handover_abc-123/acknowledge"})check(NativeProtocol.validPath(path),"Handover request path accepted");
    for(String path:new String[]{"/hospital/handovers/../acknowledge","/hospital/handovers/id%2Fother/acknowledge","/hospital/incoming?hospital=other","/orders/order_abc/patient-onboard?x=1"})check(!NativeProtocol.validPath(path),"Handover path escape rejected");
    JSONObject gated=base();check(!NativeProtocol.can(gated,"hospital_handover"),"Handover missing flag defaults off");gated.getJSONObject("capabilities").put("hospital_handover","true");check(!NativeProtocol.can(gated,"hospital_handover"),"Handover string flag defaults off");gated.getJSONObject("capabilities").put("hospital_handover",true);check(NativeProtocol.can(gated,"hospital_handover"),"Handover explicit true enables UI");
    JSONObject hs=base();hs.getJSONObject("user").put("role","hospital");check(!NativeHospitalPolicy.allowed(hs),"Hospital gate closed by default");hs.getJSONObject("capabilities").put("hospital_handover",true);check(NativeHospitalPolicy.allowed(hs),"Hospital gate explicit");check(!NativeHospitalPolicy.canReport(hs,hs.getJSONArray("orders").getJSONObject(0)),"Hospital cannot submit crew report");
    java.util.Set<String> seen=new java.util.HashSet<>();JSONObject feed=new JSONObject("{\"incoming\":[{\"id\":\"handover-1\",\"revision\":1,\"note\":\"private clinical note\"}]}");check(NativeHospitalPolicy.unseen(feed,seen),"New hospital report detected");check(!NativeHospitalPolicy.unseen(feed,seen),"Repeated poll does not alert");check(!seen.toString().contains("private"),"Deduplication retains no clinical text");feed.getJSONArray("incoming").getJSONObject(0).put("revision",2);check(NativeHospitalPolicy.unseen(feed,seen),"Changed report detected");NativeHospitalPolicy.unseen(new JSONObject(),seen);check(seen.isEmpty(),"Removed reports are cleared from deduplication");
    JSONObject early=new JSONObject("{\"capabilities\":{\"hospital_assigned_trips\":true},\"assigned_trips\":[{\"order_id\":\"order_demo_1\",\"handover_state\":\"awaiting_crew_report\",\"can_acknowledge_handover\":false,\"crew_observed\":null,\"destination\":{\"name\":\"DEMO hospital\"},\"ambulance\":{\"registration\":\"DEMO ambulance\"},\"caller_reported\":{\"reported_problem\":\"DEMO caller report\"}}]}");
    check(NativeHospitalPolicy.assigned(early).length()==1,"Verified early assignment can appear before onboard report");check(NativeHospitalPolicy.unseen(early,seen),"New assignment alerts linked hospital");check(!NativeHospitalPolicy.unseen(early,seen),"Repeated assignment poll does not alert");check(!seen.toString().contains("caller report"),"Assignment deduplication retains only reference");
    early.getJSONArray("assigned_trips").getJSONObject(0).put("can_acknowledge_handover",true);check(NativeHospitalPolicy.assigned(early).length()==0,"Early assignment with handover action is rejected");early.getJSONArray("assigned_trips").getJSONObject(0).put("can_acknowledge_handover",false).put("crew_observed",new JSONObject().put("condition","urgent"));check(NativeHospitalPolicy.assigned(early).length()==0,"Observed data cannot be mislabeled as an early assignment");early.getJSONArray("assigned_trips").getJSONObject(0).put("crew_observed",JSONObject.NULL);early.getJSONObject("capabilities").put("hospital_assigned_trips",false);check(NativeHospitalPolicy.assigned(early).length()==0,"Unadvertised early assignments remain hidden");
    JSONObject reporting=base(),reportOrder=reporting.getJSONArray("orders").getJSONObject(0);check(!NativeHospitalPolicy.canReport(reporting,reportOrder),"No onboard action without permission");reporting.getJSONObject("capabilities").put("hospital_handover",true);check(!NativeHospitalPolicy.canReport(reporting,reportOrder),"Unaccepted trip cannot report onboard");reportOrder.put("acknowledged",true);check(NativeHospitalPolicy.canReport(reporting,reportOrder),"Accepted active crew trip can report onboard");reportOrder.put("next_activities",new JSONArray());check(!NativeHospitalPolicy.canReport(reporting,reportOrder),"Closed trip cannot report onboard");check(!NativeHospitalPolicy.allowed(null),"Null hospital session denied");
    JSONObject s=base();NativeProtocol.requireSnapshot(s);check(NativeProtocol.canDuty(s),"Crew native binding");check(!NativeProtocol.can(s,"photo_upload"),"False capability stays unavailable");check(!NativeProtocol.can(s,"hospital_response"),"Missing capability stays unavailable");
    for(String role:new String[]{"hospital","admin","superadmin"}){s=base();s.getJSONObject("user").put("role",role);check(!NativeProtocol.canDuty(s),"No crew GPS for "+role);NativeProtocol.requireSnapshot(s);}
    s=base();s.getJSONObject("capabilities").put("gps","true");check(!NativeProtocol.canDuty(s),"String true is not a capability");
    s=base();s.getJSONObject("user").put("driver_id","driver_other");check(!NativeProtocol.canDuty(s),"Driver binding mismatch");s=base();s.put("vehicle",JSONObject.NULL);check(!NativeProtocol.canDuty(s),"Missing native ambulance");
    s=base();s.getJSONObject("user").put("vehicle_id","vehicle_other");check(!NativeProtocol.canDuty(s),"Ambulance binding mismatch");
    s=base();s.getJSONObject("driver").put("vehicle_id","vehicle_other");check(!NativeProtocol.canDuty(s),"Native driver vehicle differs from user binding");
    for(String role:new String[]{"viewer","owner","ADMIN",""}){s=base();s.getJSONObject("user").put("role",role);boolean rejected=false;try{NativeProtocol.requireSnapshot(s);}catch(Exception e){rejected=true;}check(rejected,"Fail closed unknown role "+role);}
    s=base();s.put("contract","mapa-mobile-v1");boolean rejected=false;try{NativeProtocol.requireSnapshot(s);}catch(Exception e){rejected=true;}check(rejected,"Reject separate command-backend contract");
    s=base();JSONObject order=NativeProtocol.order(s,"order_one");check(order!=null,"Find native public order ID");check(NativeProtocol.advertisedActivity(order,"enroute"),"Native advertised activity");check(!NativeProtocol.advertisedActivity(order,"transporting"),"No legacy stage substitution");check(!NativeProtocol.advertisedActivity(order,"completed"),"No invented completion");check(NativeProtocol.order(s,"order_other")==null,"Invisible native order");
    JSONObject other=base();other.getJSONObject("user").put("company_id","company_2");check(!NativeProtocol.scope(s).equals(NativeProtocol.scope(other)),"Company changes scope");other=base();other.getJSONObject("user").put("driver_id","driver_2");check(!NativeProtocol.scope(s).equals(NativeProtocol.scope(other)),"Driver rebind changes scope");
    Memory store=new Memory();ActionQueue queue=new ActionQueue(store);JSONObject body=new JSONObject("{\"_path\":\"/orders/order_one/activity\",\"expected_revision\":\"rev-1\",\"activity_code\":\"enroute\"}");String id=queue.add("https://mapa.example",NativeProtocol.scope(s),"vehicle_1","order_one",body,100);body.put("activity_code","completed");JSONObject saved=queue.visible("https://mapa.example",NativeProtocol.scope(s)).getJSONObject(0).getJSONObject("body");check("enroute".equals(saved.getString("activity_code")),"Queued native activity immutable");check(id.equals(saved.getString("action_id")),"Durable native action receipt key");check("rev-1".equals(saved.getString("expected_revision")),"Native revision immutable");check(queue.visible("https://old-command.example",NativeProtocol.scope(s)).length()==0,"No replay from previous server");check(queue.visible("https://mapa.example",NativeProtocol.scope(other)).length()==0,"No replay after native driver rebind");
    JSONObject result=queue.drain("https://mapa.example",NativeProtocol.scope(s),"vehicle_1",100,payload->{check(id.equals(payload.getString("action_id")),"Retry key preserved");return new JSONObject("{\"ok\":true,\"replayed\":true}");});check(result.has(id)&&queue.visible("https://mapa.example",NativeProtocol.scope(s)).length()==0,"Server-confirmed native receipt drains queue");
    s=base();order=NativeProtocol.order(s,"order_one");
    check(!NativeProtocol.canAcknowledge(s,order),"Missing per-order acknowledgement fails closed");
    order.put("can_acknowledge",false);check(!NativeProtocol.canAcknowledge(s,order),"Undispatched order acknowledgement hidden");
    order.put("can_acknowledge","true");check(!NativeProtocol.canAcknowledge(s,order),"String acknowledgement flag rejected");
    order.put("can_acknowledge",true);check(NativeProtocol.canAcknowledge(s,order),"Explicit server acknowledgement offered");
    order.put("acknowledged",true);check(!NativeProtocol.canAcknowledge(s,order),"No duplicate acknowledgement offered");
    order.put("acknowledged",false);s.getJSONObject("capabilities").put("acknowledge",false);check(!NativeProtocol.canAcknowledge(s,order),"Global acknowledgement capability required");
    s.getJSONObject("capabilities").put("acknowledge",true);s.getJSONObject("user").put("role","admin");check(!NativeProtocol.canAcknowledge(s,order),"Administrator cannot acknowledge as crew");
    s=base();String owner=NativeProtocol.scope(s);Memory heldStore=new Memory();ActionQueue heldQueue=new ActionQueue(heldStore);
    heldQueue.add("https://mapa.example",owner,"vehicle_1","order_current",new JSONObject(),100);
    heldQueue.add("https://mapa.example",owner,"vehicle_old","order_private_old_vehicle",new JSONObject(),100);
    heldQueue.add("https://other.example",owner,"vehicle_1","order_private_old_server",new JSONObject(),100);
    heldQueue.add("https://mapa.example","company_old|user_1|driver_1","vehicle_1","order_private_old_company",new JSONObject(),100);
    heldQueue.add("https://mapa.example","company_1|user_old|driver_1","vehicle_1","order_private_old_user",new JSONObject(),100);
    heldQueue.add("https://mapa.example","company_1|user_1|driver_old","vehicle_1","order_private_old_driver",new JSONObject(),100);
    JSONArray all=heldStore.read().getJSONArray("actions");String untouched=all.toString();
    JSONArray current=NativeProtocol.currentActions(all,"https://mapa.example",owner,"vehicle_1");
    check(current.length()==1&&"order_current".equals(current.getJSONObject(0).getString("assignment")),"Current action list respects all binding boundaries");
    check(NativeProtocol.heldActions(all,"https://mapa.example",owner,"vehicle_1")==5,"Neutral held count includes old server company user driver and vehicle");
    check(!current.toString().contains("private"),"Held order details never enter current action list");
    check(untouched.equals(all.toString()),"Showing held count does not alter or discard old intents");
    final int[] sends={0};heldQueue.drain("https://mapa.example",owner,"vehicle_1",100,payload->{sends[0]++;return new JSONObject("{\"ok\":true}");});
    check(sends[0]==1,"Other bindings never sent while displaying held count");
    all=heldStore.read().getJSONArray("actions");check(NativeProtocol.currentActions(all,"https://mapa.example",owner,"vehicle_1").length()==0&&NativeProtocol.heldActions(all,"https://mapa.example",owner,"vehicle_1")==5,"Current clear does not mean earlier actions disappeared");
    check(heldStore.read().getJSONArray("actions").length()==5,"Held intents remain durable without automatic discard");
    Memory gpsStore=new Memory();NativeGpsState gps=new NativeGpsState(gpsStore);String scope="https://mapa.example|company_1|user_1|driver_1|vehicle_1|session_A";
    JSONObject fix=new JSONObject("{\"latitude\":17.98,\"longitude\":82.5,\"recorded_at\":1000,\"accuracy_m\":12.5,\"heading\":90,\"speed_mps\":2.5}");
    JSONObject first=gps.next(scope,fix);String firstKey=first.getString("action_id"),firstBody=first.toString();
    check(firstKey.matches("[A-Za-z0-9_-]{8,100}"),"GPS uses valid durable action key");
    check(!fix.has("action_id"),"GPS candidate is never mutated");
    first.put("latitude",0);fix.put("latitude",1);
    JSONObject replay=new NativeGpsState(gpsStore).next(scope,new JSONObject("{\"recorded_at\":2000,\"latitude\":22}"));
    check(firstBody.equals(replay.toString()),"Uncertain GPS survives restart with exact payload and key despite newer fix");
    check(firstBody.equals(gps.next(scope,null).toString()),"Uncertain GPS receipt recoverable without inventing a new fix");
    gps.resolved(scope,"wrong-key");check(firstBody.equals(gps.next(scope,null).toString()),"Wrong receipt cannot remove GPS intent");
    gps.resolved(scope,firstKey);check(gps.next(scope,new JSONObject("{\"recorded_at\":1000}"))==null,"Confirmed GPS timestamp not sent again with a new key");
    check(new NativeGpsState(gpsStore).next(scope,new JSONObject("{\"recorded_at\":900}"))==null,"Resolved timestamp survives restart and rejects older fix");
    JSONObject fresh=gps.next(scope,new JSONObject("{\"recorded_at\":2000,\"latitude\":17.99}"));check(!firstKey.equals(fresh.getString("action_id")),"Newer fix has its own receipt key");
    gps.resolved(scope,fresh.getString("action_id"));check(gps.next(scope,new JSONObject("{\"recorded_at\":2000}"))==null,"Definitively rejected GPS can be skipped awaiting new fix");
    JSONObject pending=gps.next(scope,new JSONObject("{\"recorded_at\":3000}"));String pendingKey=pending.getString("action_id");
    String[] changedScopes={"https://other.example|company_1|user_1|driver_1|vehicle_1|session_A","https://mapa.example|company_2|user_1|driver_1|vehicle_1|session_A","https://mapa.example|company_1|user_2|driver_1|vehicle_1|session_A","https://mapa.example|company_1|user_1|driver_2|vehicle_1|session_A","https://mapa.example|company_1|user_1|driver_1|vehicle_2|session_A","https://mapa.example|company_1|user_1|driver_1|vehicle_1|session_B"};
    for(String changed:changedScopes){
      check(gps.next(changed,null)==null,"Changed GPS scope discards old pending telemetry: "+changed);
      JSONObject next=gps.next(changed,new JSONObject("{\"recorded_at\":4000}"));String nextKey=next.getString("action_id");
      check(!pendingKey.equals(nextKey),"New binding never inherits old GPS receipt key");
      gps.resolved(scope,pendingKey);check(nextKey.equals(gps.next(changed,null).getString("action_id")),"Late old-scope receipt cannot erase new GPS intent");
      scope=changed;pendingKey=nextKey;
    }
    gps.clear();check(gps.next(scope,null)==null,"Explicit stop/signout clears saved GPS intent");
    Memory failStore=new Memory(){public void write(JSONObject state)throws Exception{throw new java.io.IOException("Simulated disk failure");}};
    boolean storageFailed=false;try{new NativeGpsState(failStore).next("approved-scope",new JSONObject("{\"recorded_at\":5000}"));}catch(java.io.IOException e){storageFailed=true;}
    check(storageFailed,"GPS cannot be sent before its immutable intent is durably stored");
    System.out.println("Native MAPA contract tests passed: "+assertions+" assertions.");
  }
}
