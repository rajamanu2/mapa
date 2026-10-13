package in.asr.ambulance;

import android.content.*;
import android.security.keystore.*;
import android.util.Base64;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import javax.crypto.*;
import javax.crypto.spec.GCMParameterSpec;
import org.json.*;

/** The native first-console API only. No legacy deployment, token or outbox fallback. */
final class NativeApi {
  static final String BASE="/mapa/mobile/v1", CONTRACT="mapa-native-mobile-v1";
  static SharedPreferences prefs(Context c){return c.getSharedPreferences("mapa-native-v1",Context.MODE_PRIVATE);}
  static String server(Context c){return NativeConnection.selected(prefs(c).getString("server",""));}
  static void configure(Context c,String value)throws Exception{String origin=NativeConnection.normalize(value);if(!origin.equals(server(c)))clear(c);if(!prefs(c).edit().putString("server",origin).commit())throw new IOException("Could not save the native MAPA server address.");}
  static synchronized SecretKey key()throws Exception{
    KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);
    if(store.containsAlias("mapa-native-session-v1"))return (SecretKey)store.getKey("mapa-native-session-v1",null);
    KeyGenerator g=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");
    g.init(new KeyGenParameterSpec.Builder("mapa-native-session-v1",KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());return g.generateKey();
  }
  static JSONObject session(Context c)throws Exception{
    String encrypted=prefs(c).getString("session","");if(encrypted.isEmpty())return new JSONObject();
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(prefs(c).getString("iv",""),Base64.NO_WRAP)));
    return new JSONObject(new String(cipher.doFinal(Base64.decode(encrypted,Base64.NO_WRAP)),StandardCharsets.UTF_8));
  }
  static void save(Context c,JSONObject result)throws Exception{
    if(!CONTRACT.equals(result.optString("contract"))||result.optString("token").isEmpty()||result.optLong("expires_at")<=System.currentTimeMillis()||result.optJSONObject("user")==null)throw new Failure("This server did not provide a valid native MAPA phone session.",502);
    JSONObject record=obj("origin",server(c),"token",result.getString("token"),"expires_at",result.getLong("expires_at"),"user",result.getJSONObject("user"));
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key());
    if(!prefs(c).edit().putString("session",Base64.encodeToString(cipher.doFinal(record.toString().getBytes(StandardCharsets.UTF_8)),Base64.NO_WRAP)).putString("iv",Base64.encodeToString(cipher.getIV(),Base64.NO_WRAP)).commit())throw new IOException("Could not save this phone session.");
  }
  static void clearSession(Context c){NativePush.clear(c);NativeAlerts.stop(c);try{NativeDutyService.gpsState(c).clear();}catch(Exception ignored){}prefs(c).edit().remove("session").remove("iv").remove("alerted").putBoolean("duty_requested",false).commit();}
  static void clear(Context c){clearSession(c);NativeEnrollment.clear(c);prefs(c).edit().remove("checked_calls").commit();}
  static boolean connected(Context c){try{JSONObject s=session(c);return !server(c).isEmpty()&&server(c).equals(s.optString("origin"))&&!s.optString("token").isEmpty()&&s.optLong("expires_at")>System.currentTimeMillis();}catch(Exception e){return false;}}
  static JSONObject obj(Object... values){JSONObject o=new JSONObject();try{for(int i=0;i<values.length;i+=2)o.put((String)values[i],values[i+1]);}catch(Exception e){throw new IllegalArgumentException(e);}return o;}
  static class Failure extends IOException {final int status;Failure(String message,int status){super(message);this.status=status;}}
  static final class BoundSession {
    final String origin,token,owner,company,vehicle;final long expires;final boolean sessionBound;
    BoundSession(Context c,boolean authenticated)throws Exception{
      sessionBound=authenticated;origin=NativeConnection.normalize(server(c));JSONObject s=session(c),u=s.optJSONObject("user");token=authenticated?s.optString("token"):"";expires=s.optLong("expires_at");
      owner=u==null?"":u.optString("id");company=u==null?"":u.optString("company_id");vehicle=u==null?"":u.optString("vehicle_id");
      if(authenticated&&(!origin.equals(s.optString("origin"))||token.isEmpty()||expires<=System.currentTimeMillis()))throw new Failure("Sign in to your MAPA console again.",401);
    }
    BoundSession(Context c,String connectionToken)throws Exception{origin=NativeConnection.normalize(server(c));if(!EnrollmentState.token(connectionToken))throw new Failure("Invalid saved phone connection.",401);token=connectionToken;sessionBound=false;expires=0;owner=company=vehicle="";}
    void check(Context c)throws Exception{if(!origin.equals(server(c)))throw new Failure("The MAPA server changed. Sign in again.",401);if(sessionBound&&!token.equals(session(c).optString("token")))throw new Failure("The phone session changed. Refresh after signing in.",401);}
    String tag()throws Exception{return Base64.encodeToString(java.security.MessageDigest.getInstance("SHA-256").digest(token.getBytes(StandardCharsets.UTF_8)),Base64.NO_WRAP);}
  }
  static JSONObject call(Context c,BoundSession bound,String method,String path,JSONObject body)throws Exception{
    bound.check(c);if(!NativeProtocol.validPath(path))throw new IOException("Invalid native MAPA request path.");
    HttpURLConnection connection=(HttpURLConnection)new URL(bound.origin+BASE+path).openConnection();connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(15000);connection.setReadTimeout(25000);connection.setRequestMethod(method);connection.setRequestProperty("Accept","application/json");connection.setRequestProperty("Cache-Control","no-store");if(!bound.token.isEmpty())connection.setRequestProperty("Authorization","Bearer "+bound.token);
    try{
      if(body!=null){byte[] bytes=body.toString().getBytes(StandardCharsets.UTF_8);connection.setDoOutput(true);connection.setRequestProperty("Content-Type","application/json");connection.setFixedLengthStreamingMode(bytes.length);try(OutputStream out=connection.getOutputStream()){out.write(bytes);}}
      int status=connection.getResponseCode();if(status>=300&&status<400)throw new Failure("The native API redirected this request. Use the direct HTTPS MAPA API address.",502);
      InputStream stream=status>=400?connection.getErrorStream():connection.getInputStream();ByteArrayOutputStream out=new ByteArrayOutputStream();if(stream!=null)try(InputStream in=stream){byte[] block=new byte[8192];int count;while((count=in.read(block))!=-1){out.write(block,0,count);if(out.size()>4000000)throw new IOException("Server response is too large.");}}
      JSONObject result;try{result=new JSONObject(new String(out.toByteArray(),StandardCharsets.UTF_8));}catch(Exception e){throw new Failure("This address does not serve the native MAPA mobile API.",status>=400?status:502);}
      if(status>=400)throw new Failure(result.optString("error",result.optString("message","MAPA request failed ("+status+").")),status);
      if(connection.getContentType()==null||!connection.getContentType().contains("application/json"))throw new Failure("The native MAPA API must return JSON.",502);
      bound.check(c);result.put("_origin",bound.origin);result.put("_received_at",System.currentTimeMillis());if(bound.sessionBound)result.put("_session",bound.tag());return result;
    }finally{connection.disconnect();}
  }
  static JSONObject login(Context c,String email,String password)throws Exception{return call(c,new BoundSession(c,false),"POST","/login",obj("email",email.trim(),"password",password,"device_name","MAPA Android "+android.os.Build.MODEL));}
  static JSONObject get(Context c,String path)throws Exception{return call(c,new BoundSession(c,true),"GET",path,null);}
  static JSONObject post(Context c,String path,JSONObject body)throws Exception{return call(c,new BoundSession(c,true),"POST",path,body);}
  static JSONObject post(Context c,BoundSession bound,String path,JSONObject body)throws Exception{return call(c,bound,"POST",path,body);}
  static void validate(Context c,JSONObject result)throws Exception{BoundSession now=new BoundSession(c,true);if(!now.origin.equals(result.optString("_origin"))||!now.tag().equals(result.optString("_session")))throw new Failure("The phone session changed. Refresh this screen.",401);}
}
