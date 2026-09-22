import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

/**
 * Front-end unit tests run without the Laravel Vite plugin: they never touch
 * the manifest or the hot file, so the build pipeline cannot leak into them.
 */
export default defineConfig({
  plugins: [vue()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },

  test: {
    environment: 'happy-dom',
    include: ['resources/js/**/*.test.ts'],
    globals: true,
    restoreMocks: true,
  },
})
