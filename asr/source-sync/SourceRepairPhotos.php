<?php
declare(strict_types=1);
namespace MapaSource;
/** Immutable image uploads. Never overwrites or deletes an existing source photo. */
final class SourceRepairPhotos {
    private array $files=[],$created=[]; private ?string $key=null;
    public function __construct(array $all,private string $directory) {
        if(array_diff(array_keys($all),['repair_photos']))throw new CommandRejected(422,'Unexpected upload field.');
        $f=$all['repair_photos']??null;if($f===null)return;
        foreach(['name','tmp_name','error','size'] as $k)if(!is_array($f[$k]??null))throw new CommandRejected(422,'Invalid photo upload.');
        if(count($f['name'])>10)throw new CommandRejected(422,'Upload at most 10 repair photos.');
        foreach($f['name'] as $i=>$name){
            $error=$f['error'][$i]??null;if($error===UPLOAD_ERR_NO_FILE)continue;
            $tmp=$f['tmp_name'][$i]??null;
            if($error!==UPLOAD_ERR_OK||!is_string($tmp)||!is_uploaded_file($tmp))throw new CommandRejected(422,'A repair photo did not finish uploading. Retry the complete form.');
            $size=filesize($tmp);if($size===false||$size<1||$size>10*1024*1024)throw new CommandRejected(422,'Each repair photo must be at most 10 MB.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if(!$ext||getimagesize($tmp)===false)throw new CommandRejected(422,'Use a JPEG, PNG or WebP repair photo.');
            $this->files[]=['tmp'=>$tmp,'digest'=>hash_file('sha256',$tmp),'size'=>$size,'ext'=>$ext];
        }
    }
    public function fingerprints():array{return array_map(static fn($f)=>array_diff_key($f,['tmp'=>true]),$this->files);}
    public function bind(string $secret,string $requestHash):void{$this->key=hash_hmac('sha256','repair-upload:'.$requestHash,$secret);}
    public function store():array {
        if($this->key===null)throw new \RuntimeException('Upload not bound to a transaction.');
        if(!$this->files)return [];
        $parent=realpath(dirname($this->directory));
        if($parent===false||is_link(dirname($this->directory))||is_link($this->directory))throw new \RuntimeException('Reviewed repair upload directory required.');
        if(!is_dir($this->directory)&&!mkdir($this->directory,0755))throw new \RuntimeException('Repair upload directory unavailable.');
        $root=realpath($this->directory);if($root!==$parent.DIRECTORY_SEPARATOR.basename($this->directory))throw new \RuntimeException('Repair upload path changed.');
        $names=[];
        foreach($this->files as $i=>$f){
            $name='mapa_repair_'.hash_hmac('sha256',(string)$i.':'.$f['digest'],$this->key).'.'.$f['ext'];$path=$root.DIRECTORY_SEPARATOR.$name;
            if(file_exists($path)||is_link($path)){
                if(is_link($path)||!is_file($path)||filesize($path)!==$f['size']||!hash_equals($f['digest'],hash_file('sha256',$path)))throw new \RuntimeException('Existing upload differs; review required.');
                $names[]=$name;continue; // An interrupted identical request may reuse immutable content.
            }
            $out=fopen($path,'x+b');if($out===false)throw new \RuntimeException('Cannot create repair photo.');$this->created[]=$path;
            try{$in=fopen($f['tmp'],'rb');if($in===false)throw new \RuntimeException('Photo upload unavailable.');try{$bytes=stream_copy_to_stream($in,$out);}finally{fclose($in);}if($bytes!==$f['size']||!fflush($out)||(function_exists('fsync')&&!fsync($out)))throw new \RuntimeException('Photo upload incomplete.');}finally{fclose($out);}
            if(!hash_equals($f['digest'],hash_file('sha256',$path)))throw new \RuntimeException('Photo upload changed.');chmod($path,0644);$names[]=$name;
        }
        return $names;
    }
    /** Only call after a confirmed rollback; an unknown commit must retain its files. */
    public function rollback():void{foreach($this->created as $path)if(is_file($path)&&!is_link($path))unlink($path);$this->created=[];}
}
