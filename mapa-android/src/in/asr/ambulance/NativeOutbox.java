package in.asr.ambulance;

import android.content.Context;
import org.json.*;

/** Native order intents only. Duty changes are never queued; GPS uses its own session-bound receipt state. */
final class NativeOutbox {
  static ActionQueue queue(Context c){return new ActionQueue(new PrivateStore(c,"mapa-native-order-outbox-v1"));}
  static synchronized JSONArray visible(Context c,JSONObject snapshot)throws Exception{return NativeProtocol.currentActions(all(c),NativeApi.server(c),NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot));}
  static JSONArray all(Context c)throws Exception{JSONArray a=new PrivateStore(c,"mapa-native-order-outbox-v1").read().optJSONArray("actions");return a==null?new JSONArray():a;}
  static synchronized int heldCount(Context c,JSONObject snapshot)throws Exception{return NativeProtocol.heldActions(all(c),NativeApi.server(c),NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot));}
  static synchronized String save(Context c,JSONObject snapshot,JSONObject order,String operation,String activity)throws Exception{
    NativeProtocol.requireSnapshot(snapshot);NativeApi.validate(c,snapshot);
    if(!NativeProtocol.crew(snapshot)||!NativeProtocol.can(snapshot,"orders"))throw new Exception("This account cannot change native ambulance orders.");
    String id=order.getString("id"),path="/orders/"+id+"/"+operation;if(!NativeProtocol.validPath(path)||order.optString("revision").isEmpty())throw new Exception("This order does not provide a safe revision for the action.");
    if("ack".equals(operation)){if(!NativeProtocol.canAcknowledge(snapshot,order))throw new Exception("Acknowledgement is not available for this order.");}
    else if("activity".equals(operation)){if(!NativeProtocol.can(snapshot,"activities")||!NativeProtocol.advertisedActivity(order,activity))throw new Exception("This activity is not advertised by the current server state.");}
    else if("arrivals".equals(operation)){return queue(c).add(NativeApi.server(c),NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot),id,NativeProtocol.arrivalPayload(snapshot,order,activity,System.currentTimeMillis()),System.currentTimeMillis());}
    else throw new Exception("Unsupported native order operation.");
    JSONObject body=NativeApi.obj("_path",path,"expected_revision",order.getString("revision"));
    if(activity!=null){body.put("activity_code",activity);body.put("confirmed",true);if("completed".equals(activity))throw new Exception("Capture a trip-end photo, note and fresh received GPS before completing handover.");}
    return queue(c).add(NativeApi.server(c),NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot),id,body,System.currentTimeMillis());
  }
  static synchronized String saveCompletion(Context c,JSONObject snapshot,JSONObject order,JSONObject proof)throws Exception{
    NativeProtocol.requireSnapshot(snapshot);NativeApi.validate(c,snapshot);if(!NativeProtocol.crew(snapshot)||!NativeProtocol.can(snapshot,"activities")||!NativeProtocol.can(snapshot,"photo_upload")||!NativeProtocol.advertisedActivity(order,"completed"))throw new Exception("Trip completion evidence is not available for this call.");
    NativeApi.BoundSession bound=new NativeApi.BoundSession(c,true);String note=proof.optString("completion_note").trim();long captured=proof.optLong("photo_captured_at");String photo=proof.optString("photo_base64");
    if(!CompletionEvidence.valid(proof,System.currentTimeMillis()))throw new Exception("Add a short completion note and a new camera photograph.");
    if(order.optString("revision").isEmpty())throw new Exception("Refresh the current call before completing it.");
    JSONObject payload=new JSONObject(proof.toString());payload.put("_path","/orders/"+order.getString("id")+"/activity");payload.put("_session_tag",bound.tag());payload.put("expected_revision",order.getString("revision"));payload.put("activity_code","completed");payload.put("confirmed",true);payload.put("handover_confirmed",true);
    return queue(c).add(bound.origin,NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot),order.getString("id"),payload,System.currentTimeMillis());
  }
  static synchronized JSONObject drain(Context c,JSONObject snapshot)throws Exception{
    NativeProtocol.requireSnapshot(snapshot);NativeApi.validate(c,snapshot);if(!NativeProtocol.crew(snapshot))return new JSONObject();
    NativeApi.BoundSession bound=new NativeApi.BoundSession(c,true);
    return queue(c).drain(bound.origin,NativeProtocol.scope(snapshot),NativeProtocol.vehicle(snapshot),System.currentTimeMillis(),payload->{
      String path=payload.optString("_path");if(!NativeProtocol.validPath(path)||!path.startsWith("/orders/"))throw new ActionQueue.Problem(400,"Unsupported saved native action. Contact control.");
      // Replayed receipts must still be recoverable after a successful terminal action removes an order from the active snapshot.
      // Re-send the immutable key, revision and body. Only the server can decide
      // whether this is a replayed receipt or a conflict with a newer revision.
      if("completed".equals(payload.optString("activity_code"))&&!CompletionEvidence.sameSession(payload,bound.tag()))throw new ActionQueue.Problem(409,"Trip-end evidence belongs to an earlier phone session. Contact control; it will not be submitted under a different session.");
      JSONObject body=new JSONObject(payload.toString());body.remove("_path");body.remove("_session_tag");
      try{JSONObject result=NativeApi.post(c,bound,path,body);if(!Boolean.TRUE.equals(result.opt("ok")))throw new NativeApi.Failure("The server did not confirm this action. It remains pending.",502);return result;}
      catch(NativeApi.Failure failure){throw new ActionQueue.Problem(failure.status,failure.getMessage());}
    });
  }
  static synchronized void retry(Context c,JSONObject snapshot)throws Exception{queue(c).retry(NativeApi.server(c),NativeProtocol.scope(snapshot));}
  static synchronized void discard(Context c,JSONObject snapshot,String id)throws Exception{queue(c).discard(NativeApi.server(c),NativeProtocol.scope(snapshot),id);}
}
