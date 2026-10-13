<?php
declare(strict_types=1);
// Existing source schema is already provisioned. Ordinary ASR requests must
// not depend on MAPA locks, reservations or a remote coordination policy.
// Schema maintenance remains a separate developer operation.
$mapaRunOriginalBootstrap = false;
return;
