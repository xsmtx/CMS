import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.ts'],
      refresh: ['routes/**', 'resources/views/**', 'lang/**'],
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    tailwindcss(),
  ],

  server: {
    // The application runs in a container while Vite runs on the host, so
    // the dev server must listen on all interfaces and advertise a host the
    // browser can reach.
    host: '0.0.0.0',
    hmr: {
      host: process.env.VITE_HMR_HOST ?? 'localhost',
    },
    watch: {
      ignored: ['**/storage/framework/views/**', '**/vendor/**'],
    },
  },
})
