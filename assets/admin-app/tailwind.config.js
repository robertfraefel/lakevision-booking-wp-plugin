/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{ts,tsx}'],
  // Scope every utility under .lvb-admin-root so we never bleed into the host
  // theme's CSS. The React root <div> gets this class via main.tsx.
  important: '#lvb-admin-root',
  theme: {
    extend: {
      colors: {
        // Placeholder brand palette — refined per-site later via CSS variables.
        brand: {
          50:  '#f0fdfa',
          500: '#0ea5e9',
          600: '#0284c7',
          700: '#0369a1',
          900: '#0c4a6e',
        },
      },
    },
  },
  plugins: [],
};
