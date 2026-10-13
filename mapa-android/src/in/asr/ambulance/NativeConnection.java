package in.asr.ambulance;

/** Native console connection only. Never inherits a previous deployment or a phone-loopback address. */
final class NativeConnection {
  static final String DEFAULT_ORIGIN="https://mapa-asr-health.pages.dev";
  static String selected(String stored){return stored==null||stored.trim().isEmpty()?DEFAULT_ORIGIN:stored;}
  static String normalize(String value) throws Exception {
    String origin=ServerAddress.normalize(value);
    String host=new java.net.URI(origin).getHost().toLowerCase(java.util.Locale.ROOT);
    if("localhost".equals(host)||host.endsWith(".localhost")||host.startsWith("127.")||"[::1]".equals(host)||"::1".equals(host)||"0.0.0.0".equals(host))
      throw new IllegalArgumentException("Use your MAPA server's reachable HTTPS address. Localhost on this phone is not the Windows computer.");
    return origin;
  }
}
