import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Vite build for the LakeVision Booking admin app.
 *
 * - Outputs flat dist/index.js + dist/index.css (no content hashing). The
 *   WordPress plugin enqueues those exact filenames with LVB_VERSION as the
 *   cache-busting query string.
 * - Bundles everything into single chunks for first pass; code-splitting
 *   per route will land later when bundle size is measured.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    target: 'es2019',
    cssCodeSplit: false,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.tsx'),
      output: {
        entryFileNames: 'index.js',
        chunkFileNames: 'chunks/[name].js',
        assetFileNames: (info) => {
          if (info.name && info.name.endsWith('.css')) return 'index.css';
          return 'assets/[name][extname]';
        },
      },
    },
  },
});
