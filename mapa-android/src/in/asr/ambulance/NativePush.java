package in.asr.ambulance;

import android.content.Context;

/** The dependency-free APK clearly reports that FCM is unavailable. The FCM build supplies the optional implementation. */
final class NativePush {
  static void onSession(Context context){invoke("onSession",context);}
  static void clear(Context context){invoke("clear",context);}
  private static void invoke(String method,Context context){
    try{Class.forName("in.asr.ambulance.NativeFcmClient").getDeclaredMethod(method,Context.class).invoke(null,context.getApplicationContext());}
    catch(ClassNotFoundException absent){NativeApi.prefs(context).edit().putString("push_status","Push alerts need the Firebase-enabled MAPA build").apply();}
    catch(Exception unavailable){NativeApi.prefs(context).edit().putString("push_status","Push alerts need attention. Open MAPA and contact control.").apply();}
  }
  static String status(Context context){return NativeApi.prefs(context).getString("push_status","Push alerts have not been registered");}
}
