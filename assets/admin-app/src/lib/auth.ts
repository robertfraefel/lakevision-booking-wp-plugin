import { createContext, useContext } from 'react';
import type { Capability, CurrentUser } from '../api/client';

export interface AuthContextValue {
  user: CurrentUser;
  hasCap: (cap: Capability) => boolean;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used inside <AuthContext.Provider>');
  }
  return ctx;
}
