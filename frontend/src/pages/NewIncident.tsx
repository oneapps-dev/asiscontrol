import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function NewIncident({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="new_incident" 
      setView={setView}
      userName="Carlos Mendoza"
      userRole="Operador Nivel 1"
    >
      <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <nav className="flex text-xs font-medium text-outline mb-2">
            <span className="hover:text-primary cursor-pointer" onClick={() => setView('guard_dashboard')}>Panel</span>
            <span className="mx-2">/</span>
            <span className="text-text-primary">Nuevo Incidente</span>
          </nav>
          <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Reportar Incidente</h2>
        </div>
        <div className="flex gap-3">
          <button onClick={() => setView('guard_dashboard')} className="px-4 py-2 border border-border bg-surface text-text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-colors flex items-center gap-2">
            <Icon name="close" className="text-[20px]" /> Cancelar
          </button>
          <button onClick={() => setView('guard_dashboard')} className="px-6 py-2 bg-danger text-white rounded-lg text-sm font-medium hover:bg-danger/90 transition-all flex items-center gap-2 shadow-sm">
            <Icon name="warning" className="text-[20px]" /> Enviar Alerta
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-8 space-y-6">
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-danger">
              <Icon name="report_problem" />
              <h3 className="text-lg font-semibold text-text-primary">Detalles del Incidente</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-1.5 md:col-span-2">
                <label className="text-sm font-medium text-on-surface-variant">Tipo de Incidente</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-danger outline-none">
                  <option>Intento de acceso no autorizado</option>
                  <option>Altercado / Problema de convivencia</option>
                  <option>Falla de equipo / infraestructura</option>
                  <option>Sospecha de robo / Pérdida</option>
                  <option>Emergencia médica</option>
                  <option>Otro</option>
                </select>
              </div>
              <div className="space-y-1.5 md:col-span-2">
                <label className="text-sm font-medium text-on-surface-variant">Descripción Detallada</label>
                <textarea 
                  className="w-full p-3 bg-surface border border-border rounded-lg text-sm focus:ring-2 focus:ring-danger outline-none min-h-[120px] resize-none"
                  placeholder="Describa qué sucedió, quiénes estuvieron involucrados y cualquier detalle relevante..."
                ></textarea>
              </div>
            </div>
          </section>
        </div>

        <div className="lg:col-span-4 space-y-6">
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-text-primary">
                <Icon name="photo_camera" />
                <h3 className="text-base font-semibold">Evidencia Fotográfica</h3>
              </div>
            </div>
            <div className="aspect-video bg-gradient-to-br from-surface-subtle to-border rounded-lg overflow-hidden relative mb-4 border border-border flex flex-col items-center justify-center text-outline-variant">
              <Icon name="add_a_photo" className="text-[48px] mb-2" />
              <span className="text-xs">Adjuntar evidencia visual</span>
            </div>
            <button className="w-full h-10 bg-surface border border-border text-text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-all flex items-center justify-center gap-2">
              <Icon name="upload_file" /> Subir Archivo
            </button>
          </section>
        </div>
      </div>
    </Layout>
  );
}
