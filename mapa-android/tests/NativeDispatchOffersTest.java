package in.asr.ambulance;
import org.json.*;
public final class NativeDispatchOffersTest {
  static int checks;static void check(boolean result,String name){checks++;if(!result)throw new AssertionError(name);}
  interface Attempt{void run()throws Exception;}static void rejected(Attempt attempt,String name)throws Exception{try{attempt.run();throw new AssertionError(name);}catch(AssertionError e){throw e;}catch(Exception expected){checks++;}}
  public static void main(String[] args)throws Exception{
    String id="11111111-1111-4111-8111-111111111111";
    JSONObject offer=new JSONObject().put("id",id).put("revision",1).put("expires_at",2000);
    JSONObject crew=new JSONObject().put("user",new JSONObject().put("role","crew")).put("dispatch_offers",new JSONArray().put(offer));
    check(NativeDispatchOffers.active(crew,1000).length()==1,"Crew receives valid offer");
    check(NativeDispatchOffers.active(crew,2000).length()==0,"Expiry boundary rejected");
    JSONObject admin=new JSONObject(crew.toString());admin.getJSONObject("user").put("role","admin");
    check(NativeDispatchOffers.active(admin,1000).length()==0,"Staff does not get crew offers");
    JSONObject bad=new JSONObject(crew.toString());bad.getJSONArray("dispatch_offers").getJSONObject(0).put("id","../orders");
    check(NativeDispatchOffers.active(bad,1000).length()==0,"Malformed ID rejected");
    check(NativeProtocol.validPath("/dispatch-offers/"+id+"/respond"),"Response route allowed");
    check(!NativeProtocol.validPath("/dispatch-offers/"+id+"/respond?response=accept"),"Query injection rejected");
    JSONArray pending=NativeDispatchOffers.active(crew,1000);pending.put(new JSONObject().put("id","order_live"));
    check(NativeDispatchOffers.unexpired(pending,2000).length()==1,"Expired offer silenced without dropping assigned call");
    JSONObject cascade=new JSONObject(offer.toString()).put("state","overdue").put("can_respond",true).put("first_acceptance_wins",true).put("road_seconds",121);
    JSONObject fresh=new JSONObject(crew.toString()).put("server_time",3000).put("_received_at",100000).put("dispatch_offers",new JSONArray().put(cascade));
    JSONObject overdue=NativeDispatchOffers.active(fresh,100000).getJSONObject(0);
    check(NativeDispatchOffers.respondable(overdue,100500),"Server-advertised overdue remains actionable");
    check(NativeDispatchOffers.countdown(overdue,100000).equals("00:00"),"Overdue countdown never negative");
    check(NativeDispatchOffers.unexpired(new JSONArray().put(overdue),100500).length()==1,"Overdue offer alert remains while authoritative snapshot is fresh");
    check(!NativeDispatchOffers.respondable(overdue,220001),"Stale snapshot cannot authorize overdue response");
    check(!NativeDispatchOffers.respondable(overdue,99999),"Clock moving backward requires refreshed snapshot");
    for(String state:new String[]{"accepted","declined","cancelled","expired","unknown"}){JSONObject terminal=new JSONObject(cascade.toString()).put("state",state);check(!NativeDispatchOffers.respondable(terminal,1000),"Terminal or unknown state cannot respond: "+state);}
    check(!NativeDispatchOffers.respondable(new JSONObject(cascade.toString()).put("can_respond",false),1000),"Explicit server denial overrides local countdown");
    check(!NativeDispatchOffers.respondable(new JSONObject(cascade.toString()).put("can_respond","true"),1000),"String true cannot grant response");
    JSONObject future=new JSONObject(cascade.toString()).put("state","offered").put("expires_at",123000);
    fresh.put("dispatch_offers",new JSONArray().put(future));JSONObject anchored=NativeDispatchOffers.active(fresh,100000).getJSONObject(0);
    check(NativeDispatchOffers.countdown(anchored,100000).equals("02:00"),"Server clock offset anchors actual120-second response window");
    check(NativeDispatchOffers.countdown(anchored,101001).equals("01:59"),"Countdown rounds remaining seconds upward");
    check(NativeDispatchOffers.travelEstimate(cascade).contains("3 min"),"Road seconds round to estimated minutes");
    check(NativeDispatchOffers.travelEstimate(new JSONObject()).contains("unavailable"),"Missing travel estimate remains unknown");
    check(NativeDispatchOffers.travelEstimate(new JSONObject().put("road_seconds",0)).contains("unavailable"),"Zero does not become instant arrival");
    check(NativeDispatchOffers.travelEstimate(new JSONObject().put("road_seconds","120")).contains("unavailable"),"Malformed travel estimate not invented");
    JSONObject accept=NativeDispatchOffers.responseBody(anchored,"accept",null,null,id,100000);
    check(accept.getString("action_id").equals(id)&&accept.getInt("expected_revision")==1&&!accept.has("decline_reason"),"Accept binds immutable action and current revision without decline fields");
    for(String reason:new String[]{"unavailable","vehicle_issue","already_busy","location_issue","other"}){JSONObject decline=NativeDispatchOffers.responseBody(anchored,"decline",reason,"  DEMO reason  ",id,100000);check(decline.getString("decline_reason").equals(reason)&&decline.getString("decline_detail").equals("DEMO reason"),"Decline reason contract: "+reason);}
    rejected(()->NativeDispatchOffers.responseBody(anchored,"decline","","",id,100000),"Explicit reason selection required");
    rejected(()->NativeDispatchOffers.responseBody(anchored,"decline","other","  ",id,100000),"Other requires detail");
    rejected(()->NativeDispatchOffers.responseBody(anchored,"decline","vehicle_issue",String.join("",java.util.Collections.nCopies(501,"x")),id,100000),"Long reason rejected");
    rejected(()->NativeDispatchOffers.responseBody(anchored,"accept",null,null,"bad",100000),"Malformed action rejected");
    rejected(()->NativeDispatchOffers.responseBody(anchored,"accept",null,null,id,220001),"Stale action request blocked");
    System.out.println("PASS: "+checks+" automatic dispatch phone checks");
  }
}
