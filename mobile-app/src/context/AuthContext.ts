import React from 'react';

interface AuthContextType {
  signIn: (username: string, password: string) => Promise<{ ok: boolean; error?: string }>;
  signOut: () => Promise<void>;
  signUp: (username: string, password: string, email: string) => Promise<{ ok: boolean; error?: string }>;
}

const AuthContext = React.createContext<AuthContextType>({
  signIn: async () => ({ ok: false }),
  signOut: async () => {},
  signUp: async () => ({ ok: false }),
});

export default AuthContext;
