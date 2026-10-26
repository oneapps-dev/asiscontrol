import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function AdminDashboard({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="admin_dashboard" 
      setView={setView}
      isAdmin={true}
      userName="Admin Principal"
      userRole="Supervisor"
      hideActiveShiftBar={true}
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Panel de seguridad</h2>
            <p className="text-sm text-on-surface-variant">Resumen operativo en tiempo real</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center bg-surface border border-border rounded-lg px-3 h-10 shadow-sm">
              <Icon name="apartment" className="text-outline mr-2 text-[18px]" />
              <select className="border-none bg-transparent text-sm font-medium focus:ring-0 outline-none">
                <option>Todas las Sedes</option>
                <option>Torre Norte</option>
              </select>
            </div>
            <button className="bg-primary text-on-primary h-10 px-4 rounded-lg text-sm font-medium flex items-center gap-2 hover:bg-primary-container shadow-sm transition-colors">
              <Icon name="download" className="text-[18px]" /> Exportar
            </button>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group hover:border-primary transition-colors">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-success/10 text-success rounded-lg">
                <Icon name="login" />
              </div>
              <span className="text-success text-xs font-medium flex items-center bg-success/5 px-2 py-0.5 rounded-full">+12%</span>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Entradas Hoy</p>
            <h3 className="text-2xl font-semibold text-text-primary mt-1">1,284</h3>
            <p className="text-xs text-outline mt-2">Vs. 1,146 ayer</p>
          </div>

          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group hover:border-primary transition-colors">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-info/10 text-info rounded-lg">
                <Icon name="logout" />
              </div>
              <span className="text-info text-xs font-medium flex items-center bg-info/5 px-2 py-0.5 rounded-full">+5%</span>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Salidas Hoy</p>
            <h3 className="text-2xl font-semibold text-text-primary mt-1">942</h3>
            <p className="text-xs text-outline mt-2">En proceso: 342</p>
          </div>

          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group hover:border-primary transition-colors">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-warning/10 text-warning rounded-lg">
                <Icon name="security" />
              </div>
              <div className="flex items-center gap-1">
                <div className="w-2 h-2 rounded-full bg-success animate-pulse"></div>
                <span className="text-success text-xs font-medium">En Vivo</span>
              </div>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Guardas Activos</p>
            <h3 className="text-2xl font-semibold text-text-primary mt-1">18 / 20</h3>
            <div className="w-full bg-surface-subtle h-1.5 rounded-full mt-3 overflow-hidden">
              <div className="bg-warning h-full w-[90%]"></div>
            </div>
          </div>

          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group hover:border-primary transition-colors">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-primary/10 text-primary rounded-lg">
                <Icon name="inventory_2" />
              </div>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Paquetería Pendiente</p>
            <h3 className="text-2xl font-semibold text-text-primary mt-1">45</h3>
            <p className="text-xs text-outline mt-2">Entregas hoy: 128</p>
          </div>
        </div>

        {/* Charts & Map */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 bg-surface rounded-xl border border-border shadow-sm h-[320px] relative overflow-hidden group">
            <div className="absolute top-4 left-4 z-10">
              <div className="bg-surface px-4 py-2 rounded-lg border border-border shadow-lg flex items-center gap-2">
                <span className="w-2 h-2 rounded-full bg-primary"></span>
                <span className="text-xs font-medium">Mapa de calor: Accesos Activos</span>
              </div>
            </div>
            <img 
              src="https://lh3.googleusercontent.com/aida-public/AB6AXuDuC3_SqyejXH8FS3GuGpsRXO4RIx1jYJboBtGSV5iOPs-vcnjVNJd75-CTFOz5KTryQKiKqW4yxEssa4PIPDFOgE6wtyi61vAKFLkd8O5zwfaOsFVSTYCCbs0SN-h35qCy5WmaD36hmYeI0ZKrCPJu56sFPgNR1cl1Nawwb0FXcEmEukP-F8bF_8btmEbFzgCry4d0eUqTb7Kv6I-wWkFlL_B_jnGJEsFgD2Lre41t99xZ8BY0g8I" 
              className="w-full h-full object-cover grayscale brightness-125 transition-transform duration-700 group-hover:scale-105"
              alt="Map"
            />
          </div>
          
          <div className="bg-surface rounded-xl border border-border shadow-sm p-6">
            <h4 className="text-lg font-semibold text-text-primary mb-4">Alertas Recientes</h4>
            <div className="space-y-4">
              <div className="flex gap-4 items-start p-3 rounded-lg hover:bg-error-container/20 transition-colors">
                <div className="w-10 h-10 rounded-full bg-error-container flex items-center justify-center text-error shrink-0">
                  <Icon name="warning" className="text-[20px]" />
                </div>
                <div>
                  <p className="text-sm font-medium text-text-primary">Intento de ingreso fallido</p>
                  <p className="text-xs text-on-surface-variant mt-0.5">Vehículo BJK-421 - Placa no autorizada</p>
                  <p className="text-xs text-outline mt-1">Hace 5 min</p>
                </div>
              </div>
              <div className="flex gap-4 items-start p-3 rounded-lg hover:bg-warning/5 transition-colors">
                <div className="w-10 h-10 rounded-full bg-warning/20 flex items-center justify-center text-warning shrink-0">
                  <Icon name="timer" className="text-[20px]" />
                </div>
                <div>
                  <p className="text-sm font-medium text-text-primary">Permanencia extendida</p>
                  <p className="text-xs text-on-surface-variant mt-0.5">Contratista Juan Pérez (+4 horas)</p>
                  <p className="text-xs text-outline mt-1">Hace 22 min</p>
                </div>
              </div>
            </div>
            <button className="w-full mt-4 py-2 border border-border rounded-lg text-on-surface-variant text-sm font-medium hover:bg-surface-subtle transition-all">
              Ver todas las alertas
            </button>
          </div>
        </div>
      </div>
    </Layout>
  );
}
