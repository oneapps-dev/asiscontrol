import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function NewPackage({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="new_package" 
      setView={setView}
      userName="Carlos Mendoza"
      userRole="Operador Nivel 1"
    >
      <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <nav className="flex text-xs font-medium text-outline mb-2">
            <span className="hover:text-primary cursor-pointer" onClick={() => setView('packages')}>Paquetería</span>
            <span className="mx-2">/</span>
            <span className="text-text-primary">Nueva Recepción</span>
          </nav>
          <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Recepción de Paquete</h2>
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
              <Icon name="inventory_2" />
              <h3 className="text-lg font-semibold text-text-primary">Datos del Envío</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-1.5 md:col-span-2">
                <label className="text-sm font-medium text-on-surface-variant">Número de Guía / Tracking</label>
                <div className="relative flex rounded-lg">
                  <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg pl-3 pr-10 text-sm font-mono focus:ring-2 focus:ring-primary outline-none" placeholder="Escanee o digite..." />
                  <button className="absolute right-2 top-1/2 -translate-y-1/2 text-primary hover:scale-110 transition-transform">
                    <Icon name="qr_code_scanner" />
                  </button>
                </div>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Transportadora / Mensajero</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Servientrega</option>
                  <option>Coordinadora</option>
                  <option>MercadoLibre</option>
                  <option>Amazon</option>
                  <option>Otro</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-on-surface-variant">Tipo de Paquete</label>
                <select className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none">
                  <option>Caja Pequeña</option>
                  <option>Caja Grande</option>
                  <option>Sobre / Documento</option>
                  <option>Domicilio Comida</option>
                </select>
              </div>
            </div>
          </section>

          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center gap-2 mb-6 text-primary">
              <Icon name="person" />
              <h3 className="text-lg font-semibold text-text-primary">Destinatario</h3>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-1.5 md:col-span-2">
                <label className="text-sm font-medium text-on-surface-variant">Buscar Destinatario (Apto / Nombre)</label>
                <input type="text" className="w-full h-10 bg-surface border border-border rounded-lg px-3 text-sm focus:ring-2 focus:ring-primary outline-none" placeholder="Ej. Apto 402 o Ana Martínez" />
              </div>
            </div>
          </section>
        </div>

        <div className="lg:col-span-4 space-y-6">
          <section className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2 text-primary">
                <Icon name="photo_camera" />
                <h3 className="text-base font-semibold text-text-primary">Fotografía del Paquete</h3>
              </div>
            </div>
            <div className="aspect-square bg-gradient-to-br from-surface-subtle to-border rounded-lg overflow-hidden relative mb-4 border border-border flex flex-col items-center justify-center text-outline-variant">
              <Icon name="inventory_2" className="text-[48px] mb-2" />
              <span className="text-xs">Capturar estado del paquete</span>
            </div>
            <button className="w-full h-10 bg-surface border border-primary text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-all flex items-center justify-center gap-2">
              <Icon name="camera_alt" /> Tomar Foto
            </button>
          </section>
        </div>
      </div>
    </Layout>
  );
}
