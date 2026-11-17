import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        // the Laravel plugin refuses to start in CI (it guards its dev server), and the tests do not need it
        ...(process.env.VITEST
            ? []
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.ts'],
                      refresh: true,
                  }),
              ]),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    ],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
