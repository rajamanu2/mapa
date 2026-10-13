package in.asr.ambulance;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.Map;
import org.json.JSONObject;

/** A push hint only asks the user to reopen MAPA. It never performs dispatch, ACK, GPS or trip mutations. */
public final class NativeMessagingService extends FirebaseMessagingService {
  static final String HOSPITAL="mapa-hospital-incoming-v1";
  static void hospitalChannel(android.content.Context c){NotificationChannel channel=new NotificationChannel(HOSPITAL,"MAPA hospital reports",NotificationManager.IMPORTANCE_HIGH);channel.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);c.getSystemService(NotificationManager.class).createNotificationChannel(channel);}
  @Override public void onNewToken(String token){NativeFcmClient.tokenChanged(this);}
  @Override public void onMessageReceived(RemoteMessage message){
    try{
      if(!NativeApi.connected(this))return;
      JSONObject session=NativeApi.session(this),user=session.optJSONObject("user");
      if(user==null)return;
      String role=user.optString("role");boolean hospital="hospital".equals(role);
      Map<String,String> data=message.getData();
      if(!NativePushPolicy.valid(data,NativeFcmClient.hash(session.optString("token")),System.currentTimeMillis()/1000,role))return;
      String eventId=data.get("event_id");if(eventId.equals(NativeApi.prefs(this).getString("push_last_event","")))return;
      NotificationManager manager=getSystemService(NotificationManager.class);NativeDutyService.channels(this);hospitalChannel(this);
      NotificationChannel channel=manager.getNotificationChannel(hospital?HOSPITAL:NativeDutyService.LEADS);
      if(!manager.areNotificationsEnabled()||channel==null||channel.getImportance()==NotificationManager.IMPORTANCE_NONE)return;
      boolean telugu="te".equals(NativeApi.prefs(this).getString("language","en"));
      manager.notify(hospital?78:75,new Notification.Builder(this,hospital?HOSPITAL:NativeDutyService.LEADS).setSmallIcon(R.drawable.ic_notification)
          .setContentTitle("MAPA").setContentText(hospital?"Open MAPA to review an incoming trip or patient report.":telugu?"కొత్త అత్యవసర ఆఫర్ లేదా కేటాయింపు చూడటానికి MAPA తెరవండి":"Open MAPA to review a new emergency offer or assignment")
          .setContentIntent(hospital?NativeDutyService.open(this):NativeDutyService.openOffers(this)).setCategory(Notification.CATEGORY_EVENT)
          .setVisibility(Notification.VISIBILITY_PRIVATE).setAutoCancel(true).build());
      NativeApi.prefs(this).edit().putString("push_last_event",eventId).putLong("push_last_received_at",System.currentTimeMillis())
          .putString("push_status","Push received on this phone").apply();
    }catch(Exception ignored){/* Do not log remote data, tokens or native session contents. */}
  }
}
