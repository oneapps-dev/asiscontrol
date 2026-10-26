import React from 'react';
import { Icon } from './Icon';
import { ViewState } from '../types';

interface SidebarProps {
  currentView: ViewState;
  setView: (view: ViewState) => void;
  isAdmin?: boolean;
}

export function Sidebar({ currentView, setView, isAdmin = false }: SidebarProps) {
  const navItems = [
    { id: isAdmin ? 'admin_dashboard' : 'guard_dashboard', label: 'Panel', icon: 'dashboard' },
    { id: 'start_shift', label: 'Control de Acceso', icon: 'admin_panel_settings' },
    { id: 'new_visitor', label: 'Visitantes', icon: 'group' },
    { id: 'people_on_site', label: 'Personas en Sitio', icon: 'directions_walk' },
    { id: 'vehicles', label: 'Vehículos', icon: 'directions_car' },
    { id: 'packages', label: 'Paquetería', icon: 'inventory_2' },
    { id: 'reports', label: 'Reportes', icon: 'assessment' },
  ];

  return (
    <aside className="hidden md:flex flex-col h-screen sticky top-0 left-0 w-[280px] bg-surface border-r border-border p-6">
      <div className="flex items-center gap-3 mb-8 cursor-pointer" onClick={() => setView('login')}>
        <div className="w-10 h-10 bg-primary rounded-lg flex items-center justify-center text-on-primary">
          <Icon name="security" />
        </div>
        <div>
          <h1 className="text-[20px] font-black text-primary leading-tight tracking-tight">Asiscontrol</h1>
          <p className="text-xs text-on-surface-variant font-medium">Seguridad Operativa</p>
        </div>
      </div>
      
      <nav className="flex-grow space-y-1">
        {navItems.map(item => {
          const isActive = currentView === item.id || (item.id === 'guard_dashboard' && currentView === 'success');
          return (
            <button
              key={item.label}
              onClick={() => item.id !== '#' && setView(item.id as ViewState)}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors ${
                isActive 
                  ? 'bg-surface-container-high text-primary font-semibold' 
                  : 'text-on-surface-variant hover:bg-surface-subtle'
              }`}
            >
              <Icon name={item.icon} filled={isActive} />
              {item.label}
            </button>
          );
        })}
      </nav>
      
      <div className="mt-auto pt-4 space-y-1 border-t border-border">
        <button className="w-full flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-subtle rounded-lg text-sm font-medium transition-colors">
          <Icon name="settings" />
          Configuración
        </button>
        <button 
          onClick={() => setView('login')}
          className="w-full flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-error-container/20 hover:text-danger rounded-lg text-sm font-medium transition-colors"
        >
          <Icon name="logout" />
          Cerrar Sesión
        </button>
      </div>
    </aside>
  );
}
