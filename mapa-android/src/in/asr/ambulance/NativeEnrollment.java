package in.asr.ambulance;
import android.content.Context;
import org.json.*;
import java.security.SecureRandom;
import java.util.UUID;

/** The connection secret and uncertain enrollment body stay inside encrypted app storage. */
final class NativeEnrollment {
  static PrivateStore store(Context c){return new PrivateStore(c,"mapa-native-enrollment-v1");}
  static synchronized JSONObject state(Context c)throws Exception{if(NativeApi.prefs(c).getBoolean("connection_forgotten",false))return new JSONObject();JSONObject s=store(c).read();return EnrollmentState.belongs(s,NativeApi.server(c))?s:new JSONObject();}
  static boolean exists(Context c){try{return EnrollmentState.belongs(state(c),NativeApi.server(c));}catch(Exception e){return false;}}
  static synchronized JSONObject draft(Context c)throws Exception{return new PrivateStore(c,"mapa-native-registration-draft-v1").read();}
  static synchronized void draft(Context c,JSONObject value)throws Exception{new PrivateStore(c,"mapa-native-registration-draft-v1").write(value);}
  static synchronized void clear(Context c){NativeApi.prefs(c).edit().putBoolean("connection_forgotten",true).commit();store(c).erase();new PrivateStore(c,"mapa-native-registration-draft-v1").erase();new PrivateStore(c,"mapa-native-completion-draft-v1").erase();NativePhoto.discard(c,"selfie");NativePhoto.discard(c,"completion");}
  static String secret(){byte[] bytes=new byte[32];new SecureRandom().nextBytes(bytes);StringBuilder s=new StringBuilder();for(byte b:bytes)s.append(String.format(java.util.Locale.ROOT,"%02x",b&255));return s.toString();}
  static synchronized void prepare(Context c,JSONObject fields)throws Exception{
    if(exists(c))throw new Exception("A registration is already saved on this phone. Check its status or disconnect first.");
    String token=secret();JSONObject body=new JSONObject(fields.toString());body.put("action_id",UUID.randomUUID().toString());body.put("connect_token",token);body.put("device_name","MAPA Android "+android.os.Build.MODEL);
    if(!NativeApi.prefs(c).edit().putBoolean("connection_forgotten",false).commit())throw new Exception("Could not remember this phone registration.");
    store(c).write(NativeApi.obj("origin",NativeApi.server(c),"connect_token",token,"status","unsent","payload",body,"created_at",System.currentTimeMillis()));
  }
  static JSONObject authenticated(Context c,String method,String path,JSONObject body,String token)throws Exception{
    JSONObject before=state(c);if(!token.equals(before.optString("connect_token")))throw new NativeApi.Failure("The saved phone connection changed. Reopen registration.",401);
    JSONObject result=NativeApi.call(c,new NativeApi.BoundSession(c,token),method,path,body);
    if(!token.equals(state(c).optString("connect_token")))throw new NativeApi.Failure("The saved phone connection changed. Reopen registration.",401);return result;
  }
  static synchronized JSONObject check(Context c)throws Exception{
    JSONObject s=state(c);String token=s.optString("connect_token");if(!EnrollmentState.token(token))throw new NativeApi.Failure("No registration is saved on this phone.",401);
    JSONObject result;
    if(s.optJSONObject("payload")!=null){if(!EnrollmentState.submissionAllowed(s))throw new NativeApi.Failure(s.optString("rejection_message","This saved registration needs attention. Retake the selfie and GPS after a definite rejection, or contact control."),s.optInt("rejection_status"));try{result=NativeApi.call(c,new NativeApi.BoundSession(c,false),"POST","/enrollments",s.getJSONObject("payload"));}catch(NativeApi.Failure rejected){if(rejected.status==422||rejected.status==409){s.put("rejection_status",rejected.status);s.put("rejection_message",rejected.getMessage());store(c).write(s);}throw rejected;}}
    else result=authenticated(c,"GET","/enrollment",null,token);
    EnrollmentState.receipt(result);s.put("status",result.getString("status"));s.put("enrollment_id",result.getString("enrollment_id"));s.put("revision",result.optLong("revision"));s.put("connect_expires_at",result.optLong("connect_expires_at"));s.remove("payload");s.remove("rejection_status");s.remove("rejection_message");store(c).write(s);draft(c,new JSONObject());return result;
  }
  static synchronized void retakeRejected(Context c)throws Exception{
    JSONObject state=state(c);if(!EnrollmentState.recaptureAllowed(state)&&!EnrollmentState.existingLoginAllowed(state))throw new Exception("Only a definitely rejected, unconfirmed registration can be recaptured. Contact control.");
    JSONObject previous=state.getJSONObject("payload"),draft=NativeApi.obj("origin",NativeApi.server(c),"consent",previous.optBoolean("consent"));for(String key:new String[]{"name","email","phone","ambulance_registration","ambulance_name","hospital_name"})draft.put(key,previous.optString(key));
    draft(c,draft);if(!NativeApi.prefs(c).edit().putBoolean("connection_forgotten",true).commit())throw new Exception("Could not reset the rejected phone connection. Your earlier request is retained; contact control.");store(c).erase();NativePhoto.discard(c,"selfie");
  }
  static synchronized void useExistingAccount(Context c)throws Exception{
    JSONObject saved=state(c);if(NativeApi.connected(c)||!EnrollmentState.existingLoginAllowed(saved))throw new Exception("Only a rejected duplicate-email request without a registration receipt can return to existing-account sign in. Contact control.");
    // This request was rejected before enrollment creation. Never revoke a server registration,
    // clear a native session, or discard queued trip/completion evidence in this recovery path.
    store(c).write(new JSONObject());draft(c,new JSONObject());NativePhoto.discard(c,"selfie");
  }
  static JSONObject connect(Context c)throws Exception{
    JSONObject s=state(c);if(!EnrollmentState.renewable(s,System.currentTimeMillis()))throw new NativeApi.Failure("Control must approve this phone registration before it can connect.",403);
    JSONObject result=authenticated(c,"POST","/connect",NativeApi.obj(),s.getString("connect_token"));NativeApi.save(c,result);return result;
  }
  static boolean canReconnect(Context c){try{return EnrollmentState.renewable(state(c),System.currentTimeMillis());}catch(Exception e){return false;}}
  static void revoke(Context c)throws Exception{JSONObject s=state(c);if(EnrollmentState.token(s.optString("connect_token")))authenticated(c,"POST","/enrollment/revoke",NativeApi.obj(),s.getString("connect_token"));}
}
