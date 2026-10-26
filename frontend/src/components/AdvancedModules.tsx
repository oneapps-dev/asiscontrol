import React, { useEffect, useMemo, useState } from 'react';
import { api, queueOfflineAction } from '../api';
import { Icon } from './Icon';
import { EvidenceFile, EvidencePicker, clearEvidenceFiles, uploadEvidenceFiles } from './EvidencePicker';

type Props = { data: any; apiOnline: boolean };

const labels: Record<string, string> = {
  active: 'Activo', inactive: 'Inactivo', puntual: 'Puntual', tarde: 'Tarde', ausencia: 'Ausencia', incompleto: 'Incompleto', corregido: 'Corregido',
  entrada: 'Entrada', salida: 'Salida', online: 'En línea', offline: 'Sin conexión', import: 'Importado', open: 'Abierto', in_review: 'En revisión', closed: 'Cerrado',
  informative: 'Informativa', review: 'En revisión', warning: 'Revisión', block: 'Bloqueo', pending: 'Pendiente', completed: 'Completado', approved: 'Aprobada', rejected: 'Rechazado', returned: 'Devuelto', cancelled: 'Cancelado', expired: 'Vencido', pre_reservation: 'Pre-reserva', pending_payment: 'Pendiente de pago', paid: 'Pagado', delivered: 'Entregado', failed: 'Fallido', queued: 'En cola', processing: 'Procesando', synced: 'Sincronizado', superseded: 'Reemplazado por una versión más reciente', dismissed: 'Descartado', revoked: 'Revocado', trial: 'Prueba', active_subscription: 'Activa', past_due: 'Pago vencido', suspended: 'Suspendida', visit_exception: 'Excepción de visita', package_release: 'Liberación de paquete', parking_exception: 'Excepción de parqueadero', incident_review: 'Revisión de incidente',
  '1': 'Lunes', '2': 'Martes', '3': 'Miércoles', '4': 'Jueves', '5': 'Viernes', '6': 'Sábado', '7': 'Domingo',
};

function label(value: unknown): string {
  const key = String(value ?? '');
  return labels[key] || key.replace(/[._-]+/g, ' ').replace(/^./, (char) => char.toUpperCase());
}

function Title({ title, subtitle }: { title: string; subtitle?: string }) {
  return <div className="mb-5"><h1 className="text-2xl font-black tracking-tight text-text-primary">{title}</h1>{subtitle && <p className="mt-1 text-sm text-on-surface-variant">{subtitle}</p>}</div>;
}

function Field({ label: fieldLabel, value, onChange, type = 'text', required = false, placeholder }: { label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; placeholder?: string }) {
  return <label className="grid gap-1 text-sm font-semibold text-text-primary"><span>{fieldLabel}</span><input className="field" type={type} value={value} onChange={(event) => onChange(event.target.value)} required={required} placeholder={placeholder} /></label>;
}

function SelectField({ label: fieldLabel, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
  return <label className="grid gap-1 text-sm font-semibold text-text-primary"><span>{fieldLabel}</span><select className="field" value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option value={option} key={option}>{label(option)}</option>)}</select></label>;
}

function StatusPill({ value }: { value: string }) {
  const tone = ['active', 'puntual', 'approved', 'completed'].includes(value) ? 'success' : ['tarde', 'pending', 'in_review', 'review'].includes(value) ? 'warning' : ['inactive', 'closed', 'ausencia', 'incompleto'].includes(value) ? 'muted' : 'info';
  return <span className={`badge tone-${tone}`}>{label(value)}</span>;
}

