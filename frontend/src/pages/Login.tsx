import React, { useState } from 'react';
import { Icon } from '../components/Icon';
import { ViewState } from '../types';

export function Login({ setView }: { setView: (view: ViewState) => void }) {
  const [loading, setLoading] = useState(false);
  const [email, setEmail] = useState('');

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      // Route based on email for demo purposes
      if (email.includes('admin')) {
        setView('admin_dashboard');
      } else {
        setView('start_shift');
      }
    }, 1000);
  };

  return (
    <div className="flex-grow flex items-center justify-center p-4 md:p-6 min-h-screen bg-background relative z-10">
      <div className="w-full max-w-[440px] flex flex-col items-center">
        {/* Brand */}
        <div className="mb-8 text-center">
          <div className="flex items-center justify-center gap-2 mb-2">
            <div className="w-10 h-10 bg-primary-container rounded-lg flex items-center justify-center">
              <Icon name="admin_panel_settings" className="text-white" filled />
            </div>
            <span className="text-3xl font-black text-primary tracking-tight">Asiscontrol</span>
          </div>
          <p className="text-sm font-medium text-on-surface-variant">Seguridad Operativa Multi-inquilino</p>
        </div>

        {/* Card */}
        <div className="w-full bg-surface border border-border rounded-xl p-8 shadow-[0_4px_12px_rgba(16,24,40,0.08)]">
          <div className="mb-6">
            <h1 className="text-xl font-semibold text-text-primary mb-1">Bienvenido de nuevo</h1>
            <p className="text-sm text-on-surface-variant">Por favor ingresa tus credenciales para continuar.</p>
          </div>

          <form onSubmit={handleLogin} className="space-y-5">
            <div className="space-y-1.5">
              <label className="text-sm font-medium text-text-primary" htmlFor="email">Usuario o Email</label>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-outline">
                  <Icon name="person" className="text-[20px]" />
                </div>
                <input 
                  type="text" 
                  id="email" 
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full h-10 pl-10 pr-4 bg-surface border border-border rounded-lg text-text-primary text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all" 
                  placeholder="nombre@empresa.com" 
                />
              </div>
            </div>

            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium text-text-primary" htmlFor="password">Contraseña</label>
                <a href="#" className="text-xs font-medium text-primary hover:underline transition-all">¿Olvidaste tu contraseña?</a>
              </div>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-outline">
                  <Icon name="lock" className="text-[20px]" />
                </div>
                <input 
                  type="password" 
                  id="password" 
                  defaultValue=""
                  className="w-full h-10 pl-10 pr-10 bg-surface border border-border rounded-lg text-text-primary text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition-all" 
                  placeholder="••••••••" 
                />
                <button type="button" className="absolute inset-y-0 right-0 pr-3 flex items-center text-outline hover:text-text-primary transition-colors">
                  <Icon name="visibility" className="text-[20px]" />
                </button>
              </div>
            </div>

            <div className="flex items-center">
              <input type="checkbox" id="remember" className="w-4 h-4 text-primary border-border rounded focus:ring-primary" />
              <label htmlFor="remember" className="ml-2 text-sm text-on-surface-variant cursor-pointer">Recordar este dispositivo</label>
            </div>

            <button 
              type="submit" 
              disabled={loading}
              className="w-full h-10 bg-primary hover:bg-primary-container text-white text-sm font-medium rounded-lg shadow-sm active:scale-[0.98] transition-all flex items-center justify-center gap-2 disabled:opacity-80"
            >
              {loading ? (
                <>
                  <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Autenticando...
                </>
              ) : (
                <>
                  <span>Iniciar Sesión</span>
                  <Icon name="login" className="text-[18px]" />
                </>
              )}
            </button>
          </form>

          <div className="mt-8 pt-6 border-t border-border text-center">
            <p className="text-xs text-on-surface-variant">
              ¿Tienes problemas para acceder? 
              <a href="#" className="text-primary hover:underline font-semibold ml-1">Contactar a soporte</a>
            </p>
          </div>
        </div>

        <div className="mt-8 flex flex-col items-center gap-4">
          <div className="flex items-center gap-6 text-on-surface-variant text-xs font-medium">
            <a href="#" className="hover:text-primary transition-colors">Términos</a>
            <a href="#" className="hover:text-primary transition-colors">Privacidad</a>
            <a href="#" className="hover:text-primary transition-colors">Seguridad</a>
          </div>
          <div className="flex items-center gap-2 text-offline text-xs">
            <Icon name="verified_user" className="text-[14px]" />
            <span>Conexión cifrada de grado militar AES-256</span>
          </div>
        </div>
      </div>

      {/* Decorative blobs */}
      <div className="fixed top-0 right-0 -z-10 w-[500px] h-[500px] bg-primary/5 blur-[120px] rounded-full translate-x-1/2 -translate-y-1/2"></div>
      <div className="fixed bottom-0 left-0 -z-10 w-[400px] h-[400px] bg-secondary/5 blur-[100px] rounded-full -translate-x-1/3 translate-y-1/3"></div>
    </div>
  );
}
