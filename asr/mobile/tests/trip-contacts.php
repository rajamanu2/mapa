<?php
// Pure normalization checks: no Laravel boot, database, provider or network.
require_once __DIR__.'/../CallerPhone.php';
require_once __DIR__.'/../TripContacts.php';
use App\MapaMobile\TripContacts;
$count=0;
function check($value,$message){global $count;if(!$value)throw new RuntimeException($message);$count++;}
check(TripContacts::empty()===['driver'=>null,'caller'=>null,'hospital'=>null],'stable nullable roles');
check(TripContacts::contact('DEMO Driver','+1 (202) 555-0142','Driver')===['name'=>'DEMO Driver','phone'=>'+12025550142'],'stored phone normalized');
check(TripContacts::contact('','98765 43210','Driver')['phone']==='+919876543210','existing Indian normalization only');
check(TripContacts::contact(null,'+12025550142','Driver')['name']==='Driver','name fallback');
foreach([null,'','bad','tel:+12025550142','+12025550142;123','+12025550142,123',"+12025550142\n",['phone'=>'+12025550142'],1234567890,'+0123456789'] as $bad)
    check(TripContacts::contact('DEMO',$bad,'Driver')===null,'missing/hostile phone hidden');
check(TripContacts::contact("<b>DEMO</b>\n",'+12025550142','Driver')['name']==='DEMO','display label stripped');
echo "Trip contacts: $count checks passed; no external requests.\n";
