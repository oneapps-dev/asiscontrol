import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function Packages({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="packages" 
      setView={setView}
      userName="Admin_Sede01"
      userRole="Supervisión"
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Control de Paquetería</h2>
            <p className="text-sm text-on-surface-variant">Gestión de correspondencia y paquetes recibidos.</p>
          </div>
          <div className="flex gap-3">
            <button className="flex items-center gap-2 px-4 h-10 bg-surface border border-border rounded-lg text-sm font-medium text-text-primary hover:bg-surface-subtle transition-all">
              <Icon name="filter_list" className="text-[20px]" /> Filtrar
            </button>
            <button onClick={() => setView('new_package')} className="flex items-center gap-2 px-4 h-10 bg-primary text-on-primary rounded-lg text-sm font-medium hover:opacity-90 transition-all shadow-sm">
              <Icon name="add" className="text-[20px]" /> Recepción
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm border-l-4 border-l-warning">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Pendientes de Entrega</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-warning">45</span>
            </div>
          </div>
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Entregados Hoy</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-text-primary">128</span>
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="bg-surface border border-border rounded-xl overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-surface-subtle border-b border-border">
                  <th className="px-6 py-4 text-sm font-medium text-outline">Guía / Tracking</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Transportadora</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Destinatario</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Ubicación</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Estado</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline text-right">Acción</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4 text-sm font-mono text-text-primary">ML-987654321</td>
                  <td className="px-6 py-4 text-sm text-on-surface-variant">MercadoLibre</td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Ana Martínez</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-sm text-on-surface-variant">Apto 402, Torre A</span>
                  </td>
                  <td className="px-6 py-4">
                    <span className="px-2 py-1 rounded-full bg-warning/10 text-warning text-[10px] font-bold uppercase">En Recepción</span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-primary text-primary text-sm font-medium rounded-lg hover:bg-primary hover:text-on-primary transition-all">
                      Entregar
                    </button>
                  </td>
                </tr>
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4 text-sm font-mono text-text-primary">FED-1234567</td>
                  <td className="px-6 py-4 text-sm text-on-surface-variant">FedEx</td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Dpto. TI</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-sm text-on-surface-variant">Oficina 305</span>
                  </td>
                  <td className="px-6 py-4">
                    <span className="px-2 py-1 rounded-full bg-success/10 text-success text-[10px] font-bold uppercase">Entregado</span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-border text-outline text-sm font-medium rounded-lg hover:bg-surface-subtle transition-all">
                      Detalles
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
