package in.asr.ambulance;
import org.json.*;
/** Server-scoped offers only; expired offers must stop sounding even during a connection loss. */
final class NativeDispatchOffers {
  static JSONArray active(JSONObject snapshot,long now){JSONArray result=new JSONArray();if(!NativeProtocol.crew(snapshot))return result;JSONArray rows=NativeProtocol.rows(snapshot,"dispatch_offers");for(int i=0;i<rows.length();i++){JSONObject row=rows.optJSONObject(i);if(row==null||!NativeProtocol.validPath("/dispatch-offers/"+row.optString("id")+"/respond")||row.optInt("revision")<1||row.optLong("expires_at")<=0)continue;try{JSONObject copy=new JSONObject(row.toString());long received=snapshot.optLong("_received_at"),server=snapshot.optLong("server_time");if(received>0&&server>0){copy.put("_offer_deadline",received+(row.optLong("expires_at")-server));copy.put("_snapshot_received_at",received);}copy.put("dispatch_offer",true);if(respondable(copy,now))result.put(copy);}catch(JSONException ignored){}}return result;}
  static boolean respondable(JSONObject offer,long now){long received=offer.optLong("_snapshot_received_at");if(received>0&&(now<received||now-received>120000))return false;if(offer.has("can_respond"))return Boolean.TRUE.equals(offer.opt("can_respond"))&&("offered".equals(offer.optString("state"))||"overdue".equals(offer.optString("state")));return deadline(offer)>now;}
  static long deadline(JSONObject offer){return offer.optLong("_offer_deadline",offer.optLong("expires_at"));}
  static long remainingSeconds(JSONObject offer,long now){return Math.max(0,(deadline(offer)-now+999)/1000);}
  static String countdown(JSONObject offer,long now){long seconds=remainingSeconds(offer,now);return String.format(java.util.Locale.ROOT,"%02d:%02d",seconds/60,seconds%60);}
  static String travelEstimate(JSONObject offer){Object value=offer.opt("road_seconds");if(!(value instanceof Number)||!Double.isFinite(((Number)value).doubleValue())||((Number)value).doubleValue()<=0)return "Road travel estimate unavailable";return "Estimated road travel to pickup: "+(long)Math.ceil(((Number)value).doubleValue()/60)+" min · traffic and access may change this";}
  static JSONObject responseBody(JSONObject offer,String response,String reason,String detail,String actionId,long now)throws Exception{
    if(!NativeProtocol.validPath("/dispatch-offers/"+offer.optString("id")+"/respond")||offer.optInt("revision")<1||!respondable(offer,now))throw new Exception("This offer changed or needs refreshing. Check the latest offer before responding.");
    if(!("accept".equals(response)||"decline".equals(response))||actionId==null||!actionId.matches("[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}"))throw new Exception("Invalid offer response.");
    JSONObject body=new JSONObject().put("action_id",actionId).put("expected_revision",offer.getInt("revision")).put("response",response);
    if("decline".equals(response)){String note=detail==null?"":detail.trim();if(!java.util.Arrays.asList("unavailable","vehicle_issue","already_busy","location_issue","other").contains(reason)||note.length()>500||("other".equals(reason)&&note.isEmpty()))throw new Exception("Choose an operational reason. For Other, add a brief explanation (up to 500 characters).");body.put("decline_reason",reason).put("decline_detail",note);}
    return body;
  }
  static JSONArray unexpired(JSONArray rows,long now){JSONArray result=new JSONArray();for(int i=0;i<rows.length();i++){JSONObject row=rows.optJSONObject(i);if(row!=null&&(!row.optBoolean("dispatch_offer")||respondable(row,now)))result.put(row);}return result;}
}
