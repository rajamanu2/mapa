package in.asr.ambulance;

import android.app.NotificationManager;
import android.content.Context;
import android.content.SharedPreferences;
import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.atomic.AtomicBoolean;
import org.json.JSONObject;

/** FCM token sync is bound to the exact authenticated native session, never a supplied crew/vehicle ID. */
public final class NativeFcmClient {
  private static final ExecutorService worker=Executors.newSingleThreadExecutor();
  private static final AtomicBoolean syncing=new AtomicBoolean(false);
  static String hash(String token)throws Exception{StringBuilder out=new StringBuilder();for(byte value:MessageDigest.getInstance("SHA-256").digest(token.getBytes(StandardCharsets.UTF_8)))out.append(String.format(java.util.Locale.ROOT,"%02x",value&255));return out.toString();}
  static void status(Context c,String value){NativeApi.prefs(c).edit().putString("push_status",value).apply();}
  private static String channel(Context c){try{return "hospital".equals(NativeApi.session(c).getJSONObject("user").optString("role"))?NativeMessagingService.HOSPITAL:NativeDutyService.LEADS;}catch(Exception e){return NativeDutyService.LEADS;}}
  public static void onSession(Context c){
    if(!NativeApi.connected(c))return;
    if(!c.getSystemService(NotificationManager.class).areNotificationsEnabled()){status(c,"Allow MAPA notifications to receive alerts");return;}
    NativeDutyService.channels(c);
    NativeMessagingService.hospitalChannel(c);
    if(c.getSystemService(NotificationManager.class).getNotificationChannel(channel(c)).getImportance()==NotificationManager.IMPORTANCE_NONE){status(c,"Enable the MAPA notification channel");return;}
    if(!syncing.compareAndSet(false,true))return;
    worker.execute(()->{
      try{
        NativeApi.BoundSession bound=new NativeApi.BoundSession(c,true);
        JSONObject session=NativeApi.session(c),user=session.optJSONObject("user");
        if(user==null||!("crew".equals(user.optString("role"))||"hospital".equals(user.optString("role")))){syncing.set(false);return;}
        String sessionHash=hash(bound.token);SharedPreferences prefs=NativeApi.prefs(c);
        if(sessionHash.equals(prefs.getString("push_session",""))&&System.currentTimeMillis()-prefs.getLong("push_registered_at",0)<900000){syncing.set(false);return;}
        JSONObject available=NativeApi.call(c,bound,"GET","/push",null);
        if(!Boolean.TRUE.equals(available.opt("available"))){status(c,"Push alerts are not configured on this server");syncing.set(false);return;}
        if(FirebaseApp.initializeApp(c)==null)throw new IllegalStateException("Firebase configuration unavailable");
        FirebaseMessaging.getInstance().setAutoInitEnabled(true);
        FirebaseMessaging.getInstance().getToken().addOnCompleteListener(task->{
          if(!task.isSuccessful()||task.getResult()==null){status(c,"Push registration failed. Check Google Play services and internet.");syncing.set(false);return;}
          worker.execute(()->{try{
            bound.check(c);String installation=prefs.getString("push_installation","");
            if(installation.isEmpty()){installation=UUID.randomUUID().toString();if(!prefs.edit().putString("push_installation",installation).commit())throw new IllegalStateException("Cannot save installation identity");}
            JSONObject result=NativeApi.call(c,bound,"POST","/push/register",NativeApi.obj("installation_id",installation,"fcm_token",task.getResult()));
            if(!Boolean.TRUE.equals(result.opt("registered")))throw new IllegalStateException("Registration not confirmed");
            bound.check(c);prefs.edit().putString("push_session",sessionHash).putLong("push_registered_at",System.currentTimeMillis()).apply();
            status(c,"Push alerts registered; phone delivery still needs verification");
          }catch(Exception e){status(c,"Push registration failed. Reopen MAPA or contact control.");}finally{syncing.set(false);}});
        });
      }catch(Exception e){status(c,"Push registration failed. Reopen MAPA or contact control.");syncing.set(false);}
    });
  }
  static void tokenChanged(Context c){NativeApi.prefs(c).edit().remove("push_session").remove("push_registered_at").apply();onSession(c);}
  public static void clear(Context c){
    NativeApi.prefs(c).edit().remove("push_session").remove("push_registered_at").remove("push_last_event").putString("push_status","Sign in to register alerts").apply();
    c.getSystemService(NotificationManager.class).cancel(75);
    c.getSystemService(NotificationManager.class).cancel(78);
    // Backend logout revokes the session. Sending always revalidates it; a queued hint is rejected by the local session tag.
    try{FirebaseMessaging.getInstance().setAutoInitEnabled(false);}catch(Exception ignored){}
  }
}
