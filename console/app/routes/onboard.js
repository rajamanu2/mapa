import Route from '@ember/routing/route';

export default class OnboardRoute extends Route {
    beforeModel() {
        // Existing organization accounts only; keep the deployed enrollment boundary.
        window.location.replace('/auth');
    }
}
