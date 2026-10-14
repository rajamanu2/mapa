<?php
return [
    // ASR remains the compatibility default until the explicit fleet cutover.
    'dispatch_authority' => env('MAPA_DISPATCH_AUTHORITY', 'asr'),
    // Registration alone does not expose routes or enroll users.
    'enabled' => env('MAPA_MOBILE_ENABLED', false),
    'source_sync_enabled' => env('MAPA_SOURCE_SYNC_ENABLED', false),
    'source_commands_enabled' => env('MAPA_SOURCE_COMMANDS_ENABLED', false),
    'source_workflows_enabled' => env('MAPA_SOURCE_WORKFLOWS_ENABLED', false),
    'source_masters_enabled' => env('MAPA_SOURCE_MASTERS_ENABLED', false),
    'source_connections_enabled' => env('MAPA_SOURCE_CONNECTIONS_ENABLED', false),
    'source_connections_demo_enabled' => env('MAPA_SOURCE_CONNECTIONS_DEMO_ENABLED', false),
    'source_dispatch_coordination_enabled' => env('MAPA_SOURCE_DISPATCH_COORDINATION_ENABLED', false),
    'session_minutes' => 720,
    // Explicit feature cutover after additive migration and private-storage checks.
    'enrollment_enabled' => env('MAPA_MOBILE_ENROLLMENT_ENABLED', false),
    'completion_evidence_enabled' => env('MAPA_MOBILE_COMPLETION_EVIDENCE_ENABLED', false),
    'trips_enabled' => env('MAPA_MOBILE_TRIPS_ENABLED', true),
    'hospital_handover_enabled' => env('MAPA_HOSPITAL_HANDOVER_ENABLED', false),
    'caller_tracking_enabled' => env('MAPA_CALLER_TRACKING_ENABLED', false),
    'device_ingest_enabled' => env('MAPA_DEVICE_INGEST_ENABLED', false),
    'tracking_origin' => env('MAPA_TRACKING_ORIGIN', ''),
    'road_routing' => [
        'enabled' => env('MAPA_ROAD_ROUTING_ENABLED', false),
        // Only the internal Docker service http://mapa-road-router:5000 is accepted.
        'origin' => env('MAPA_ROAD_ROUTING_ORIGIN', ''),
    ],
    'sms' => [
        'enabled' => env('MAPA_SMS_ENABLED', false),
        'gateway_origin' => env('MAPA_SMS_GATEWAY_ORIGIN', ''),
        'gateway_token' => env('MAPA_SMS_GATEWAY_TOKEN', ''),
        'ca_file' => env('MAPA_SMS_CA_FILE', ''),
        'client_certificate' => env('MAPA_SMS_CLIENT_CERTIFICATE', ''),
        'client_key' => env('MAPA_SMS_CLIENT_KEY', ''),
    ],
    'whatsapp' => [
        'enabled' => env('MAPA_WHATSAPP_ENABLED', false),
        // Explicit caller-consented send from the New Call tracker button.
        'waha_caller_enabled' => env('MAPA_CALLER_WAHA_ENABLED', false),
        'phone_id' => env('MAPA_WHATSAPP_PHONE_ID', ''),
        'token' => env('MAPA_WHATSAPP_TOKEN', ''),
        'app_secret' => env('MAPA_WHATSAPP_APP_SECRET', ''),
        'verify_token' => env('MAPA_WHATSAPP_VERIFY_TOKEN', ''),
        'api_version' => env('MAPA_WHATSAPP_API_VERSION', ''),
        'template' => env('MAPA_WHATSAPP_TEMPLATE', ''),
        'language' => env('MAPA_WHATSAPP_LANGUAGE', 'en'),
    ],
    'push' => [
        'enabled' => env('MAPA_MOBILE_PUSH_ENABLED', false),
        'hospital_enabled' => env('MAPA_HOSPITAL_PUSH_ENABLED', false),
        'project_id' => env('MAPA_FCM_PROJECT_ID', ''),
        // Read-only mounted service-account file, never bundled into APK or web assets.
        'credentials_file' => env('MAPA_FCM_CREDENTIALS_FILE', ''),
    ],
    'superadmin_user_uuids' => array_values(array_filter(explode(',', env('MAPA_MOBILE_SUPERADMIN_UUIDS', '')))),
];
