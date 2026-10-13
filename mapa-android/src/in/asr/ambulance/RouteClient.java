package in.asr.ambulance;
import org.json.*;
import java.io.*;
import java.net.*;

final class RouteClient {
  static JSONObject fetch(double lat,double lng,double targetLat,double targetLng)throws Exception{
    HttpURLConnection c=(HttpURLConnection)new URL(RouteData.url(lat,lng,targetLat,targetLng)).openConnection();
    c.setConnectTimeout(12000);c.setReadTimeout(15000);c.setInstanceFollowRedirects(false);
    c.setRequestProperty("User-Agent","MAPA-Crew/1.10 pilot");c.setRequestProperty("Accept","application/json");
    // This request carries only coordinates, never MAPA tokens or order details.
    try{
      if(c.getResponseCode()!=200)throw new IOException("Routing unavailable. Check internet and retry.");
      try(InputStream in=c.getInputStream();ByteArrayOutputStream out=new ByteArrayOutputStream()){
        byte[] b=new byte[8192];int n;while((n=in.read(b))!=-1){out.write(b,0,n);if(out.size()>4000000)throw new IOException("Route response too large");}
        return RouteData.parse(new JSONObject(out.toString("UTF-8")));
      }
    }finally{c.disconnect();}
  }
}
