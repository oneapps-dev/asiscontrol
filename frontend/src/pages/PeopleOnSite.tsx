import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function PeopleOnSite({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="people_on_site" 
      setView={setView}
      userName="Admin_Sede01"
      userRole="Supervisión"
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Personas en Sitio (VISIT-07)</h2>
            <p className="text-sm text-on-surface-variant">Monitoreo en tiempo real de visitantes y personal externo activo.</p>
          </div>
          <div className="flex gap-3">
            <button className="flex items-center gap-2 px-4 h-10 bg-surface border border-border rounded-lg text-sm font-medium text-text-primary hover:bg-surface-subtle transition-all">
              <Icon name="filter_list" className="text-[20px]" /> Filtrar
            </button>
            <button onClick={() => setView('new_visitor')} className="flex items-center gap-2 px-4 h-10 bg-primary text-on-primary rounded-lg text-sm font-medium hover:opacity-90 transition-all shadow-sm">
              <Icon name="person_add" className="text-[20px]" /> Nuevo Registro
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Total Presentes</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-text-primary">124</span>
              <span className="text-success text-xs font-medium flex items-center gap-0.5"><Icon name="arrow_upward" className="text-[14px]" /> 12%</span>
            </div>
          </div>
          <div className="bg-surface border border-border p-5 rounded-xl flex flex-col gap-1 shadow-sm border-l-4 border-l-warning">
            <span className="text-outline text-xs font-medium uppercase tracking-wider">Tiempo Excedido</span>
            <div className="flex items-baseline gap-2">
              <span className="text-[28px] font-semibold text-warning">8</span>
              <span className="text-on-surface-variant text-xs">requieren revisión</span>
            </div>
          </div>
        </div>

        {/* Table */}
        <div className="bg-surface border border-border rounded-xl overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-surface-subtle border-b border-border">
                  <th className="px-6 py-4 text-sm font-medium text-outline">Foto</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Nombre del Visitante</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Documento</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Hora Entrada</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline">Duración</th>
                  <th className="px-6 py-4 text-sm font-medium text-outline text-right">Acción</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4">
                    <div className="w-10 h-10 rounded-full border border-border overflow-hidden bg-surface-subtle">
                      <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuDN8j-ejUJbaAX0usLVfD-6XxJW-ICicsav0LiaIJvfe9o0DS_kq2D3pXPGaBcFjSF4Ft9K3F89Olz3y0E6r23xtoTvEXPSza-5E0d74BSxCGicHdFj_IwpMNzvKFDVsHtUnyBwf147rfBMM_JXw6LzfydneJm1s2NrH6njT1PMG3jyVA38d4XwGqWUnEbPbOPPJAGYZFAD5HZzuqUTDsMxeiczi_BOtsTeplg6shvJ4VWEJE-EqMs" className="w-full h-full object-cover" alt="Avatar" />
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Andrés Felipe Morales</span>
                      <span className="text-xs text-on-surface-variant">Contratista Externo</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-on-surface-variant font-mono">1.020.***.442</td>
                  <td className="px-6 py-4 text-sm text-text-primary">08:15 AM</td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-danger">04h 22m</span>
                      <span className="px-2 py-0.5 rounded-full bg-error-container text-on-error-container text-[10px] font-bold uppercase animate-pulse">Vencido</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-primary text-primary text-sm font-medium rounded-lg hover:bg-primary hover:text-on-primary transition-all">
                      Registrar Salida
                    </button>
                  </td>
                </tr>
                <tr className="hover:bg-surface-container-low/30 transition-colors">
                  <td className="px-6 py-4">
                    <div className="w-10 h-10 rounded-full border border-border overflow-hidden bg-surface-subtle">
                      <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBS1AsxNy1PyALl-AVEuihC4A4q1ZaHDhRZWnIMRtCnaeOXkMbOHKJsfERN0YD-mnJ7FERCLDLnfziNJTH2KawabAiUaElEAASgPyMacF5P-ZTmDKntfbCR_yjoNmGaRsA9KYjVayRYzff98a71xgOF6YdE-0__ypIKoHn8cjGV-GoEgs3wb1618b5sHn8AN94ri6Dyte0ScPCnl9zi-N6TP51Li7fpia1KHT9uWr03hrVeKfwfGl8" className="w-full h-full object-cover" alt="Avatar" />
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-text-primary">Claudia Patricia Vélez</span>
                      <span className="text-xs text-on-surface-variant">Proveedor</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-sm text-on-surface-variant font-mono">52.***.819</td>
                  <td className="px-6 py-4 text-sm text-text-primary">09:45 AM</td>
                  <td className="px-6 py-4 text-sm text-text-primary">02h 52m</td>
                  <td className="px-6 py-4 text-right">
                    <button className="px-4 py-1.5 border border-primary text-primary text-sm font-medium rounded-lg hover:bg-primary hover:text-on-primary transition-all">
                      Registrar Salida
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div className="px-6 py-4 bg-surface-subtle border-t border-border flex justify-between items-center">
            <span className="text-xs text-on-surface-variant">Mostrando 2 de 124 registros</span>
            <div className="flex gap-2">
              <button className="px-3 py-1 border border-border rounded bg-surface hover:bg-surface-subtle disabled:opacity-50 text-xs font-medium" disabled>Anterior</button>
              <button className="px-3 py-1 border border-border rounded bg-surface hover:bg-surface-subtle text-xs font-medium">Siguiente</button>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
