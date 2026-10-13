package in.asr.ambulance;

import org.json.*;
import java.util.*;

/** Received device observations, never a claim of identity, distance or a completed trip. */
final class NativeTravel {
  static JSONArray points(JSONObject snapshot){
    JSONArray result=new JSONArray();if(!NativeProtocol.canDuty(snapshot))return result;
    JSONObject evidence=snapshot.optJSONObject("travel_evidence"),user=snapshot.optJSONObject("user");
    if(evidence==null||user==null)return result;
    ArrayList<JSONObject> valid=new ArrayList<>();JSONArray rows=NativeProtocol.rows(evidence,"points");
    for(int i=0;i<rows.length();i++){
      JSONObject p=rows.optJSONObject(i);if(p==null||!user.optString("driver_id").equals(p.optString("driver_id"))||!user.optString("vehicle_id").equals(p.optString("vehicle_id")))continue;
      if(!"device_gps".equals(p.optString("source"))||Boolean.TRUE.equals(p.opt("mock_location"))||!(p.opt("simulated") instanceof Boolean))continue;
      if(!number(p,"latitude")||!number(p,"longitude")||!number(p,"accuracy_m")||!number(p,"recorded_at")||!number(p,"received_at"))continue;
      if(Math.abs(p.optDouble("latitude"))>90||Math.abs(p.optDouble("longitude"))>180||p.optDouble("accuracy_m")<0||p.optDouble("accuracy_m")>100||p.optLong("recorded_at")<=0||p.optLong("received_at")<=0||p.optLong("recorded_at")-p.optLong("received_at")>30000)continue;
      valid.add(p);
    }
    Collections.sort(valid,(a,b)->Long.compare(a.optLong("recorded_at"),b.optLong("recorded_at")));
    for(JSONObject p:valid)result.put(p);return result;
  }
  private static boolean number(JSONObject p,String key){return p.opt(key) instanceof Number&&Double.isFinite(p.optDouble(key));}
  static JSONObject evidence(JSONObject snapshot){JSONObject value=snapshot.optJSONObject("travel_evidence");return value==null?new JSONObject():value;}
  static boolean fresh(JSONObject p,long serverTime){long recorded=p.optLong("recorded_at"),received=p.optLong("received_at");return serverTime>=recorded&&serverTime-recorded<120000&&serverTime>=received&&serverTime-received<120000;}
}
