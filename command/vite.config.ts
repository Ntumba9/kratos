/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In Docker Compose the browser reaches Vite through Nginx on :8080, so HMR
// must connect back on that port. Outside Docker, API calls are proxied to
// the edge proxy so the app still runs same-origin.
const hmrClientPort = process.env.VITE_HMR_CLIENT_PORT ? Number(process.env.VITE_HMR_CLIENT_PORT) : undefined
const apiTarget = process.env.KRATOS_API_PROXY ?? 'http://localhost:8080'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    allowedHosts: ['localhost', 'command'],
    hmr: hmrClientPort ? { clientPort: hmrClientPort } : undefined,
    // File-change events do not cross Docker Desktop bind mounts from
    // Windows/macOS hosts, so the containerised dev server polls instead.
    watch: process.env.VITE_USE_POLLING === 'true' ? { usePolling: true, interval: 1000 } : undefined,
    proxy: hmrClientPort
      ? undefined
      : {
          '/api': { target: apiTarget, changeOrigin: false },
          '/sanctum': { target: apiTarget, changeOrigin: false },
        },
  },
  build: {
    sourcemap: false,
    target: 'es2022',
  },
  test: {
    environment: 'jsdom',
    globals: false,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    include: ['src/**/*.test.{ts,tsx}'],
  },
})
