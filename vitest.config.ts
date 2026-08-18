import { defineConfig } from 'vitest/config';

// Deliberately not layered on top of vite.config.ts: that config wires up the Laravel
// and Tailwind plugins for building the app, neither of which a composable's unit tests
// need, and both of which expect a full asset-build context that isn't present here.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
