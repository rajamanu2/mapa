"""Refresh the existing console source offer with reviewed live-access code only."""
from pathlib import Path
import zipfile
import tempfile
import argparse
from source_offer_policy import validate_archive, validate_content

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--check-only', action='store_true', help='Validate source inputs and existing archive without changing it')
args = parser.parse_args()

root = Path(__file__).resolve().parents[2]
target = root / 'private/live-access/console/mapa-source.zip'
reviewed = [
    'fleetbase/live-access/test-demo-cleanup-read-only.py',
    'fleetbase/live-access/run-demo-cleanup.py',
    'fleetbase/live-access/retire-demo-data.php',
    'fleetbase/live-access/audit-demo-cleanup.php',
    'fleetbase/asr/mobile/PUSH-SETUP.txt',
    'mapa-android/LIVE-READINESS.md',
    'mapa-android/SELF-REGISTRATION-GUIDE.html',
    'mapa-android/README.md',
    'fleetbase/console/scripts/test-mapa-crew-admin.cjs',
    'fleetbase/console/scripts/test-mapa-crew-native.cjs',
    'fleetbase/console/scripts/test-mapa-crew-install.cjs',
    'fleetbase/console/scripts/test-mapa-crew-review-lifecycle.cjs',
    'fleetbase/console/scripts/test-mapa-ambulance-directory-dom.cjs',
    'fleetbase/console/scripts/test-mapa-null-vehicle-console.cjs',
    'fleetbase/console/app/transforms/mapa-vehicle-point.js',
    'fleetbase/console/app/initializers/mapa-null-vehicle-location.js',
    'fleetbase/live-access/install-null-vehicle-console.py',
    'fleetbase/live-access/test-null-vehicle-console.py',
    'fleetbase/live-access/overrides/vehicle-null-console-manifest.json',
    'fleetops-source/addon/components/positions-replay.js',
    'fleetops-source/addon/components/positions-replay.hbs',
    'fleetops-source/addon/components/map/leaflet-live-map.js',
    'fleetops-source/addon/services/vehicle-actions.js',
    'fleetbase/console/scripts/test-mapa-ambulance-directory.cjs',
    'fleetbase/live-access/verify-ambulance-directory-live.py',
    'fleetbase/live-access/README.md',
    'fleetbase/live-access/VALIDATION.md',
    'fleetbase/live-access/AVAILABILITY.md',
    'fleetbase/asr/compose.yml',
    'fleetbase/live-access/compose.yml',
    'fleetbase/live-access/access.mjs',
    'fleetbase/live-access/access.test.mjs',
    'fleetbase/live-access/gateway.conf.template',
    'fleetbase/live-access/proxy.conf',
    'fleetbase/live-access/relay.mjs',
    'fleetbase/live-access/render-relay.py',
    'fleetbase/live-access/prepare-console.py',
    'fleetbase/live-access/fix-account-recovery.py',
    'fleetbase/live-access/prepare-admin-recovery.py',
    'fleetbase/live-access/fix-entry-branding.py',
    'fleetbase/live-access/fix-operations-navigation.py',
    'fleetbase/live-access/fix-panel-layout.py',
    'fleetbase/live-access/fix-caller-phone.py',
    'fleetbase/live-access/fix-optional-call-sections.py',
    'fleetbase/console/scripts/install-mapa-optional-sections.mjs',
    'fleetops-source/addon/components/order/form/documents.hbs',
    'fleetops-source/addon/components/order/form/orchestrator-constraints.hbs',
    'fleetbase/live-access/caller_phone_edit.py',
    'fleetbase/console/scripts/mapa-caller-phone-display.hbs',
    'fleetops-source/addon/components/order/caller-phone.hbs',
    'fleetops-source/addon/components/order/caller-phone.js',
    'fleetops-source/app/components/order/caller-phone.js',
    'fleetops-source/addon/components/modals/order-form.hbs',
    'fleetops-source/addon/components/order/details/detail.hbs',
    'fleetops-source/addon/components/order/details/detail.js',
    'fleetops-source/addon/services/order-actions.js',
    'fleetbase/console/scripts/mapa-caller-phone-field.hbs',
    'fleetbase/console/scripts/mapa-caller-phone-methods.js.txt',
    'fleetbase/console/scripts/install-mapa-caller-phone.mjs',
    'fleetbase/console/scripts/test-mapa-caller-phone.cjs',
    'fleetbase/console/scripts/test-mapa-caller-edit.cjs',
    'fleetbase/console/Dockerfile',
    'fleetops-source/addon/components/order/form/details.hbs',
    'fleetops-source/addon/components/order/form/details.js',
    'fleetops-source/translations/en-us.yaml',
    'fleetbase/live-access/fix-product-labels.py',
    'fleetbase/live-access/fix-changelog.py',
    'fleetbase/console/public/mapa-changelog.json',
    'asr-customization/package/addon/components/modals/changelog.js',
    'fleetbase/console/scripts/apply-mapa-product-labels.mjs',
    'fleetops-source/addon/controllers/connectivity/telematics/details/attachments.js',
    'fleetops-source/addon/utils/fleet-ops-options.js',
    'fleetbase/live-access/fix-admin-branding.py',
    'fleetbase/live-access/set-mapa-mail-defaults.py',
    'fleetbase/api/config/mail.php',
    'fleetbase/console/app/components/configure/filesystem.hbs',
    'fleetbase/console/app/components/configure/mail.hbs',
    'fleetbase/console/app/components/configure/queue.hbs',
    'fleetbase/console/app/components/configure/services.js',
    'fleetbase/console/app/components/configure/notification-channels.js',
    'asr-customization/package/addon/components/chat-tray/contact-row.hbs',
    'asr-customization/package/addon/components/template-builder/query-form.hbs',
    'asr-customization/package/addon/components/template-builder/queries-panel.hbs',
    'asr-customization/package/addon/components/layout/header.js',
    'asr-customization/package/addon/components/docs-panel.hbs',
    'fleetops-source/addon/extension.js',
    'fleetbase/live-access/fix-ai-branding.py',
    'fleetbase/console/scripts/apply-mapa-ai-branding.mjs',
    'fleetbase/console/public/mapa-layout-fixes.css',
    'fleetops-source/addon/templates/operations/orders/index/new.hbs',
    'asr-customization/package/addon/components/layout/resource/panel/header.hbs',
    'fleetbase/live-access/sync-legal-assets.py',
    'fleetbase/live-access/sync-operator-assets.py',
    'fleetbase/live-access/install-loading-assets.py',
    'fleetbase/console/public/mapa-loading.js',
    'fleetbase/console/public/mapa-loading.css',
    'fleetbase/console/public/mapa-voice.js',
    'fleetbase/console/public/mapa-crew-native.js',
    'fleetbase/console/public/mapa-crew-native.css',
    'fleetbase/console/public/mapa-voice.css',
    'fleetbase/console/public/call-intake/index.html',
    'fleetbase/console/public/mapa-map/leaflet.js',
    'fleetbase/console/public/mapa-map/leaflet.css',
    'fleetbase/console/public/mapa-map/leaflet-license.txt',
    'fleetbase/console/scripts/install-mapa-voice.sh',
    'fleetbase/console/scripts/test-mapa-voice.cjs',
    'fleetbase/console/scripts/test-mapa-live-search.cjs',
    'fleetbase/console/scripts/test-mapa-dynamic-pin.cjs',
    'fleetbase/console/scripts/test-mapa-google-pickup.cjs',
    'fleetbase/asr/Dockerfile.console-voice',
    'fleetbase/console/public/mapa-legal.js',
    'fleetbase/console/public/mapa-legal.css',
    'fleetbase/console/public/mapa-notices.html',
    'fleetbase/console/app/index.html',
    'fleetbase/console/scripts/install-mapa-legal.sh',
    'fleetbase/asr/Dockerfile.console-legal',
    'fleetbase/console/scripts/mapa-customize.mjs',
    'fleetbase/live-access/provision-customer-access.py',
    'fleetbase/live-access/provision-customer-superadmin.py',
    'fleetbase/live-access/verify-caller-phone-live.py',
    'fleetbase/live-access/release-mobile-demo-assignment.py',
    'fleetbase/live-access/verify-customer-features.py',
    'fleetbase/live-access/enable-customer-call-intake.py',
    'fleetbase/console/public/images/mapa-logo.svg',
    'fleetbase/console/public/favicon/site.webmanifest',
    'fleetbase/console/translations/en-us.yaml',
    'fleetbase/console/app/components/onboarding/form.hbs',
    'fleetbase/console/app/components/onboarding/form.js',
    'fleetbase/console/app/components/onboarding/verify-email.hbs',
    'fleetbase/console/app/components/onboarding/verify-email.js',
    'fleetbase/console/app/templates/auth.hbs',
    'fleetbase/console/app/templates/invite.hbs',
    'fleetbase/console/app/templates/install.hbs',
    'fleetbase/console/app/templates/catch.hbs',
    'fleetbase/console/app/templates/invite/for-user.hbs',
    'fleetbase/console/app/templates/console/admin/branding.hbs',
    'fleetbase/console/app/controllers/console/admin/branding.js',
    'fleetbase/live-access/verify-live.py',
    'fleetbase/live-access/verify-crew-registration-live.py',
    'fleetbase/live-access/verify-native-crew-live.py',
    'fleetbase/live-access/CREW-REVIEW-VALIDATION.md',
    'fleetbase/live-access/package-source.py',
    'fleetbase/live-access/source_offer_policy.py',
    'fleetbase/live-access/tests/test_source_offer_policy.py',
    'fleetbase/live-access/tests/test_console_static_boundary.py',
    'fleetbase/console/nginx.conf',
    'fleetbase/console/app/initializers/load-intl-polyfills.js',
    'fleetbase/console/Dockerfile.production-review',
    'fleetbase/console/ember-cli-build.js',
    'fleetbase/console/package.json',
    'fleetbase/console/pnpm-lock.yaml',
    'fleetbase/console/pnpm-workspace.yaml',
    'fleetbase/console/scripts/prepare-production-review.py',
    'fleetbase/console/scripts/finalize-production-review.py',
    'fleetbase/console/scripts/install-mapa-production-guards.mjs',
    'fleetops-source/server/src/Http/Controllers/Internal/v1/LiveController.php',
    'fleetbase/live-access/overrides/LiveController.php',
    'fleetops-source/server/src/Http/Resources/v1/Index/Vehicle.php',
    'fleetbase/live-access/overrides/VehicleIndexResource.php',
    'fleetbase/asr/import/tests/validate-private-plan.py',
    'fleetbase/asr/import/tests/run-native.py',
    'fleetbase/asr/import/tests/policy.php',
    'fleetbase/asr/import/tests/offline.py',
    'fleetbase/asr/import/tests/native.php',
    'fleetbase/asr/import/test_stage.py',
    'fleetbase/asr/import/stage.py',
    'fleetbase/asr/import/snapshot-native.php',
    'fleetbase/asr/import/README.md',
    'fleetbase/asr/import/inspect-native.php',
    'fleetbase/asr/import/ImportPlan.php',
    'fleetbase/asr/import/ImportApply.php',
    'fleetbase/asr/import/capture-snapshot.py',
    'fleetbase/asr/import/apply.py',
    'fleetbase/asr/import/apply-native.php',
    'fleetbase/asr/import/VerifyInventory.php',
    'fleetbase/asr/import/verify-native.php',
    'fleetbase/asr/import/verify.py',
    'fleetbase/asr/import/tests/verification.php',
    'fleetbase/asr/import/SupplementPlan.php',
    'fleetbase/asr/import/SupplementApply.php',
    'fleetbase/asr/import/supplement-native.php',
    'fleetbase/asr/import/supplement.py',
    'fleetbase/asr/import/stage-supplement.py',
    'fleetbase/asr/import/tests/supplement-policy.php',
    'fleetbase/asr/import/tests/supplement-offline.py',
    'fleetbase/asr/import/tests/supplement-native.php',
    'fleetbase/asr/import/tests/run-supplement-native.py',
    'fleetops-source/server/src/Http/Resources/v1/VehicleWithoutDriver.php',
    'fleetops-source/server/src/Http/Resources/v1/Vehicle.php',
    'fleetbase/live-access/overrides/vehicle-null-location-manifest.json',
    'fleetbase/live-access/overrides/VehicleWithoutDriverResource.php',
    'fleetbase/live-access/overrides/VehicleResource.php',
    'fleetbase/live-access/install-null-vehicle-location.py',
    'fleetbase/asr/Dockerfile',
    'fleetbase/live-access/overrides/SettingController.php',
    'fleetbase/live-access/overrides/OrderResource.php',
    'fleetbase/live-access/overrides/CurrentJobResource.php',
    'fleetbase/cloud/runtime-config.mjs',
    'fleetbase/client-preview/fix-viewer-navigation.py',
    'fleetbase/client-preview/fix-osm-attribution.py',
    'fleetbase/client-preview/map-credit.js',
    'fleetops-source/server/src/Http/Controllers/Internal/v1/SettingController.php',
]
for module in ('mobile','voice','sms'):
    for source in sorted((root/'fleetbase/asr'/module).rglob('*')):
        if source.is_file() and source.suffix in ('.php','.md','.py','.json','.txt') and '__pycache__' not in source.parts:
            reviewed.append(source.relative_to(root).as_posix())
