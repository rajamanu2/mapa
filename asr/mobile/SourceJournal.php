<?php
namespace MapaSource;

/** Private immutable observations; source generation time is never an action time. */
final class SourceJournal
{
    const MAX_FILES=2048;
    const MAX_TOTAL_BYTES=134217728;
    const MAX_FILE_BYTES=16777216;
    public static function observations(?array $old,array $new): array {
        $events=[];
        foreach(['drivers','ambulances'] as $dataset) {
            $before=[];
            foreach($old['datasets'][$dataset]??[] as $row) $before[(string)$row['id']]=$row;
            foreach($new['datasets'][$dataset] as $row) {
                $id=(string)$row['id'];$prior=$before[$id]??null;unset($before[$id]);$fields=[];
                if($prior) foreach($row as $field=>$value) if(!array_key_exists($field,$prior)||$value!==$prior[$field]) $fields[]=$field;
                if(!$prior||$fields) $events[]=['dataset'=>$dataset,'source_id'=>$id,
                    'kind'=>!$prior?'added':(array_intersect($fields,['phc_id','institute_type_id'])?'transfer_observed':'changed'),
                    'fields'=>$fields,'before'=>self::summary($dataset,$prior),'after'=>self::summary($dataset,$row)];
            }
            foreach($before as $id=>$prior) $events[]=['dataset'=>$dataset,'source_id'=>(string)$id,'kind'=>'removed_at_source',
                'fields'=>[],'before'=>self::summary($dataset,$prior),'after'=>null];
        }
        return $events;
    }
    private static function summary(string $dataset,?array $row): ?array {
        if($row===null)return null;
        return array_intersect_key($row,array_flip(['id',$dataset==='drivers'?'name':'vehicle_number','phc_id','institute_type_id','type_id','vehicle_type']));
    }
    public static function prepare(string $statePath,?array $old,array $snapshot,int $now): ?string {
        $previous=$old['reconciliation_head']??null;
        if($previous!==null&&!preg_match('/^[a-f0-9]{64}$/D',$previous))throw new \RuntimeException('Invalid journal head.');
        $events=self::observations($old['snapshot']??null,$snapshot);
        if(!$events)return $previous;
        $document=['version'=>1,'company_id'=>$snapshot['company_id'],'source'=>$snapshot['source'],
            'previous'=>$previous,'generated_at'=>$snapshot['generated_at'],'observed_at'=>$now,'events'=>$events];
        $body=SourceSync::json($document);$head=hash('sha256',$body);
        if(strlen($body)>self::MAX_FILE_BYTES)throw new \RuntimeException('Reconciliation generation exceeds private journal limit.');
        $directory=dirname($statePath).'/reconciliation';
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new \RuntimeException('Cannot create private journal.');
        $files=glob($directory.'/*.json');$bytes=0;
        foreach($files as $file)$bytes+=filesize($file);
        $path=$directory.'/'.$head.'.json';
        if(is_file($path)) {
            if(!hash_equals($head,hash_file('sha256',$path)))throw new \RuntimeException('Journal content mismatch.');
            return $head;
        }
        // Never evict unconsumed observations. Capacity exhaustion leaves the prior mirror intact.
        if(count($files)>=self::MAX_FILES||$bytes+strlen($body)>self::MAX_TOTAL_BYTES)throw new \RuntimeException('Private reconciliation journal requires archival.');
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if(file_put_contents($tmp,$body,LOCK_EX)!==strlen($body))throw new \RuntimeException('Cannot persist journal.');
            chmod($tmp,0600);
            if(!rename($tmp,$path))throw new \RuntimeException('Cannot commit journal.');
        } finally {if(is_file($tmp))unlink($tmp);}
        return $head;
    }
    /** Follow only the promoted chain. Unreachable files from failed saves are ignored. */
    public static function pending(string $statePath,?string $head,?string $cursor,string $company): array {
        $out=[];$seen=[];
        while($head!==$cursor) {
            if($head===null||!preg_match('/^[a-f0-9]{64}$/D',$head)||isset($seen[$head])||count($seen)>=self::MAX_FILES)
                throw new \RuntimeException('Reconciliation chain is incomplete.');
            $seen[$head]=true;$path=dirname($statePath).'/reconciliation/'.$head.'.json';
            if(!is_file($path)||filesize($path)>self::MAX_FILE_BYTES)throw new \RuntimeException('Journal unavailable.');
            $body=file_get_contents($path);
            if(!hash_equals($head,hash('sha256',$body)))throw new \RuntimeException('Journal verification failed.');
            $entry=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            if(($entry['version']??null)!==1||($entry['company_id']??null)!==$company||($entry['source']??null)!=='https://asrhealth.in'
                ||!is_array($entry['events']??null)||!array_is_list($entry['events'])||!is_int($entry['observed_at']??null))
                throw new \RuntimeException('Journal organization mismatch.');
            $out[]=['head'=>$head,'document'=>$entry];$head=$entry['previous'];
        }
        return array_reverse($out);
    }
}
