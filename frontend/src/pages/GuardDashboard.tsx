import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function GuardDashboard({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="guard_dashboard" 
      setView={setView}
      userName="Carlos Mendoza"
      userRole="Operador Nivel 1"
      avatarUrl="https://lh3.googleusercontent.com/aida-public/AB6AXuCC3qNRfGqHyGD-o3CSduWA5PXw9SNbXago_VyBMuCIMP0FJ0VZR4-1A4SXAu9J7Bx9G-e3XRIoXjhOcJICmsTGUVwxbtZiyiMQjI4Lk9LJ9N-y1bB2Jxs5tkIEd9qKDAnOysLniffFitC6lIHI1T7qzWIm_wW1aFhBK3Dj_SBY3e_c4tsAPsedB_Ub47c7K5KLDp-K1nuIxqW2l0QATVpUGu0kVDDRSgQUHGJtbhXjLj71XVhSpkI"
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Panel de control</h2>
            <p className="text-sm text-on-surface-variant">Gestión de accesos en tiempo real para el Sector 1</p>
          </div>
          <div className="flex gap-2">
            <button className="bg-surface-subtle border border-border text-text-primary px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 hover:bg-surface-container transition-colors">
              <Icon name="sync" className="text-[20px]" /> Sincronizar
            </button>
            <button onClick={() => setView('new_visitor')} className="bg-primary text-on-primary px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 hover:opacity-90 active:scale-95 transition-all">
              <Icon name="qr_code_scanner" className="text-[20px]" /> Cámara Rápida
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Quick Actions */}
          <div className="lg:col-span-8 grid grid-cols-2 sm:grid-cols-3 gap-4">
            <button onClick={() => setView('new_visitor')} className="bg-primary text-on-primary rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:shadow-lg transition-all group">
              <Icon name="document_scanner" className="text-[40px] group-hover:scale-110 transition-transform" />
              <span className="text-base font-semibold text-center leading-tight">Escanear Documento</span>
            </button>
            <button onClick={() => setView('new_visitor')} className="bg-surface border border-border rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:bg-surface-subtle transition-all group">
              <Icon name="person_add" className="text-[40px] text-primary" />
              <span className="text-base font-semibold text-center leading-tight">Visita Manual</span>
            </button>
            <button onClick={() => setView('people_on_site')} className="bg-surface border border-border rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:bg-surface-subtle transition-all group">
              <Icon name="logout" className="text-[40px] text-danger" />
              <span className="text-base font-semibold text-center leading-tight">Registrar Salida</span>
            </button>
            <button onClick={() => setView('new_vehicle')} className="bg-surface border border-border rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:bg-surface-subtle transition-all group">
              <Icon name="local_shipping" className="text-[40px] text-primary" />
              <span className="text-base font-semibold text-center leading-tight">Vehículo Pesado</span>
            </button>
            <button onClick={() => setView('new_package')} className="bg-surface border border-border rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:bg-surface-subtle transition-all group">
              <Icon name="package_2" className="text-[40px] text-primary" />
              <span className="text-base font-semibold text-center leading-tight">Paquete / Domicilio</span>
            </button>
            <button onClick={() => setView('new_incident')} className="bg-surface border border-border rounded-xl p-6 flex flex-col items-center justify-center gap-3 hover:bg-surface-subtle transition-all group">
              <Icon name="warning" className="text-[40px] text-warning" />
              <span className="text-base font-semibold text-center leading-tight">Reportar Incidente</span>
            </button>
          </div>

          {/* Metrics */}
          <div className="lg:col-span-4 flex flex-col gap-4">
            <div className="bg-surface-container-low border border-border rounded-xl p-5 flex items-center justify-between">
              <div>
                <p className="text-xs text-on-surface-variant uppercase tracking-wider">Personas Dentro</p>
                <p className="text-[28px] font-semibold text-text-primary leading-tight mt-1">42</p>
              </div>
              <div className="w-12 h-12 bg-secondary-container rounded-full flex items-center justify-center text-on-secondary-container">
                <Icon name="group" />
              </div>
            </div>
            <div className="bg-surface-container-low border border-border rounded-xl p-5 flex items-center justify-between">
              <div>
                <p className="text-xs text-on-surface-variant uppercase tracking-wider">Vehículos Dentro</p>
                <p className="text-[28px] font-semibold text-text-primary leading-tight mt-1">15</p>
              </div>
              <div className="w-12 h-12 bg-surface-container-high rounded-full flex items-center justify-center text-primary">
                <Icon name="directions_car" />
              </div>
            </div>
            <div className="bg-tertiary-fixed border border-border rounded-xl p-5 flex items-center justify-between">
              <div>
                <p className="text-xs text-on-tertiary-fixed-variant uppercase tracking-wider">Autorizaciones Pendientes</p>
                <p className="text-[28px] font-semibold text-tertiary leading-tight mt-1">3</p>
              </div>
              <div className="w-12 h-12 bg-tertiary-container rounded-full flex items-center justify-center text-on-tertiary-container">
                <Icon name="notifications_active" />
              </div>
            </div>
          </div>

          {/* Table */}
          <div className="lg:col-span-12 bg-surface border border-border rounded-xl overflow-hidden">
            <div className="px-6 py-4 border-b border-border flex items-center justify-between">
              <h3 className="text-lg font-semibold text-text-primary">Últimos Ingresos</h3>
              <button className="text-primary text-sm font-medium hover:underline">Ver todo</button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead className="bg-surface-subtle text-on-surface-variant text-xs font-medium uppercase">
                  <tr>
                    <th className="px-6 py-3">Persona / Vehículo</th>
                    <th className="px-6 py-3">ID / Placa</th>
                    <th className="px-6 py-3">Destino</th>
                    <th className="px-6 py-3">Hora</th>
                    <th className="px-6 py-3">Estado</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  <tr className="hover:bg-surface-subtle transition-colors">
                    <td className="px-6 py-4 flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full bg-surface-container-high flex items-center justify-center text-primary font-bold text-xs">RM</div>
                      <span className="text-sm font-medium text-text-primary">Ricardo Mendoza</span>
                    </td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">1.032.445.678</td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">Torre A - 402</td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">09:12 AM</td>
                    <td className="px-6 py-4">
                      <span className="bg-success/10 text-success px-2 py-1 rounded-full text-xs font-bold flex items-center gap-1 w-fit">
                        <span className="w-1.5 h-1.5 rounded-full bg-success"></span>
                        AUTORIZADO
                      </span>
                    </td>
                  </tr>
                  <tr className="hover:bg-surface-subtle transition-colors">
                    <td className="px-6 py-4 flex items-center gap-3">
                      <div className="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-primary">
                        <Icon name="local_shipping" className="text-[20px]" />
                      </div>
                      <span className="text-sm font-medium text-text-primary">Logística Express S.A.</span>
                    </td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">XYZ-789</td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">Muelle de Carga 2</td>
                    <td className="px-6 py-4 text-sm text-on-surface-variant">09:05 AM</td>
                    <td className="px-6 py-4">
                      <span className="bg-info/10 text-info px-2 py-1 rounded-full text-xs font-bold flex items-center gap-1 w-fit">
                        <span className="w-1.5 h-1.5 rounded-full bg-info"></span>
                        EN PROCESO
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
}
