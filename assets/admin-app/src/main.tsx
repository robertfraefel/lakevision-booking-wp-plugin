import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import { App } from './App';

const root = document.getElementById('lvb-admin-root');
if (root) {
  // Apply scoping class so Tailwind utilities (configured with
  // `important: '#lvb-admin-root'`) take effect inside the React tree.
  root.classList.add('lvb-admin-root');

  // Imperatively widen any ancestor that constrains us. The CSS in
  // index.css already targets common WordPress container classes via
  // :has(), but custom themes (or the websitestudio theme-generator)
  // sometimes ship their own classnames — walk up the DOM and clear
  // max-width / width caps so the React app can use the full viewport.
  document.body.classList.add('lvb-admin-page');
  let el: HTMLElement | null = root.parentElement;
  while (el && el !== document.body) {
    const cs = window.getComputedStyle(el);
    const w = parseFloat(cs.maxWidth);
    if (Number.isFinite(w) && w > 0 && w < window.innerWidth) {
      el.style.maxWidth = 'none';
      el.style.width = '100%';
    }
    el = el.parentElement;
  }

  ReactDOM.createRoot(root).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
