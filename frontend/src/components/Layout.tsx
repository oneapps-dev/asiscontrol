import React from 'react';
import { ViewState } from '../types';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { ActiveShiftBar } from './ActiveShiftBar';

interface LayoutProps {
  currentView: ViewState;
  setView: (view: ViewState) => void;
  children: React.ReactNode;
  isAdmin?: boolean;
  hideActiveShiftBar?: boolean;
  topbarTitle?: string;
  userName: string;
  userRole: string;
  avatarUrl?: string;
}

export function Layout({ 
  currentView, 
  setView, 
  children, 
  isAdmin, 
  hideActiveShiftBar,
  topbarTitle,
  userName,
  userRole,
  avatarUrl
}: LayoutProps) {
  return (
    <div className="flex flex-col min-h-screen">
      {!hideActiveShiftBar && <ActiveShiftBar />}
      <div className="flex flex-1 overflow-hidden">
        <Sidebar currentView={currentView} setView={setView} isAdmin={isAdmin} />
        <main className="flex-1 flex flex-col min-w-0 bg-background overflow-y-auto">
          <Topbar 
            userName={userName} 
            userRole={userRole} 
            avatarUrl={avatarUrl} 
            title={topbarTitle} 
          />
          <div className="p-4 md:p-6 pb-24 md:pb-6">
            {children}
          </div>
        </main>
      </div>
      
      {/* Mobile Bottom Nav */}
      <nav className="md:hidden fixed bottom-0 left-0 w-full bg-surface border-t border-border flex justify-around items-center h-[64px] z-50">
        <button onClick={() => setView(isAdmin ? 'admin_dashboard' : 'guard_dashboard')} className={`flex flex-col items-center justify-center gap-1 ${currentView.includes('dashboard') ? 'text-primary' : 'text-on-surface-variant'}`}>
          <Icon name="dashboard" filled={currentView.includes('dashboard')} />
          <span className="text-[10px] font-medium">Inicio</span>
        </button>
        <button onClick={() => setView('new_visitor')} className={`flex flex-col items-center justify-center gap-1 ${currentView === 'new_visitor' ? 'text-primary' : 'text-on-surface-variant'}`}>
          <Icon name="group" filled={currentView === 'new_visitor'} />
          <span className="text-[10px] font-medium">Visitas</span>
        </button>
        <button onClick={() => setView('people_on_site')} className={`flex flex-col items-center justify-center gap-1 ${currentView === 'people_on_site' ? 'text-primary' : 'text-on-surface-variant'}`}>
          <Icon name="qr_code_scanner" filled={currentView === 'people_on_site'} />
          <span className="text-[10px] font-medium">Escanear</span>
        </button>
        <button onClick={() => setView('login')} className="flex flex-col items-center justify-center gap-1 text-on-surface-variant">
          <Icon name="account_circle" />
          <span className="text-[10px] font-medium">Perfil</span>
        </button>
      </nav>
    </div>
  );
}

// Ensure Icon is imported internally since we didn't add it to imports of Layout
import { Icon } from './Icon';
