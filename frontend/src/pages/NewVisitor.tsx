import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function NewVisitor({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="new_visitor" 
      setView={setView}
      userName="Carlos Mendoza"
      userRole="Operador Nivel 1"
    >
      <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <nav className="flex text-xs font-medium text-outline mb-2">
            <span className="hover:text-primary cursor-pointer">Visitantes</span>
            <span className="mx-2">/</span>
            <span className="text-text-primary">Nuevo Registro</span>
          </nav>
          <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Registro de Nueva Persona</h2>
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
          {/* Document Data */}
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-primary">
              <Icon name="badge" />
              <h3 className="text-lg font-semibold text-text-primary">Datos del Documento</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">País de Emisión</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Colombia</option>
                  <option>Otro</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Tipo de Documento</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Cédula de Ciudadanía</option>
                  <option>Pasaporte</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Número de Identificación</label>
                <div className="relative flex rounded-lg">
                  <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg pl-3 pr-10 text-sm focus:ring-2 focus:ring-primary outline-none" placeholder="Escanee o digite..." />
                  <button className="absolute right-2 top-1/2 -translate-y-1/2 text-primary hover:scale-110 transition-transform">
                    <Icon name="qr_code_scanner" />
                  </button>
                </div>
                <p className="text-[11px] text-outline italic">Tip: Use lector PDF417</p>
              </div>
            </div>
          </section>

          {/* Personal Info */}
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-primary">
              <Icon name="person_outline" />
              <h3 className="text-lg font-semibold text-text-primary">Información Personal</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Primer Nombre</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" defaultValue="Juan Carlos" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Primer Apellido</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" defaultValue="Pérez" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Destino (Apto / Torre)</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" defaultValue="Torre B, Piso 12" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Anfitrión</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" defaultValue="Claudia Gaviria" />
              </div>
            </div>
          </section>
        </div>

        <div className="lg:col-span-4 space-y-6">
          {/* Photo */}
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-primary">
                <Icon name="photo_camera" />
                <h3 className="text-base font-semibold text-text-primary">Captura Biométrica</h3>
              </div>
            </div>
            <div className="aspect-[3/4] bg-gradient-to-br from-surface-subtle to-border rounded-lg overflow-hidden relative mb-4 border border-border flex items-center justify-center">
              <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuDQAe0ln85s4AoWQiE3tPHz2vuxKpTqLClRTKFvzh6MBt81yCRQ8w31KN1bvOA_7JtlH8NYjnrPAr1IOnLzgOWtkkuqkDs-XesZDqgpXzcelixZQVPF-8iqChmkaInkORABqYQwwHHP5BjQjww7yM5PQSr9Y_Fmp7mZ35FU0TnxVDyt8t3mX3tji9VSARblhA3x17JlwoqdZRaXze8x7Vquxd2dNhRMj4SZSop_MLEX9aX3onL6A6Q" className="w-full h-full object-cover" alt="Captured" />
            </div>
            <button className="w-full h-10 bg-primary text-on-primary rounded-lg text-sm font-medium hover:bg-primary-container transition-all flex items-center justify-center gap-2">
              <Icon name="center_focus_strong" /> Capturar Foto
            </button>
          </section>
        </div>
      </div>
    </Layout>
  );
}
