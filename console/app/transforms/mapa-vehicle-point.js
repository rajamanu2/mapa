import Transform from '@ember-data/serializer/transform';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';

export default class MapaVehiclePointTransform extends Transform {
    deserialize(value) { return value === null || value === undefined ? null : new Point(value); }
    serialize(value) { return value === null || value === undefined ? null : new Point(value); }
}
