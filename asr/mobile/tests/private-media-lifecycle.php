<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static array $rows=[]; public static bool $unavailable=false;
        public static function table($name){return new class {private string $id; public function where($key,$id){$this->id=$id;return $this;} public function exists(){if(DB::$unavailable)throw new \RuntimeException('DEMO disconnected QA DB');return isset(DB::$rows[$this->id]);}};}
    }
}
namespace {
require __DIR__.'/../PrivateMedia.php';
use App\MapaMobile\PrivateMedia;
use Illuminate\Support\Facades\DB;
$count=0;function check($ok,$name){global $count;if(!$ok)throw new RuntimeException($name);$count++;}
$directory=sys_get_temp_dir().'/mapa-private-media-qa-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('Writable disposable QA tmpfs required.');
$paths=[];
function track(PrivateMedia $media,string $directory,string $id):string{
    global $paths;$path=$directory.'/'.$id.'.jpg';file_put_contents($path,'DEMO synthetic private bytes');$paths[]=$path;
    $property=new ReflectionProperty(PrivateMedia::class,'uncommitted');$property->setAccessible(true);$property->setValue($media,[$path]);return $path;
}
try {
    $media=new PrivateMedia;$id='11111111-1111-4111-8111-111111111111';$path=track($media,$directory,$id);$media->rollback();check(!file_exists($path),'proven rolled-back row deletes only its own private file');
    $media=new PrivateMedia;$id='22222222-2222-4222-8222-222222222222';$path=track($media,$directory,$id);DB::$rows[$id]=true;
    // Simulate an operation throwing after its database media row committed.
    try{throw new RuntimeException('DEMO afterCommit callback failure');}catch(RuntimeException $e){$media->rollback();}
    check(file_exists($path),'afterCommit exception never deletes committed photo');
    $media=new PrivateMedia;$id='33333333-3333-4333-8333-333333333333';$path=track($media,$directory,$id);DB::$unavailable=true;$media->rollback();DB::$unavailable=false;
    check(file_exists($path),'unknown database commit state preserves possible evidence');
    $requestA=new PrivateMedia;$requestB=new PrivateMedia;
    $pathA=track($requestA,$directory,'44444444-4444-4444-8444-444444444444');$pathB=track($requestB,$directory,'55555555-5555-4555-8555-555555555555');
    $requestA->rollback();check(!file_exists($pathA)&&file_exists($pathB),'concurrent request cleanup does not touch another request');
    $requestB->committed();$requestB->rollback();check(file_exists($pathB),'successful request releases cleanup ownership');
    echo "Passed $count private media lifecycle checks.\n";
} finally { foreach($paths as $path)if(is_file($path))unlink($path);rmdir($directory); }
}
