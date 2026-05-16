import { useEffect, useRef, useState } from 'react';
import { api } from '../api/client';
import type { Customer } from '../api/types';

interface CustomerPickerProps {
  currentEmail: string;
  onPick: (customer: Customer) => void;
  onClear: () => void;
}

/**
 * Type-ahead customer search. Debounced 200ms; shows up to 20 hits from
 * /admin/customers/search. Clicking a hit fills the form; "Neu anlegen"
 * clears the picker so the form fields can be edited from scratch.
 */
export function CustomerPicker({ currentEmail, onPick, onClear }: CustomerPickerProps) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState<Customer[]>([]);
  const [open, setOpen] = useState(false);
  const timer = useRef<number | null>(null);

  useEffect(() => {
    if (timer.current) window.clearTimeout(timer.current);
    if (q.trim().length < 2) {
      setResults([]);
      return;
    }
    timer.current = window.setTimeout(() => {
      api
        .searchCustomers(q)
        .then((r) => setResults(r.items))
        .catch(() => setResults([]));
    }, 200);
    return () => {
      if (timer.current) window.clearTimeout(timer.current);
    };
  }, [q]);

  return (
    <div className="relative">
      <div className="flex items-center gap-2">
        <input
          type="search"
          placeholder={currentEmail ? `Aktuell: ${currentEmail}` : 'Kunde suchen (Name / E-Mail)…'}
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          className="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
        {currentEmail && (
          <button
            type="button"
            onClick={() => {
              setQ('');
              setResults([]);
              onClear();
            }}
            className="text-xs text-gray-500 hover:text-gray-700"
          >
            Neu anlegen
          </button>
        )}
      </div>
      {open && results.length > 0 && (
        <ul className="absolute z-10 left-0 right-0 mt-1 max-h-56 overflow-y-auto bg-white border border-gray-200 rounded shadow-lg">
          {results.map((c) => (
            <li key={c.id}>
              <button
                type="button"
                onMouseDown={(e) => {
                  e.preventDefault();
                  onPick(c);
                  setQ('');
                  setResults([]);
                  setOpen(false);
                }}
                className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50"
              >
                <div className="font-medium">
                  {c.first_name} {c.last_name}
                </div>
                <div className="text-xs text-gray-500">
                  {c.email} {c.phone && `· ${c.phone}`}
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