for source in sorted((root/'fleetbase/console/public/track').rglob('*')):
    if source.is_file() and source.suffix in ('.html', '.js', '.css'):
        reviewed.append(source.relative_to(root).as_posix())
for name in reviewed:
    path = root / name
    if (not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(root.resolve())
            or any(parent.is_symlink() for parent in path.parents if parent != root)):
        raise SystemExit('Missing reviewed source: ' + name)
    try:
        validate_content(name, path.read_bytes())
    except ValueError as exc:
        raise SystemExit('Source release blocked: ' + name + ' (' + str(exc) + ')') from None
# Validate carried-forward entries too; an old ZIP is not automatically trusted.
with zipfile.ZipFile(target) as old:
    validate_archive(old)
    if args.check_only:
        print('Source release safety checks passed:', len(old.infolist()), 'existing entries and', len(set(reviewed)), 'current inputs. No archive changed; corresponding-source completeness is a separate release check.')
        raise SystemExit(0)
    with tempfile.NamedTemporaryFile(prefix='mapa-source-', suffix='.zip', dir=target.parent, delete=False) as handle:
        temporary = Path(handle.name)
    try:
        with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED) as new:
            for entry in old.infolist():
                if entry.filename not in reviewed:
                    new.writestr(entry, old.read(entry))
            for name in sorted(set(reviewed)):
                new.writestr(name, (root / name).read_bytes())
        with zipfile.ZipFile(temporary) as check:
            validate_archive(check)
            if check.testzip() is not None or not set(reviewed).issubset(check.namelist()):
                raise SystemExit('Source archive verification failed.')
        # On Windows, close the old archive before atomic replacement.
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
temporary.replace(target)
print('Updated source offer with', len(set(reviewed)), 'reviewed files; release safety checks passed. Corresponding-source completeness still requires release review.')
