import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function NewVehicle({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="new_vehicle" 
      setView={setView}
      userName="Carlos Mendoza"
      userRole="Operador Nivel 1"
    >
      <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <nav className="flex text-xs font-medium text-outline mb-2">
            <span className="hover:text-primary cursor-pointer" onClick={() => setView('vehicles')}>Vehículos</span>
            <span className="mx-2">/</span>
            <span className="text-text-primary">Nuevo Registro</span>
          </nav>
          <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Registro de Vehículo</h2>
        </div>
        <div className="flex gap-3">
          <button onClick={() => setView('guard_dashboard')} className="px-4 py-2 border border-border bg-surface text-text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-colors flex items-center gap-2">
            <Icon name="close" className="text-[20px]" /> Cancelar
          </button>
          <button onClick={() => setView('success')} className="px-6 py-2 bg-primary text-on-primary rounded-lg text-sm font-medium hover:bg-primary-container transition-all flex items-center gap-2 shadow-sm">
            <Icon name="save" className="text-[20px]" /> Guardar Registro
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-8 space-y-6">
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-primary">
              <Icon name="directions_car" />
              <h3 className="text-lg font-semibold text-text-primary">Datos del Vehículo</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Placa del Vehículo</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm font-bold uppercase focus:ring-2 focus:ring-primary outline-none" placeholder="ABC-123" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Tipo de Vehículo</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Automóvil</option>
                  <option>Camioneta</option>
                  <option>Motocicleta</option>
                  <option>Camión / Furgón</option>
                </select>
              </div>
              <div className="space-y-1.5 md:col-span-2">
                <label className="text-sm font-medium text-on-surface-variant">Empresa / Transportadora (Opcional)</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" placeholder="Ej. Logística Express" />
              </div>
            </div>
          </section>

          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-primary">
              <Icon name="person" />
              <h3 className="text-lg font-semibold text-text-primary">Datos del Conductor</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Documento del Conductor</label>
                <div className="relative flex rounded-lg">
                  <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg pl-3 pr-10 text-sm focus:ring-2 focus:ring-primary outline-none" placeholder="Escanee o digite..." />
                  <button className="absolute right-2 top-1/2 -translate-y-1/2 text-primary hover:scale-110 transition-transform">
                    <Icon name="qr_code_scanner" />
                  </button>
                </div>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Nombre Completo</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" />
              </div>
            </div>
          </section>
        </div>

        <div className="lg:col-span-4 space-y-6">
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-primary">
                <Icon name="photo_camera" />
                <h3 className="text-base font-semibold text-text-primary">Fotografía / Evidencia</h3>
              </div>
            </div>
            <div className="aspect-[4/3] bg-gradient-to-br from-surface-subtle to-border rounded-lg overflow-hidden relative mb-4 border border-border flex flex-col items-center justify-center text-outline-variant">
              <Icon name="directions_car" className="text-[48px] mb-2" />
              <span className="text-xs">Capturar placa o vehículo</span>
            </div>
            <button className="w-full h-10 bg-surface border border-primary text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-all flex items-center justify-center gap-2">
              <Icon name="camera_alt" /> Tomar Foto
            </button>
          </section>
          
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
             <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Destino</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Parqueadero Visitantes</option>
                  <option>Muelle de Carga 1</option>
                  <option>Muelle de Carga 2</option>
                </select>
              </div>
          </section>
        </div>
      </div>
    </Layout>
  );
}
