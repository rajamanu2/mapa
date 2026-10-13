<?php
namespace App\MapaMobile { class MobileService {public static function fail(string $message,int $status=403):never{throw new \RuntimeException($message,$status);}} }
namespace {
require __DIR__.'/../Policy.php';require __DIR__.'/../EnrollmentPolicy.php';require __DIR__.'/../PrivateMedia.php';
use App\MapaMobile\PrivateMedia as M;
$count=0;function check($ok,$name){global $count;if(!$ok)throw new RuntimeException($name);$count++;}
function denied($encoded){try{M::jpeg($encoded);throw new RuntimeException('Expected invalid photo rejection');}catch(RuntimeException $e){check($e->getCode()===422,'invalid JPEG rejected');}}
if(!function_exists('imagecreatetruecolor'))throw new RuntimeException('GD required for private media acceptance tests');
$image=imagecreatetruecolor(32,32);imagefill($image,0,0,imagecolorallocate($image,0,100,110));ob_start();imagejpeg($image,null,80);$jpeg=ob_get_clean();imagedestroy($image);
$clean=M::jpeg(base64_encode($jpeg));check(getimagesizefromstring($clean)[2]===IMAGETYPE_JPEG,'valid camera JPEG re-encoded');
// EXIF-like APP1 data is stripped, including private metadata and source GPS strings.
$private='Exif'.chr(0).chr(0).'DEMO private metadata GPS 18.326,82.874';
$withExif=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($private)+2).$private.substr($jpeg,2);
$sanitized=M::jpeg(base64_encode($withExif));check(!str_contains($sanitized,$private)&&!str_contains($sanitized,'Exif'),'EXIF and private metadata stripped');
foreach([null,'data:image/jpeg;base64,'.base64_encode($jpeg),'***',base64_encode('DEMO not an image'),base64_encode(substr($jpeg,0,-10)),base64_encode($jpeg.'<script>DEMO polyglot</script>'),base64_encode(str_repeat('a',2097153))] as $encoded)denied($encoded);
$image=imagecreatetruecolor(32,32);ob_start();imagepng($image);$png=ob_get_clean();imagedestroy($image);denied(base64_encode($png));
check(!str_contains(file_get_contents(__DIR__.'/../PrivateMedia.php'),"public_path("),'private storage never uses public path');
check(str_contains(file_get_contents(__DIR__.'/../PrivateMedia.php'),"storage_path('app/mapa-mobile-private") ,'dedicated private storage');
check(str_contains(file_get_contents(__DIR__.'/../PrivateMedia.php'),"'X-Content-Type-Options'=>'nosniff'") ,'media cannot MIME sniff');
echo "Passed $count private JPEG checks.\n";
}
