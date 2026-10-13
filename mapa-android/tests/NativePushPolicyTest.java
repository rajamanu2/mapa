package in.asr.ambulance;
import java.util.HashMap;
import java.util.Map;
public final class NativePushPolicyTest {
  static int count;static void check(boolean value){if(!value)throw new AssertionError("Push policy check "+count);count++;}
  public static void main(String[] args){
    String session=new String(new char[64]).replace('\0','a'),event=new String(new char[64]).replace('\0','b');
    Map<String,String> data=new HashMap<>();data.put("kind","mapa_assignments_changed");data.put("event_id",event);data.put("session_tag",session);data.put("issued_at","1000");
    check(NativePushPolicy.valid(data,session,1001));check(!NativePushPolicy.valid(data,event,1001));
    check(!NativePushPolicy.valid(data,session,1121));check(!NativePushPolicy.valid(data,session,969));
    data.put("issued_at","NaN");check(!NativePushPolicy.valid(data,session,1001));data.put("issued_at","1000");
    data.put("patient_name","must-never-appear");check(!NativePushPolicy.valid(data,session,1001));data.remove("patient_name");
    data.put("kind","dispatch");check(!NativePushPolicy.valid(data,session,1001));data.put("kind","mapa_assignments_changed");
    data.put("event_id","https://example.invalid/");check(!NativePushPolicy.valid(data,session,1001));
    check(!NativePushPolicy.valid(null,session,1001));check(!NativePushPolicy.valid(data,null,1001));
    data.put("event_id",event);data.put("kind","mapa_hospital_changed");
    check(NativePushPolicy.valid(data,session,1001,"hospital"));check(!NativePushPolicy.valid(data,session,1001,"crew"));
    check(!NativePushPolicy.valid(data,session,1001,"admin"));check(!NativePushPolicy.valid(data,event,1001,"hospital"));
    check(!NativePushPolicy.valid(data,session,1121,"hospital"));data.put("patient_note","secret");check(!NativePushPolicy.valid(data,session,1001,"hospital"));data.remove("patient_note");
    data.put("kind","mapa_assignments_changed");check(!NativePushPolicy.valid(data,session,1001,"hospital"));
    System.out.println(count+" push policy checks passed");
  }
}
