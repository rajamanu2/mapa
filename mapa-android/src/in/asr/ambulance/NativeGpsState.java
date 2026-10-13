package in.asr.ambulance;
import org.json.*;
import java.util.UUID;

/** One encrypted, session-bound GPS intent; uncertainty never changes its key or body. */
final class NativeGpsState {
  private static final Object LOCK=new Object();private final ActionQueue.Store store;
  NativeGpsState(ActionQueue.Store store){this.store=store;}
  JSONObject next(String scope,JSONObject fix)throws Exception{synchronized(LOCK){
    JSONObject state=store.read();if(!scope.equals(state.optString("scope"))){state=new JSONObject();state.put("scope",scope);state.put("resolved_at",0);store.write(state);}
    JSONObject pending=state.optJSONObject("pending");if(pending!=null)return new JSONObject(pending.toString());
    if(fix==null||fix.optLong("recorded_at")<=state.optLong("resolved_at"))return null;
    JSONObject payload=new JSONObject(fix.toString());payload.put("action_id",UUID.randomUUID().toString());state.put("pending",payload);store.write(state);return new JSONObject(payload.toString());
  }}
  void resolved(String scope,String id)throws Exception{synchronized(LOCK){JSONObject state=store.read(),pending=state.optJSONObject("pending");if(scope.equals(state.optString("scope"))&&pending!=null&&id.equals(pending.optString("action_id"))){state.put("resolved_at",Math.max(state.optLong("resolved_at"),pending.optLong("recorded_at")));state.remove("pending");store.write(state);}}}
  void clear()throws Exception{synchronized(LOCK){store.write(new JSONObject());}}
}