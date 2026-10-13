<?php
namespace App\MapaText;
use Illuminate\Support\Facades\Crypt;
final class PrivateLink {
 public static function encode(string $url): string {return 'enc:'.Crypt::encryptString($url);}
 public static function decode(string $stored): string {
  if(str_starts_with($stored,'enc:'))return Crypt::decryptString(substr($stored,4));
  // Compatibility with records created before encryption was introduced.
  return $stored;
 }
}
