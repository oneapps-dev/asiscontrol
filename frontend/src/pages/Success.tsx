import React from 'react';
import { ViewState } from '../types';
import { Icon } from '../components/Icon';

export function SuccessScreen({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <div className="bg-background text-on-surface min-h-screen flex flex-col">
      <div className="flex items-center justify-between px-6 w-full py-1 text-on-surface-variant bg-surface-container-low border-b border-border h-[32px] text-xs font-medium">
        <div className="flex items-center gap-2">
          <Icon name="badge" className="text-[16px]" />
          <span>Agente: Ricardo Montoya</span>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-1">
            <Icon name="location_on" className="text-[16px]" />
            <span>Torre B - Acceso A</span>
          </div>
        </div>
      </div>

      <main className="flex-1 flex flex-col items-center justify-center p-6 relative overflow-y-auto">
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-primary/5 rounded-full blur-[100px] -z-10"></div>
        
        <div className="w-full max-w-xl space-y-8 flex flex-col items-center">
          <div className="text-center space-y-4">
            <div className="inline-flex items-center justify-center w-20 h-20 bg-success/10 text-success rounded-full mb-2 border-2 border-success/20 animate-[scaleIn_0.5s_cubic-bezier(0.175,0.885,0.32,1.275)]">
              <Icon name="check_circle" className="text-[48px]" filled />
            </div>
            <h1 className="text-3xl font-semibold text-primary tracking-tight">Ingreso Autorizado</h1>
            <p className="text-sm text-on-surface-variant max-w-md mx-auto">
              El registro de visitante se ha completado correctamente. Se ha notificado al anfitrión y el acceso ha sido habilitado.
            </p>
          </div>

          <div className="w-full bg-surface border border-border rounded-xl shadow-sm overflow-hidden grid grid-cols-1 md:grid-cols-12">
            <div className="md:col-span-7 p-6 border-b md:border-b-0 md:border-r border-border">
              <div className="flex items-center gap-4 mb-6">
                <div className="w-16 h-16 rounded-lg bg-surface-subtle overflow-hidden border border-border">
                  <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuDQAe0ln85s4AoWQiE3tPHz2vuxKpTqLClRTKFvzh6MBt81yCRQ8w31KN1bvOA_7JtlH8NYjnrPAr1IOnLzgOWtkkuqkDs-XesZDqgpXzcelixZQVPF-8iqChmkaInkORABqYQwwHHP5BjQjww7yM5PQSr9Y_Fmp7mZ35FU0TnxVDyt8t3mX3tji9VSARblhA3x17JlwoqdZRaXze8x7Vquxd2dNhRMj4SZSop_MLEX9aX3onL6A6Q" alt="Juan Carlos" className="w-full h-full object-cover" />
                </div>
                <div>
                  <h2 className="text-base font-semibold text-text-primary">Juan Carlos Pérez</h2>
                  <p className="text-xs font-medium text-on-surface-variant">CC 1.098.765.432</p>
                </div>
              </div>
              <div className="space-y-4">
                <div className="flex justify-between items-center py-2 border-b border-surface-subtle">
                  <span className="text-sm font-medium text-on-surface-variant flex items-center gap-2">
                    <Icon name="apartment" className="text-[18px]" /> Destino
                  </span>
                  <span className="text-sm font-medium text-text-primary">Torre B, Piso 12</span>
                </div>
                <div className="flex justify-between items-center py-2">
                  <span className="text-sm font-medium text-on-surface-variant flex items-center gap-2">
                    <Icon name="description" className="text-[18px]" /> ID
                  </span>
                  <span className="text-sm font-medium font-mono text-primary bg-primary/5 px-2 py-0.5 rounded">VISIT-06-2023-X</span>
                </div>
              </div>
            </div>
            <div className="md:col-span-5 p-6 bg-surface-container-low flex flex-col items-center justify-center text-center">
              <div className="bg-surface p-4 rounded-xl border border-border shadow-sm mb-4">
                <div className="w-24 h-24 flex items-center justify-center">
                  <Icon name="qr_code_2" className="text-[96px] text-text-primary" />
                </div>
              </div>
              <button className="flex items-center gap-2 text-primary text-sm font-medium hover:underline">
                <Icon name="print" className="text-[18px]" /> Imprimir ticket
              </button>
            </div>
          </div>

          <div className="w-full flex flex-col sm:flex-row gap-4 justify-center">
            <button onClick={() => setView('new_visitor')} className="h-12 px-8 bg-primary text-on-primary rounded-lg text-sm font-medium flex items-center justify-center gap-2 hover:bg-primary-container transition-all active:scale-[0.98] shadow-md shadow-primary/20">
              <Icon name="person_add" /> Registrar Otro
            </button>
            <button onClick={() => setView('guard_dashboard')} className="h-12 px-8 bg-surface border border-border text-text-primary rounded-lg text-sm font-medium flex items-center justify-center gap-2 hover:bg-surface-subtle transition-all active:scale-[0.98]">
              <Icon name="list_alt" /> Volver al Inicio
            </button>
          </div>
        </div>
      </main>
    </div>
  );
}
