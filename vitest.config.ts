import path from 'path';
import { defineConfig } from 'vitest/config';

// Deliberately not layered on top of vite.config.ts: that config wires up the Laravel
// and Tailwind plugins for building the app, neither of which a composable's unit tests
// need, and both of which expect a full asset-build context that isn't present here.
export default defineConfig({
    // The one thing this config does share with vite.config.ts: the `@` alias. Without it
    // a tested module may not import the way the rest of the codebase does — the first
    // one that tried failed to resolve at run time while tsconfig and vue-tsc were happy.
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
