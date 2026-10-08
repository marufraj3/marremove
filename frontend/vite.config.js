import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    plugins: [react(), tailwindcss()],
    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      allowedHosts: ['.e2b.app'],
      proxy: {
        '/api': {
          target: env.LARAVEL_API_URL || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
      },
    },
    preview: {
      host: '0.0.0.0',
      port: 4173,
      strictPort: true,
      allowedHosts: ['.e2b.app'],
    },
    test: {
      environment: 'jsdom',
      setupFiles: './src/test/setup.js',
      restoreMocks: true,
      clearMocks: true,
    },
  };
});
