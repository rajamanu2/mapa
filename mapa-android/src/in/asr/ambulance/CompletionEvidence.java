package in.asr.ambulance;
import org.json.*;

/** Client-side evidence freshness; server must independently check its own received GPS. */
final class CompletionEvidence {
  static boolean sameSession(JSONObject payload,String tag){return !tag.isEmpty()&&tag.equals(payload.optString("_session_tag"));}
  static boolean valid(JSONObject p,long now){
    String note=p.optString("completion_note").trim(),photo=p.optString("photo_base64");long captured=p.optLong("photo_captured_at"),fix=p.optLong("recorded_at");
    return !note.isEmpty()&&note.getBytes(java.nio.charset.StandardCharsets.UTF_8).length<=2000&&!photo.isEmpty()&&photo.length()<=1500000&&captured>0&&now>=captured&&now-captured<=120000&&fix>=captured-30000&&fix<=captured+120000&&EnrollmentState.fresh(fix,now,p.optDouble("accuracy_m"),p.optBoolean("mock_location"))&&EnrollmentState.validCoordinates(p.optDouble("latitude"),p.optDouble("longitude"));
  }
}
