package in.asr.ambulance;
import org.json.*;
public class RouteDataTest {
  static int count;
  static void check(boolean b,String s){count++;if(!b)throw new AssertionError(s);}
  static JSONObject valid()throws Exception{return new JSONObject("{\"code\":\"Ok\",\"routes\":[{\"distance\":1234,\"duration\":120,\"geometry\":{\"type\":\"LineString\",\"coordinates\":[[82.1,18.1],[82.2,18.2]]}}]}");}
  static void rejects(JSONObject o)throws Exception{boolean rejected=false;try{RouteData.parse(o);}catch(Exception e){rejected=true;}check(rejected,"Malformed routing response rejected");}
  public static void main(String[] args)throws Exception{
    check(RouteData.coordinate(0,0),"Zero coordinates valid");check(!RouteData.coordinate(91,0),"Latitude bounded");check(!RouteData.coordinate(0,181),"Longitude bounded");check(!RouteData.coordinate(Double.NaN,0),"NaN rejected");check(!RouteData.coordinate(0,Double.POSITIVE_INFINITY),"Infinity rejected");
    check(RouteData.url(18.1,82.1,18.2,82.2).contains("82.100000,18.100000;82.200000,18.200000"),"OSRM uses longitude first");
    JSONObject route=RouteData.parse(valid());check(route.getJSONArray("points").getJSONObject(0).getDouble("lat")==18.1,"GeoJSON converted to map latitude");check(route.getDouble("distance")==1234,"Distance retained in meters");check(route.getDouble("duration")==120,"Duration retained in seconds");
    rejects(new JSONObject().put("code","NoRoute"));rejects(new JSONObject().put("code","Ok").put("routes",new JSONArray()));
    JSONObject bad=valid();bad.getJSONArray("routes").getJSONObject(0).put("distance",-1);rejects(bad);
    bad=valid();bad.getJSONArray("routes").getJSONObject(0).put("duration",-1);rejects(bad);
    bad=valid();bad.getJSONArray("routes").getJSONObject(0).getJSONObject("geometry").put("type","Point");rejects(bad);
    bad=valid();bad.getJSONArray("routes").getJSONObject(0).getJSONObject("geometry").put("coordinates",new JSONArray("[[82,18]]"));rejects(bad);
    bad=valid();bad.getJSONArray("routes").getJSONObject(0).getJSONObject("geometry").put("coordinates",new JSONArray("[[82,91],[83,18]]"));rejects(bad);
    bad=valid();bad.getJSONArray("routes").getJSONObject(0).getJSONObject("geometry").put("coordinates",new JSONArray("[[\"82\",18],[83,18]]"));rejects(bad);
    System.out.println("Route data: "+count+" assertions passed");
  }
}
