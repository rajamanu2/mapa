<?php
declare(strict_types=1);
require_once __DIR__.'/SourceCoordinationPolicy.php';
// MAPA display-only integration: ASR owns all fleet and trip operations.
// Original ASR login/permission checks remain in each calling handler.
// No MAPA lock, reservation, schema gate, transaction or status mutation.
return;
