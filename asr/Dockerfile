ARG MAPA_API_BASE=fleetbase/fleetbase-api:latest
FROM ${MAPA_API_BASE}
WORKDIR /fleetbase/api
COPY live-access/overrides/VehicleResource.php vendor/fleetbase/fleetops-api/server/src/Http/Resources/v1/Vehicle.php
COPY live-access/overrides/VehicleWithoutDriverResource.php vendor/fleetbase/fleetops-api/server/src/Http/Resources/v1/VehicleWithoutDriver.php
COPY live-access/overrides/VehicleIndexResource.php vendor/fleetbase/fleetops-api/server/src/Http/Resources/v1/Index/Vehicle.php
COPY live-access/overrides/LiveController.php vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Internal/v1/LiveController.php
COPY asr/mobile app/MapaMobile
COPY asr/voice app/MapaVoice
COPY asr/text app/MapaText
COPY asr/maintenance app/MapaMaintenance
COPY asr/MapaServiceProvider.php app/Providers/MapaServiceProvider.php
COPY asr/MapaFleetController.php app/Http/Controllers/MapaFleetController.php
COPY asr/config.php config/asr.php
COPY asr/config.php config/mapa.php
COPY asr/register-provider.php /tmp/asr-register-provider.php
RUN php /tmp/asr-register-provider.php && php -l app/Providers/MapaServiceProvider.php && php -l app/Http/Controllers/MapaFleetController.php && find app/MapaMobile app/MapaVoice app/MapaMaintenance -name '*.php' -exec php -l {} \; && composer dump-autoload --no-scripts && php artisan config:clear && php artisan route:clear
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--max-requests=1000", "--port=8000", "--host=0.0.0.0"]

