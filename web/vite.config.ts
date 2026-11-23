/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Where the PHP backend listens during local development. docker-compose.yml does not publish a
// port for the php service yet; when it does, it must be this one, so the dev server can proxy
// /api to it (same origin as in production, HLD AD-9). Change it here and nowhere else.
const BACKEND_DEV_PORT = 8080;

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: `http://localhost:${String(BACKEND_DEV_PORT)}`,
        changeOrigin: false,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
    typecheck: {
      enabled: false,
    },
  },
});
