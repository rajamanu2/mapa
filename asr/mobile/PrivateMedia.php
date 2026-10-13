<?php
namespace App\MapaMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Files never enter native public uploads, asset URLs, logs or public exports. */
final class PrivateMedia
{
    private array $uncommitted=[];
    public static function jpeg(mixed $encoded): string
    {
        if(!is_string($encoded) || strlen($encoded)>2796204 || !preg_match('/^[A-Za-z0-9+\/=]+$/D',$encoded)) MobileService::fail('A JPEG camera photo is required.',422);
        $bytes=base64_decode($encoded,true);
        if($bytes===false || strlen($bytes)<64 || strlen($bytes)>EnrollmentPolicy::MAX_JPEG || substr($bytes,0,2)!=="\xff\xd8" || substr($bytes,-2)!=="\xff\xd9") MobileService::fail('Photo exceeds the 2 MB limit or is invalid.',422);
        $size=@getimagesizefromstring($bytes);
        if(!$size || $size[2]!==IMAGETYPE_JPEG || $size[0]<16 || $size[1]<16 || $size[0]>4096 || $size[1]>4096 || $size[0]*$size[1]>12000000)
            MobileService::fail('Use a JPEG camera photo between 16 and 4096 pixels.',422);
        // Decode/re-encode strips EXIF, embedded GPS, comments and trailing data.
        if(!function_exists('imagecreatefromstring')) MobileService::fail('Private camera storage requires the GD image extension.',503);
        $image=@imagecreatefromstring($bytes); if(!$image) MobileService::fail('Photo cannot be decoded.',422);
        ob_start(); $ok=imagejpeg($image,null,86); $clean=ob_get_clean(); imagedestroy($image);
        if(!$ok || !is_string($clean) || strlen($clean)>EnrollmentPolicy::MAX_JPEG) MobileService::fail('Photo cannot be stored.',422);
        return $clean;
    }
    public function put(string $jpeg,array $owner): string
    {
        $id=(string)Str::uuid(); $directory=storage_path('app/mapa-mobile-private');
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) MobileService::fail('Private camera storage unavailable.',503);
        $path=$directory.'/'.$id.'.jpg';
        if(file_put_contents($path,$jpeg,LOCK_EX)!==strlen($jpeg)) { @unlink($path); MobileService::fail('Private camera storage unavailable.',503); }
        @chmod($path,0600); $this->uncommitted[]=$path;
        DB::table('mapa_mobile_private_media')->insert(['id'=>$id,...$owner,'sha256'=>hash('sha256',$jpeg),'bytes'=>strlen($jpeg),'mime'=>'image/jpeg','created_at'=>now()]);
        return $id;
    }
    public function committed(): void { $this->uncommitted=[]; }
    public function rollback(): void {
        foreach($this->uncommitted as $path) {
            // An after-commit cache/log callback may throw after durable success.
            // Never delete referenced evidence, or guess when commit state is unknown.
            $id=basename($path,'.jpg');
            try { if(!DB::table('mapa_mobile_private_media')->where('id',$id)->exists()) @unlink($path); }
            catch(\Throwable $e) { /* Preserve inaccessible private bytes for audit when DB state cannot be read. */ }
        }
        $this->uncommitted=[];
    }
    public static function response(object $media)
    {
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$media->id)) MobileService::fail('Photo not found.',404);
        $path=storage_path('app/mapa-mobile-private/'.$media->id.'.jpg');
        if(!is_file($path) || !hash_equals($media->sha256,hash_file('sha256',$path))) MobileService::fail('Private photo unavailable.',404);
        return response()->file($path,['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline; filename="mapa-camera.jpg"']);
    }
}
