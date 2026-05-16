import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import { App } from './App';

const root = document.getElementById('lvb-admin-root');
if (root) {
  // Apply scoping class so Tailwind utilities (configured with
  // `important: '#lvb-admin-root'`) take effect inside the React tree.
  root.classList.add('lvb-admin-root');

  ReactDOM.createRoot(root).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
