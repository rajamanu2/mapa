package in.asr.ambulance;

import java.util.Map;

/** Reject stale, cross-session and malformed push hints before creating any notification. */
final class NativePushPolicy {
  static boolean valid(Map<String,String> data,String sessionHash,long nowSeconds){
    return valid(data,sessionHash,nowSeconds,"crew");
  }
  static boolean valid(Map<String,String> data,String sessionHash,long nowSeconds,String role){
    String kind="hospital".equals(role)?"mapa_hospital_changed":"crew".equals(role)?"mapa_assignments_changed":"";
    if(kind.isEmpty()||data==null||data.size()!=4||!kind.equals(data.get("kind"))
        ||sessionHash==null||!sessionHash.matches("[a-f0-9]{64}")||!sessionHash.equals(data.get("session_tag"))
        ||data.get("event_id")==null||!data.get("event_id").matches("[a-f0-9]{64}"))return false;
    try{long issued=Long.parseLong(data.get("issued_at"));return issued>=nowSeconds-120&&issued<=nowSeconds+30;}
    catch(Exception e){return false;}
  }
}
