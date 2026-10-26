import React from 'react';
import { Icon } from './Icon';

export function ActiveShiftBar({ 
  guardName = 'Carlos Mendoza', 
  site = 'Complejo Industrial Norte',
  startTime = '06:00 AM'
}) {
  return (
    <div className="w-full h-[32px] bg-surface-container-low border-b border-border flex items-center justify-between px-6 py-1 text-on-surface-variant text-xs font-medium">
      <div className="flex items-center gap-4">
        <span className="flex items-center gap-1">
          <Icon name="shield_person" className="text-[16px]" />
          Guarda: <span className="font-bold text-secondary">{guardName}</span>
        </span>
        <span className="flex items-center gap-1">
          <Icon name="location_on" className="text-[16px]" />
          Sede: <span className="font-bold text-secondary">{site}</span>
        </span>
      </div>
      <div className="flex items-center gap-4">
        <span className="flex items-center gap-1">
          <Icon name="schedule" className="text-[16px]" />
          Inicio: <span className="font-bold text-secondary">{startTime}</span>
        </span>
        <span className="text-success flex items-center gap-1">
          <span className="w-2 h-2 rounded-full bg-success animate-pulse"></span>
          Turno Activo
        </span>
      </div>
    </div>
  );
}
