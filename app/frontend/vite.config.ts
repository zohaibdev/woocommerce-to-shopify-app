import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // In production the app is served from https://<domain>/app/ (the landing
  // page owns the root). Dev still runs at "/".
  base: process.env.NODE_ENV === "production" ? "/app/" : "/",
  server: {
    port: 5173,
    // Allow the public tunnel hostname (cloudflared/ngrok) to reach the dev server.
    // Vite otherwise rejects unknown Host headers with "Blocked request".
    allowedHosts: true,
    proxy: {
      // Forward API calls to the Laravel backend during local dev.
      // Over a tunnel, /api requests hit this frontend host and are proxied here.
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
