import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Vite build for the LakeVision Booking admin app.
 *
 * Bundle strategy
 * ---------------
 * - Entry stays at the fixed path `dist/index.js` so the PHP plugin can
 *   enqueue it without parsing a Vite manifest. WordPress's enqueue
 *   versioning (LVB_VERSION) does cache-busting.
 * - All Page components are lazy-loaded (see App.tsx), so the entry
 *   chunk only contains routing + auth + the shell.
 * - manualChunks splits the heavy third-party libs into their own
 *   chunks so they only download when the user actually navigates to a
 *   route that needs them:
 *     vendor-react      — react / react-dom / react-router-dom (always loaded)
 *     vendor-fullcalendar — only fetched when /calendar is visited
 *     vendor-dndkit       — only fetched on /services or /intake-forms/builder
 *     vendor-sonner       — toast lib, loaded with the entry
 *
 * cssCodeSplit stays false: keeping all Tailwind utilities in a single
 * dist/index.css file is simpler than per-route CSS plumbing and our
 * Tailwind output is tiny (~18 KB).
 */
export default defineConfig({
  plugins: [react()],
  // Relative base so lazy chunk imports resolve against the entry script's
  // path, not the document root. The plugin folder name differs across
  // sites (lakevision-booking vs. lakevision-booking-wp-plugin), so we
  // cannot hard-code an absolute base at build time.
  base: './',
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
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          if (info.name && info.name.endsWith('.css')) return 'index.css';
          return 'assets/[name][extname]';
        },
        manualChunks(id) {
          if (id.includes('node_modules')) {
            if (id.includes('@fullcalendar')) return 'vendor-fullcalendar';
            if (id.includes('@dnd-kit')) return 'vendor-dndkit';
            if (id.includes('sonner')) return 'vendor-sonner';
            if (
              id.includes('/react/') ||
              id.includes('/react-dom/') ||
              id.includes('/react-router') ||
              id.includes('/scheduler/')
            ) {
              return 'vendor-react';
            }
          }
        },
      },
    },
  },
});
