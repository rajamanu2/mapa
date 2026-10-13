<?php
return [
    'socket_origin' => env('CONSOLE_HOST', 'http://localhost:4200'),
    'token' => env('MAPA_ADAPTER_TOKEN', env('ASR_ADAPTER_TOKEN', '')),
    'company_uuid' => env('MAPA_COMPANY_UUID', env('ASR_COMPANY_UUID', '')),
];

