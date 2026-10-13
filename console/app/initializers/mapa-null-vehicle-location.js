import Vehicle from '@fleetbase/fleetops-data/models/vehicle';
import { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export function initialize() {
    // Apply before application instances create stores/model metadata. The
    // retained prebuilt bundle installer makes the same vehicle-only changes.
    Vehicle.reopen({
        location: attr('mapa-vehicle-point'),
        longitude: computed('location', function () {
            return this.location?.coordinates?.[0];
        }),
        latitude: computed('location', function () {
            return this.location?.coordinates?.[1];
        }),
    });
}

export default { name: 'mapa-null-vehicle-location', initialize };
