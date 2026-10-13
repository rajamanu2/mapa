package in.asr.ambulance;

import org.json.*;
import java.util.*;

/** Pure contract checks used by both the screen and foreground worker. */
final class NativeProtocol {
  static final String CONTRACT="mapa-native-mobile-v1";
  static boolean validPath(String value){return value!=null&&(Arrays.asList("/login","/logout","/enrollments","/enrollment","/connect","/enrollment/revoke","/capabilities","/snapshot","/duty","/gps","/push","/push/register","/push/unregister","/hospital/incoming").contains(value)||value.matches("/orders/order_[A-Za-z0-9_-]+/(ack|activity|patient-onboard|arrivals)")||value.matches("/dispatch-offers/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/respond")||value.matches("/hospital/handovers/[A-Za-z0-9_-]{1,100}/acknowledge"));}
  static JSONArray rows(JSONObject o,String key){JSONArray a=o.optJSONArray(key);return a==null?new JSONArray():a;}
  static String role(JSONObject snapshot){JSONObject user=snapshot.optJSONObject("user");return user==null?"":user.optString("role");}
  static boolean knownRole(String role){return Arrays.asList("crew","hospital","admin","superadmin").contains(role);}
  static boolean crew(JSONObject snapshot){return "crew".equals(role(snapshot));}
  static String roleLabel(String role){switch(role){case "crew":return "Crew";case "hospital":return "Hospital staff";case "admin":return "Administrator";case "superadmin":return "Super administrator";default:return "Unsupported account role";}}
  static boolean can(JSONObject snapshot,String capability){JSONObject caps=snapshot.optJSONObject("capabilities");return caps!=null&&Boolean.TRUE.equals(caps.opt(capability));}
  static void requireSnapshot(JSONObject snapshot)throws Exception{
    if(!CONTRACT.equals(snapshot.optString("contract"))||snapshot.optJSONObject("user")==null||snapshot.optJSONObject("capabilities")==null||snapshot.optJSONArray("orders")==null||snapshot.optJSONArray("places")==null)throw new Exception("The server does not provide the supported native MAPA snapshot contract.");
    JSONObject u=snapshot.getJSONObject("user");if(u.optString("id").isEmpty()||u.optString("company_id").isEmpty()||!knownRole(u.optString("role")))throw new Exception("This account has no supported MAPA company role. Contact your administrator.");
  }
  static boolean canDuty(JSONObject snapshot){JSONObject u=snapshot.optJSONObject("user"),d=snapshot.optJSONObject("driver"),v=snapshot.optJSONObject("vehicle");return crew(snapshot)&&can(snapshot,"duty")&&can(snapshot,"gps")&&u!=null&&d!=null&&v!=null&&d.optString("id").equals(u.optString("driver_id"))&&v.optString("id").equals(u.optString("vehicle_id"))&&v.optString("id").equals(d.optString("vehicle_id"))&&!v.optString("id").isEmpty();}
  static boolean canAcknowledge(JSONObject snapshot,JSONObject order){return crew(snapshot)&&can(snapshot,"acknowledge")&&Boolean.TRUE.equals(order.opt("can_acknowledge"))&&!order.optBoolean("acknowledged");}
  static boolean currentAction(JSONObject action,String origin,String owner,String vehicle){return origin.equals(action.optString("origin"))&&owner.equals(action.optString("owner"))&&vehicle.equals(action.optString("vehicle"));}
  static JSONArray currentActions(JSONArray actions,String origin,String owner,String vehicle){JSONArray out=new JSONArray();for(int i=0;i<actions.length();i++){JSONObject action=actions.optJSONObject(i);if(action!=null&&currentAction(action,origin,owner,vehicle))out.put(action);}return out;}
  static int heldActions(JSONArray actions,String origin,String owner,String vehicle){return actions.length()-currentActions(actions,origin,owner,vehicle).length();}
  static boolean advertisedActivity(JSONObject order,String code){for(int i=0;i<rows(order,"next_activities").length();i++)if(code.equals(rows(order,"next_activities").optJSONObject(i).optString("code")))return true;return false;}
  static boolean advertisedArrival(JSONObject snapshot,JSONObject order,String code){
    if(!crew(snapshot)||!can(snapshot,"arrivals")||!Boolean.TRUE.equals(order.opt("acknowledged")))return false;
    if(!(("pickup_arrived".equals(code)&&"started".equals(order.optString("status")))||("hospital_arrived".equals(code)&&"enroute".equals(order.optString("status")))))return false;
    JSONArray options=rows(order,"next_arrivals");for(int i=0;i<options.length();i++){JSONObject option=options.optJSONObject(i);if(option!=null&&code.equals(option.optString("code")))return true;}return false;
  }
  static JSONObject arrivalPayload(JSONObject snapshot,JSONObject order,String code,long now)throws Exception{
    String path="/orders/"+order.optString("id")+"/arrivals",revision=order.optString("revision");
    if(!advertisedArrival(snapshot,order,code)||!validPath(path)||!revision.matches("[a-f0-9]{64}")||now<=0)throw new Exception("Arrival is not advertised for the current call. Refresh and review it.");
    return new JSONObject().put("_path",path).put("expected_revision",revision).put("arrival_code",code).put("confirmed",true).put("reported_at",now);
  }
  static String scope(JSONObject snapshot){JSONObject u=snapshot.optJSONObject("user");return u==null?"":u.optString("company_id")+"|"+u.optString("id")+"|"+u.optString("driver_id");}
  static String vehicle(JSONObject snapshot){JSONObject u=snapshot.optJSONObject("user");return u==null?"":u.optString("vehicle_id");}
  static JSONObject order(JSONObject snapshot,String id){JSONArray orders=rows(snapshot,"orders");for(int i=0;i<orders.length();i++){JSONObject order=orders.optJSONObject(i);if(order!=null&&id.equals(order.optString("id")))return order;}return null;}
}
