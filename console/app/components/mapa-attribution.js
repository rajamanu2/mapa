// Copyright (c) 2026 MAPA.DEV. Original MAPA presentation component.
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapaAttributionComponent extends Component {
    @service modalsManager;

    @action openNotices() {
        // A separate first-party legal view can be updated without rebuilding Ember.
        const request = new CustomEvent('mapa:open-legal', { cancelable: true });
        if (!window.dispatchEvent(request)) return;
        this.modalsManager.show('modals/fleetbase-legal-notice', {
            title: 'MAPA.DEV — open-source notices',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
        });
    }
}
