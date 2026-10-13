<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/SourceReceiver.php';
try {umask(0077);echo \MapaSource\SourceSync::json((new \MapaSource\SourceReceiver)->run($argv[1]??''))."\n";}
catch(\MapaSource\SourceSyncBusy $e){echo "Sync already running or cooling down.\n";}
catch(Throwable $e){fwrite(STDERR,"MAPA source sync failed; previous snapshot retained.\n");exit(1);}
