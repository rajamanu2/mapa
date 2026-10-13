<?php
return [
    'enabled'=>env('MAPA_VOICE_ENABLED',false),
    // ASR Health operates on real calls. Historical training records retain
    // their labels; an old environment file must not enable new training calls.
    'demo_enabled'=>false,
    'live_enabled'=>env('MAPA_VOICE_LIVE_ENABLED',false),
    'manual_enabled'=>env('MAPA_VOICE_MANUAL_ENABLED',false),
    // A separately labeled, no-patient drill with real, explicitly scoped crews.
    // Creating the draft never enables automatic offers.
    'supervised_pilot_intake_enabled'=>env('MAPA_SUPERVISED_PILOT_INTAKE_ENABLED',false),
    'geocoding_enabled'=>env('MAPA_GEOCODING_ENABLED',false),
    'photon_enabled'=>env('MAPA_PHOTON_ENABLED',true),
    'bridge_secret'=>env('MAPA_VOICE_BRIDGE_SECRET',''),
    'exotel_account_sid'=>env('MAPA_EXOTEL_ACCOUNT_SID',''),
    'openai_key'=>env('OPENAI_API_KEY',''),
    'extraction_model'=>env('MAPA_VOICE_EXTRACTION_MODEL',''),
    'max_gps_age_seconds'=>180,
    'automatic_dispatch_enabled'=>env('MAPA_AUTOMATIC_DISPATCH_ENABLED',false),
    'automatic_dispatch_pilot_call_id'=>env('MAPA_AUTOMATIC_DISPATCH_PILOT_CALL_ID',''),
    'automatic_dispatch_pilot_driver_uuids'=>env('MAPA_AUTOMATIC_DISPATCH_PILOT_DRIVER_UUIDS',''),
    'automatic_offer_seconds'=>120,
];
