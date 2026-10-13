package in.asr.ambulance;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.location.*;
import android.os.*;
import android.view.*;
import android.widget.*;
import org.json.*;
import java.util.*;
import java.util.concurrent.*;

/** In-app map; screen-local GPS is independent of the explicitly started duty service. */
public class NavigationActivity extends Activity implements LocationListener {
  private TripMap map;private TextView status,estimate;private LocationManager manager;private Location fix,routeStart;
  private final Handler handler=new Handler(Looper.getMainLooper());
  private final ExecutorService worker=Executors.newSingleThreadExecutor();
  private final JSONArray trail=new JSONArray();private JSONObject route;
  private boolean resumed,routeBusy,routeEnabled,follow=true;private long lastRouteAttempt,routeTime;
  private double targetLat,targetLng;private boolean target;private String targetName;private String routeMessage="";
  private final Runnable tick=new Runnable(){public void run(){if(!resumed)return;update();handler.postDelayed(this,1000);}};
  private String t(String s){return NativeUi.t(NativeApi.prefs(this).getString("language","en"),s);}
  private int dp(int n){return (int)(n*getResources().getDisplayMetrics().density+.5f);}
  private TextView label(String value,int size){TextView v=new TextView(this);v.setText(value);v.setTextSize(size);v.setTextColor(Color.WHITE);v.setPadding(dp(14),dp(8),dp(14),dp(8));return v;}
  private void button(LinearLayout row,String label,Runnable action){Button b=new Button(this);b.setText(t(label));b.setAllCaps(false);row.addView(b,new LinearLayout.LayoutParams(0,-2,1));b.setOnClickListener(v->action.run());}
  public void onCreate(Bundle state){super.onCreate(state);getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE|WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    targetLat=getIntent().getDoubleExtra("lat",Double.NaN);targetLng=getIntent().getDoubleExtra("lng",Double.NaN);target=RouteData.coordinate(targetLat,targetLng);targetName=getIntent().getStringExtra("label");if(targetName==null)targetName="";
    LinearLayout root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setFitsSystemWindows(true);root.setBackgroundColor(Color.rgb(12,20,35));
    root.addView(label(t(target?"Route and live tracking":"Live tracking"),23));
    if(!targetName.isEmpty()){TextView name=label(targetName,14);name.setMaxLines(2);root.addView(name);}
    status=label("",14);root.addView(status);estimate=label("",14);root.addView(estimate);
    map=new TripMap(this);root.addView(map,new LinearLayout.LayoutParams(-1,0,1));
    LinearLayout controls=new LinearLayout(this);button(controls,"Follow me",()->{follow=true;update();});button(controls,"Fit map",()->{follow=false;map.recenter();});if(target)button(controls,"Road route",this::enableRoute);root.addView(controls);
    LinearLayout bottom=new LinearLayout(this);button(bottom,"Location settings",()->startActivity(new Intent(android.provider.Settings.ACTION_LOCATION_SOURCE_SETTINGS)));button(bottom,"Back",this::finish);root.addView(bottom);setContentView(root);manager=getSystemService(LocationManager.class);
    Location shared=NativeDutyService.phoneFix();if(shared!=null)fix=shared;
  }
  protected void onResume(){super.onResume();resumed=true;startLocations();handler.post(tick);}
  protected void onPause(){resumed=false;handler.removeCallbacks(tick);if(manager!=null)manager.removeUpdates(this);super.onPause();}
  protected void onDestroy(){handler.removeCallbacks(tick);worker.shutdownNow();map.destroy();super.onDestroy();}
  private void startLocations(){
    if(checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED&&checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION)!=PackageManager.PERMISSION_GRANTED){requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION},31);return;}
    for(String p:manager.getAllProviders())if(p.equals("gps")||p.equals("network")||p.equals("fused"))try{manager.requestLocationUpdates(p,2000,1,this,Looper.getMainLooper());}catch(Exception ignored){}
  }
  public void onRequestPermissionsResult(int c,String[] p,int[] g){super.onRequestPermissionsResult(c,p,g);if(c==31&&resumed&&(checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)==PackageManager.PERMISSION_GRANTED||checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION)==PackageManager.PERMISSION_GRANTED))startLocations();}
  private boolean enabled(){return Build.VERSION.SDK_INT>=28?manager.isLocationEnabled():manager.isProviderEnabled("gps")||manager.isProviderEnabled("network");}
  private boolean fresh(){if(fix==null||!enabled())return false;long age=(SystemClock.elapsedRealtimeNanos()-fix.getElapsedRealtimeNanos())/1000000;return age>=0&&age<30000&&fix.getAccuracy()<=100;}
  public void onLocationChanged(Location p){
    if(p.isFromMockProvider()||!p.hasAccuracy()||!Float.isFinite(p.getAccuracy())||p.getAccuracy()<0||!RouteData.coordinate(p.getLatitude(),p.getLongitude()))return;
    if(fix!=null&&p.getElapsedRealtimeNanos()<=fix.getElapsedRealtimeNanos())return;fix=new Location(p);
    if(fresh()){if(trail.length()>=600)trail.remove(0);trail.put(NativeApi.obj("lat",p.getLatitude(),"lng",p.getLongitude(),"recorded_at",p.getTime(),"simulated",false,"label",t("Phone location")));}
    update();
  }
  public void onProviderDisabled(String p){update();}public void onProviderEnabled(String p){update();}public void onStatusChanged(String p,int s,Bundle b){}
  private void enableRoute(){
    if(routeEnabled){calculate();return;}
    new AlertDialog.Builder(this).setTitle(t("Road route")).setMessage(t("OSRM receives your phone and destination coordinates to calculate a driving route. No account or caller details are sent. Estimates exclude live traffic. This pilot service can be unavailable."))
      .setNegativeButton(t("Cancel"),null).setPositiveButton(t("Show route"),(d,w)->{routeEnabled=true;calculate();}).show();
  }
  private void calculate(){
    if(routeBusy||!target||!fresh()){if(!fresh())routeMessage=t("Waiting for a fresh precise GPS fix");update();return;}
    routeBusy=true;lastRouteAttempt=SystemClock.elapsedRealtime();Location start=new Location(fix);routeMessage=t("Calculating road route…");
    worker.execute(()->{try{JSONObject result=RouteClient.fetch(start.getLatitude(),start.getLongitude(),targetLat,targetLng);runOnUiThread(()->{if(isDestroyed())return;routeBusy=false;route=result;routeStart=start;routeTime=System.currentTimeMillis();routeMessage="";if(resumed)update();});}catch(Exception e){runOnUiThread(()->{if(isDestroyed())return;routeBusy=false;routeMessage=t("Route unavailable. Tap Road route to retry.");if(resumed)update();});}});
  }
  private void update(){
    if(!resumed||map==null)return;
    boolean live=fresh();String position=live?t("Live phone location")+" · ±"+Math.round(fix.getAccuracy())+" m":t(enabled()?"Waiting for a fresh precise GPS fix":"Phone location is switched off");
    long receipt=NativeDutyService.lastGpsReceipt;boolean received=NativeDutyService.running&&receipt>0&&System.currentTimeMillis()>=receipt&&System.currentTimeMillis()-receipt<120000;
    status.setText(position+"\n"+t(received?"Recent server GPS receipt":NativeDutyService.running?"Server GPS receipt pending":"Background sharing is off; start duty from Home"));
    String summary=route==null?t(target?"Tap Road route for driving directions":"Tracking this phone while this screen is open"):String.format(Locale.getDefault(),"%.1f km · %.0f min",route.optDouble("distance")/1000,Math.ceil(route.optDouble("duration")/60))+" · "+t("Estimate without live traffic")+"\n"+t("Route calculated")+" "+new java.text.SimpleDateFormat("HH:mm:ss",Locale.getDefault()).format(new Date(routeTime));
    if(!routeMessage.isEmpty())summary+="\n"+routeMessage;if(!target&&!targetName.isEmpty())summary+="\n"+t("Destination pin missing. Ask control to confirm coordinates.");estimate.setText(summary);
    JSONObject point=fix==null?null:NativeApi.obj("lat",fix.getLatitude(),"lng",fix.getLongitude(),"accuracy",fix.getAccuracy(),"stale",!live,"label",t("Phone location"));
    map.update(NativeApi.obj("current",point==null?JSONObject.NULL:point,"destination",target?NativeApi.obj("lat",targetLat,"lng",targetLng,"label",targetName):JSONObject.NULL,"positions",trail,"route",route==null?new JSONArray():route.optJSONArray("points"),"follow",follow&&live,"hint",t("Blue: road route · Teal: phone trail")));
    if(routeEnabled&&!routeBusy&&live&&SystemClock.elapsedRealtime()-lastRouteAttempt>=30000&&(routeStart==null||fix.distanceTo(routeStart)>75))calculate();
  }
}
