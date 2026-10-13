package in.asr.ambulance;
import java.io.IOException;
import org.json.*;
final class DraftGuard {
  static JSONObject capture(JSONObject body,JSONObject displayed)throws Exception{
    if(displayed==null||!displayed.has("revision"))throw new IOException("Refresh this trip online before editing; revision is unavailable.");
    JSONObject copy=new JSONObject(body.toString());copy.put("expected_revision",displayed.getLong("revision"));copy.put("expected_state",displayed.getString("state"));return copy;
  }
  static void require(JSONObject body)throws Exception{
    if(!body.has("expected_revision")||body.optLong("expected_revision",-1)<0||body.optString("expected_state").isEmpty())throw new IOException("Reopen the trip details before saving this draft.");
  }
}
