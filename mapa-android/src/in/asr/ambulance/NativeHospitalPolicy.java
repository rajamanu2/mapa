package in.asr.ambulance;
import org.json.*;
import java.util.*;
/** No clinical data is stored by notification deduplication; server remains authoritative. */
final class NativeHospitalPolicy {
  static boolean allowed(JSONObject state){return state!=null&&"hospital".equals(NativeProtocol.role(state))&&NativeProtocol.can(state,"hospital_handover");}
  static boolean canReport(JSONObject state,JSONObject order){return state!=null&&order!=null&&NativeProtocol.crew(state)&&NativeProtocol.can(state,"hospital_handover")&&Boolean.TRUE.equals(order.opt("acknowledged"))&&NativeProtocol.rows(order,"next_activities").length()>0;}
  static JSONArray assigned(JSONObject result){
    JSONArray visible=new JSONArray();if(result==null||!Boolean.TRUE.equals(result.optJSONObject("capabilities")==null?null:result.optJSONObject("capabilities").opt("hospital_assigned_trips")))return visible;
    JSONArray rows=NativeProtocol.rows(result,"assigned_trips");for(int i=0;i<rows.length();i++){
      JSONObject row=rows.optJSONObject(i);if(row==null||row.optString("order_id").isEmpty()
          ||!"awaiting_crew_report".equals(row.optString("handover_state"))
          ||!Boolean.FALSE.equals(row.opt("can_acknowledge_handover"))
          ||!row.has("crew_observed")||!row.isNull("crew_observed")
          ||row.optJSONObject("destination")==null||row.optJSONObject("ambulance")==null)continue;
      visible.put(row);
    }return visible;
  }
  static boolean unseen(JSONObject result,Set<String> seen){
    boolean changed=false;Set<String> current=new HashSet<>();JSONArray rows=NativeProtocol.rows(result,"incoming");
    for(int i=0;i<rows.length();i++){JSONObject row=rows.optJSONObject(i);if(row==null||row.optString("id").isEmpty()||row.optInt("revision")<1)continue;String key="report:"+row.optString("id")+":"+row.optInt("revision");current.add(key);if(!seen.contains(key))changed=true;}
    JSONArray assignments=assigned(result);for(int i=0;i<assignments.length();i++){String key="assignment:"+assignments.optJSONObject(i).optString("order_id");current.add(key);if(!seen.contains(key))changed=true;}
    seen.clear();seen.addAll(current);return changed;
  }
}
