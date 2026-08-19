import { route as ziggyRoute } from 'ziggy-js';

declare global {
    const route: typeof ziggyRoute;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
