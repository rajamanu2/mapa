package in.asr.ambulance;
import android.content.*;
import android.app.*;
public class NativeRecoveryReceiver extends BroadcastReceiver {
  public void onReceive(Context c,Intent intent){if(Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction())||Intent.ACTION_MY_PACKAGE_REPLACED.equals(intent.getAction()))remind(c);}
  static void remind(Context c){if(!NativeApi.prefs(c).getBoolean("duty_requested",false))return;NotificationManager n=c.getSystemService(NotificationManager.class);if(!n.areNotificationsEnabled())return;NativeDutyService.channels(c);n.notify(74,new Notification.Builder(c,NativeDutyService.LEADS).setSmallIcon(R.drawable.ic_notification).setContentTitle("MAPA").setContentText(NativeDutyService.local(c,"Open MAPA to resume your shift")).setContentIntent(NativeDutyService.open(c)).setAutoCancel(true).setVisibility(Notification.VISIBILITY_PRIVATE).build());}
}