package in.asr.ambulance;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.content.pm.*;
import android.location.*;
import android.media.*;
import android.os.*;
import org.json.*;
import java.util.*;
import java.util.concurrent.*;

/** Explicit foreground GPS for the signed-in native driver and linked ambulance. */
public class NativeDutyService extends Service implements LocationListener {
  static volatile boolean running=false;static volatile String health="Location sharing is off";static volatile long lastGpsReceipt=0;static volatile boolean mockLocation=false;
  private static volatile Location latestFix,acceptedFix;private volatile Location fix;private volatile boolean destroyed;
  private LocationManager manager;private ScheduledExecutorService worker;private String binding="";
  private final Object lifecycle=new Object();private NativeGpsState gps;
  static final String DUTY="mapa-native-duty",LEADS="mapa-native-orders";
  static NativeGpsState gpsState(Context c){return new NativeGpsState(new PrivateStore(c,"mapa-native-gps-v1"));}
  static String local(Context c,String value){return NativeUi.t(NativeApi.prefs(c).getString("language","en"),value);}
  static Location phoneFix(){Location value=latestFix;return value==null?null:new Location(value);}
  static PendingIntent open(Context c){return PendingIntent.getActivity(c,7,new Intent(c,NativeMainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP|Intent.FLAG_ACTIVITY_CLEAR_TOP),PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);}
  static PendingIntent openOffers(Context c){return PendingIntent.getActivity(c,75,new Intent(c,NativeMainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP|Intent.FLAG_ACTIVITY_CLEAR_TOP).putExtra("dispatch_offers",true),PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);}
  static void channels(Context c){NotificationManager n=c.getSystemService(NotificationManager.class);n.createNotificationChannel(new NotificationChannel(DUTY,"MAPA location sharing",NotificationManager.IMPORTANCE_LOW));NotificationChannel orders=new NotificationChannel(LEADS,"MAPA assigned ambulance calls",NotificationManager.IMPORTANCE_HIGH);orders.enableVibration(true);orders.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());orders.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);n.createNotificationChannel(orders);}
  public void onCreate(){super.onCreate();channels(this);gps=gpsState(this);}
  private Notification notification(){return new Notification.Builder(this,DUTY).setSmallIcon(R.drawable.ic_notification).setContentTitle("MAPA · "+local(this,"Location sharing")).setContentText(local(this,health)).setContentIntent(open(this)).setOngoing(true).setCategory(Notification.CATEGORY_SERVICE).setVisibility(Notification.VISIBILITY_PRIVATE).build();}
  public int onStartCommand(Intent intent,int flags,int id){
    if(running)return START_NOT_STICKY;
    if(!NativeApi.connected(this)||(checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED&&checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION)!=PackageManager.PERMISSION_GRANTED)){health="Sign in and allow location before starting";stopSelf();return START_NOT_STICKY;}
    try{
      health="Connecting to the native MAPA console";if(Build.VERSION.SDK_INT>=29)startForeground(71,notification(),ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);else startForeground(71,notification());running=true;lastGpsReceipt=0;latestFix=null;acceptedFix=null;mockLocation=false;NativeApi.prefs(this).edit().putBoolean("duty_requested",true).commit();getSystemService(NotificationManager.class).cancel(74);
      manager=getSystemService(LocationManager.class);
      for(String provider:manager.getAllProviders())if(LocationManager.GPS_PROVIDER.equals(provider)||LocationManager.NETWORK_PROVIDER.equals(provider)||"fused".equals(provider)){try{manager.requestLocationUpdates(provider,5000,0,this,Looper.getMainLooper());Location cached=manager.getLastKnownLocation(provider);if(cached!=null&&SystemClock.elapsedRealtimeNanos()-cached.getElapsedRealtimeNanos()<30000000000L)onLocationChanged(cached);}catch(SecurityException ignored){}}
      worker=Executors.newSingleThreadScheduledExecutor();worker.scheduleWithFixedDelay(this::sync,0,10,TimeUnit.SECONDS);
    }catch(Exception e){health="Could not start foreground GPS. Check phone permissions.";stopSelf();}
    return START_NOT_STICKY;
  }
  static synchronized void announce(Context c,JSONObject snapshot)throws Exception{NativeAlerts.update(c,snapshot);}
  static Location completionFix(long capturedAt){Location value=acceptedFix;if(value==null||mockLocation)return null;long now=System.currentTimeMillis(),age=(SystemClock.elapsedRealtimeNanos()-value.getElapsedRealtimeNanos())/1000000;if(age<0||age>30000||value.getTime()<capturedAt-30000||value.getTime()>capturedAt+120000||!EnrollmentState.fresh(value.getTime(),now,value.getAccuracy(),false))return null;return new Location(value);}
  private void sync(){
    if(destroyed)return;
    try{
      NativeApi.BoundSession bound=new NativeApi.BoundSession(this,true);
      JSONObject snapshot=NativeApi.call(this,bound,"GET","/snapshot",null);NativeProtocol.requireSnapshot(snapshot);NativeApi.validate(this,snapshot);if(destroyed)return;
      if(!NativeProtocol.canDuty(snapshot))throw new NativeApi.Failure("Crew or ambulance access changed. Reopen MAPA.",403);
      String current=bound.origin+"|"+NativeProtocol.scope(snapshot)+"|"+NativeProtocol.vehicle(snapshot)+"|"+bound.tag();if(!binding.isEmpty()&&!binding.equals(current))throw new NativeApi.Failure("Your crew, ambulance or phone session changed. Restart location sharing.",403);binding=current;
      if(!snapshot.getJSONObject("driver").optBoolean("online")){NativeApi.prefs(this).edit().putBoolean("duty_requested",false).commit();health="Control has taken this driver offline";stopSelf();return;}
      NativeOutbox.drain(this,snapshot);announce(this,snapshot);Location value=fix;long age=value==null?Long.MAX_VALUE:(SystemClock.elapsedRealtimeNanos()-value.getElapsedRealtimeNanos())/1000000;
      if(!enabled()){health="Phone location is switched off";}
      else{
        JSONObject candidate=null;
        if(value!=null&&age>=0&&age<120000&&value.getAccuracy()<=100){candidate=NativeApi.obj("latitude",value.getLatitude(),"longitude",value.getLongitude(),"recorded_at",value.getTime(),"accuracy_m",value.getAccuracy(),"mock_location",false);if(value.hasBearing())candidate.put("heading",value.getBearing());if(value.hasSpeed())candidate.put("speed_mps",value.getSpeed());}
        JSONObject payload;synchronized(lifecycle){if(destroyed)return;payload=gps.next(current,candidate);}
        if(payload==null){health=mockLocation?"Mock location is not shared":"Connected · waiting for a new accurate GPS fix";}
        else{
          try{
            if(destroyed)return;JSONObject receipt=NativeApi.post(this,bound,"/gps",payload);if(!Boolean.TRUE.equals(receipt.opt("ok")))throw new NativeApi.Failure("MAPA has not confirmed this position.",502);
            synchronized(lifecycle){if(destroyed)return;gps.resolved(current,payload.getString("action_id"));}
            Location accepted=new Location("MAPA receipt");accepted.setLatitude(payload.getDouble("latitude"));accepted.setLongitude(payload.getDouble("longitude"));accepted.setAccuracy((float)payload.getDouble("accuracy_m"));accepted.setTime(payload.getLong("recorded_at"));accepted.setElapsedRealtimeNanos(SystemClock.elapsedRealtimeNanos()-Math.max(0,System.currentTimeMillis()-accepted.getTime())*1000000L);acceptedFix=accepted;lastGpsReceipt=System.currentTimeMillis();health="Position receipt confirmed · waiting for a new fix";
          }catch(NativeApi.Failure failure){
            // A definite rejection is not a receipt to retry forever. Uncertain responses
            // keep the exact encrypted payload/key, even across process restarts.
            if(failure.status>=400&&failure.status<500&&failure.status!=401&&failure.status!=403&&!ActionQueue.retryable(failure.status)){synchronized(lifecycle){if(!destroyed)gps.resolved(current,payload.getString("action_id"));}}
            throw failure;
          }
        }
      }
    }catch(NativeApi.Failure e){health=e.getMessage();if(e.status==401||e.status==403){NativeApi.prefs(this).edit().putBoolean("duty_requested",false).commit();try{synchronized(lifecycle){gps.clear();}}catch(Exception ignored){}getSystemService(NotificationManager.class).notify(73,new Notification.Builder(this,LEADS).setSmallIcon(R.drawable.ic_notification).setContentTitle("MAPA · "+local(this,"Location needs attention")).setContentText(local(this,"Open MAPA to resume your shift")).setContentIntent(open(this)).setAutoCancel(true).setVisibility(Notification.VISIBILITY_PRIVATE).build());stopSelf();}}
    catch(Exception e){health="Connection unavailable · retrying. Contact control if urgent.";}
    if(!destroyed)getSystemService(NotificationManager.class).notify(71,notification());
  }
  private boolean enabled(){return manager!=null&&(Build.VERSION.SDK_INT>=28?manager.isLocationEnabled():manager.isProviderEnabled(LocationManager.GPS_PROVIDER)||manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER));}
  public void onLocationChanged(Location value){if(value!=null&&(Build.VERSION.SDK_INT>=31?value.isMock():value.isFromMockProvider())){mockLocation=true;fix=null;latestFix=null;acceptedFix=null;health="Mock location is not shared";return;}if(value==null||!value.hasAccuracy()||!Double.isFinite(value.getLatitude())||!Double.isFinite(value.getLongitude())||!Float.isFinite(value.getAccuracy())||value.getAccuracy()<0)return;Location previous=fix;if(previous==null||value.getElapsedRealtimeNanos()>previous.getElapsedRealtimeNanos()){mockLocation=false;fix=new Location(value);latestFix=new Location(value);}}
  public void onProviderDisabled(String provider){if(!enabled()){fix=null;latestFix=null;acceptedFix=null;health="Phone location is switched off";}}
  public void onProviderEnabled(String provider){health="Waiting for a fresh GPS fix";}
  public void onStatusChanged(String provider,int status,Bundle data){}
  public void onDestroy(){synchronized(lifecycle){destroyed=true;if(!NativeApi.prefs(this).getBoolean("duty_requested",false))try{gps.clear();}catch(Exception ignored){}}running=false;if(worker!=null)worker.shutdownNow();if(manager!=null)manager.removeUpdates(this);fix=null;latestFix=null;acceptedFix=null;if(!NativeMainActivity.visible)NativeAlerts.stop(this);stopForeground(STOP_FOREGROUND_REMOVE);NativeRecoveryReceiver.remind(this);super.onDestroy();}
  public IBinder onBind(Intent intent){return null;}
}
