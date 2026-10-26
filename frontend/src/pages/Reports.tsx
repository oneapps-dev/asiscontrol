import React from 'react';
import { ViewState } from '../types';
import { Layout } from '../components/Layout';
import { Icon } from '../components/Icon';

export function Reports({ setView }: { setView: (view: ViewState) => void }) {
  return (
    <Layout 
      currentView="reports" 
      setView={setView}
      userName="Admin_Sede01"
      userRole="Supervisión"
      isAdmin={true}
    >
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl md:text-3xl font-semibold text-text-primary tracking-tight">Reportes y Auditoría</h2>
            <p className="text-sm text-on-surface-variant">Análisis histórico y reportes detallados del sistema.</p>
          </div>
          <div className="flex gap-3">
            <button className="flex items-center gap-2 px-4 h-10 bg-surface border border-border rounded-lg text-sm font-medium text-text-primary hover:bg-surface-subtle transition-all">
              <Icon name="calendar_today" className="text-[20px]" /> Este Mes
            </button>
            <button className="flex items-center gap-2 px-4 h-10 bg-primary text-on-primary rounded-lg text-sm font-medium hover:opacity-90 transition-all shadow-sm">
              <Icon name="download" className="text-[20px]" /> Generar PDF
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-primary/10 text-primary rounded-lg">
                <Icon name="analytics" />
              </div>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Reporte de Visitantes</p>
            <p className="text-xs text-outline mt-2">Estadísticas de acceso por categorías y tiempos de estadía.</p>
            <button className="mt-4 text-sm font-semibold text-primary hover:underline">Ver Reporte</button>
          </div>
          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-secondary/10 text-secondary rounded-lg">
                <Icon name="history" />
              </div>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Log de Auditoría</p>
            <p className="text-xs text-outline mt-2">Registro inmutable de todas las acciones de los guardas en el sistema.</p>
            <button className="mt-4 text-sm font-semibold text-primary hover:underline">Explorar Log</button>
          </div>
          <div className="bg-surface p-6 rounded-xl border border-border shadow-sm group">
            <div className="flex justify-between items-start mb-4">
              <div className="p-2 bg-warning/10 text-warning rounded-lg">
                <Icon name="warning" />
              </div>
            </div>
            <p className="text-sm font-medium text-on-surface-variant">Reporte de Incidentes</p>
            <p className="text-xs text-outline mt-2">Listado de alertas, accesos denegados y novedades reportadas.</p>
            <button className="mt-4 text-sm font-semibold text-primary hover:underline">Revisar Incidentes</button>
          </div>
        </div>

        <div className="bg-surface border border-border rounded-xl p-6 shadow-sm">
            <div className="flex items-center justify-between mb-6">
                <h3 className="text-lg font-semibold text-text-primary">Actividad Reciente del Sistema</h3>
            </div>
            <div className="space-y-4">
                {[
                    { title: "Exportación de Reporte", user: "Admin_Sede01", time: "Hace 2 horas", icon: "download", color: "text-primary", bg: "bg-primary/10" },
                    { title: "Turno Forzado Cerrado", user: "Super_Admin", time: "Hace 5 horas", icon: "gavel", color: "text-danger", bg: "bg-error-container" },
                    { title: "Nuevo Perfil de Guarda Creado", user: "Admin_Sede01", time: "Ayer 14:30", icon: "person_add", color: "text-success", bg: "bg-success/10" },
                ].map((log, idx) => (
                    <div key={idx} className="flex gap-4 items-center p-3 rounded-lg hover:bg-surface-subtle transition-colors border border-transparent hover:border-border">
                        <div className={`w-10 h-10 rounded-full ${log.bg} flex items-center justify-center ${log.color} shrink-0`}>
                            <Icon name={log.icon} className="text-[20px]" />
                        </div>
                        <div className="flex-grow">
                            <p className="text-sm font-medium text-text-primary">{log.title}</p>
                            <p className="text-xs text-on-surface-variant">Por: {log.user}</p>
                        </div>
                        <span className="text-xs text-outline whitespace-nowrap">{log.time}</span>
                    </div>
                ))}
            </div>
        </div>
      </div>
    </Layout>
  );
}
