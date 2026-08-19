import { route as ziggyRoute } from 'ziggy-js';

declare module 'ziggy-js' {
    interface TypeConfig {
        strictRouteNames: true;
    }
}

declare global {
    const route: typeof ziggyRoute;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
