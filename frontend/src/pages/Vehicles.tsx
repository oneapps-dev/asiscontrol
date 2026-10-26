import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function Vehicles({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="vehicles" 
      setView={setView}
      userName="Admin_Sede01"
      userRole="Supervisión"
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Control de Vehículos</h2>
            <p className="text-sm text-on-surface-variant">Monitoreo en tiempo real de vehículos en las instalaciones.</p>
          </div>
          <div className="flex gap-3">
            <button className="flex items-center gap-2 px-4 h-10 bg-surface border border-border rounded-lg text-sm font-medium text-text-primary hover:bg-surface-subtle transition-all">
              <Icon name="filter_list" className="text-[20px]" /> Filtrar
            </button>
            <button onClick={() => setView('new_vehicle')} className="flex items-center gap-2 px-4 h-10 bg-primary text-on-primary rounded-lg text-sm font-medium hover:opacity-90 transition-all shadow-sm">
              <Icon name="add" className="text-[20px]" /> Nuevo Ingreso
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Vehículos Dentro</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-text-primary">15</span>
            </div>
          </div>
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm border-l-4 border-l-info">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Vehículos Pesados</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-info">3</span>
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="bg-surface border border-border rounded-xl overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-surface-subtle border-b border-border">
                  <th className="px-6 py-4 text-sm font-medium text-outline">Placa / ID</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Tipo</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Conductor</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Hora Entrada</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Destino</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline text-right">Acción</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4">
                    <span className="text-sm font-bold text-text-primary bg-surface-subtle border border-border px-2 py-1 rounded">XYZ-789</span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <Icon name="local_shipping" className="text-primary" />
                      <span className="text-sm text-text-primary">Camión</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Pedro Gómez</span>
                      <span className="text-xs text-on-surface-variant">Logística Express S.A.</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-text-primary">09:05 AM</td>
                  <td className="px-6 py-4">
                    <span className="px-2 py-1 rounded bg-surface-container text-on-surface text-[11px] font-semibold border border-border">Muelle 2</span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-primary text-primary text-sm font-medium rounded-lg hover:bg-primary hover:text-on-primary transition-all">
                      Registrar Salida
                    </button>
                  </td>
                </tr>
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4">
                    <span className="text-sm font-bold text-text-primary bg-surface-subtle border border-border px-2 py-1 rounded">BJK-421</span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <Icon name="directions_car" className="text-primary" />
                      <span className="text-sm text-text-primary">Automóvil</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Juan Pérez</span>
                      <span className="text-xs text-on-surface-variant">Contratista</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-text-primary">10:15 AM</td>
                  <td className="px-6 py-4">
                    <span className="px-2 py-1 rounded bg-surface-container text-on-surface text-[11px] font-semibold border border-border">Parqueadero Visitantes</span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-primary text-primary text-sm font-medium rounded-lg hover:bg-primary hover:text-on-primary transition-all">
                      Registrar Salida
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Layout>
  );
}
