package in.asr.ambulance;

import android.content.Context;
import android.webkit.*;
import java.io.*;
import java.nio.charset.StandardCharsets;
import org.json.JSONObject;

/** Bundled map code receives coordinates only; no API session or native bridge. */
final class TripMap extends WebView {
  private boolean ready=false;
  private String pending="{}";
  TripMap(Context context){
    super(context);
    getSettings().setJavaScriptEnabled(true);
    getSettings().setAllowFileAccess(false);
    getSettings().setAllowContentAccess(false);
    getSettings().setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
    getSettings().setUserAgentString(getSettings().getUserAgentString()+" MAPA-Console/1.9");
    setWebViewClient(new WebViewClient(){
      public boolean shouldOverrideUrlLoading(WebView v,WebResourceRequest request){return true;}
      public void onPageFinished(WebView v,String url){ready=true;send();}
    });
    try{
      // Raw resources use distinct basenames for JS and CSS.
      String html="<!doctype html><html><head><meta charset=\"UTF-8\"><meta name='viewport' content='width=device-width,initial-scale=1'><meta http-equiv='Content-Security-Policy' content=\"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src https://tile.openstreetmap.org data:;\"><style>"+read(context,R.raw.leaflet_style)+"html,body,#map{height:100%;margin:0}#hint{position:absolute;z-index:900;bottom:25px;left:8px;right:8px;background:#fff;padding:8px;font:13px sans-serif;color:#234;border-radius:8px}</style></head><body><div id='map'></div><div id='hint'></div><script>"+read(context,R.raw.leaflet)+"</script><script>"+read(context,R.raw.trip_map)+"</script></body></html>";
      loadDataWithBaseURL(NativeApi.server(context)+"/android-map/",html,"text/html","UTF-8",null);
    }catch(Exception e){loadData("Map unavailable. Use Navigate for directions.","text/plain","UTF-8");}
  }
  private static String read(Context c,int resource)throws IOException{try(InputStream input=c.getResources().openRawResource(resource);ByteArrayOutputStream out=new ByteArrayOutputStream()){byte[] b=new byte[8192];int n;while((n=input.read(b))!=-1)out.write(b,0,n);return new String(out.toByteArray(),StandardCharsets.UTF_8);}}
  void update(JSONObject state){pending=state.toString();send();}
  private void send(){if(ready)evaluateJavascript("(function(){if(!window.updateTrip)return 'Map unavailable';window.updateTrip("+pending+");return window.describeTrip?window.describeTrip():'MAPA map';})()",value->{try{String description=new org.json.JSONArray("["+value+"]").optString(0,"MAPA map");if(!description.equals(getContentDescription()))setContentDescription(description);}catch(Exception ignored){}});}
  void recenter(){if(ready)evaluateJavascript("window.recenterTrip&&window.recenterTrip()",null);}
}



