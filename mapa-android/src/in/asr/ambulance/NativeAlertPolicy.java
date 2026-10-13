package in.asr.ambulance;
import org.json.*;
import java.util.*;

/** Checking a call silences its revision only; it does not acknowledge the assignment. */
final class NativeAlertPolicy {
  static String marker(JSONObject snapshot,JSONObject order){return NativeProtocol.scope(snapshot)+"/"+order.optString("id")+"/"+order.optString("revision");}
  static JSONArray unchecked(JSONObject snapshot,JSONArray checked){JSONArray result=new JSONArray();if(!NativeProtocol.crew(snapshot)||!NativeProtocol.can(snapshot,"orders"))return result;Set<String> seen=new HashSet<>();for(int i=0;i<checked.length();i++)seen.add(checked.optString(i));JSONArray orders=NativeProtocol.rows(snapshot,"orders");for(int i=0;i<orders.length();i++){JSONObject order=orders.optJSONObject(i);if(order!=null&&NativeProtocol.canAcknowledge(snapshot,order)&&!order.optString("revision").isEmpty()&&!seen.contains(marker(snapshot,order)))result.put(order);}return result;}
  static JSONArray checked(JSONArray previous,String marker){JSONArray next=new JSONArray();for(int i=Math.max(0,previous.length()-199);i<previous.length();i++)if(!marker.equals(previous.optString(i)))next.put(previous.optString(i));next.put(marker);return next;}
  static boolean localTestAllowed(int unresolved,boolean visible){return unresolved==0&&visible;}
  static boolean audible(boolean notifications,boolean channelSound,int importance,boolean normalRinger,boolean dndAll,int volume){return notifications&&channelSound&&importance>=3&&normalRinger&&dndAll&&volume>0;}
}
