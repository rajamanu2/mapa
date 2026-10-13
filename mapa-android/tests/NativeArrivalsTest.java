package in.asr.ambulance;
import org.json.*;
public final class NativeArrivalsTest {
  static int checks;static void check(boolean ok,String text){checks++;if(!ok)throw new AssertionError(text);}
  static class Memory implements ActionQueue.Store {JSONObject state=new JSONObject();public JSONObject read()throws Exception{return new JSONObject(state.toString());}public void write(JSONObject value)throws Exception{state=new JSONObject(value.toString());}}
  public static void main(String[] args)throws Exception{
    JSONObject snapshot=new JSONObject().put("contract",NativeProtocol.CONTRACT).put("user",new JSONObject().put("id","DEMO-user").put("company_id","DEMO-company").put("role","crew")).put("capabilities",new JSONObject().put("arrivals",true)).put("orders",new JSONArray().put(new JSONObject().put("id","order_one"))).put("places",new JSONArray());
    JSONObject order=NativeProtocol.order(snapshot,"order_one");order.put("status","started").put("acknowledged",true).put("revision",new String(new char[64]).replace('\0','a'));
    order.put("next_arrivals",new JSONArray().put(new JSONObject().put("code","pickup_arrived")));
    check(NativeProtocol.validPath("/orders/order_one/arrivals"),"arrival route permitted");
    check(!NativeProtocol.validPath("/orders/order_one/arrivals?other=1"),"arrival query route denied");
    check(NativeProtocol.advertisedArrival(snapshot,order,"pickup_arrived"),"pickup only when native started and advertised");
    check(!NativeProtocol.advertisedArrival(snapshot,order,"hospital_arrived"),"hospital not at pickup stage");
    JSONObject payload=NativeProtocol.arrivalPayload(snapshot,order,"pickup_arrived",10000);
    check(payload.optBoolean("confirmed")&&payload.getLong("reported_at")==10000&&"pickup_arrived".equals(payload.getString("arrival_code")),"confirmation and capture time bound in queued body");
    check(!payload.has("activity_code")&&!payload.has("handover_confirmed"),"arrival never masquerades as native activity/handover");
    Memory store=new Memory();ActionQueue queue=new ActionQueue(store);queue.add("https://DEMO.invalid","DEMO-owner","DEMO-vehicle","order_one",payload,10000);
    final String[] sent={null};queue.drain("https://DEMO.invalid","DEMO-owner","DEMO-vehicle",10000,body->{sent[0]=body.toString();throw new ActionQueue.Problem(503,"DEMO offline");});
    queue.retry("https://DEMO.invalid","DEMO-owner");queue.drain("https://DEMO.invalid","DEMO-owner","DEMO-vehicle",20000,body->{check(body.toString().equals(sent[0]),"arrival retry keeps action UUID revision and original report time");return new JSONObject().put("ok",true);});
    check(queue.visible("https://DEMO.invalid","DEMO-owner").length()==0,"acknowledged queue arrival removed");
    snapshot.getJSONObject("capabilities").put("arrivals",false);check(!NativeProtocol.advertisedArrival(snapshot,order,"pickup_arrived"),"old backend never gains unsupported button");snapshot.getJSONObject("capabilities").put("arrivals",true);
    snapshot.getJSONObject("user").put("role","hospital");check(!NativeProtocol.advertisedArrival(snapshot,order,"pickup_arrived"),"hospital account cannot report crew arrival");snapshot.getJSONObject("user").put("role","crew");
    order.put("acknowledged",false);check(!NativeProtocol.advertisedArrival(snapshot,order,"pickup_arrived"),"unacknowledged order blocks arrival");order.put("acknowledged",true);
    order.put("status","enroute").put("next_arrivals",new JSONArray().put(new JSONObject().put("code","hospital_arrived")));
    check(NativeProtocol.advertisedArrival(snapshot,order,"hospital_arrived"),"hospital advertised at enroute");
    order.put("status","completed");check(!NativeProtocol.advertisedArrival(snapshot,order,"hospital_arrived"),"completed trip blocks new arrival");
    order.put("status","enroute").put("revision","bad");boolean rejected=false;try{NativeProtocol.arrivalPayload(snapshot,order,"hospital_arrived",10000);}catch(Exception e){rejected=true;}check(rejected,"invalid revision cannot be queued");
    System.out.println("NATIVE ARRIVALS PASS "+checks+" checks");
  }
}
