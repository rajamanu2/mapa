package in.asr.ambulance;
import org.json.*;

/** Pure enrollment guards. An enrollment receipt is never a crew session. */
final class EnrollmentState {
  static boolean token(String value){return value!=null&&value.matches("[a-f0-9]{64}");}
  static boolean belongs(JSONObject state,String origin){return state!=null&&origin.equals(state.optString("origin"))&&token(state.optString("connect_token"));}
  static void receipt(JSONObject value)throws Exception{
    if(!NativeProtocol.CONTRACT.equals(value.optString("contract"))||value.optString("enrollment_id").isEmpty()||!java.util.Arrays.asList("pending","approved","rejected","revoked","cancelled").contains(value.optString("status")))throw new Exception("This server did not confirm a supported MAPA registration.");
  }
  static boolean renewable(JSONObject state,long now){return "approved".equals(state.optString("status"))&&state.optLong("connect_expires_at")>now;}
  static boolean submissionAllowed(JSONObject state){int rejected=state.optInt("rejection_status");return state.optJSONObject("payload")!=null&&rejected!=422&&rejected!=409;}
  static boolean automaticCheckAllowed(JSONObject state){if(state.optJSONObject("payload")!=null)return submissionAllowed(state);return java.util.Arrays.asList("pending","approved").contains(state.optString("status"));}
  static boolean recaptureAllowed(JSONObject state){return "unsent".equals(state.optString("status"))&&state.optInt("rejection_status")==422&&state.optJSONObject("payload")!=null&&state.optString("enrollment_id").isEmpty();}
  static boolean existingLoginAllowed(JSONObject state){return "unsent".equals(state.optString("status"))&&state.optInt("rejection_status")==409&&state.optJSONObject("payload")!=null&&state.optString("enrollment_id").isEmpty()&&"This email already has an account or pending registration. Contact control; existing credentials are preserved.".equals(state.optString("rejection_message"));}
  static boolean fresh(long fix,long now,double accuracy,boolean mock){return !mock&&fix>0&&now>=fix&&now-fix<=120000&&Double.isFinite(accuracy)&&accuracy>=0&&accuracy<=100;}
  static boolean validCoordinates(double lat,double lng){return Double.isFinite(lat)&&Double.isFinite(lng)&&Math.abs(lat)<=90&&Math.abs(lng)<=180&&!(lat==0&&lng==0);}
}