export function EmployeesModule({ data, apiOnline }: Props) {
  const [rows, setRows] = useState<any[]>([]);
  const [attendance, setAttendance] = useState<any[]>([]);
  const [activeTab, setActiveTab] = useState<'empleados' | 'horarios' | 'asistencia'>('empleados');
  const [error, setError] = useState('');
  const [form, setForm] = useState({ document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', company: '', employee_code: '', schedule_start: '07:00', schedule_end: '17:00', tolerance_mins: '10' });
  const [schedule, setSchedule] = useState({ employee: '', day_of_week: '1', start_time: '07:00', end_time: '17:00', tolerance_mins: '10' });
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);

  const load = async () => {
    if (!apiOnline) return;
    try {
      const [employeesResponse, attendanceResponse] = await Promise.all([api.employees(), api.attendance()]);
      setRows(employeesResponse.data);
      setAttendance(attendanceResponse.data);
      setError('');
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible cargar empleados.'); }
  };
  useEffect(() => { load().catch(() => undefined); }, [apiOnline]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    try {
      const response = await api.createEmployee({ ...form, tolerance_mins: Number(form.tolerance_mins) });
      await uploadEvidenceFiles('employee', String(response.id), evidenceFiles);
      setForm({ document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', company: '', employee_code: '', schedule_start: '07:00', schedule_end: '17:00', tolerance_mins: '10' });
      clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
      await load();
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible guardar el empleado.'); }
  };

  const markAttendance = async (employee: any, eventType: 'entrada' | 'salida') => {
    if (!['admin', 'superadmin'].includes(data.user.role) && !data.activeShift) {
      setError('Debes iniciar turno antes de registrar asistencia.');
      return;
    }
    const occurredAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const payload = { employee_identifier: employee.employee_code || employee.document_number, event_type: eventType, occurred_at: occurredAt, identification_method: 'manual', source: 'offline', site_id: data.user.siteId || null, shift_id: data.activeShift?.id || null };
    try {
      if (apiOnline) {
        await api.createAttendance({ employee_profile_id: employee.employee_profile_id, event_type: eventType, occurred_at: occurredAt, site_id: data.user.siteId || null, shift_id: data.activeShift?.id || null });
        await load();
      } else {
        queueOfflineAction({ method: 'kioskAttendance', args: [payload], label: `${eventType === 'entrada' ? 'Entrada' : 'Salida'} de ${employee.full_name}` });
        setError('');
      }
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible registrar asistencia.'); }
  };

  const saveSchedule = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!schedule.employee) return;
    try { await api.updateEmployeeSchedule(schedule.employee, { day_of_week: Number(schedule.day_of_week), start_time: schedule.start_time, end_time: schedule.end_time, tolerance_mins: Number(schedule.tolerance_mins) }); setError(''); }
    catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible guardar el horario.'); }
  };

  const toggleEmployee = async (employee: any) => {
    try {
      await api.updateEmployee(String(employee.person_id), { status: employee.employee_status === 'inactive' ? 'active' : 'inactive', reason: employee.employee_status === 'inactive' ? 'Reactivación administrativa' : 'Desactivación administrativa' });
      await load();
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible actualizar el estado del empleado.'); }
  };

  return <div className="space-y-4">
    <Title title="Gestión de empleados" subtitle="Ficha laboral, horarios y asistencia con trazabilidad por sede." />
    {!apiOnline && <div className="rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-warning">Conecta la API para editar empleados y registrar asistencia.</div>}
    {!data.activeShift && !['admin', 'superadmin'].includes(data.user.role) && <div className="rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-warning">Debes iniciar turno para registrar entradas y salidas de empleados.</div>}
    {error && <div className="rounded-xl border border-danger/30 bg-danger/10 p-3 text-sm text-danger">{error}</div>}
    <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Secciones de empleados">{(['empleados', 'horarios', 'asistencia'] as const).map((tab) => <button key={tab} type="button" role="tab" aria-selected={activeTab === tab} className={activeTab === tab ? 'btn-primary' : 'btn-ghost'} onClick={() => setActiveTab(tab)}>{tab === 'empleados' ? 'Empleados' : tab === 'horarios' ? 'Horarios' : 'Asistencia'}</button>)}</div>
    <div className="grid gap-4 xl:grid-cols-[0.82fr_1.18fr]">
      <section className="panel">
        <div className="panel-head"><h3>Nuevo empleado</h3><Icon name="badge" /></div>
        {activeTab === 'empleados' && <form className="grid gap-3 sm:grid-cols-2" onSubmit={submit}>
          <SelectField label="Documento" value={form.document_type} onChange={(value) => setForm({ ...form, document_type: value })} options={['CC', 'CE', 'Pasaporte']} />
          <Field label="Número" value={form.document_number} onChange={(value) => setForm({ ...form, document_number: value })} required />
          <div className="sm:col-span-2"><Field label="Nombre completo" value={form.full_name} onChange={(value) => setForm({ ...form, full_name: value })} required /></div>
          <Field label="Código interno" value={form.employee_code} onChange={(value) => setForm({ ...form, employee_code: value })} placeholder="EMP-001" />
          <Field label="Empresa" value={form.company} onChange={(value) => setForm({ ...form, company: value })} />
          <Field label="Teléfono" value={form.phone} onChange={(value) => setForm({ ...form, phone: value })} />
          <Field label="Correo" value={form.email} onChange={(value) => setForm({ ...form, email: value })} type="email" />
          <div className="sm:col-span-2"><EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} label="Soportes del empleado" /></div>
          <button className="btn-primary sm:col-span-2" disabled={!apiOnline}><Icon name="person_add" /> Guardar empleado</button>
        </form>}
      </section>
      <section className="panel">
        <div className="panel-head"><h3>Directorio y control de asistencia</h3><button className="btn-small" onClick={() => load()}><Icon name="refresh" /> Actualizar</button></div>
    {activeTab === 'empleados' && <div className="responsive-table"><table className="data-table"><thead><tr><th>Empleado</th><th>Horario</th><th>Hoy</th><th>Estado</th><th>Acción</th></tr></thead><tbody>{rows.map((row) => <tr key={row.person_id}><td>{row.full_name}<span className="block text-xs text-on-surface-variant">{row.employee_code || 'Sin código'} · {row.document_number}</span></td><td>{row.schedule_start || '—'} – {row.schedule_end || '—'}<span className="block text-xs text-on-surface-variant">Tolerancia {row.tolerance_mins || 0} min</span></td><td><StatusPill value={row.today_events ? 'completed' : 'pending'} /><span className="ml-2 text-xs text-on-surface-variant">{row.today_events || 0} eventos</span></td><td><StatusPill value={row.employee_status || 'active'} /></td><td><div className="flex flex-wrap gap-1"><button className="btn-small" onClick={() => markAttendance(row, 'entrada')} disabled={row.employee_status === 'inactive' || (!data.activeShift && !['admin', 'superadmin'].includes(data.user.role))}><Icon name="login" /> Entrada</button><button className="btn-small" onClick={() => markAttendance(row, 'salida')} disabled={row.employee_status === 'inactive' || (!data.activeShift && !['admin', 'superadmin'].includes(data.user.role))}><Icon name="logout" /> Salida</button><button className="btn-small" onClick={() => setSchedule({ ...schedule, employee: String(row.person_id) })}><Icon name="schedule" /> Horario</button><button className="btn-small" onClick={() => toggleEmployee(row)}><Icon name={row.employee_status === 'inactive' ? 'person_check' : 'person_off'} /> {row.employee_status === 'inactive' ? 'Reactivar' : 'Desactivar'}</button></div></td></tr>)}</tbody></table></div>}
        {rows.length === 0 && <p className="mt-3 text-sm text-on-surface-variant">No hay empleados registrados.</p>}
        {activeTab === 'horarios' && <form className="mt-4 grid gap-3 rounded-xl border border-border bg-surface-container-low p-3 sm:grid-cols-5" onSubmit={saveSchedule}>
          <SelectField label="Empleado" value={schedule.employee} onChange={(value) => setSchedule({ ...schedule, employee: value })} options={['', ...rows.map((row) => String(row.person_id))]} />
          <SelectField label="Día" value={schedule.day_of_week} onChange={(value) => setSchedule({ ...schedule, day_of_week: value })} options={['1', '2', '3', '4', '5', '6', '7']} />
          <Field label="Desde" value={schedule.start_time} onChange={(value) => setSchedule({ ...schedule, start_time: value })} type="time" />
          <Field label="Hasta" value={schedule.end_time} onChange={(value) => setSchedule({ ...schedule, end_time: value })} type="time" />
          <button className="btn-primary self-end" disabled={!apiOnline || !schedule.employee}><Icon name="save" /> Guardar</button>
        </form>}
      </section>
    </div>
    {activeTab === 'asistencia' && <section className="panel"><div className="panel-head"><h3>Últimos registros de asistencia</h3><div className="flex flex-wrap gap-2"><button className="btn-ghost" disabled={!apiOnline} onClick={() => api.exportAttendanceCsv()}><Icon name="download" /> CSV</button><button className="btn-ghost" disabled={!apiOnline} onClick={() => api.exportAttendanceXlsx()}><Icon name="table_view" /> Excel</button><button className="btn-small" disabled={!apiOnline} onClick={() => api.openAttendanceReport()}><Icon name="print" /> Vista imprimible</button></div></div><div className="responsive-table"><table className="data-table"><thead><tr><th>Empleado</th><th>Evento</th><th>Fecha</th><th>Estado</th><th>Origen</th></tr></thead><tbody>{attendance.slice(0, 25).map((row) => <tr key={row.id}><td>{row.full_name}<span className="block text-xs text-on-surface-variant">{row.employee_code || row.document_number}</span></td><td>{label(row.event_type)}</td><td>{row.occurred_at}</td><td><StatusPill value={row.status} /></td><td>{label(row.source)}</td></tr>)}</tbody></table></div></section>}
  </div>;
}

export function KioskModule({ data, apiOnline }: Props) {
  const [identifier, setIdentifier] = useState('');
  const [eventType, setEventType] = useState<'entrada' | 'salida'>('entrada');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const value = identifier.trim();
    if (!value) return;
    setMessage(''); setError(''); setSaving(true);
    const online = apiOnline;
    const payload = {
      employee_identifier: value,
      event_type: eventType,
      occurred_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      site_id: data.user.siteId || null,
      shift_id: data.activeShift?.id && /^\d+$/.test(data.activeShift.id) ? data.activeShift.id : null,
      identification_method: 'manual',
      source: online ? 'online' : 'offline',
      idempotency_key: typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
    };
    try {
      if (online) {
        const result = await api.kioskAttendance(payload);
        await uploadEvidenceFiles('attendance', String(result.id), evidenceFiles);
        setMessage(`Marcación registrada para ${value}: ${label(result.status)}.`);
      } else {
        queueOfflineAction({ method: 'kioskAttendance', args: [payload], label: `Marcación ${eventType} · ${value}` });
        setMessage('Marcación guardada en este dispositivo; se enviará al recuperar la conexión.');
      }
      setIdentifier('');
      clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible registrar la marcación.'); }
    finally { setSaving(false); }
  };

  return <div className="grid min-h-[60vh] place-items-center">
    <section className="panel w-full max-w-xl text-center">
      <div className="mx-auto mb-4 grid h-16 w-16 place-items-center rounded-2xl bg-primary/10 text-primary"><Icon name="touch_app" className="text-4xl" /></div>
      <Title title="Kiosco de asistencia" subtitle="Registra entrada o salida con código interno, documento o correo del empleado." />
      <div className="mb-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-left text-sm text-warning">La integración definitiva del kiosco está pendiente de confirmar el dispositivo, el modo offline y los roles autorizados. Consulta <code>docs/qa-implementation-questions.md</code>; este flujo manual no modifica hardware.</div>
      {!apiOnline && <div className="mb-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-left text-sm text-warning">Sin conexión: la marcación quedará en la cola local y se sincronizará automáticamente.</div>}
      {!data.activeShift && !['admin', 'superadmin'].includes(data.user.role) && <div className="mb-4 rounded-xl border border-warning/30 bg-warning/10 p-3 text-left text-sm text-warning">Debes iniciar turno antes de registrar asistencia.</div>}
      {message && <div className="mb-4 rounded-xl border border-success/30 bg-success/10 p-3 text-left text-sm text-success">{message}</div>}
      {error && <div className="mb-4 rounded-xl border border-danger/30 bg-danger/10 p-3 text-left text-sm text-danger">{error}</div>}
      <form className="grid gap-4 text-left" onSubmit={submit}>
        <Field label="Código, documento o correo" value={identifier} onChange={setIdentifier} required placeholder="Ej. EMP-001 o 1005006007" />
        <SelectField label="Tipo de marcación" value={eventType} onChange={(value) => setEventType(value as 'entrada' | 'salida')} options={['entrada', 'salida']} />
        <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} label="Evidencia de la marcación" />
        <button className="btn-primary min-h-14 text-base" disabled={saving || (!data.activeShift && !['admin', 'superadmin'].includes(data.user.role))}><Icon name={eventType === 'entrada' ? 'login' : 'logout'} /> {saving ? 'Registrando…' : `Registrar ${eventType}`}</button>
      </form>
      <p className="mt-5 text-xs text-on-surface-variant">La identificación biométrica y los lectores se conectarán mediante los adaptadores homologados cuando se defina el hardware.</p>
    </section>
  </div>;
}

export function ContractorsModule({ data, apiOnline }: Props) {
  const [rows, setRows] = useState<any[]>([]);
  const [error, setError] = useState('');
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const [form, setForm] = useState({ document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', company: '', arl: '', arl_until: '', course: '', course_until: '', destination: '' });
  const load = async () => { if (!apiOnline) return; try { setRows((await api.contractors()).data); setError(''); } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible cargar contratistas.'); } };
  useEffect(() => { load().catch(() => undefined); }, [apiOnline]);
  const submit = async (event: React.FormEvent) => { event.preventDefault(); try { const response = await api.createContractor({ ...form, qualifications: [{ type: 'arl', reference: form.arl, valid_until: form.arl_until }, { type: 'curso_alturas', reference: form.course, valid_until: form.course_until }] }); await uploadEvidenceFiles('contractor', String(response.id), evidenceFiles); setForm({ document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', company: '', arl: '', arl_until: '', course: '', course_until: '', destination: '' }); clearEvidenceFiles(evidenceFiles, setEvidenceFiles); await load(); } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible guardar contratista.'); } };
  return <div className="space-y-4"><Title title="Contratistas y proveedores" subtitle="Control temporal, empresa, ARL y soportes de ingreso." />{!apiOnline && <div className="rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-warning">Conecta la API para administrar contratistas.</div>}{error && <div className="rounded-xl border border-danger/30 bg-danger/10 p-3 text-sm text-danger">{error}</div>}<div className="grid gap-4 xl:grid-cols-[0.82fr_1.18fr]"><section className="panel"><div className="panel-head"><h3>Registrar contratista</h3><Icon name="engineering" /></div><form className="grid gap-3 sm:grid-cols-2" onSubmit={submit}><SelectField label="Documento" value={form.document_type} onChange={(value) => setForm({ ...form, document_type: value })} options={['CC', 'CE', 'Pasaporte']} /><Field label="Número" value={form.document_number} onChange={(value) => setForm({ ...form, document_number: value })} required /><div className="sm:col-span-2"><Field label="Nombre completo" value={form.full_name} onChange={(value) => setForm({ ...form, full_name: value })} required /></div><div className="sm:col-span-2"><Field label="Empresa / NIT" value={form.company} onChange={(value) => setForm({ ...form, company: value })} required /></div><Field label="Teléfono" value={form.phone} onChange={(value) => setForm({ ...form, phone: value })} /><Field label="Destino / trabajo" value={form.destination} onChange={(value) => setForm({ ...form, destination: value })} required /><Field label="ARL / referencia" value={form.arl} onChange={(value) => setForm({ ...form, arl: value })} /><Field label="Vigencia ARL" value={form.arl_until} onChange={(value) => setForm({ ...form, arl_until: value })} type="date" /><Field label="Curso / certificado" value={form.course} onChange={(value) => setForm({ ...form, course: value })} /><Field label="Vigencia curso" value={form.course_until} onChange={(value) => setForm({ ...form, course_until: value })} type="date" /><div className="sm:col-span-2"><EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} label="Soportes del contratista" /></div><button className="btn-primary sm:col-span-2" disabled={!apiOnline}><Icon name="save" /> Guardar y enviar a revisión</button></form></section><section className="panel"><div className="panel-head"><h3>Contratistas activos</h3><button className="btn-small" onClick={() => load()}><Icon name="refresh" /> Actualizar</button></div><div className="responsive-table"><table className="data-table"><thead><tr><th>Persona</th><th>Empresa</th><th>Contacto</th><th>Cualificaciones</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.full_name}<span className="block text-xs text-on-surface-variant">{row.document_type} {row.document_number}</span></td><td>{row.company || '—'}</td><td>{row.phone || row.email || '—'}</td><td>{Number(row.qualification_count || 0) > 0 ? <StatusPill value="completed" /> : <StatusPill value="pending" />}</td></tr>)}</tbody></table></div>{rows.length === 0 && <p className="mt-3 text-sm text-on-surface-variant">No hay contratistas activos.</p>}</section></div></div>;
}

export function IntegrityModule({ data, apiOnline }: Props) {
  const [conflicts, setConflicts] = useState<any[]>([]);
  const [backups, setBackups] = useState<any[]>([]);
  const [restores, setRestores] = useState<any[]>([]);
  const [corrections, setCorrections] = useState<any[]>([]);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [restoreReason, setRestoreReason] = useState('');
  const [correctionForm, setCorrectionForm] = useState({ entity_type: 'visit', entity_id: '', field_name: 'reason', new_value: '', reason: '' });
  const load = async () => {
    if (!apiOnline) return;
    try {
      const canResolveOffline = ['superadmin', 'supervisor'].includes(data.user.role);
      const canViewBackups = ['superadmin', 'supervisor'].includes(data.user.role);
      const requests: Promise<any>[] = [];
      if (canResolveOffline) requests.push(api.offlineConflicts());
      if (canViewBackups) requests.push(api.backups());
      if (data.user.role === 'superadmin') requests.push(api.backupRestores(), api.corrections());
      const responses = await Promise.all(requests);
      let index = 0;
      const conflictResponse = canResolveOffline ? responses[index++] : undefined;
      const backupResponse = canViewBackups ? responses[index++] : undefined;
      const restoreResponse = data.user.role === 'superadmin' ? responses[index++] : undefined;
      const correctionResponse = data.user.role === 'superadmin' ? responses[index] : undefined;
      setConflicts(conflictResponse?.data || []); setBackups(backupResponse?.data || []); setRestores(restoreResponse?.data || []); setCorrections(correctionResponse?.data || []); setError('');
    } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible cargar integridad.'); }
  };
  useEffect(() => { load().catch(() => undefined); }, [apiOnline, data.user.role]);
  const resolve = async (id: string) => { const notes = window.prompt('Describe la resolución del conflicto'); if (!notes) return; await api.resolveOfflineConflict(id, { status: 'resolved', resolution_notes: notes }); await load(); };
  const requestRestore = async (id: string) => { const reason = restoreReason.trim() || window.prompt('Motivo de restauración') || ''; if (!reason) return; await api.requestBackupRestore(id, reason); setMessage('Solicitud de restauración creada; requiere aprobación del administrador principal.'); await load(); };
  const approveRestore = async (id: string) => { await api.approveBackupRestore(id); setMessage('Restauración aprobada y enviada a la cola técnica.'); await load(); };
  const createCorrection = async (event: React.FormEvent) => { event.preventDefault(); if (!correctionForm.entity_id || !correctionForm.new_value || !correctionForm.reason) return; await api.createCorrection({ ...correctionForm, entity_id: Number(correctionForm.entity_id) }); setCorrectionForm({ ...correctionForm, entity_id: '', new_value: '', reason: '' }); setMessage('Corrección aplicada y registrada en auditoría.'); await load(); };
  const pendingConflicts = useMemo(() => conflicts.filter((item) => item.status === 'pending'), [conflicts]);
  return <div className="space-y-4">
    <Title title="Integridad y continuidad" subtitle="Conflictos offline, copias de seguridad y restauración con doble confirmación." />
    {!apiOnline && <div className="rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-warning">La API está desconectada. Los registros locales se conservarán y se sincronizarán cuando vuelva la conexión.</div>}
    {message && <div className="rounded-xl border border-success/30 bg-success/10 p-3 text-sm text-success">{message}</div>}
    {error && <div className="rounded-xl border border-danger/30 bg-danger/10 p-3 text-sm text-danger">{error}</div>}
    <div className="grid gap-4 xl:grid-cols-2">
      <section className="panel"><div className="panel-head"><h3>Conflictos de sincronización</h3><div className="flex items-center gap-2"><span className="badge tone-warning">{pendingConflicts.length} pendientes</span><button className="btn-small" onClick={() => load()}><Icon name="refresh" /></button></div></div><div className="space-y-3">{pendingConflicts.map((item) => <article className="rounded-xl border border-border bg-surface-container-low p-3" key={item.id}><div className="flex items-start justify-between gap-2"><div><p className="font-semibold text-text-primary">Evento local {String(item.local_event_uuid).slice(0, 12)}…</p><p className="mt-1 text-sm text-on-surface-variant">{item.reason}</p></div><StatusPill value={item.status} /></div><button className="btn-small mt-3" onClick={() => resolve(String(item.id))}><Icon name="fact_check" /> Marcar como resuelto</button></article>)}{pendingConflicts.length === 0 && <p className="text-sm text-on-surface-variant">No hay conflictos pendientes.</p>}</div></section>
      <section className="panel"><div className="panel-head"><h3>Backups y restauración</h3><button className="btn-small" onClick={() => load()}><Icon name="refresh" /> Actualizar</button></div>{data.user.role === 'superadmin' && <label className="mb-3 grid gap-1 text-sm font-semibold text-text-primary"><span>Motivo de restauración</span><input className="field" value={restoreReason} onChange={(event) => setRestoreReason(event.target.value)} placeholder="Ej. Recuperar datos después de una incidencia" /></label>}<div className="responsive-table"><table className="data-table"><thead><tr><th>Tipo</th><th>Estado</th><th>Fecha</th><th>Acción</th></tr></thead><tbody>{backups.map((backup) => <tr key={backup.id}><td>{label(backup.backup_type)}</td><td><StatusPill value={backup.status} /></td><td>{backup.created_at}</td><td>{backup.status === 'completed' && data.user.role === 'superadmin' ? <button className="btn-small" onClick={() => requestRestore(String(backup.id))}><Icon name="restore" /> Solicitar</button> : '—'}</td></tr>)}</tbody></table></div>{restores.length > 0 && <div className="mt-4 border-t border-border pt-3"><p className="mb-2 text-sm font-bold">Solicitudes de restauración</p>{restores.map((restore) => <div className="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2 text-sm" key={restore.id}><span>#{restore.id} · {restore.reason}</span><span className="flex items-center gap-2"><StatusPill value={restore.status} />{restore.status === 'pending' && data.user.role === 'superadmin' && <button className="btn-small" onClick={() => approveRestore(String(restore.id))}>Aprobar</button>}</span></div>)}</div>}</section>
    </div>
    {data.user.role === 'superadmin' && <section className="panel"><div className="panel-head"><h3>Correcciones administrativas</h3><span className="text-sm text-on-surface-variant">{corrections.length} registradas</span></div><form className="grid gap-3 sm:grid-cols-5" onSubmit={createCorrection}><SelectField label="Registro" value={correctionForm.entity_type} onChange={(value) => setCorrectionForm({ ...correctionForm, entity_type: value })} options={['visit', 'vehicle', 'incident', 'booking']} /><Field label="ID" value={correctionForm.entity_id} onChange={(value) => setCorrectionForm({ ...correctionForm, entity_id: value })} type="number" required /><Field label="Campo" value={correctionForm.field_name} onChange={(value) => setCorrectionForm({ ...correctionForm, field_name: value })} /><Field label="Nuevo valor" value={correctionForm.new_value} onChange={(value) => setCorrectionForm({ ...correctionForm, new_value: value })} required /><div className="flex items-end gap-2"><Field label="Motivo" value={correctionForm.reason} onChange={(value) => setCorrectionForm({ ...correctionForm, reason: value })} required /><button className="btn-primary" disabled={!apiOnline}><Icon name="edit_note" /> Aplicar</button></div></form><div className="mt-4 responsive-table"><table className="data-table"><thead><tr><th>Registro</th><th>Campo</th><th>Anterior</th><th>Nuevo</th><th>Motivo</th></tr></thead><tbody>{corrections.slice(0, 20).map((item) => <tr key={item.id}><td>{item.entity_type} #{item.entity_id}</td><td>{item.field_name}</td><td>{item.previous_value || '—'}</td><td>{item.new_value || '—'}</td><td>{item.reason}</td></tr>)}</tbody></table></div></section>}
  </div>;
}
