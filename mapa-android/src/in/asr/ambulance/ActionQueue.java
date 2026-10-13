package in.asr.ambulance;

import org.json.*;
import java.util.UUID;

/** Durable intent, not an optimistic server-state model. All methods serialize through the owner. */
final class ActionQueue {
  interface Store { JSONObject read()throws Exception; void write(JSONObject value)throws Exception; }
  interface Transport { JSONObject send(JSONObject body)throws Exception; }
  static class Problem extends Exception { final int status; Problem(int status,String message){super(message);this.status=status;} }
  private final Store store;
  ActionQueue(Store store){this.store=store;}
  private JSONArray rows(JSONObject state)throws JSONException{JSONArray rows=state.optJSONArray("actions");if(rows==null){rows=new JSONArray();state.put("actions",rows);}return rows;}
  static boolean retryable(int status){return status==408||status==429||status>=500;}
  String add(String origin,String owner,String vehicle,String assignment,JSONObject body,long now)throws Exception{
    JSONObject state=store.read();JSONArray actions=rows(state);
    for(int i=0;i<actions.length();i++){JSONObject a=actions.getJSONObject(i);if(origin.equals(a.getString("origin"))&&owner.equals(a.getString("owner"))&&assignment.equals(a.getString("assignment")))throw new Problem(409,"A pending action for this trip must be synced or reviewed first.");}
    if(actions.length()>=100)throw new Problem(409,"Pending action storage is full. Review pending sync before continuing.");
    String id=UUID.randomUUID().toString();JSONObject payload=new JSONObject(body.toString());payload.put("action_id",id);
    JSONObject a=new JSONObject();a.put("id",id);a.put("origin",origin);a.put("owner",owner);a.put("vehicle",vehicle);a.put("assignment",assignment);a.put("body",payload);a.put("created_at",now);a.put("state","pending");a.put("attempts",0);a.put("next_at",0);a.put("message","Waiting to sync");actions.put(a);store.write(state);return id;
  }
  JSONObject drain(String origin,String owner,String vehicle,long now,Transport transport)throws Exception{
    JSONObject state=store.read(),committed=new JSONObject();JSONArray actions=rows(state);
    for(int i=0;i<actions.length();){JSONObject a=actions.getJSONObject(i);
      if(!origin.equals(a.getString("origin"))||!owner.equals(a.getString("owner"))){i++;continue;}
      if(!vehicle.equals(a.getString("vehicle"))){a.put("state","blocked");a.put("message","Your ambulance assignment changed. Contact control and review this action.");store.write(state);i++;continue;}
      if(!"pending".equals(a.getString("state"))){i++;continue;}
      if(a.optLong("next_at")>now)break;
      JSONObject result;try{result=transport.send(new JSONObject(a.getJSONObject("body").toString()));}
      catch(Problem e){if(e.status==401){a.put("message","Sign in again to sync pending actions");store.write(state);break;}if(!retryable(e.status)){a.put("state","blocked");a.put("rejection_status",e.status);a.put("message",e.getMessage());store.write(state);i++;continue;}defer(a,now,e.getMessage());store.write(state);break;}
      catch(Exception e){defer(a,now,"Connection unavailable. Action is pending, not confirmed.");store.write(state);break;}
      actions.remove(i);store.write(state);committed.put(a.getString("id"),result);
    }
    return committed;
  }
  private void defer(JSONObject a,long now,String message)throws JSONException{int attempts=Math.min(16,a.optInt("attempts")+1);a.put("attempts",attempts);a.put("next_at",now+Math.min(300000L,5000L*(1L<<Math.min(6,attempts-1))));a.put("message",message);}
  JSONArray visible(String origin,String owner)throws Exception{JSONArray all=rows(store.read()),out=new JSONArray();for(int i=0;i<all.length();i++){JSONObject a=all.getJSONObject(i);if(origin.equals(a.getString("origin"))&&owner.equals(a.getString("owner")))out.put(new JSONObject(a.toString()));}return out;}
  void retry(String origin,String owner)throws Exception{JSONObject s=store.read();JSONArray all=rows(s);for(int i=0;i<all.length();i++){JSONObject a=all.getJSONObject(i);if(origin.equals(a.getString("origin"))&&owner.equals(a.getString("owner"))&&"pending".equals(a.getString("state")))a.put("next_at",0);}store.write(s);}
  void discard(String origin,String owner,String id)throws Exception{JSONObject s=store.read();JSONArray all=rows(s);for(int i=0;i<all.length();i++){JSONObject a=all.getJSONObject(i);if(id.equals(a.getString("id"))&&origin.equals(a.getString("origin"))&&owner.equals(a.getString("owner"))){all.remove(i);store.write(s);return;}}}
}
