import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin-users.js', 'resources/js/email-tracking.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        ...(process.env.VITE_DEV_SERVER_HOST ? {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            hmr: { host: process.env.VITE_DEV_SERVER_HOST },
        } : {}),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
