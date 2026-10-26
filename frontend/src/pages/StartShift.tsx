import React from 'react';
import { ViewState } from '../types';
import { Icon } from '../components/Icon';
import { Topbar } from '../components/Topbar';

export function StartShift({ setView }: { setView: (view: ViewState) => void }) {
  
  const handleStart = (e: React.FormEvent) => {
    e.preventDefault();
    setView('guard_dashboard');
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="w-full h-[32px] flex items-center justify-between px-6 py-1 text-on-surface-variant bg-surface-container-low border-b border-border text-xs font-medium">
        <div className="flex items-center gap-2">
          <Icon name="schedule" className="text-sm" />
          <span>Esperando inicio de turno...</span>
        </div>
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-warning"></span>
          <span>Sesión: SHIFT-01</span>
        </div>
      </div>
      
      <Topbar userName="Carlos Mendoza" userRole="Supervisor de Seguridad" />

      <main className="max-w-[1200px] mx-auto p-4 md:p-6 grid grid-cols-1 lg:grid-cols-12 gap-6 pb-24">
        {/* Left Column: Guard & Status */}
        <div className="lg:col-span-4 space-y-6">
          <section className="bg-surface/80 backdrop-blur-md border border-border rounded-xl p-6 shadow-sm flex flex-col items-center text-center">
            <div className="relative mb-4">
              <img 
                src="https://lh3.googleusercontent.com/aida-public/AB6AXuCC3qNRfGqHyGD-o3CSduWA5PXw9SNbXago_VyBMuCIMP0FJ0VZR4-1A4SXAu9J7Bx9G-e3XRIoXjhOcJICmsTGUVwxbtZiyiMQjI4Lk9LJ9N-y1bB2Jxs5tkIEd9qKDAnOysLniffFitC6lIHI1T7qzWIm_wW1aFhBK3Dj_SBY3e_c4tsAPsedB_Ub47c7K5KLDp-K1nuIxqW2l0QATVpUGu0kVDDRSgQUHGJtbhXjLj71XVhSpkI" 
                alt="Carlos Mendoza"
                className="w-24 h-24 rounded-full object-cover border-4 border-surface shadow-md"
              />
              <div className="absolute bottom-0 right-0 w-6 h-6 bg-success rounded-full border-2 border-white flex items-center justify-center">
                <Icon name="check" className="text-[12px] text-white" filled />
              </div>
            </div>
            <h2 className="text-xl font-semibold text-primary">Carlos Mendoza</h2>
            <p className="text-sm text-on-surface-variant mb-4">Supervisor de Seguridad</p>
            
            <div className="w-full grid grid-cols-2 gap-4 pt-4 border-t border-border">
              <div className="text-left">
                <p className="text-xs font-medium text-outline">ID de Empleado</p>
                <p className="text-sm font-semibold text-text-primary">AF-2901-C</p>
              </div>
              <div className="text-left">
                <p className="text-xs font-medium text-outline">Rango</p>
                <p className="text-sm font-semibold text-text-primary">Nivel 3</p>
              </div>
            </div>
          </section>

          <section className="bg-surface-container-low border border-border rounded-xl p-5">
            <div className="flex items-center gap-3 text-secondary mb-3">
              <Icon name="verified_user" filled />
              <span className="text-sm font-medium">Protocolo de Identidad OK</span>
            </div>
            <p className="text-xs text-on-surface-variant">
              Su identidad ha sido verificada biométricamente en el dispositivo actual. Proceda con la selección del puesto.
            </p>
          </section>
        </div>

        {/* Right Column: Shift Setup Form */}
        <div className="lg:col-span-8 space-y-6">
          <div className="flex flex-col gap-1 mb-2">
            <h3 className="text-2xl font-semibold text-text-primary">Configuración de Turno</h3>
            <p className="text-sm text-on-surface-variant">Complete los detalles para formalizar la apertura operativa.</p>
          </div>

          <form onSubmit={handleStart} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-sm font-medium text-on-surface flex items-center gap-2">
                  <Icon name="apartment" className="text-[18px]" /> Sede / Conjunto
                </label>
                <select className="w-full h-10 px-3 bg-surface border border-border rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none transition-all">
                  <option>Conjunto Residencial Altos del Parque</option>
                  <option>Torres de la Castellana</option>
                  <option>Centro Empresarial Quantum</option>
                </select>
              </div>
              
              <div className="space-y-2">
                <label className="text-sm font-medium text-on-surface flex items-center gap-2">
                  <Icon name="door_front" className="text-[18px]" /> Punto de Acceso
                </label>
                <select className="w-full h-10 px-3 bg-surface border border-border rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none transition-all">
                  <option>Portería Principal</option>
                  <option>Acceso Vehicular Norte</option>
                  <option>Peatonal Torre A</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-sm font-medium text-on-surface flex items-center gap-2">
                  <Icon name="camera_alt" className="text-[18px]" /> Foto de Apertura (Requerido)
                </label>
                <div className="relative aspect-video bg-inverse-surface rounded-xl overflow-hidden flex flex-col items-center justify-center text-on-primary-container">
                  <div className="absolute inset-0 z-0">
                    <img 
                      src="https://lh3.googleusercontent.com/aida-public/AB6AXuDWglJ-TXgYGt2jajeqEJVl3Da1sCr33FHnHTDOaPvA9Q7YobqX91qpGK84NpsCRUF8GWtTFAorjAEBw928wFpMzlyorCaiRU8Owg932W2ck1y0ymvgHW4OZog1mgmV71rwnh0Vbg1UPTVld8ji_fkVSgPDlzW0B0sxeuof9YGUbyZshiM_BEkcphJ0N4Zoe1vVaN23lIgIAfInsm47LNeo7xKCfPyW-IJlq1H_9ccx1mTmPyyR55k" 
                      className="w-full h-full object-cover opacity-50"
                      alt="Camera view"
                    />
                  </div>
                  <div className="relative z-10 flex flex-col items-center gap-2">
                    <button type="button" className="bg-primary text-white px-6 py-2 rounded-full text-sm font-medium flex items-center gap-2 hover:bg-primary-container transition-transform active:scale-95 shadow-lg">
                      <Icon name="shutter_speed" /> Capturar
                    </button>
                    <p className="text-[10px] font-medium uppercase tracking-wider opacity-70">Cámara Posterior Activa</p>
                  </div>
                </div>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-on-surface flex items-center gap-2">
                  <Icon name="location_on" className="text-[18px]" /> Geolocalización
                </label>
                <div className="aspect-video bg-surface-subtle border border-border rounded-xl overflow-hidden relative">
                  <img 
                    src="https://lh3.googleusercontent.com/aida-public/AB6AXuAK4dPFxefGb-WYqAHcf4BpPMFcdn1c-IncTNjYumpZxcoUkNvDGfV4-iB0O4Mw_7JcevzNKVw1cABqeJgga_mGa93ULq8AUUb8jS9Xr8PH0k_MokGnuYNc4xgvqbxBJWqS8vFZECo4rjG9_TT4hecC9snv8wGDG_s6vQRwP6n2DN7L4uiZoeB86VJK7y2j8J69msL8Fn09WgEcpRWW6cMPQOjKWFBeRCX_wz-LrYQXSr3Y2Y4vQ8c" 
                    className="w-full h-full object-cover"
                    alt="Map location"
                  />
                  <div className="absolute bottom-2 left-2 right-2 bg-surface/90 backdrop-blur p-2 rounded-lg border border-border flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 bg-success rounded-full animate-pulse"></span>
                      <span className="text-[11px] font-semibold text-on-surface">Ubicación Precisa</span>
                    </div>
                    <span className="text-[10px] text-outline">± 4m</span>
                  </div>
                </div>
              </div>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-on-surface flex items-center gap-2">
                <Icon name="edit_note" className="text-[18px]" /> Notas de Apertura / Observaciones
              </label>
              <textarea 
                className="w-full p-4 bg-surface border border-border rounded-xl text-sm focus:ring-2 focus:ring-primary outline-none min-h-[120px] transition-all resize-none"
                placeholder="Ej. Recibo puesto con 3 radios operativos, libros de minuta al día y sin novedades en perímetro..."
              ></textarea>
            </div>

            <div className="flex items-center justify-between pt-6 border-t border-border">
              <div className="hidden sm:flex items-center gap-2 text-warning">
                <Icon name="warning" className="text-[20px]" />
                <span className="text-xs font-medium">Asegúrese que toda la información es verídica.</span>
              </div>
              <div className="flex w-full sm:w-auto items-center gap-4 justify-end">
                <button type="button" onClick={() => setView('login')} className="px-6 h-10 border border-border text-text-primary rounded-lg text-sm font-medium hover:bg-surface-subtle transition-colors">
                  Cancelar
                </button>
                <button type="submit" className="px-8 h-10 bg-primary text-white rounded-lg text-sm font-bold hover:bg-primary-container shadow-md active:scale-95 flex items-center gap-2 transition-all">
                  Iniciar Turno
                  <Icon name="play_arrow" className="text-[18px]" />
                </button>
              </div>
            </div>
          </form>
        </div>
      </main>
    </div>
  );
}
