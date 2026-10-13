#!/usr/bin/env python3
"""Offline provider configuration inspection. Never sends, enables or prints secrets."""
import argparse
import json
import os
import re
from pathlib import Path


def read_object(path, limit=32768):
    if not path:
        return {}
    p = Path(path)
    if p.is_symlink() or not p.is_file() or p.stat().st_size > limit:
        raise ValueError('Invalid configuration file')
    def unique_object(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate configuration key')
            result[key] = value
        return result
    data = json.loads(p.read_text(encoding='utf-8-sig'), object_pairs_hook=unique_object)
    if not isinstance(data, dict):
        raise ValueError('Expected object')
    return data


def inspect(env, android, server):
    def match(key, pattern):
        return bool(re.fullmatch(pattern, env.get(key, '')))
    def present(key, minimum=1):
        return len(env.get(key, '').strip()) >= minimum
    def enabled(key):
        # PHP env does not interpret arbitrary strings such as "off" as false.
        return env.get(key, '').lower() in ('true', '1')
    def nonempty_string(value):
        return isinstance(value, str) and bool(value.strip())
    def contains_server_key(value):
        if isinstance(value, dict):
            return ('private_key' in value or value.get('type') == 'service_account'
                    or any(contains_server_key(v) for v in value.values()))
        if isinstance(value, list):
            return any(contains_server_key(v) for v in value)
        return False
    flags = ('MAPA_WHATSAPP_ENABLED', 'MAPA_MOBILE_PUSH_ENABLED', 'MAPA_HOSPITAL_PUSH_ENABLED')
    project = env.get('MAPA_FCM_PROJECT_ID', '')
    info = android.get('project_info', {})
    if not isinstance(info, dict):
        info = {}
    clients = android.get('client', [])
    if not isinstance(clients, list):
        clients = []
    valid_client = False
    for client in clients:
        if not isinstance(client, dict):
            break
        ci = client.get('client_info', {})
        if not isinstance(ci, dict):
            break
        ai = ci.get('android_client_info', {})
        keys = client.get('api_key', [])
        if not isinstance(ai, dict) or not isinstance(keys, list):
            break
        if ai.get('package_name') == 'in.asr.ambulance':
            # Gradle selects the first matching client, not a later valid one.
            valid_client = (nonempty_string(ci.get('mobilesdk_app_id'))
                            and bool(keys and isinstance(keys[0], dict)
                                     and nonempty_string(keys[0].get('current_key'))))
            break
    key = server.get('private_key', '')
    email = server.get('client_email', '')
    checks = {
        'tracking_origin_canonical': env.get('MAPA_TRACKING_ORIGIN', '').rstrip('/') == 'https://app.itda.asrhealth.in',
        'whatsapp_phone_id_format': match('MAPA_WHATSAPP_PHONE_ID', r'[0-9]{5,30}'),
        'whatsapp_token_present': present('MAPA_WHATSAPP_TOKEN', 20),
        'whatsapp_api_version_format': match('MAPA_WHATSAPP_API_VERSION', r'v[0-9]{1,3}\.0'),
        'whatsapp_template_format': match('MAPA_WHATSAPP_TEMPLATE', r'[a-z0-9_]{1,100}'),
        'whatsapp_language_format': match('MAPA_WHATSAPP_LANGUAGE', r'[a-z]{2,3}(?:_[A-Z]{2})?'),
        'whatsapp_webhook_secrets_present': present('MAPA_WHATSAPP_APP_SECRET', 20) and present('MAPA_WHATSAPP_VERIFY_TOKEN', 20),
        'firebase_project_format': match('MAPA_FCM_PROJECT_ID', r'[a-z][a-z0-9-]{4,61}[a-z0-9]'),
        'android_config_is_not_server_key': bool(android) and not contains_server_key(android),
        'android_package_configured': valid_client,
        'android_project_matches': bool(project) and info.get('project_id') == project and bool(re.fullmatch(r'[0-9]+', str(info.get('project_number', '')))),
        'server_project_matches': bool(project) and server.get('type') == 'service_account' and server.get('project_id') == project,
        'server_email_format': isinstance(email, str) and bool(re.fullmatch(r'[A-Za-z0-9._%+-]+@[A-Za-z0-9][A-Za-z0-9.-]*\.iam\.gserviceaccount\.com', email)),
        'server_key_envelope_present': isinstance(key, str) and key.startswith('-----BEGIN PRIVATE KEY-----\n') and key.rstrip().endswith('-----END PRIVATE KEY-----'),
        'server_mount_path_present': present('MAPA_FCM_CREDENTIALS_FILE'),
        'provider_flag_values_valid': all(env.get(k, '').lower() in ('', 'true', 'false', '1', '0') for k in flags),
        'hospital_push_flag_consistent': not enabled('MAPA_HOSPITAL_PUSH_ENABLED') or enabled('MAPA_MOBILE_PUSH_ENABLED'),
    }
    return {
        'mode': 'offline_structural_check',
        'configuration_complete': all(checks.values()),
        'live_ready': False,
        'checks': checks,
        'enabled_flags': {k: enabled(k) if env.get(k, '').lower() in ('', 'true', 'false', '1', '0') else None for k in flags},
        'still_required': ['Provider credential validation and permissions', 'Current supported Meta API version and approved matching template',
                           'Private mounts and deployed configuration in API and queue', 'Signed Firebase APK and real-phone locked-screen acceptance',
                           'Consented WhatsApp delivery and webhook receipt acceptance', 'Controlled end-to-end trip acceptance'],
        'note': 'No network requests or changes. Key envelope is not cryptographic validation. Passing structure never establishes live readiness.',
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config-json', help='Private JSON object of MAPA environment settings; defaults to process environment')
    p.add_argument('--android-config', help='Private Android google-services.json path')
    p.add_argument('--service-account', help='Private backend service-account JSON path; never Android config')
    args = p.parse_args()
    try:
        env = read_object(args.config_json) if args.config_json else dict(os.environ)
        if any(not isinstance(v, str) for v in env.values()):
            raise ValueError('Settings must be strings')
        result = inspect(env, read_object(args.android_config), read_object(args.service_account))
    except (OSError, ValueError, TypeError, RecursionError):
        print(json.dumps({'mode': 'offline_structural_check', 'configuration_complete': False, 'live_ready': False,
                          'error': 'Configuration could not be safely read. Check file type, size, JSON shape and access.'}))
        return 2
    print(json.dumps(result, indent=2))
    return 0 if result['configuration_complete'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
