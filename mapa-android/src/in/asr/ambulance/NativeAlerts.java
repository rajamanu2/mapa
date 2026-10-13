package in.asr.ambulance;
import android.app.*;
import android.content.*;
import android.media.*;
import android.os.*;
import org.json.*;

/** One repeating notification-sound owner for both foreground polling and duty service. */
final class NativeAlerts {
  private static final Handler main=new Handler(Looper.getMainLooper());private static Ringtone tone;private static JSONArray pending=new JSONArray();private static String origin="",session="";private static long refreshed;private static Context app;private static boolean scheduled;private static long localTestUntil;private static int localSoundRequests;
  static JSONArray checked(Context c){try{return new JSONArray(NativeApi.prefs(c).getString("checked_calls","[]"));}catch(Exception e){return new JSONArray();}}
  static synchronized void checked(Context c,JSONObject snapshot,JSONObject order)throws Exception{
    if(!NativeProtocol.crew(snapshot))return;NativeApi.validate(c,snapshot);String marker=NativeAlertPolicy.marker(snapshot,order);JSONArray marks=NativeAlertPolicy.checked(checked(c),marker);if(!NativeApi.prefs(c).edit().putString("checked_calls",marks.toString()).commit())throw new Exception("Could not save the checked call. Contact control.");update(c,snapshot);
  }
  static synchronized void update(Context c,JSONObject snapshot)throws Exception{
    NativeApi.validate(c,snapshot);app=c.getApplicationContext();JSONArray unchecked=NativeDispatchOffers.active(snapshot,System.currentTimeMillis());JSONArray calls=NativeAlertPolicy.unchecked(snapshot,checked(c));for(int i=0;i<calls.length();i++)unchecked.put(calls.optJSONObject(i));if(localTestUntil>System.currentTimeMillis()&&unchecked.length()==0)return;if(localTestUntil>0){localTestUntil=0;clearSound();c.getSystemService(NotificationManager.class).cancel(76);}pending=unchecked;origin=NativeApi.server(c);session=NativeApi.session(c).optString("token");refreshed=System.currentTimeMillis();
    if(pending.length()==0){clearSound();c.getSystemService(NotificationManager.class).cancel(72);return;}
    JSONObject first=pending.optJSONObject(0);Intent intent=new Intent(c,NativeMainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP|Intent.FLAG_ACTIVITY_CLEAR_TOP);intent.putExtra("dispatch_offers",first.optBoolean("dispatch_offer"));if(!first.optBoolean("dispatch_offer"))intent.putExtra("order_id",first.optString("id"));PendingIntent open=PendingIntent.getActivity(c,72,intent,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
    NotificationManager n=c.getSystemService(NotificationManager.class);if(n.areNotificationsEnabled())n.notify(72,new Notification.Builder(c,NativeDutyService.LEADS).setSmallIcon(R.drawable.ic_notification).setContentTitle("MAPA ambulance call").setContentText("Open the assigned call to check it. Accept separately when ready.").setContentIntent(open).setOnlyAlertOnce(true).setOngoing(true).setCategory(Notification.CATEGORY_EVENT).setVisibility(Notification.VISIBILITY_PRIVATE).build());
    if(!scheduled){scheduled=true;main.postDelayed(repeat,8000);}
  }
  private static final Runnable repeat=new Runnable(){public void run(){synchronized(NativeAlerts.class){scheduled=false;if(app==null||pending.length()==0)return;long now=System.currentTimeMillis();boolean viable=NativeDutyService.running||NativeMainActivity.visible;
    try{boolean testing=localTestUntil>0;if(!testing){pending=NativeDispatchOffers.unexpired(pending,now);if(pending.length()==0){stop(app);return;}}if(testing&&(!NativeMainActivity.visible||now>=localTestUntil)){stopLocalTest(app);return;}if(!testing&&(!viable||!NativeApi.connected(app)||!origin.equals(NativeApi.server(app))||!session.equals(NativeApi.session(app).optString("token"))||now<refreshed||now-refreshed>120000)){stop(app);return;}
      NotificationManager n=app.getSystemService(NotificationManager.class);NotificationChannel channel=n.getNotificationChannel(NativeDutyService.LEADS);AudioManager audio=app.getSystemService(AudioManager.class);
      boolean allowed=NativeAlertPolicy.audible(n.areNotificationsEnabled(),channel!=null&&channel.getSound()!=null,channel==null?0:channel.getImportance(),audio.getRingerMode()==AudioManager.RINGER_MODE_NORMAL,n.getCurrentInterruptionFilter()==NotificationManager.INTERRUPTION_FILTER_ALL,audio.getStreamVolume(AudioManager.STREAM_NOTIFICATION));
      if(allowed){if(tone==null){tone=RingtoneManager.getRingtone(app,channel.getSound());if(tone!=null)tone.setAudioAttributes(new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());}if(tone!=null&&!tone.isPlaying()){tone.play();if(testing){localSoundRequests++;android.util.Log.i("MapaAlertTest","Local sound request "+localSoundRequests+"; player active="+tone.isPlaying());}}}else if(tone!=null)tone.stop();
    }catch(Exception e){clearSound();}if(pending.length()>0){scheduled=true;main.postDelayed(this,8000);}
  }}};
  static synchronized void localTest(Context c)throws Exception{
    if(!NativeAlertPolicy.localTestAllowed(pending.length(),NativeMainActivity.visible))throw new Exception("Check any assigned call first. The local test cannot replace a real call alert.");
    NativeDutyService.channels(c);NotificationManager n=c.getSystemService(NotificationManager.class);NotificationChannel channel=n.getNotificationChannel(NativeDutyService.LEADS);if(!n.areNotificationsEnabled()||channel==null||channel.getImportance()==NotificationManager.IMPORTANCE_NONE)throw new Exception("Enable MAPA call notifications before testing alert sound.");
    app=c.getApplicationContext();localTestUntil=System.currentTimeMillis()+30000;localSoundRequests=0;pending=new JSONArray().put(new JSONObject());refreshed=System.currentTimeMillis();Intent intent=new Intent(c,NativeMainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP|Intent.FLAG_ACTIVITY_CLEAR_TOP).putExtra("stop_alert_test",true);PendingIntent stop=PendingIntent.getActivity(c,76,intent,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
    n.notify(76,new Notification.Builder(c,NativeDutyService.LEADS).setSmallIcon(R.drawable.ic_notification).setContentTitle("MAPA LOCAL sound test").setContentText("30-second phone test only. Tap to stop. No call was created.").setContentIntent(stop).setOngoing(true).setCategory(Notification.CATEGORY_EVENT).setVisibility(Notification.VISIBILITY_PRIVATE).build());clearSound();scheduled=true;main.postDelayed(repeat,8000);main.removeCallbacks(testEnd);main.postDelayed(testEnd,30000);android.util.Log.i("MapaAlertTest","30-second local alert test started; no API action");
  }
  private static final Runnable testEnd=()->{synchronized(NativeAlerts.class){if(localTestUntil>0)stopLocalTest(app);}};
  static synchronized String testStatus(){return "Local phone test: "+(localTestUntil>0?"running":"stopped")+" / sound requests "+localSoundRequests+". Ask someone near the phone to confirm whether it was audible.";}
  static synchronized void stopLocalTest(Context c){if(localTestUntil<=0)return;localTestUntil=0;pending=new JSONArray();clearSound();main.removeCallbacks(testEnd);if(c!=null)c.getSystemService(NotificationManager.class).cancel(76);android.util.Log.i("MapaAlertTest","Local alert test stopped; sound requests="+localSoundRequests);}
  private static void clearSound(){main.removeCallbacks(repeat);scheduled=false;if(tone!=null){tone.stop();tone=null;}}
  static synchronized void stop(Context c){pending=new JSONArray();localTestUntil=0;main.removeCallbacks(testEnd);origin="";session="";clearSound();if(c!=null){c.getSystemService(NotificationManager.class).cancel(72);c.getSystemService(NotificationManager.class).cancel(76);}}
}
