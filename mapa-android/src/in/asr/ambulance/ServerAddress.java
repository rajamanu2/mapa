package in.asr.ambulance;

import java.net.URI;

/** Explicit HTTPS origin configuration; credentials never follow redirects. */
final class ServerAddress {
  static String normalize(String value) throws Exception {
    URI uri = new URI(value == null ? "" : value.trim());
    if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null ||
        uri.getUserInfo() != null || uri.getQuery() != null || uri.getFragment() != null ||
        uri.getPort() == 0 || uri.getPort() > 65535 ||
        !(uri.getPath().isEmpty() || "/".equals(uri.getPath()))) {
      throw new IllegalArgumentException("Enter the HTTPS address of your MAPA control centre, without a page path.");
    }
    return new URI("https", null, uri.getHost().toLowerCase(java.util.Locale.ROOT),
      uri.getPort() == 443 ? -1 : uri.getPort(), null, null, null).toString();
  }
  static boolean sameOrigin(String origin, String candidate) {
    try {
      URI uri = new URI(candidate);
      if (uri.getUserInfo() != null || uri.getFragment() != null) return false;
      return normalize(origin).equals(normalize(new URI(uri.getScheme(), null, uri.getHost(), uri.getPort(), null, null, null).toString()));
    } catch (Exception ignored) { return false; }
  }
}