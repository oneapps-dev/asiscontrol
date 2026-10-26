import React from 'react';
import { Icon } from './Icon';

interface TopbarProps {
  userName: string;
  userRole: string;
  avatarUrl?: string;
  title?: string;
}

export function Topbar({ userName, userRole, avatarUrl, title }: TopbarProps) {
  return (
    <header className="w-full h-[64px] sticky top-0 z-40 bg-surface border-b border-border flex justify-between items-center px-6">
      <div className="flex items-center gap-4 flex-grow max-w-xl">
        {title ? (
          <h2 className="text-lg font-bold text-primary">{title}</h2>
        ) : (
          <div className="relative w-full">
            <Icon name="search" className="absolute left-3 top-1/2 -translate-y-1/2 text-outline" />
            <input 
              type="text" 
              placeholder="Buscar por nombre, placa o documento..." 
              className="w-full h-10 pl-10 pr-4 bg-surface-subtle border border-border rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all"
            />
          </div>
        )}
      </div>
      
      <div className="flex items-center gap-2 ml-4">
        <button className="p-2 text-on-surface-variant hover:text-primary transition-colors relative">
          <Icon name="notifications" />
          <span className="absolute top-2 right-2 w-2 h-2 bg-danger rounded-full"></span>
        </button>
        <button className="p-2 text-on-surface-variant hover:text-primary transition-colors">
          <Icon name="help_outline" />
        </button>
        
        <div className="flex items-center gap-3 ml-4 border-l pl-4 border-border cursor-pointer">
          <div className="hidden sm:block text-right">
            <p className="text-sm font-semibold text-text-primary leading-tight">{userName}</p>
            <p className="text-xs text-on-surface-variant leading-tight">{userRole}</p>
          </div>
          {avatarUrl ? (
            <div className="w-9 h-9 rounded-full bg-surface-container overflow-hidden border border-border">
              <img src={avatarUrl} alt={userName} className="w-full h-full object-cover" />
            </div>
          ) : (
            <Icon name="account_circle" className="text-[36px] text-primary" />
          )}
        </div>
      </div>
    </header>
  );
}
