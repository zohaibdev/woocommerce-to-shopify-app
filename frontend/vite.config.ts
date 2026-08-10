import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
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
