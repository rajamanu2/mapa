package in.asr.ambulance;
import org.json.*;
import java.util.Locale;

/** Validated OSRM geometry. Never replace a failed route with a straight line. */
final class RouteData {
  static boolean coordinate(double lat,double lng){return Double.isFinite(lat)&&Double.isFinite(lng)&&Math.abs(lat)<=90&&Math.abs(lng)<=180;}
  static String url(double lat,double lng,double targetLat,double targetLng){
    if(!coordinate(lat,lng)||!coordinate(targetLat,targetLng))throw new IllegalArgumentException("Invalid route coordinates");
    return String.format(Locale.ROOT,"https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=geojson&steps=false",lng,lat,targetLng,targetLat);
  }
  static JSONObject parse(JSONObject response)throws Exception{
    if(!"Ok".equals(response.optString("code")))throw new Exception("No driving route available. Confirm the pickup pin with control.");
    JSONObject route=response.getJSONArray("routes").getJSONObject(0),geometry=route.getJSONObject("geometry");
    if(!"LineString".equals(geometry.optString("type")))throw new Exception("Invalid route geometry");
    JSONArray coordinates=geometry.getJSONArray("coordinates"),points=new JSONArray();
    if(coordinates.length()<2||coordinates.length()>50000)throw new Exception("Invalid route size");
    for(int i=0;i<coordinates.length();i++){
      JSONArray p=coordinates.getJSONArray(i);
      if(!(p.opt(0) instanceof Number)||!(p.opt(1) instanceof Number)||!coordinate(p.getDouble(1),p.getDouble(0)))throw new Exception("Invalid route coordinate");
      points.put(new JSONObject().put("lat",p.getDouble(1)).put("lng",p.getDouble(0)));
    }
    double distance=route.getDouble("distance"),duration=route.getDouble("duration");
    if(!Double.isFinite(distance)||!Double.isFinite(duration)||distance<0||duration<0)throw new Exception("Invalid route estimate");
    return new JSONObject().put("points",points).put("distance",distance).put("duration",duration);
  }
}
