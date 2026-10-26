import React, { useEffect, useMemo, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { api, ApiError, apiRequired, getOfflineQueue, queueOfflineAction, syncOfflineQueue } from './api';
import { Icon } from './components/Icon';
import { EmployeesModule, KioskModule, ContractorsModule, IntegrityModule } from './components/AdvancedModules';

type Theme = 'light' | 'dark';
type Role = 'superadmin' | 'admin' | 'guard' | 'supervisor' | 'coordinator' | 'auditor' | 'resident' | 'tech_operator';
type Screen =
  | 'dashboard'
  | 'shift'
  | 'visitors'
  | 'invitations'
  | 'portal'
  | 'approvals'
  | 'people'
  | 'employees'
  | 'kiosk'
  | 'contractors'
  | 'integrity'
  | 'vehicles'
  | 'bookings'
  | 'packages'
  | 'incidents'
  | 'reports'
  | 'settings'
  | 'plans'
  | 'platform';

type Status = 'inside' | 'exited' | 'pending' | 'delivered' | 'rejected' | 'returned' | 'open' | 'in_review' | 'closed';
type BookingStatus = 'pre_reservation' | 'pending' | 'confirmed' | 'pending_payment' | 'paid' | 'cancelled' | 'expired';

interface PersonRecord {
  id: string;
  kind: 'visitante' | 'empleado' | 'contratista' | 'residente' | 'domestico';
  documentType: string;
  documentNumber: string;
  fullName: string;
  phone: string;
  company?: string;
  destination: string;
  host: string;
  reason: string;
  enteredAt: string;
  exitedAt?: string;
  status: Extract<Status, 'inside' | 'exited'>;
  guard: string;
  shiftId: string;
  vehiclePlate?: string;
  alert?: string;
}

interface VehicleRecord {
  id: string;
  plate: string;
  driver: string;
  type: string;
  destination: string;
  parkingSpace?: string;
  enteredAt: string;
  exitedAt?: string;
  status: Extract<Status, 'inside' | 'exited'>;
}

interface PackageRecord {
  id: string;
  tracking: string;
  packageType?: string;
  courier: string;
  recipient: string;
  destination: string;
  receivedAt: string;
  deliveredAt?: string;
  status: Extract<Status, 'pending' | 'delivered' | 'rejected' | 'returned'>;
  signatureName?: string;
}

interface BookingResource {
  id: string;
  name: string;
  type: string;
  capacity: number;
  pricePerHour: number;
  pricePerDay: number;
  color: string;
  rules?: string;
}

interface BookingRecord {
  id: string;
  resourceId: string;
  resourceName: string;
  requesterName: string;
  requesterPhone: string;
  purpose: string;
  startsAt: string;
  endsAt: string;
  status: BookingStatus;
  amount: number;
  paidAt?: string;
  notes?: string;
}

interface EvidenceFile {
  id: string;
  file: File;
  previewUrl: string;
  isImage: boolean;
}

interface EvidenceActionPayload {
  observations: string;
  reason?: string;
  receiverName?: string;
  signatureDataUrl?: string;
  evidenceFiles: EvidenceFile[];
}

interface InvitationRecord {
  id: string;
  guestName: string;
  guestDocument?: string;
  destination: string;
  host: string;
  reason: string;
  qrToken: string;
  validFrom: string;
  validUntil: string;
  status: 'active' | 'used' | 'expired' | 'cancelled';
}

interface IncidentRecord {
  id: string;
  title: string;
  severity: 'baja' | 'media' | 'alta';
  area: string;
  description: string;
  createdAt: string;
  status: Extract<Status, 'open' | 'in_review' | 'closed'>;
}

interface AuditRecord {
  id: string;
  at: string;
  action: string;
  actor: string;
  detail: string;
}

interface AppData {
  theme: Theme;
  user: {
    name: string;
    email: string;
    role: Role;
    tenant: string;
    site: string;
    siteId?: string;
    tenantId?: string;
    residentId?: string;
    unitId?: string;
    isPlatformAdmin?: boolean;
  };
  activeShift?: {
    id: string;
    startedAt: string;
    checkpoint: string;
    notes: string;
  };
  visitors: PersonRecord[];
  vehicles: VehicleRecord[];
  bookingResources: BookingResource[];
  bookings: BookingRecord[];
  packages: PackageRecord[];
  invitations: InvitationRecord[];
  incidents: IncidentRecord[];
  restrictions: Array<{ documentNumber: string; reason: string; level: 'informative' | 'review' | 'warning' | 'block' }>;
  units: any[];
  residents: any[];
  parkingSpaces: any[];
  audit: AuditRecord[];
}

const storageKey = 'asiscontrol.app.v1';

const nowIso = () => new Date().toISOString();
const shortTime = (value: string) => new Intl.DateTimeFormat('es-CO', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
const shortDate = (value: string) => new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const newId = (prefix: string) => `${prefix}-${Math.random().toString(36).slice(2, 8)}-${Date.now().toString(36)}`;
const money = (value: number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value || 0);
const pad2 = (value: number) => String(value).padStart(2, '0');
const startOfMonth = (date: Date) => new Date(date.getFullYear(), date.getMonth(), 1);
const addMonths = (date: Date, months: number) => new Date(date.getFullYear(), date.getMonth() + months, 1);
const dateKey = (date: Date) => `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
const monthLabel = (date: Date) => new Intl.DateTimeFormat('es-CO', { month: 'long', year: 'numeric' }).format(date);
const datetimeLocalValue = (date: Date) => `${dateKey(date)}T${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
const toMysqlDateTime = (value: string) => value.replace('T', ' ') + (value.length === 16 ? ':00' : '');

function queueOfflineAccessEvent(action: 'visit_exit' | 'vehicle_exit', payload: Record<string, unknown>, label: string): void {
  const occurredAt = String(payload.occurred_at || nowIso());
  const eventUuid = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `offline-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  queueOfflineAction({
    method: 'offlineEvents',
    args: [{
      event_uuid: eventUuid,
      idempotency_key: `${action}-${eventUuid}`,
      action,
      client_occurred_at: occurredAt,
      payload: { ...payload, occurred_at: occurredAt },
    }],
    label,
  });
}

function defaultBookingStart(): string {
  const date = new Date();
  date.setHours(date.getHours() + 1, 0, 0, 0);
  return datetimeLocalValue(date);
}

function defaultBookingEnd(): string {
  const date = new Date();
  date.setHours(date.getHours() + 3, 0, 0, 0);
  return datetimeLocalValue(date);
}

function monthCalendarDays(monthDate: Date): Date[] {
  const first = startOfMonth(monthDate);
  const offset = (first.getDay() + 6) % 7;
  const start = new Date(first);
  start.setDate(first.getDate() - offset);
  return Array.from({ length: 42 }, (_, index) => {
    const day = new Date(start);
    day.setDate(start.getDate() + index);
    return day;
  });
}

function hasBookingConflict(bookings: BookingRecord[], resourceId: string, startsAt: string, endsAt: string): boolean {
  const start = new Date(startsAt).getTime();
  const end = new Date(endsAt).getTime();
  if (!resourceId || Number.isNaN(start) || Number.isNaN(end) || end <= start) return true;
  return bookings.some((booking) => (
    booking.resourceId === resourceId
    && booking.status !== 'cancelled'
    && new Date(booking.startsAt).getTime() < end
    && new Date(booking.endsAt).getTime() > start
  ));
}

const initialData: AppData = {
  theme: 'light',
  user: {
    name: 'Carlos Mendoza',
    email: 'carlos.mendoza@asiscontrol.co',
    role: 'guard',
    tenant: 'Conjunto Altos del Norte',
    site: 'Porteria Principal',
    isPlatformAdmin: false,
  },
  activeShift: {
    id: 'turno-demo-001',
    startedAt: new Date(Date.now() - 1000 * 60 * 95).toISOString(),
    checkpoint: 'Porteria Principal',
    notes: 'Turno de demostracion activo.',
  },
  visitors: [
    {
      id: 'vis-001',
      kind: 'visitante',
      documentType: 'CC',
      documentNumber: '1032445678',
      fullName: 'Ricardo Mendoza',
      phone: '3005550198',
      company: 'Independiente',
      destination: 'Torre A - 402',
      host: 'Laura Gomez',
      reason: 'Visita familiar',
      enteredAt: new Date(Date.now() - 1000 * 60 * 42).toISOString(),
      status: 'inside',
      guard: 'Carlos Mendoza',
      shiftId: 'turno-demo-001',
    },
    {
      id: 'vis-002',
      kind: 'contratista',
      documentType: 'CC',
      documentNumber: '79999111',
      fullName: 'Diana Prieto',
      phone: '3107778822',
      company: 'Mantenimiento Integral',
      destination: 'Cuarto tecnico',
      host: 'Administracion',
      reason: 'Revision ascensor',
      enteredAt: new Date(Date.now() - 1000 * 60 * 130).toISOString(),
      status: 'inside',
      guard: 'Carlos Mendoza',
      shiftId: 'turno-demo-001',
      alert: 'Verificar ARL vigente',
    },
  ],
  vehicles: [
    {
      id: 'veh-001',
      plate: 'KLM-428',
      driver: 'Diana Prieto',
      type: 'Camioneta',
      destination: 'Bahia de servicio',
      enteredAt: new Date(Date.now() - 1000 * 60 * 118).toISOString(),
      status: 'inside',
    },
  ],
  bookingResources: [
    {
      id: 'res-001',
      name: 'Salon social',
      type: 'Zona social',
      capacity: 60,
      pricePerHour: 45000,
      pricePerDay: 280000,
      color: '#2563eb',
      rules: 'Requiere deposito y entrega de inventario.',
    },
    {
      id: 'res-002',
      name: 'Sala de reuniones',
      type: 'Sala empresarial',
      capacity: 12,
      pricePerHour: 35000,
      pricePerDay: 190000,
      color: '#7c3aed',
      rules: 'Incluye proyector y wifi.',
    },
  ],
  bookings: [
    {
      id: 'book-001',
      resourceId: 'res-001',
      resourceName: 'Salon social',
      requesterName: 'Laura Gomez',
      requesterPhone: '3002223344',
      purpose: 'Cumpleanos familiar',
      startsAt: new Date(Date.now() + 1000 * 60 * 60 * 26).toISOString(),
      endsAt: new Date(Date.now() + 1000 * 60 * 60 * 31).toISOString(),
      status: 'confirmed',
      amount: 225000,
      notes: 'Pendiente deposito.',
    },
  ],
  packages: [
    {
      id: 'pkg-001',
      tracking: 'ML-884192',
      courier: 'Mercado Envio',
      recipient: 'Apartamento 702',
      destination: 'Torre B',
      receivedAt: new Date(Date.now() - 1000 * 60 * 23).toISOString(),
      status: 'pending',
    },
  ],
  invitations: [],
  incidents: [
    {
      id: 'inc-001',
      title: 'Puerta peatonal con cierre lento',
      severity: 'media',
      area: 'Acceso peatonal',
      description: 'Se reporta demora en el cierre automatico. Se informa a mantenimiento.',
      createdAt: new Date(Date.now() - 1000 * 60 * 55).toISOString(),
      status: 'open',
    },
  ],
  restrictions: [
    { documentNumber: '1111111111', reason: 'Ingreso bloqueado por administracion', level: 'block' },
    { documentNumber: '79999111', reason: 'Solicitar soporte de ARL antes del ingreso', level: 'warning' },
  ],
  units: [],
  residents: [],
  parkingSpaces: [],
  audit: [
    {
      id: 'aud-001',
      at: new Date(Date.now() - 1000 * 60 * 95).toISOString(),
      actor: 'Carlos Mendoza',
      action: 'turno.inicio',
      detail: 'Inicio de turno en Porteria Principal',
    },
  ],
};

function loadData(): AppData {
  try {
    const raw = localStorage.getItem(storageKey);
    return raw ? { ...initialData, ...JSON.parse(raw) } : initialData;
  } catch {
    return initialData;
  }
}

function mapApiData(current: AppData, payload: any, user?: any): AppData {
  const activeSite = payload.sites?.[0];

  return {
    ...current,
    user: {
      ...current.user,
      name: user?.name || current.user.name,
      email: user?.email || current.user.email,
      role: normalizeRole(user?.role || current.user.role),
      tenant: user?.tenant_name || current.user.tenant,
      site: activeSite?.name || current.user.site,
      siteId: activeSite?.id ? String(activeSite.id) : current.user.siteId,
      tenantId: user?.tenant_id ? String(user.tenant_id) : current.user.tenantId,
      residentId: user?.resident_id ? String(user.resident_id) : current.user.residentId,
      unitId: user?.unit_id ? String(user.unit_id) : current.user.unitId,
      isPlatformAdmin: Boolean(payload.platform_admin ?? user?.platform_admin ?? current.user.isPlatformAdmin),
    },
    activeShift: payload.active_shift ? {
      id: String(payload.active_shift.id),
      startedAt: payload.active_shift.started_at,
      checkpoint: payload.active_shift.checkpoint,
      notes: payload.active_shift.notes || '',
    } : undefined,
    visitors: (payload.visits || []).map((item: any): PersonRecord => ({
      id: String(item.id),
      kind: item.person_type || 'visitante',
      documentType: item.document_type,
      documentNumber: item.document_number,
      fullName: item.full_name,
      phone: item.phone || '',
      company: item.company || '',
      destination: item.destination,
      host: item.host,
      reason: item.reason,
      enteredAt: item.entered_at,
      exitedAt: item.exited_at || undefined,
      status: item.status,
      guard: current.user.name,
      shiftId: String(item.shift_id),
      vehiclePlate: item.vehicle_plate || undefined,
    })),
    vehicles: (payload.vehicles || []).map((item: any): VehicleRecord => ({
      id: String(item.id),
      plate: item.plate,
      driver: item.driver_name,
      type: item.vehicle_type,
      destination: item.destination,
      parkingSpace: item.parking_space || undefined,
      enteredAt: item.entered_at,
      exitedAt: item.exited_at || undefined,
      status: item.status,
    })),
    bookingResources: (payload.booking_resources || []).map((item: any): BookingResource => ({
      id: String(item.id),
      name: item.name,
      type: item.resource_type,
      capacity: Number(item.capacity || 0),
      pricePerHour: Number(item.price_per_hour || 0),
      pricePerDay: Number(item.price_per_day || 0),
      color: item.color || '#2563eb',
      rules: item.rules || undefined,
    })),
    bookings: (payload.bookings || []).map((item: any): BookingRecord => ({
      id: String(item.id),
      resourceId: String(item.resource_id),
      resourceName: item.resource_name,
      requesterName: item.requester_name,
      requesterPhone: item.requester_phone || '',
      purpose: item.purpose,
      startsAt: item.starts_at,
      endsAt: item.ends_at,
      status: item.status,
      amount: Number(item.amount || 0),
      paidAt: item.paid_at || undefined,
      notes: item.notes || undefined,
    })),
    packages: (payload.packages || []).map((item: any): PackageRecord => ({
      id: String(item.id),
      tracking: item.tracking,
      packageType: item.package_type || 'paquete',
      courier: item.courier,
      recipient: item.recipient,
      destination: item.destination,
      receivedAt: item.received_at,
      deliveredAt: item.delivered_at || undefined,
      status: item.status,
      signatureName: item.signature_name || undefined,
    })),
    invitations: (payload.invitations || []).map((item: any): InvitationRecord => ({
      id: String(item.id),
      guestName: item.guest_name,
      guestDocument: item.guest_document || undefined,
      destination: item.destination,
      host: item.host,
      reason: item.reason,
      qrToken: item.qr_token,
      validFrom: item.valid_from,
      validUntil: item.valid_until,
      status: item.status,
    })),
    incidents: (payload.incidents || []).map((item: any): IncidentRecord => ({
      id: String(item.id),
      title: item.title,
      severity: item.severity,
      area: item.area,
      description: item.description,
      createdAt: item.created_at,
      status: item.status,
    })),
    restrictions: (payload.restrictions || []).map((item: any) => ({
      documentNumber: item.document_number,
      reason: item.reason,
      level: item.level,
    })),
    units: payload.units || current.units || [],
    residents: payload.residents || current.residents || [],
    parkingSpaces: payload.parking || current.parkingSpaces || [],
    audit: (payload.audit || []).map((item: any): AuditRecord => ({
      id: String(item.id),
      at: item.created_at,
      action: item.action,
      actor: item.actor || 'Sistema',
      detail: item.detail,
    })),
  };
}

function normalizeRole(role: string): Role {
  if (role === 'superadmin' || role === 'admin' || role === 'supervisor' || role === 'coordinator' || role === 'auditor' || role === 'resident' || role === 'tech_operator') return role;
  return 'guard';
}

// Los códigos cortos se conservan en la API y en la base de datos para
// filtros y auditoría. En la interfaz siempre se muestran como textos claros.
const CODE_LABELS: Record<string, string> = {
  'turno.inicio': 'Inicio de turno',
  'turno.cierre': 'Cierre de turno',
  'turno.verificacion': 'Verificación del puesto',
  'persona.archivada': 'Persona archivada',
  'visita.ingreso': 'Ingreso de visitante',
  'visita.salida': 'Salida de visitante',
  'sede.creada': 'Sede creada',
  'sede.actualizada': 'Sede actualizada',
  'punto_acceso.creado': 'Punto de acceso creado',
  'punto_acceso.actualizado': 'Punto de acceso actualizado',
  'dispositivo.autorizado': 'Dispositivo autorizado',
  'dispositivo.revocado': 'Dispositivo revocado',
  'usuario.creado': 'Usuario creado',
  'usuario.actualizado': 'Usuario actualizado',
  'restriccion.creada': 'Restricción creada',
  'parqueadero.creado': 'Cupo de parqueadero creado',
  'parqueadero.actualizado': 'Cupo de parqueadero actualizado',
  'reserva.recurso_creado': 'Espacio reservable creado',
  'reserva.creada': 'Reserva creada',
  'reserva.estado': 'Estado de reserva actualizado',
  'reserva.eliminada': 'Reserva eliminada',
  'vehiculo.ingreso': 'Ingreso de vehículo',
  'vehiculo.salida': 'Salida de vehículo',
  'permisos.actualizados': 'Permisos actualizados',
  'unidad.creada': 'Unidad creada',
  'residente.creado': 'Residente creado',
  'paquete.recibido': 'Paquete recibido',
  'paquete.entregado': 'Paquete entregado',
  'paquete.firma': 'Firma de entrega de paquete',
  'aprobacion.solicitada': 'Solicitud de aprobación',
  'aprobacion.approved': 'Solicitud aprobada',
  'aprobacion.rejected': 'Solicitud rechazada',
  'invitacion.creada': 'Invitación creada',
  'invitacion.cancelada': 'Invitación cancelada',
  'invitacion.usada': 'Invitación utilizada',
  'incidente.creado': 'Incidente registrado',
  'incidente.cerrado': 'Incidente cerrado',
  'importacion.csv': 'Importación de archivo CSV',
  'archivo.subido': 'Archivo adjunto cargado',
  'acceso.evento': 'Evento de acceso registrado',
  'offline.conflicto_auto': 'Conflicto offline resuelto automáticamente',
  'offline.evento_aplicado': 'Evento offline sincronizado',
  'offline.evento_recibido': 'Evento offline recibido',
  'offline.conflicto_resuelto': 'Conflicto offline revisado',
  'backup.solicitado': 'Backup solicitado',
  'backup.politica_actualizada': 'Política de backups actualizada',
  'cliente.creado': 'Cliente creado',
  'cliente.estado': 'Estado del cliente actualizado',
  'suscripcion.actualizada': 'Suscripción actualizada',
  'cuenta.activada': 'Cuenta activada',
  visit_exception: 'Excepción de visita',
  package_release: 'Liberación de paquete',
  parking_exception: 'Excepción de parqueadero',
  incident_review: 'Revisión de incidente',
  active: 'Activo',
  inactive: 'Inactivo',
  accepted: 'Aceptado',
  resolved: 'Resuelto',
  used: 'Utilizada',
  archived: 'Archivado',
  deleted: 'Eliminado',
  inside: 'Dentro',
  exited: 'Salió',
  pending: 'Pendiente',
  confirmed: 'Confirmada',
  paid: 'Pagada',
  cancelled: 'Cancelada',
  delivered: 'Entregado',
  rejected: 'Rechazada',
  returned: 'Devuelto',
  open: 'Abierto',
  in_review: 'En revisión',
  closed: 'Cerrado',
  approved: 'Aprobada',
  failed: 'Fallido',
  sent: 'Enviado',
  completed: 'Completado',
  queued: 'En cola',
  processing: 'Procesando',
  synced: 'Sincronizado',
  superseded: 'Reemplazado por una versión más reciente',
  dismissed: 'Descartado',
  trial: 'Prueba',
  past_due: 'Pago vencido',
  suspended: 'Suspendido',
  expired: 'Vencido',
  revoked: 'Revocado',
  review: 'En revisión',
  informative: 'Informativa',
  pre_reservation: 'Pre-reserva',
  pending_payment: 'Pendiente de pago',
  manual_review: 'Revisión manual',
  latest_wins: 'Prevalece el más reciente',
  block: 'Bloqueo',
  warning: 'Revisión',
  guard: 'Guardia de seguridad',
  supervisor: 'Supervisor',
  coordinator: 'Coordinador',
  admin: 'Administrador',
  superadmin: 'Administrador principal',
  auditor: 'Auditor',
  resident: 'Residente',
  tech_operator: 'Operador técnico',
  domestico: 'Personal doméstico',
  monthly: 'Mensual',
  annual: 'Anual',
  lifetime: 'Permanente',
  manual: 'Manual',
  daily: 'Diaria',
  weekly: 'Semanal',
  visit: 'Visita',
  vehicle: 'Vehículo',
  package: 'Paquete',
  incident: 'Incidente',
  restrictions: 'Restricciones',
  people: 'Personas',
  users: 'Usuarios',
  technical: 'Técnico',
  operational: 'Operativo',
  consolidated: 'Consolidado',
  con_vigilancia: 'Con vigilancia',
  sin_vigilancia: 'Sin vigilancia',
  'visits.manage': 'Gestionar visitas',
  'vehicles.manage': 'Gestionar vehículos',
  'packages.manage': 'Gestionar paquetes',
  'bookings.manage': 'Gestionar reservas',
  'bookings.view': 'Consultar reservas',
  'incidents.manage': 'Gestionar incidentes',
  'approvals.manage': 'Gestionar aprobaciones',
  'reports.view': 'Consultar reportes',
  'audit.view': 'Consultar auditoría',
  'settings.manage': 'Administrar configuración',
  'imports.manage': 'Gestionar importaciones',
  'files.manage': 'Gestionar adjuntos',
  'devices.manage': 'Gestionar dispositivos',
  'devices.view': 'Consultar dispositivos',
  'devices.revoke': 'Revocar dispositivos',
  'offline.resolve': 'Resolver conflictos sin conexión',
  'backups.manage': 'Gestionar copias de seguridad',
  'corrections.manage': 'Gestionar correcciones',
  'access_events.register': 'Registrar eventos de acceso',
};

function humanizeCode(value: unknown): string {
  const raw = String(value ?? '').trim();
  if (!raw) return '—';
  if (CODE_LABELS[raw]) return CODE_LABELS[raw];
  const natural = raw.replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').trim();
  return natural ? natural.charAt(0).toUpperCase() + natural.slice(1) : '—';
}

function humanizeCodeText(value: unknown): string {
  let text = String(value ?? '');
  Object.entries(CODE_LABELS)
    .sort(([left], [right]) => right.length - left.length)
    .forEach(([code, label]) => {
      const escaped = code.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      // Evita convertir partes de correos (admin@..., people@...) en etiquetas.
      text = text.replace(new RegExp(`(^|[^\\w.@])${escaped}(?=$|[^\\w.@])`, 'g'), (_match, prefix: string) => `${prefix}${label}`);
    });
  return text;
}

function createTextSignature(name: string): string {
  const canvas = document.createElement('canvas');
  canvas.width = 640;
  canvas.height = 220;
  const context = canvas.getContext('2d');
  if (!context) return '';
  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.strokeStyle = '#101828';
  context.lineWidth = 2;
  context.beginPath();
  context.moveTo(48, 162);
  context.lineTo(592, 162);
  context.stroke();
  context.fillStyle = '#101828';
  context.font = '42px cursive';
  context.fillText(name, 64, 128, 500);
  context.font = '18px sans-serif';
  context.fillText(new Date().toLocaleString('es-CO'), 64, 192);
  return canvas.toDataURL('image/png');
}

function App() {
  const [authenticated, setAuthenticated] = useState(false);
  const [screen, setScreen] = useState<Screen>('dashboard');
  const [data, setData] = useState<AppData>(() => loadData());
  const [apiOnline, setApiOnline] = useState(false);
  const [connectionState, setConnectionState] = useState<'ONLINE' | 'MODO_LOCAL' | 'SINCRONIZANDO' | 'ERROR_SINCRONIZACION'>('MODO_LOCAL');
  const [notice, setNotice] = useState('');
  const [offlineCount, setOfflineCount] = useState(() => getOfflineQueue().length);

  useEffect(() => {
    localStorage.setItem(storageKey, JSON.stringify(data));
    document.documentElement.classList.toggle('dark', data.theme === 'dark');
  }, [data]);

  useEffect(() => {
    const updateCount = () => setOfflineCount(getOfflineQueue().length);
    window.addEventListener('asiscontrol-offline-queue', updateCount);
    window.addEventListener('online', updateCount);
    return () => {
      window.removeEventListener('asiscontrol-offline-queue', updateCount);
      window.removeEventListener('online', updateCount);
    };
  }, []);

  const addAudit = (action: string, detail: string) => {
    setData((current) => ({
      ...current,
      audit: [{ id: newId('aud'), at: nowIso(), actor: current.user.name, action, detail }, ...current.audit].slice(0, 80),
    }));
  };

  const updateData = (updater: (current: AppData) => AppData, action: string, detail: string) => {
    setData((current) => {
      const updated = updater(current);
      return {
        ...updated,
        audit: [{ id: newId('aud'), at: nowIso(), actor: updated.user.name, action, detail }, ...updated.audit].slice(0, 80),
      };
    });
  };

  const refreshFromApi = async (user?: any) => {
    try {
      const response = await api.bootstrap();
      setData((current) => mapApiData(current, { ...response.data, platform_admin: response.platform_admin }, user));
      setApiOnline(true);
      setConnectionState('ONLINE');
    } catch (error) {
      setApiOnline(false);
      setConnectionState('ERROR_SINCRONIZACION');
      throw error;
    }
  };

  const handleLogin = async (email: string, password: string) => {
    try {
      const response = await api.login(email, password);
      await refreshFromApi(response.user);
      if (response.user?.role === 'resident') setScreen('portal');
      setNotice('Conectado a base de datos.');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        throw error;
      }

      if (apiRequired) {
        if (error instanceof ApiError && error.status >= 500) {
          const reference = error.requestId ? ` Referencia: ${error.requestId}.` : '';
          const phase = error.code ? ` Código: ${error.code}.` : '';
          throw new Error(`${error.message}.${phase}${reference} Revisa el registro de errores PHP y /api/health.`);
        }
        throw new Error('API no disponible. Revisa el enrutamiento PHP, el archivo .env y la base de datos.');
      }

      const role: Role = email.includes('admin') ? 'admin' : email.includes('supervisor') ? 'supervisor' : 'guard';
      setData((current) => ({ ...current, user: { ...current.user, email, role, name: role === 'admin' ? 'Ana Torres' : current.user.name } }));
      setApiOnline(false);
      setConnectionState('MODO_LOCAL');
      setNotice('API no disponible; usando modo demo local.');
    }
    setAuthenticated(true);
  };

  const handleLogout = async () => {
    if (apiOnline) {
      await api.logout().catch(() => undefined);
    }
    setAuthenticated(false);
  };

  const handleSyncOffline = async () => {
    setConnectionState('SINCRONIZANDO');
    try {
      const result = await syncOfflineQueue();
      setNotice(`Sincronización offline: ${result.synced} enviadas, ${result.failed} pendientes.`);
      setOfflineCount(getOfflineQueue().length);
      if (result.failed > 0) setConnectionState('ERROR_SINCRONIZACION');
      else setConnectionState('ONLINE');
      if (result.synced > 0) await refreshFromApi().catch(() => undefined);
    } catch {
      setConnectionState('ERROR_SINCRONIZACION');
    }
  };

  useEffect(() => {
    let syncing = false;
    const synchronize = async () => {
      if (!authenticated || !navigator.onLine || syncing || getOfflineQueue().length === 0) return;
      syncing = true;
      setConnectionState('SINCRONIZANDO');
      try {
        const result = await syncOfflineQueue();
        setOfflineCount(getOfflineQueue().length);
        setNotice(`Sincronización automática: ${result.synced} enviadas, ${result.failed} pendientes.`);
        setConnectionState(result.failed > 0 ? 'ERROR_SINCRONIZACION' : 'ONLINE');
        if (result.synced > 0) await refreshFromApi().catch(() => undefined);
      } catch {
        setConnectionState('ERROR_SINCRONIZACION');
      } finally {
        syncing = false;
      }
    };
    const reconnect = () => { refreshFromApi().then(() => synchronize()).catch(() => undefined); };
    window.addEventListener('online', reconnect);
    const interval = window.setInterval(() => { void synchronize(); }, 30000);
    return () => {
      window.removeEventListener('online', reconnect);
      window.clearInterval(interval);
    };
  }, [authenticated]);

  useEffect(() => {
    if (!authenticated) return;
    const refresh = () => { refreshFromApi().catch(() => undefined); };
    const interval = window.setInterval(refresh, 60000);
    return () => window.clearInterval(interval);
  }, [authenticated]);

  // Defensive client-side timeout complements the server session timeout.
  // Any keyboard, pointer or touch activity keeps the session alive.
  useEffect(() => {
    if (!authenticated) return;
    let timer = window.setTimeout(() => { void handleLogout(); }, 30 * 60 * 1000);
    const reset = () => { window.clearTimeout(timer); timer = window.setTimeout(() => { void handleLogout(); }, 30 * 60 * 1000); };
    ['click', 'keydown', 'pointermove', 'touchstart'].forEach((event) => window.addEventListener(event, reset, { passive: true }));
    return () => { window.clearTimeout(timer); ['click', 'keydown', 'pointermove', 'touchstart'].forEach((event) => window.removeEventListener(event, reset)); };
  }, [authenticated]);

  if (!authenticated) {
    return <Login data={data} onLogin={handleLogin} />;
  }

  return (
    <Shell data={data} screen={screen} setScreen={setScreen} setData={setData} onLogout={handleLogout} apiOnline={apiOnline} connectionState={connectionState} notice={notice} offlineCount={offlineCount} onSyncOffline={handleSyncOffline}>
      {screen === 'dashboard' && <Dashboard data={data} setScreen={setScreen} />}
      {screen === 'shift' && <Shift data={data} updateData={updateData} addAudit={addAudit} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'visitors' && <Visitors data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'invitations' && <Invitations data={data} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'portal' && <ResidentPortal data={data} apiOnline={apiOnline} />}
      {screen === 'approvals' && <Approvals data={data} apiOnline={apiOnline} />}
      {screen === 'people' && <People data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'employees' && <EmployeesModule data={data} apiOnline={apiOnline} />}
      {screen === 'kiosk' && <KioskModule data={data} apiOnline={apiOnline} />}
      {screen === 'contractors' && <ContractorsModule data={data} apiOnline={apiOnline} />}
      {screen === 'integrity' && <IntegrityModule data={data} apiOnline={apiOnline} />}
      {screen === 'vehicles' && <Vehicles data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'bookings' && <Bookings data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'packages' && <Packages data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'incidents' && <Incidents data={data} updateData={updateData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'reports' && <Reports data={data} apiOnline={apiOnline} />}
      {screen === 'settings' && <Settings data={data} setData={setData} apiOnline={apiOnline} refreshFromApi={refreshFromApi} />}
      {screen === 'plans' && <Plans />}
      {screen === 'platform' && <PlatformAdmin data={data} apiOnline={apiOnline} />}
    </Shell>
  );
}

function Login({ data, onLogin }: { data: AppData; onLogin: (email: string, password: string) => Promise<void> }) {
  // Keep both credential fields empty so a production login never suggests a
  // seeded/demo account. The operator must enter the organization account.
  const [email, setEmail] = useState('');
  // Never prefill a production credential. Demo credentials belong in the
  // seed documentation and should be entered explicitly by the operator.
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [recoveryOpen, setRecoveryOpen] = useState(false);

  const login = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setLoading(true);
    try {
      await onLogin(email, password);
    } catch (loginError) {
      setError(loginError instanceof Error ? loginError.message : 'No fue posible iniciar sesión.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="min-h-screen bg-background text-on-background grid place-items-center p-4">
      <section className="w-full max-w-[440px]">
        <div className="mb-7 text-center">
          <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-lg bg-primary text-on-primary">
            <Icon name="admin_panel_settings" filled />
          </div>
          <h1 className="text-3xl font-black tracking-tight text-primary">Asiscontrol</h1>
        <p className="mt-1 text-sm font-medium text-on-surface-variant">Gestión integral de accesos</p>
        </div>
        <form onSubmit={login} className="rounded-lg border border-border bg-surface p-6 shadow-sm">
          <h2 className="text-xl font-semibold text-text-primary">Inicio de sesión</h2>
          <p className="mt-1 text-sm text-on-surface-variant">Ingresa con las credenciales configuradas para tu organización.</p>
          <label className="mt-5 block text-sm font-medium" htmlFor="email">Correo</label>
          <input id="email" value={email} onChange={(event) => setEmail(event.target.value)} className="field mt-1" />
          <label className="mt-4 block text-sm font-medium" htmlFor="password">Contraseña</label>
          <input id="password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} className="field mt-1" />
          {error && <Alert tone="danger">{error}</Alert>}
          <button className="btn-primary mt-6 w-full" type="submit" disabled={loading}>
            <Icon name="login" /> {loading ? 'Conectando...' : 'Entrar'}
          </button>
          <button className="mt-3 w-full text-sm font-semibold text-primary underline" type="button" onClick={() => { setRecoveryOpen((value) => !value); setError(''); }}>
            ¿Olvidaste tu contraseña?
          </button>
          {recoveryOpen && <RecoveryForm email={email} onEmailChange={setEmail} />}
        </form>
      </section>
    </main>
  );
}

function RecoveryForm({ email, onEmailChange }: { email: string; onEmailChange: (value: string) => void }) {
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const submit = async () => {
    setLoading(true); setMessage(''); setError('');
    try {
      const response = await api.passwordRequest(email);
      setMessage(response.message || 'Si el correo existe, recibirás instrucciones.');
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'No fue posible solicitar la recuperación.');
    } finally { setLoading(false); }
  };
  return <div className="mt-4 rounded-lg border border-border bg-surface-container-low p-3">
    <label className="label" htmlFor="recovery-email">Correo de recuperación</label>
    <input id="recovery-email" className="field mt-1" type="email" value={email} onChange={(event) => onEmailChange(event.target.value)} required />
    {message && <Alert tone="success">{message}</Alert>}
    {error && <Alert tone="danger">{error}</Alert>}
    <button type="button" className="btn-small mt-2 w-full" disabled={loading} onClick={() => void submit()}>{loading ? 'Enviando…' : 'Enviar instrucciones'}</button>
  </div>;
}

function Shell({
  data,
  screen,
  setScreen,
  setData,
  onLogout,
  apiOnline,
  connectionState,
  notice,
  offlineCount,
  onSyncOffline,
  children,
}: {
  data: AppData;
  screen: Screen;
  setScreen: (screen: Screen) => void;
  setData: React.Dispatch<React.SetStateAction<AppData>>;
  onLogout: () => void;
  apiOnline: boolean;
  connectionState: 'ONLINE' | 'MODO_LOCAL' | 'SINCRONIZANDO' | 'ERROR_SINCRONIZACION';
  notice: string;
  offlineCount: number;
  onSyncOffline: () => Promise<void>;
  children: React.ReactNode;
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const nav: Array<{ id: Screen; label: string; icon: string }> = [
    { id: 'dashboard', label: 'Panel', icon: 'dashboard' },
    { id: 'shift', label: 'Turno', icon: 'schedule' },
    { id: 'visitors', label: 'Visitantes', icon: 'person_add' },
    { id: 'people', label: 'En sitio', icon: 'groups' },
    { id: 'vehicles', label: 'Vehículos', icon: 'directions_car' },
    { id: 'bookings', label: 'Reservas', icon: 'event_available' },
    { id: 'packages', label: 'Paquetes', icon: 'inventory_2' },
    { id: 'incidents', label: 'Incidentes', icon: 'warning' },
    { id: 'invitations', label: 'Invitaciones', icon: 'qr_code_2' },
    { id: 'portal', label: 'Mi portal', icon: 'home_app_logo' },
    { id: 'approvals', label: 'Aprobaciones', icon: 'fact_check' },
    { id: 'employees', label: 'Empleados', icon: 'badge' },
    { id: 'kiosk', label: 'Kiosco', icon: 'touch_app' },
    { id: 'contractors', label: 'Contratistas', icon: 'engineering' },
    { id: 'reports', label: 'Reportes', icon: 'assessment' },
    { id: 'settings', label: 'Config.', icon: 'settings' },
    { id: 'plans', label: 'Planes', icon: 'payments' },
    { id: 'platform', label: 'Clientes', icon: 'admin_panel_settings' },
    { id: 'integrity', label: 'Integridad', icon: 'verified_user' },
  ];
  const visibleNav = nav.filter((item) => {
    if (item.id === 'reports') return ['superadmin', 'supervisor', 'coordinator', 'auditor'].includes(data.user.role);
    if (item.id === 'approvals') return ['superadmin', 'admin'].includes(data.user.role);
    if (item.id === 'settings') return ['superadmin', 'admin', 'supervisor', 'coordinator'].includes(data.user.role);
    if (item.id === 'plans') return ['superadmin', 'admin'].includes(data.user.role);
    if (item.id === 'portal') return data.user.role === 'resident';
    if (item.id === 'invitations') return data.user.role !== 'resident';
    if (item.id === 'employees' || item.id === 'contractors') return ['superadmin', 'admin', 'supervisor', 'coordinator'].includes(data.user.role);
    if (item.id === 'kiosk') return ['superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'tech_operator'].includes(data.user.role);
    if (item.id === 'integrity') return ['superadmin', 'supervisor', 'coordinator'].includes(data.user.role);
    if (item.id === 'platform') return Boolean(data.user.isPlatformAdmin) || data.user.role === 'tech_operator';
    if (item.id === 'shift') return ['guard', 'supervisor', 'coordinator', 'admin', 'superadmin', 'tech_operator'].includes(data.user.role);
    return data.user.role !== 'resident' || item.id === 'dashboard';
  });
  const goToScreen = (nextScreen: Screen) => {
    setScreen(nextScreen);
    setMenuOpen(false);
  };

  const navigation = (
    <>
      <button onClick={() => goToScreen('dashboard')} className="mb-7 flex items-center gap-3 text-left">
        <span className="grid h-10 w-10 place-items-center rounded-lg bg-primary text-on-primary"><Icon name="security" /></span>
        <span>
          <span className="block text-xl font-black text-primary">Asiscontrol</span>
          <span className="text-xs font-medium text-on-surface-variant">Control multi-tenant</span>
        </span>
      </button>
      <nav className="space-y-1">
        {visibleNav.map((item) => (
          <button key={item.id} onClick={() => goToScreen(item.id)} className={`nav-item ${screen === item.id ? 'nav-item-active' : ''}`}>
            <Icon name={item.icon} filled={screen === item.id} /> {item.label}
          </button>
        ))}
      </nav>
    </>
  );

  return (
    <div className="min-h-screen bg-background text-on-background xl:grid xl:grid-cols-[260px_1fr]">
      <aside className="hidden border-r border-border bg-surface p-5 xl:block">
        {navigation}
      </aside>
      {menuOpen && (
        <div className="fixed inset-0 z-40 xl:hidden" role="dialog" aria-modal="true" aria-label="Menu principal">
          <button className="absolute inset-0 bg-black/45" aria-label="Cerrar menu" onClick={() => setMenuOpen(false)} />
          <aside className="relative h-full w-[min(86vw,320px)] overflow-y-auto border-r border-border bg-surface p-5 shadow-2xl">
            <div className="mb-4 flex justify-end">
              <button className="btn-small" onClick={() => setMenuOpen(false)}><Icon name="close" /> Cerrar</button>
            </div>
            {navigation}
          </aside>
        </div>
      )}
      <div className="min-w-0">
        <header className="sticky top-0 z-20 border-b border-border bg-surface/95 backdrop-blur">
          <div className="flex min-h-16 flex-col gap-3 px-4 py-3 md:flex-row md:items-center md:justify-between md:px-6">
            <div className="flex items-center gap-3">
              <button className="btn-ghost xl:hidden" onClick={() => setMenuOpen(true)} aria-label="Abrir menu">
                <Icon name="menu" />
              </button>
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-primary">{data.user.tenant}</p>
                <h2 className="text-lg font-semibold text-text-primary">{data.user.site}</h2>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={connectionState === 'ONLINE' ? 'success' : connectionState === 'ERROR_SINCRONIZACION' ? 'danger' : 'warning'}>{connectionState === 'ONLINE' ? 'En línea · base de datos' : connectionState === 'SINCRONIZANDO' ? 'Sincronizando…' : connectionState === 'ERROR_SINCRONIZACION' ? 'Error de sincronización' : 'Modo local'}</Badge>
              {offlineCount > 0 && (
                <button className="btn-ghost" onClick={onSyncOffline}>
                  <Icon name="sync" /> {offlineCount} pendientes
                </button>
              )}
              {data.activeShift ? <Badge tone="success">Turno activo desde {shortTime(data.activeShift.startedAt)}</Badge> : <Badge tone="danger">Sin turno activo</Badge>}
              <button className="btn-ghost" onClick={() => setData((current) => ({ ...current, theme: current.theme === 'light' ? 'dark' : 'light' }))}>
                <Icon name={data.theme === 'light' ? 'dark_mode' : 'light_mode'} /> {data.theme === 'light' ? 'Oscuro' : 'Claro'}
              </button>
              <button className="btn-ghost" onClick={onLogout}><Icon name="logout" /> Salir</button>
            </div>
          </div>
          {notice && <div className="px-4 pb-3 text-xs font-medium text-on-surface-variant md:px-6">{notice}</div>}
        </header>
        <main className="p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}

function ContextDashboard({ tenantId, role }: { tenantId?: string; role: Role }) {
  const [context, setContext] = useState<{
    tenant_name: string;
    client_type_name: string;
    role: string;
    scope: string;
    metrics: Record<string, number>;
  } | null>(null);
  useEffect(() => {
    let alive = true;
    setContext(null);
    void api.contextualDashboard().then((result) => {
      if (alive) setContext(result.data);
    }).catch(() => { if (alive) setContext(null); });
    return () => { alive = false; };
  }, [tenantId, role]);

  if (!context) return null;
  const extra = context.client_type_name === 'Empresa de seguridad'
    ? [['Clientes administrados', context.metrics.managed_clients || 0]]
    : context.client_type_name === 'Propiedad horizontal'
      ? [['Unidades activas', context.metrics.units_active || 0]]
      : [['Turnos activos', context.metrics.active_shifts || 0]];
  return <section className="panel">
    <div className="panel-head">
      <h3>Indicadores del contexto autorizado</h3>
      <Badge tone="info">{context.client_type_name} · {context.scope === 'tenant' ? 'Cliente completo' : 'Sedes asignadas'}</Badge>
    </div>
    <p className="mb-3 text-sm text-on-surface-variant">{context.tenant_name} · {humanizeCode(context.role)}</p>
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {[['Personas dentro', context.metrics.people_inside], ['Vehículos dentro', context.metrics.vehicles_inside],
        ['Paquetes pendientes', context.metrics.packages_pending], ['Incidentes abiertos', context.metrics.incidents_open],
        ...extra].map(([label, value]) => <div key={String(label)} className="rounded-lg border border-border bg-surface-container-low p-3">
          <p className="text-xs uppercase text-on-surface-variant">{label}</p>
          <p className="mt-1 text-2xl font-black text-primary">{Number(value || 0).toLocaleString('es-CO')}</p>
        </div>)}
    </div>
  </section>;
}

function Dashboard({ data, setScreen }: { data: AppData; setScreen: (screen: Screen) => void }) {
  const insidePeople = data.visitors.filter((visitor) => visitor.status === 'inside').length;
  const insideVehicles = data.vehicles.filter((vehicle) => vehicle.status === 'inside').length;
  const pendingPackages = data.packages.filter((item) => item.status === 'pending').length;
  const openIncidents = data.incidents.filter((incident) => incident.status === 'open').length;
  const latest = [...data.visitors].sort((a, b) => b.enteredAt.localeCompare(a.enteredAt)).slice(0, 10);
  const upcomingBookings = [...data.bookings]
    .filter((booking) => booking.status !== 'cancelled' && new Date(booking.endsAt) >= new Date())
    .sort((a, b) => a.startsAt.localeCompare(b.startsAt))
    .slice(0, 5);
  const activity = Array.from({ length: 7 }, (_, index) => {
    const day = new Date();
    day.setHours(0, 0, 0, 0);
    day.setDate(day.getDate() - (6 - index));
    const next = new Date(day);
    next.setDate(next.getDate() + 1);
    const total = data.visitors.filter((visitor) => {
      const entered = new Date(visitor.enteredAt);
      return entered >= day && entered < next;
    }).length;
    return { label: new Intl.DateTimeFormat('es-CO', { weekday: 'short' }).format(day).replace('.', ''), total };
  });
  const activityMax = Math.max(1, ...activity.map((item) => item.total));

  return (
    <div className="space-y-6">
      <PageTitle title="Panel operativo" subtitle="Resumen vivo de accesos, novedades y pendientes de portería." />
      <ContextDashboard tenantId={data.user.tenantId} role={data.user.role} />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Metric icon="groups" label="Personas dentro" value={insidePeople} tone="primary" onClick={() => setScreen('people')} />
        <Metric icon="directions_car" label="Vehículos dentro" value={insideVehicles} tone="info" onClick={() => setScreen('vehicles')} />
        <Metric icon="inventory_2" label="Paquetes pendientes" value={pendingPackages} tone="warning" onClick={() => setScreen('packages')} />
        <Metric icon="warning" label="Incidentes abiertos" value={openIncidents} tone="danger" onClick={() => setScreen('incidents')} />
      </div>
      <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
        <section className="panel">
          <div className="panel-head">
            <h3>Acciones rápidas</h3>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <QuickAction icon="document_scanner" title="Registrar visita" onClick={() => setScreen('visitors')} />
            <QuickAction icon="logout" title="Registrar salida" onClick={() => setScreen('people')} />
            <QuickAction icon="directions_car" title="Ingreso vehiculo" onClick={() => setScreen('vehicles')} />
            <QuickAction icon="event_available" title="Crear reserva" onClick={() => setScreen('bookings')} />
            <QuickAction icon="inventory_2" title="Recibir paquete" onClick={() => setScreen('packages')} />
            <QuickAction icon="warning" title="Reportar incidente" onClick={() => setScreen('incidents')} />
          </div>
        </section>
        <section className="panel">
          <div className="panel-head"><h3>Alertas y restricciones</h3></div>
          <div className="space-y-3">
            {data.restrictions.map((restriction) => (
              <button key={restriction.documentNumber} type="button" onClick={() => setScreen('people')} className="w-full rounded-lg border border-border bg-surface-container-low p-3 text-left transition hover:border-primary">
                <Badge tone={restriction.level === 'block' ? 'danger' : 'warning'}>{humanizeCode(restriction.level)}</Badge>
                <p className="mt-2 text-sm font-semibold">{restriction.documentNumber}</p>
                <p className="text-sm text-on-surface-variant">{restriction.reason}</p>
              </button>
            ))}
          </div>
        </section>
      </div>
      <section className="panel">
        <div className="panel-head"><h3>Próximas reservas</h3></div>
        <DataTable headers={['Recurso', 'Solicitante', 'Fecha', 'Estado', 'Valor']}>
          {upcomingBookings.map((booking) => (
            <tr key={booking.id}>
              <td>{booking.resourceName}<span className="block text-xs text-on-surface-variant">{booking.purpose}</span></td>
              <td>{booking.requesterName}<span className="block text-xs text-on-surface-variant">{booking.requesterPhone}</span></td>
              <td>{shortDate(booking.startsAt)}</td>
              <td><BookingBadge status={booking.status} /></td>
              <td>{money(booking.amount)}</td>
            </tr>
          ))}
        </DataTable>
        {upcomingBookings.length === 0 && <p className="mt-3 text-sm text-on-surface-variant">No hay reservas próximas.</p>}
      </section>
      <section className="panel">
        <div className="panel-head"><h3>Últimos ingresos</h3><button type="button" className="btn-small" onClick={() => setScreen('people')}>Ver todos</button></div>
        <DataTable headers={['Persona', 'Documento', 'Destino', 'Hora', 'Estado']}>
          {latest.map((visitor) => (
            <tr key={visitor.id}>
              <td>{visitor.fullName}</td>
              <td>{visitor.documentType} {visitor.documentNumber}</td>
              <td>{visitor.destination}</td>
              <td>{shortTime(visitor.enteredAt)}</td>
              <td><Badge tone={visitor.status === 'inside' ? 'success' : 'muted'}>{visitor.status === 'inside' ? 'Dentro' : 'Salió'}</Badge></td>
            </tr>
          ))}
        </DataTable>
      </section>
      <section className="panel">
        <div className="panel-head"><div><h3>Actividad de accesos</h3><p className="mt-1 text-sm text-on-surface-variant">Ingresos registrados durante los últimos siete días.</p></div><Badge tone="info">{activity.reduce((sum, item) => sum + item.total, 0)} ingresos</Badge></div>
        <div className="grid h-40 grid-cols-7 items-end gap-2 sm:gap-4">
          {activity.map((item) => <div key={item.label} className="flex h-full flex-col items-center justify-end gap-2"><span className="text-xs font-bold text-on-surface-variant">{item.total}</span><div className="w-full max-w-12 rounded-t-lg bg-primary transition-all" style={{ height: `${Math.max(8, (item.total / activityMax) * 100)}%` }} title={`${item.total} ingresos`} /><span className="text-xs font-semibold capitalize text-on-surface-variant">{item.label}</span></div>)}
        </div>
      </section>
    </div>
  );
}

function Shift({
  data,
  updateData,
  addAudit,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  addAudit: (action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [checkpoint, setCheckpoint] = useState(data.activeShift?.checkpoint || data.user.site);
  const [notes, setNotes] = useState('');
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);

  if (['admin', 'superadmin'].includes(data.user.role)) {
    return <ShiftMonitor apiOnline={apiOnline} />;
  }

  const start = async () => {
    const payload = { site_id: data.user.siteId || 1, checkpoint, notes };
    if (apiOnline) {
      let createdOnline = false;
      try {
        const response = await api.startShift(payload);
        createdOnline = true;
        await uploadEvidenceFiles('shift_start', response.id, evidenceFiles);
        await refreshFromApi();
        clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
        return;
      } catch {
        // If the connection drops after creation, the local shift remains
        // visible while the next bootstrap reconciles it from the server.
        if (!createdOnline) queueOfflineAction({ method: 'startShift', args: [payload], label: `Inicio de turno en ${checkpoint}` });
      }
    } else {
      queueOfflineAction({ method: 'startShift', args: [payload], label: `Inicio de turno en ${checkpoint}` });
    }
    updateData((current) => ({
      ...current,
      activeShift: { id: newId('turno'), startedAt: nowIso(), checkpoint, notes },
    }), 'turno.inicio', `Inicio de turno en ${checkpoint}`);
  };

  const close = async () => {
    if (apiOnline && data.activeShift) {
      try {
        await api.closeShift(data.activeShift.id);
        await refreshFromApi();
        return;
      } catch {
        if (/^\d+$/.test(String(data.activeShift.id))) queueOfflineAction({ method: 'closeShift', args: [data.activeShift.id], label: 'Cierre de turno' });
      }
    } else if (data.activeShift && /^\d+$/.test(String(data.activeShift.id))) {
      queueOfflineAction({ method: 'closeShift', args: [data.activeShift.id], label: 'Cierre de turno' });
    }
    updateData((current) => ({ ...current, activeShift: undefined }), 'turno.cierre', 'Cierre de turno operativo');
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
      <section className="panel">
        <PageTitle title="Control de turno" subtitle="Todo registro de acceso debe quedar asociado al turno activo." />
        <label className="label" htmlFor="checkpoint">Puesto</label>
        <input id="checkpoint" className="field" value={checkpoint} onChange={(event) => setCheckpoint(event.target.value)} />
        <label className="label" htmlFor="notes">Observaciones</label>
        <textarea id="notes" className="field min-h-28" value={notes} onChange={(event) => setNotes(event.target.value)} />
        <div className="mt-3"><EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} label="Evidencia de apertura" /></div>
        <div className="mt-5 flex flex-wrap gap-2">
          <button className="btn-primary" onClick={start}><Icon name="play_circle" /> Iniciar turno</button>
          <button className="btn-danger" onClick={close} disabled={!data.activeShift}><Icon name="stop_circle" /> Cerrar turno</button>
          <button className="btn-ghost" onClick={() => addAudit('turno.verificacion', 'Verificacion manual del puesto')}><Icon name="fact_check" /> Verificar puesto</button>
        </div>
      </section>
      <AuditPanel audit={data.audit} />
    </div>
  );
}

function ShiftMonitor({ apiOnline }: { apiOnline: boolean }) {
  const [rows, setRows] = useState<any[]>([]);
  const [error, setError] = useState('');
  const load = async () => {
    if (!apiOnline) return;
    try { setRows((await api.shifts()).data); setError(''); } catch (requestError) { setError(requestError instanceof Error ? requestError.message : 'No fue posible cargar los turnos.'); }
  };
  useEffect(() => { void load(); }, [apiOnline]);
  const forceClose = async (row: any) => {
    const reason = window.prompt('Motivo obligatorio del cierre forzado')?.trim();
    if (!reason) return;
    await api.forceCloseShift(String(row.id), reason);
    await load();
  };
  const active = rows.filter((row) => !row.ended_at);
  return <div className="space-y-4">
    <PageTitle title="Control de puestos y turnos activos" subtitle="Vista global para supervisar cobertura, conexión y cierre auditado de turnos." />
    {!apiOnline && <Alert tone="warning">Sin conexión: la última información disponible se conserva en este dispositivo.</Alert>}
    {error && <Alert tone="danger">{error}</Alert>}
    <div className="grid gap-3 sm:grid-cols-3"><Metric icon="schedule" label="Turnos activos" value={active.length} tone="success" /><Metric icon="groups" label="Puestos registrados" value={rows.length} tone="primary" /><Metric icon="cloud_off" label="Sin conexión" value={apiOnline ? 0 : active.length} tone="warning" /></div>
    <section className="panel"><div className="panel-head"><h3>Detalle de puestos</h3><button className="btn-small" onClick={() => void load()}><Icon name="refresh" /> Actualizar</button></div><DataTable headers={['Vigilante', 'Sede / puesto', 'Inicio', 'Estado', 'Acciones']}>
      {rows.map((row) => <tr key={row.id}><td>{row.user_name || '—'}</td><td>{row.site_name || '—'}<span className="block text-xs text-on-surface-variant">{row.checkpoint}</span></td><td>{shortDate(row.started_at)}</td><td><Badge tone={row.ended_at ? 'muted' : 'success'}>{row.ended_at ? 'Cerrado' : 'Activo'}</Badge></td><td>{!row.ended_at && <button className="btn-small" onClick={() => void forceClose(row)}><Icon name="stop_circle" /> Cerrar con motivo</button>}</td></tr>)}
    </DataTable>{rows.length === 0 && <p className="mt-3 text-sm text-on-surface-variant">No hay turnos registrados.</p>}</section>
  </div>;
}

function Visitors({
  data,
  updateData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [form, setForm] = useState({
    kind: 'visitante',
    documentType: 'CC',
    documentNumber: '',
    identificationMethod: 'manual',
    fullName: '',
    phone: '',
    company: '',
    destination: '',
    host: '',
    reason: '',
    vehiclePlate: '',
  });
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const [profiles, setProfiles] = useState<any[]>([]);
  const restriction = data.restrictions.find((item) => item.documentNumber === form.documentNumber.trim());

  useEffect(() => {
    if (!apiOnline) return;
    api.people().then((response) => setProfiles(response.data || [])).catch(() => undefined);
  }, [apiOnline]);

  useEffect(() => {
    const existing = profiles.find((item) => item.document_type === form.documentType && item.document_number === form.documentNumber.trim() && (item.record_status || 'active') === 'active')
      || data.visitors.find((item) => item.documentType === form.documentType && item.documentNumber === form.documentNumber.trim());
    if (!existing || form.fullName.trim()) return;
    setForm((current) => ({
      ...current,
      fullName: existing.fullName || existing.full_name || '',
      phone: existing.phone || '',
      company: existing.company || '',
      destination: existing.destination || current.destination,
      host: existing.host || current.host,
    }));
  }, [form.documentNumber, form.documentType, form.fullName, profiles, data.visitors]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!data.activeShift || restriction?.level === 'block') return;
    const payload = {
      shift_id: data.activeShift.id,
      person_type: form.kind,
      document_type: form.documentType,
      document_number: form.documentNumber.trim(),
      identification_method: form.identificationMethod,
      full_name: form.fullName.trim(),
      phone: form.phone.trim(),
      company: form.company.trim(),
      destination: form.destination.trim(),
      host: form.host.trim(),
      reason: form.reason.trim(),
      vehicle_plate: form.vehiclePlate.trim() || null,
    };
    if (apiOnline) {
      let createdOnline = false;
      try {
        const response = await api.createVisit(payload);
        createdOnline = true;
        await uploadEvidenceFiles('visit', response.id, evidenceFiles);
        await refreshFromApi();
        setForm({ kind: 'visitante', documentType: 'CC', documentNumber: '', identificationMethod: 'manual', fullName: '', phone: '', company: '', destination: '', host: '', reason: '', vehiclePlate: '' });
        clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
        return;
      } catch {
        // If the server accepted the record but the follow-up refresh failed,
        // never enqueue a second create operation.
        if (createdOnline) {
          setForm({ kind: 'visitante', documentType: 'CC', documentNumber: '', identificationMethod: 'manual', fullName: '', phone: '', company: '', destination: '', host: '', reason: '', vehiclePlate: '' });
          clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
          return;
        }
        queueOfflineAction({ method: 'createVisit', args: [payload], label: `Ingreso visita ${form.fullName}` });
      }
    } else {
      queueOfflineAction({ method: 'createVisit', args: [payload], label: `Ingreso visita ${form.fullName}` });
    }
    const visitor: PersonRecord = {
      id: newId('vis'),
      kind: form.kind as PersonRecord['kind'],
      documentType: form.documentType,
      documentNumber: form.documentNumber.trim(),
      fullName: form.fullName.trim(),
      phone: form.phone.trim(),
      company: form.company.trim(),
      destination: form.destination.trim(),
      host: form.host.trim(),
      reason: form.reason.trim(),
      vehiclePlate: form.vehiclePlate.trim() || undefined,
      enteredAt: nowIso(),
      status: 'inside',
      guard: data.user.name,
      shiftId: data.activeShift.id,
      alert: restriction?.reason,
    };
    updateData((current) => ({ ...current, visitors: [visitor, ...current.visitors] }), 'visita.ingreso', `Ingreso de ${visitor.fullName}`);
    setForm({ kind: 'visitante', documentType: 'CC', documentNumber: '', identificationMethod: 'manual', fullName: '', phone: '', company: '', destination: '', host: '', reason: '', vehiclePlate: '' });
    clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
      <section className="panel">
        <PageTitle title="Registrar visitante" subtitle="Ingreso manual compatible con lector PDF417 en modo teclado." />
        {!data.activeShift && <Alert tone="danger">Debes iniciar turno antes de registrar ingresos.</Alert>}
        {restriction && <Alert tone={restriction.level === 'block' ? 'danger' : 'warning'}>{restriction.reason}</Alert>}
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
          <Select label="Tipo" value={form.kind} onChange={(value) => setForm({ ...form, kind: value })} options={['visitante', 'empleado', 'contratista', 'residente', 'domestico']} />
          <Select label="Documento" value={form.documentType} onChange={(value) => setForm({ ...form, documentType: value })} options={['CC', 'Tarjeta de Identidad', 'CE', 'Pasaporte', 'NIT']} />
          <Select label="Método de identificación" value={form.identificationMethod} onChange={(value) => setForm({ ...form, identificationMethod: value })} options={['manual', 'cedula', 'qr', 'huella']} />
          <Input label="Número" value={form.documentNumber} onChange={(value) => setForm({ ...form, documentNumber: value })} required />
          <Input label="Nombre completo" value={form.fullName} onChange={(value) => setForm({ ...form, fullName: value })} required />
          <Input label="Teléfono" value={form.phone} onChange={(value) => setForm({ ...form, phone: value })} />
          <Input label="Empresa" value={form.company} onChange={(value) => setForm({ ...form, company: value })} />
          <div><Input label="Destino" value={form.destination} onChange={(value) => setForm({ ...form, destination: value })} required list="visitor-destinations" /><datalist id="visitor-destinations">{data.units.map((unit: any) => <option key={unit.id} value={[unit.tower, unit.unit_code].filter(Boolean).join(' - ')} />)}</datalist></div>
          <div><Input label="Autoriza / anfitrión" value={form.host} onChange={(value) => setForm({ ...form, host: value })} required list="visitor-hosts" /><datalist id="visitor-hosts">{data.residents.map((resident: any) => <option key={resident.id} value={resident.full_name} />)}</datalist></div>
          <Input label="Motivo" value={form.reason} onChange={(value) => setForm({ ...form, reason: value })} required />
          <Input label="Placa opcional" value={form.vehiclePlate} onChange={(value) => setForm({ ...form, vehiclePlate: value.toUpperCase() })} />
          <div className="sm:col-span-2">
            <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} />
          </div>
          <button className="btn-primary sm:col-span-2" disabled={!data.activeShift || restriction?.level === 'block'}><Icon name="how_to_reg" /> Registrar ingreso</button>
        </form>
      </section>
      <People data={data} updateData={updateData} compact apiOnline={apiOnline} refreshFromApi={refreshFromApi} />
    </div>
  );
}

function Invitations({ data, apiOnline, refreshFromApi }: { data: AppData; apiOnline: boolean; refreshFromApi: () => Promise<void> }) {
  const canCreate = ['admin', 'superadmin'].includes(data.user.role);
  const [form, setForm] = useState({
    guestName: '',
    guestDocument: '',
    destination: '',
    host: '',
    reason: '',
    validUntil: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 16),
  });
  const [createdToken, setCreatedToken] = useState('');
  const [redeem, setRedeem] = useState({ qrToken: '', documentType: 'CC', documentNumber: '', fullName: '', phone: '' });
  const [scannerOpen, setScannerOpen] = useState(false);
  const [statusFilter, setStatusFilter] = useState('todos');
  const [search, setSearch] = useState('');
  const visibleInvitations = data.invitations.filter((item) => (statusFilter === 'todos' || item.status === statusFilter) && `${item.guestName} ${item.guestDocument || ''} ${item.destination} ${item.host}`.toLowerCase().includes(search.toLowerCase()));

  useEffect(() => {
    const documentNumber = redeem.documentNumber.trim();
    if (!documentNumber || redeem.qrToken.trim()) return;
    const match = data.invitations.find((item) => item.status === 'active' && item.guestDocument === documentNumber);
    if (match) setRedeem((current) => ({ ...current, qrToken: match.qrToken, fullName: current.fullName || match.guestName }));
  }, [redeem.documentNumber, redeem.qrToken, data.invitations]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!apiOnline) return;
    const response = await api.createInvitation({
      site_id: data.user.siteId || 1,
      guest_name: form.guestName,
      guest_document: form.guestDocument || null,
      destination: form.destination,
      host: form.host,
      reason: form.reason,
      valid_from: new Date().toISOString().slice(0, 19).replace('T', ' '),
      valid_until: form.validUntil.replace('T', ' '),
    });
    setCreatedToken(response.qr_token);
    setForm({ guestName: '', guestDocument: '', destination: '', host: '', reason: '', validUntil: form.validUntil });
    await refreshFromApi();
  };

  const cancel = async (id: string) => {
    await api.cancelInvitation(id);
    await refreshFromApi();
  };

  const redeemInvitation = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!apiOnline || !data.activeShift) return;
    const documentMatch = data.invitations.find((item) => item.status === 'active' && item.guestDocument === redeem.documentNumber.trim());
    const qrToken = redeem.qrToken.trim() || documentMatch?.qrToken || '';
    if (!qrToken) { window.alert('Ingresa un QR o un documento asociado a una invitación activa.'); return; }
    await api.redeemInvitation({
      qr_token: qrToken,
      shift_id: data.activeShift.id,
      document_type: redeem.documentType,
      document_number: redeem.documentNumber,
      full_name: redeem.fullName,
      phone: redeem.phone,
    });
    setRedeem({ qrToken: '', documentType: 'CC', documentNumber: '', fullName: '', phone: '' });
    await refreshFromApi();
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Invitaciones QR" subtitle="Preautoriza visitas y convierte el token en ingreso al llegar." />
        {!apiOnline && <Alert tone="warning">Las invitaciones requieren API y base de datos.</Alert>}
        {!data.activeShift && <Alert tone="warning">Para redimir invitaciones debes tener turno activo.</Alert>}
        {!canCreate && <Alert tone="warning">La creación de invitaciones está disponible para administradores. Como guardia, valida el QR o documento del invitado.</Alert>}
        {canCreate && <form className="grid gap-3" onSubmit={submit}>
          <Input label="Nombre invitado" value={form.guestName} onChange={(value) => setForm({ ...form, guestName: value })} required />
          <Input label="Documento invitado" value={form.guestDocument} onChange={(value) => setForm({ ...form, guestDocument: value })} />
          <div><Input label="Destino de la invitación" value={form.destination} onChange={(value) => setForm({ ...form, destination: value })} required list="invitation-destinations" /><datalist id="invitation-destinations">{data.units.map((unit: any) => <option key={unit.id} value={[unit.tower, unit.unit_code].filter(Boolean).join(' - ')} />)}</datalist></div>
          <div><Input label="Anfitrión de la invitación" value={form.host} onChange={(value) => setForm({ ...form, host: value })} required list="invitation-hosts" /><datalist id="invitation-hosts">{data.residents.map((resident: any) => <option key={resident.id} value={resident.full_name} />)}</datalist></div>
          <Input label="Motivo de la invitación" value={form.reason} onChange={(value) => setForm({ ...form, reason: value })} required />
          <label className="label" htmlFor="valid-until">Valida hasta</label>
          <input id="valid-until" className="field" type="datetime-local" value={form.validUntil} onChange={(event) => setForm({ ...form, validUntil: event.target.value })} />
          <button className="btn-primary" disabled={!apiOnline}><Icon name="qr_code_2" /> Crear invitación</button>
        </form>}
        {createdToken && (
          <div className="mt-4 rounded-lg border border-border bg-surface-container-low p-3">
            <p className="text-sm font-semibold">Token QR</p>
            <QrCanvas value={createdToken} />
            <code className="mt-2 block break-all text-xs text-on-surface-variant">{createdToken}</code>
          </div>
        )}
        <div className="mt-5 border-t border-border pt-5">
          <h3 className="mb-3 font-semibold text-text-primary">Redimir invitación</h3>
          <form className="grid gap-3" onSubmit={redeemInvitation}>
            <Input label="Token QR (opcional si buscas por documento)" value={redeem.qrToken} onChange={(value) => setRedeem({ ...redeem, qrToken: value })} />
            <button type="button" className="btn-ghost" disabled={!apiOnline} onClick={() => setScannerOpen(true)}><Icon name="qr_code_scanner" /> Escanear QR</button>
            <Select label="Documento visitante" value={redeem.documentType} onChange={(value) => setRedeem({ ...redeem, documentType: value })} options={['CC', 'Tarjeta de Identidad', 'CE', 'Pasaporte', 'NIT']} />
          <Input label="Número de documento" value={redeem.documentNumber} onChange={(value) => setRedeem({ ...redeem, documentNumber: value })} required />
            <Input label="Nombre visitante" value={redeem.fullName} onChange={(value) => setRedeem({ ...redeem, fullName: value })} />
            <Input label="Teléfono del visitante" value={redeem.phone} onChange={(value) => setRedeem({ ...redeem, phone: value })} />
            <button className="btn-primary" disabled={!apiOnline || !data.activeShift}><Icon name="login" /> Registrar ingreso QR</button>
          </form>
          {scannerOpen && <QrScanner onClose={() => setScannerOpen(false)} onScan={(value) => setRedeem((current) => ({ ...current, qrToken: value }))} />}
        </div>
      </section>
      <section className="panel">
        <div className="mb-4 flex flex-wrap items-center gap-2"><input className="field max-w-sm" placeholder="Buscar invitado, documento o evento" value={search} onChange={(event) => setSearch(event.target.value)} /><select className="field max-w-[170px]" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} aria-label="Filtrar estado de invitación"><option value="todos">Todos los estados</option><option value="active">Activas</option><option value="used">Ingresaron</option><option value="expired">Vencidas</option><option value="cancelled">Canceladas</option></select><Badge tone="success">{data.invitations.filter((item) => item.status === 'active').length} activas</Badge><Badge tone="muted">{data.invitations.filter((item) => item.status === 'used').length} ingresaron</Badge></div>
        <DataTable headers={['Invitado', 'Destino', 'Validez', 'Estado', 'Token', 'Accion']}>
          {visibleInvitations.map((invitation) => (
            <tr key={invitation.id}>
              <td>{invitation.guestName}<span className="block text-xs text-on-surface-variant">{invitation.guestDocument || 'Sin documento'}</span></td>
              <td>{invitation.destination}<span className="block text-xs text-on-surface-variant">{invitation.host}</span></td>
              <td>{shortDate(invitation.validUntil)}</td>
              <td><Badge tone={invitation.status === 'active' ? 'success' : 'muted'}>{humanizeCode(invitation.status)}</Badge></td>
              <td><code className="text-xs">{invitation.qrToken.slice(0, 12)}...</code></td>
              <td className="space-x-2">
                <button className="btn-small" onClick={() => api.openInvitationPrint(invitation.id)}>Imprimir</button>
                {invitation.status === 'active' ? <button className="btn-small" onClick={() => cancel(invitation.id)}>Cancelar</button> : <span className="text-sm text-on-surface-variant">Sin accion</span>}
              </td>
            </tr>
          ))}
        </DataTable>
      </section>
    </div>
  );
}

function ResidentPortal({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [portalEmail, setPortalEmail] = useState('');
  const [portalPassword, setPortalPassword] = useState('');
  const [portalToken, setPortalToken] = useState('');
  const [portalResident, setPortalResident] = useState<any>(null);
  const [inviteForm, setInviteForm] = useState({
    guestName: '',
    guestDocument: '',
    reason: 'Visita residencial',
    validUntil: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 16),
  });
  const [packages, setPackages] = useState<any[]>([]);
  const [invitations, setInvitations] = useState<any[]>([]);
  const [portalBookings, setPortalBookings] = useState<any[]>([]);
  const [portalActivity, setPortalActivity] = useState<any[]>([]);
  const [createdToken, setCreatedToken] = useState('');
  const [bookingForm, setBookingForm] = useState({
    resourceId: data.bookingResources[0]?.id || '',
    purpose: '',
    startsAt: defaultBookingStart(),
    endsAt: defaultBookingEnd(),
    termsAccepted: false,
  });
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const portalDestination = portalResident ? [portalResident.tower, portalResident.unit_code].filter(Boolean).join(' - ') : '';

  const requestPortalAccess = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setLoading(true);
    try {
      const response = await api.portalAuthRequest(portalEmail);
      setMessage(response.debug_token ? `${response.message} Token demo: ${response.debug_token}` : response.message);
      if (response.debug_token) setPortalToken(response.debug_token);
    } catch (portalError) {
      setError(portalError instanceof Error ? portalError.message : 'No fue posible solicitar acceso.');
    } finally {
      setLoading(false);
    }
  };

  const loadPortalData = async () => {
    const [packageResponse, invitationResponse, bookingResponse, activityResponse] = await Promise.all([api.portalPackages(), api.portalInvitations(), api.portalBookings(), api.portalActivity()]);
    setPackages(packageResponse.data);
    setInvitations(invitationResponse.data);
    setPortalBookings(bookingResponse.data);
    setPortalActivity(activityResponse.data);
  };

  useEffect(() => {
    if (data.user.role !== 'resident') return;
    const resident = data.residents.find((item: any) => String(item.id) === String(data.user.residentId)) || data.residents.find((item: any) => String(item.email || '').toLowerCase() === data.user.email.toLowerCase());
    if (resident) {
      setPortalResident(resident);
      setPortalEmail(data.user.email);
      if (apiOnline) loadPortalData().catch((requestError) => setError(requestError instanceof Error ? requestError.message : 'No fue posible cargar el portal.'));
    }
  }, [data.user.role, data.user.residentId, data.user.email, data.residents, apiOnline]);

  const verifyPortalAccess = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setLoading(true);
    try {
      const response = await api.portalAuthVerify(portalToken);
      setPortalResident(response.resident);
      setMessage(`Bienvenido/a ${response.resident.full_name}.`);
      await loadPortalData();
    } catch (portalError) {
      setError(portalError instanceof Error ? portalError.message : 'Token invalido o vencido.');
    } finally {
      setLoading(false);
    }
  };

  const loginPortal = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setLoading(true);
    try {
      const response = await api.portalLogin(portalEmail, portalPassword);
      setPortalResident(response.resident);
      setMessage(`Bienvenido/a ${response.resident.full_name}.`);
      await loadPortalData();
    } catch (portalError) {
      setError(portalError instanceof Error ? portalError.message : 'Correo o contraseña inválidos.');
    } finally { setLoading(false); }
  };

  const logoutPortal = async () => {
    await api.portalLogout().catch(() => undefined);
    setPortalResident(null);
    setPackages([]);
    setInvitations([]);
    setPortalBookings([]);
    setPortalActivity([]);
    setCreatedToken('');
    setMessage('Sesión de portal cerrada.');
  };

  const createPortalInvitation = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');

    if (!apiOnline) {
      setError('El portal necesita conexión con la API para crear invitaciones reales.');
      return;
    }

    setLoading(true);
    try {
      const response = await api.portalCreateInvitation({
        guest_name: inviteForm.guestName,
        guest_document: inviteForm.guestDocument || null,
        reason: inviteForm.reason,
        valid_until: inviteForm.validUntil.replace('T', ' '),
      });
      setCreatedToken(response.qr_token);
      setMessage('Invitacion creada. Comparte el QR o el token con tu visitante.');
      setInviteForm((current) => ({ ...current, guestName: '', guestDocument: '' }));
      await loadPortalData();
    } catch (portalError) {
      setError(portalError instanceof Error ? portalError.message : 'No fue posible crear la invitación.');
    } finally {
      setLoading(false);
    }
  };

  const createPortalBooking = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    if (!apiOnline) {
      setError('La solicitud de reserva necesita conexión con la API.');
      return;
    }
    if (!bookingForm.termsAccepted) {
      setError('Acepta el reglamento del espacio para continuar.');
      return;
    }
    setLoading(true);
    try {
      await api.portalCreateBooking({
        resource_id: bookingForm.resourceId,
        purpose: bookingForm.purpose,
        starts_at: toMysqlDateTime(bookingForm.startsAt),
        ends_at: toMysqlDateTime(bookingForm.endsAt),
        terms_accepted: true,
      });
      setMessage('Solicitud de reserva enviada. Administración confirmará disponibilidad y valor.');
      setBookingForm((current) => ({ ...current, purpose: '', termsAccepted: false }));
      await loadPortalData();
    } catch (bookingError) {
      setError(bookingError instanceof Error ? bookingError.message : 'No fue posible solicitar la reserva.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-4">
      <PageTitle title="Portal seguro de residentes" subtitle="Crea invitaciones QR y consulta paquetes, reservas y actividad de tu unidad." />
      {!apiOnline && <Alert tone="warning">Este modulo usa endpoints reales; en modo local solo se muestra la interfaz.</Alert>}
      {message && <Alert tone="success">{message}</Alert>}
      {error && <Alert tone="danger">{error}</Alert>}
      {!portalResident && data.user.role !== 'resident' && (
        <div className="grid gap-4 xl:grid-cols-2">
          <section className="panel">
            <h3 className="mb-1 text-lg font-semibold text-text-primary">Ingresar con contraseña</h3>
            <p className="mb-4 text-sm text-on-surface-variant">Usa tu correo de residente y la contraseña asignada por administración.</p>
            <form className="grid gap-3" onSubmit={loginPortal}>
              <Input label="Correo residente" value={portalEmail} onChange={setPortalEmail} required type="email" />
              <Input label="Contraseña" value={portalPassword} onChange={setPortalPassword} required type="password" />
              <button className="btn-primary" disabled={!apiOnline || loading}><Icon name="login" /> Entrar</button>
            </form>
            <div className="my-5 border-t border-border pt-5">
            <h3 className="mb-1 text-lg font-semibold text-text-primary">Solicitar acceso</h3>
            <p className="mb-4 text-sm text-on-surface-variant">Ingresa el correo registrado como residente. Te enviaremos un token de acceso temporal.</p>
            <form className="grid gap-3" onSubmit={requestPortalAccess}>
              <Input label="Correo residente" value={portalEmail} onChange={setPortalEmail} required />
              <button className="btn-primary" disabled={!apiOnline || loading}><Icon name="mail" /> Enviar token</button>
            </form>
            </div>
          </section>
          <section className="panel">
            <h3 className="mb-1 text-lg font-semibold text-text-primary">Verificar token</h3>
            <p className="mb-4 text-sm text-on-surface-variant">Usa el token recibido para abrir tu portal. El token vence y solo se puede usar una vez.</p>
            <form className="grid gap-3" onSubmit={verifyPortalAccess}>
              <Input label="Token de acceso" value={portalToken} onChange={setPortalToken} required />
              <button className="btn-primary" disabled={!apiOnline || loading}><Icon name="verified_user" /> Entrar al portal</button>
            </form>
          </section>
        </div>
      )}
      {portalResident && (
      <div className="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
        <section className="panel">
          <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h3 className="text-lg font-semibold text-text-primary">Crear invitación para visitante</h3>
              <p className="text-sm text-on-surface-variant">{portalResident.full_name} · {portalDestination} · {portalResident.site_name}</p>
            </div>
            <button type="button" className="btn-small" onClick={logoutPortal}>Cerrar portal</button>
          </div>
          <form className="grid gap-3" onSubmit={createPortalInvitation}>
            <Input label="Nombre visitante" value={inviteForm.guestName} onChange={(value) => setInviteForm({ ...inviteForm, guestName: value })} required />
            <Input label="Documento visitante" value={inviteForm.guestDocument} onChange={(value) => setInviteForm({ ...inviteForm, guestDocument: value })} />
            <Input label="Motivo" value={inviteForm.reason} onChange={(value) => setInviteForm({ ...inviteForm, reason: value })} required />
            <label className="label" htmlFor="portal-valid-until">Valida hasta</label>
            <input id="portal-valid-until" className="field" type="datetime-local" value={inviteForm.validUntil} onChange={(event) => setInviteForm({ ...inviteForm, validUntil: event.target.value })} />
            <button className="btn-primary" disabled={!apiOnline || loading}><Icon name="qr_code_2" /> Crear QR</button>
          </form>
          <div className="mt-6 border-t border-border pt-5">
            <h3 className="mb-1 text-lg font-semibold text-text-primary">Solicitar reserva</h3>
            <p className="mb-4 text-sm text-on-surface-variant">Consulta espacios disponibles y envía una pre-reserva. El valor lo confirma administración.</p>
            <form className="grid gap-3" onSubmit={createPortalBooking}>
              <label className="label" htmlFor="portal-booking-resource">Espacio</label>
              <select id="portal-booking-resource" className="field" value={bookingForm.resourceId} onChange={(event) => setBookingForm({ ...bookingForm, resourceId: event.target.value })} required>
                <option value="">Selecciona un espacio</option>
                {data.bookingResources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name} · capacidad {resource.capacity}</option>)}
              </select>
              <Input label="Motivo o evento" value={bookingForm.purpose} onChange={(value) => setBookingForm({ ...bookingForm, purpose: value })} required />
              <div className="grid gap-3 sm:grid-cols-2">
                <div><label className="label" htmlFor="portal-booking-start">Inicio</label><input id="portal-booking-start" className="field" type="datetime-local" value={bookingForm.startsAt} onChange={(event) => setBookingForm({ ...bookingForm, startsAt: event.target.value })} required /></div>
                <div><label className="label" htmlFor="portal-booking-end">Fin</label><input id="portal-booking-end" className="field" type="datetime-local" value={bookingForm.endsAt} onChange={(event) => setBookingForm({ ...bookingForm, endsAt: event.target.value })} required /></div>
              </div>
              <label className="flex items-start gap-2 text-sm text-on-surface-variant"><input type="checkbox" checked={bookingForm.termsAccepted} onChange={(event) => setBookingForm({ ...bookingForm, termsAccepted: event.target.checked })} required />Acepto el reglamento y las condiciones de uso del espacio.</label>
              <button className="btn-primary" disabled={!apiOnline || loading || !bookingForm.resourceId}><Icon name="event_available" /> Solicitar reserva</button>
            </form>
          </div>
          {createdToken && (
            <div className="mt-4 rounded-lg border border-border bg-surface-container-low p-3">
              <p className="text-sm font-semibold text-text-primary">Código de invitación</p>
              <QrCanvas value={createdToken} />
              <code className="mt-2 block break-all text-xs text-on-surface-variant">{createdToken}</code>
            </div>
          )}
        </section>
        <section className="panel">
          <h3 className="mb-1 text-lg font-semibold text-text-primary">Consultar paquetes</h3>
          <p className="mb-4 text-sm text-on-surface-variant">Solo se muestran paquetes asociados a tu unidad registrada.</p>
          <button className="btn-ghost mb-4" disabled={!apiOnline || loading} onClick={loadPortalData}><Icon name="refresh" /> Actualizar portal</button>
          <DataTable headers={['Guia', 'Mensajeria', 'Destino', 'Recibido', 'Estado']}>
            {packages.map((item) => (
              <tr key={`${item.tracking}-${item.received_at}`}>
                <td>{item.tracking || 'Sin guia'}</td>
                <td>{item.courier || 'Sin dato'}</td>
                <td>{item.recipient}<span className="block text-xs text-on-surface-variant">{item.destination}</span></td>
                <td>{item.received_at ? shortDate(item.received_at) : 'Sin fecha'}</td>
                <td><Badge tone={item.status === 'delivered' ? 'success' : 'warning'}>{item.status === 'delivered' ? 'Entregado' : 'Pendiente'}</Badge></td>
              </tr>
            ))}
          </DataTable>
          <h3 className="mb-3 mt-6 text-lg font-semibold text-text-primary">Invitaciones recientes</h3>
          <DataTable headers={['Invitado', 'Validez', 'Estado', 'Token']}>
            {invitations.map((invitation) => (
              <tr key={`${invitation.qr_token}-${invitation.created_at}`}>
                <td>{invitation.guest_name}<span className="block text-xs text-on-surface-variant">{invitation.reason}</span></td>
                <td>{shortDate(invitation.valid_until)}</td>
                <td><Badge tone={invitation.status === 'active' ? 'success' : 'muted'}>{humanizeCode(invitation.status)}</Badge></td>
                <td><code className="text-xs">{String(invitation.qr_token).slice(0, 12)}...</code></td>
              </tr>
            ))}
          </DataTable>
          <h3 className="mb-3 mt-6 text-lg font-semibold text-text-primary">Mis reservas</h3>
          <DataTable headers={['Espacio', 'Fecha', 'Estado', 'Valor']}>
            {portalBookings.map((booking) => <tr key={booking.id}><td>{booking.resource_name}<span className="block text-xs text-on-surface-variant">{booking.purpose}</span></td><td>{shortDate(booking.starts_at)}</td><td><Badge tone={booking.status === 'paid' ? 'success' : booking.status === 'cancelled' ? 'muted' : 'warning'}>{humanizeCode(booking.status)}</Badge></td><td>{money(Number(booking.amount || 0))}</td></tr>)}
          </DataTable>
          <h3 className="mb-3 mt-6 text-lg font-semibold text-text-primary">Actividad reciente</h3>
          <DataTable headers={['Tipo', 'Detalle', 'Fecha', 'Estado']}>
            {portalActivity.slice(0, 10).map((item) => <tr key={`${item.tipo}-${item.id}`}><td>{humanizeCode(item.tipo)}</td><td>{item.detalle}</td><td>{shortDate(item.ocurrido_at)}</td><td><Badge tone={item.status === 'delivered' || item.status === 'exited' ? 'success' : 'warning'}>{humanizeCode(item.status)}</Badge></td></tr>)}
          </DataTable>
        </section>
      </div>
      )}
    </div>
  );
}

function Approvals({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [rows, setRows] = useState<any[]>([]);
  const [form, setForm] = useState({ subject_type: 'visit_exception', notes: '' });
  const canDecide = data.user.role === 'admin' || data.user.role === 'superadmin' || data.user.role === 'supervisor';

  const load = async () => {
    if (!apiOnline) return;
    const response = await api.approvals();
    setRows(response.data);
  };

  useEffect(() => {
    load().catch(() => undefined);
  }, [apiOnline]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createApproval(form);
    setForm({ subject_type: 'visit_exception', notes: '' });
    await load();
  };

  const decide = async (row: any, decision: 'approve' | 'reject') => {
    await api.decideApproval(String(row.id), decision);
    await load();
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Aprobaciones" subtitle="Solicitudes de excepcion y autorizaciones operativas." />
        {!apiOnline && <Alert tone="warning">Las aprobaciones requieren API.</Alert>}
        <form className="grid gap-3" onSubmit={submit}>
          <Select label="Tipo solicitud" value={form.subject_type} onChange={(value) => setForm({ ...form, subject_type: value })} options={['visit_exception', 'package_release', 'parking_exception', 'incident_review']} />
          <label className="label" htmlFor="approval-notes">Detalle</label>
          <textarea id="approval-notes" className="field min-h-28" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} required />
          <button className="btn-primary" disabled={!apiOnline}><Icon name="send" /> Solicitar aprobacion</button>
        </form>
      </section>
      <section className="panel">
        <DataTable headers={['Solicitud', 'Solicita', 'Estado', 'Fecha', 'Decision']}>
          {rows.map((row) => (
            <tr key={row.id}>
              <td>{humanizeCode(row.subject_type)}<span className="block text-xs text-on-surface-variant">{row.notes}</span></td>
              <td>{row.requested_by_name || 'Sistema'}</td>
              <td><Badge tone={row.status === 'approved' ? 'success' : row.status === 'rejected' ? 'danger' : 'warning'}>{humanizeCode(row.status)}</Badge></td>
              <td>{shortDate(row.requested_at)}</td>
              <td>
                {canDecide && row.status === 'pending' ? (
                  <div className="flex gap-2">
                    <button className="btn-small" onClick={() => decide(row, 'approve')}>Aprobar</button>
                    <button className="btn-small" onClick={() => decide(row, 'reject')}>Rechazar</button>
                  </div>
                ) : (
                  <span className="text-sm text-on-surface-variant">{row.decided_by_name || 'Sin decision'}</span>
                )}
              </td>
            </tr>
          ))}
        </DataTable>
      </section>
    </div>
  );
}

function People({
  data,
  updateData,
  compact = false,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  compact?: boolean;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [query, setQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('todos');
  const [exitTarget, setExitTarget] = useState<PersonRecord | null>(null);
  const [adminExit, setAdminExit] = useState(false);
  const [savingExit, setSavingExit] = useState(false);
  const rows = data.visitors.filter((visitor) => (typeFilter === 'todos' || visitor.kind === typeFilter) && `${visitor.fullName} ${visitor.documentNumber} ${visitor.destination}`.toLowerCase().includes(query.toLowerCase()));
  const employeeInside = data.visitors.filter((visitor) => visitor.kind === 'empleado' && visitor.status === 'inside');

  const administrativeEmployeeExit = async () => {
    if (employeeInside.length === 0) { window.alert('No hay empleados dentro del sitio.'); return; }
    const reason = window.prompt(`Se dará salida administrativa a ${employeeInside.length} empleados. Motivo obligatorio:`)?.trim();
    if (!reason) return;
    if (!window.confirm(`¿Confirmas la salida administrativa de ${employeeInside.length} empleados?`)) return;
    if (apiOnline) {
      await api.adminExitEmployees({ reason });
      await refreshFromApi();
    } else {
      queueOfflineAction({ method: 'adminExitEmployees', args: [{ reason }], label: `Salida administrativa de ${employeeInside.length} empleados` });
      updateData((current) => ({ ...current, visitors: current.visitors.map((item) => item.kind === 'empleado' && item.status === 'inside' ? { ...item, status: 'exited', exitedAt: nowIso() } : item) }), 'empleados.salida_administrativa', reason);
    }
  };

  const exitPerson = async (payload: EvidenceActionPayload) => {
    if (!exitTarget) return;
    setSavingExit(true);
    try {
      if (apiOnline) {
        if (adminExit) await api.adminExitVisit(exitTarget.id, { reason: payload.reason || '', observations: payload.observations });
        else await api.exitVisit(exitTarget.id, { observations: payload.observations });
        await uploadEvidenceFiles('visit_exit', exitTarget.id, payload.evidenceFiles);
        await refreshFromApi();
        setExitTarget(null);
        setAdminExit(false);
        return;
      }
      if (/^\d+$/.test(String(exitTarget.id))) {
        queueOfflineAccessEvent('visit_exit', {
          event_type: 'salida',
          visit_id: Number(exitTarget.id),
          observations: payload.observations || '',
          reason: payload.reason || '',
          exit_type: adminExit ? 'administrativa' : 'normal',
          identification_method: 'manual',
        }, `${adminExit ? 'Salida administrativa' : 'Salida'} de ${exitTarget.fullName}`);
      }
      updateData((current) => ({
        ...current,
        visitors: current.visitors.map((item) => item.id === exitTarget.id ? { ...item, status: 'exited', exitedAt: nowIso() } : item),
      }), 'visita.salida', `Salida de ${exitTarget.fullName}`);
      setExitTarget(null);
      setAdminExit(false);
    } finally {
      setSavingExit(false);
    }
  };

  const closeExitModal = () => {
    if (!savingExit) setExitTarget(null);
  };

  return (
    <section className="panel">
      {!compact && <PageTitle title="Personas en sitio" subtitle="Consulta y salida de visitantes, empleados, residentes y contratistas." />}
      <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-2"><input className="field max-w-md" placeholder="Buscar por nombre, documento o destino" value={query} onChange={(event) => setQuery(event.target.value)} /><select className="field max-w-[170px]" value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} aria-label="Filtrar tipo de persona"><option value="todos">Todos los tipos</option><option value="visitante">Visitantes</option><option value="empleado">Empleados</option><option value="residente">Residentes</option><option value="contratista">Contratistas</option></select></div>
        <div className="flex flex-wrap items-center gap-2"><Badge tone="primary">{rows.filter((item) => item.status === 'inside').length} dentro</Badge>{!compact && ['admin', 'superadmin'].includes(data.user.role) && <button className="btn-small" onClick={administrativeEmployeeExit}><Icon name="group_remove" /> Salida administrativa de empleados</button>}</div>
      </div>
      <DataTable headers={['Nombre', 'Documento', 'Destino', 'Ingreso', 'Tiempo dentro', 'Alerta', 'Accion']}>
        {rows.map((visitor) => (
          <tr key={visitor.id}>
            <td>{visitor.fullName}<span className="block text-xs text-on-surface-variant">{visitor.kind}</span></td>
            <td>{visitor.documentType} {visitor.documentNumber}</td>
            <td>{visitor.destination}<span className="block text-xs text-on-surface-variant">{visitor.host}</span></td>
            <td>{shortDate(visitor.enteredAt)}</td>
            <td>{visitor.status === 'inside' ? `${Math.max(0, Math.round((Date.now() - new Date(visitor.enteredAt).getTime()) / 60000))} min` : '—'}</td>
            <td>{visitor.alert ? <Badge tone="warning">{visitor.alert}</Badge> : <span className="text-on-surface-variant">Sin alerta</span>}</td>
            <td>{visitor.status === 'inside' ? <button className="btn-small" onClick={() => { setAdminExit(false); setExitTarget(visitor); }}>Dar salida</button> : <Badge tone="muted">Salió {visitor.exitedAt ? shortTime(visitor.exitedAt) : ''}</Badge>}</td>
          </tr>
        ))}
      </DataTable>
      {exitTarget && (
        <EvidenceActionModal
          title={adminExit ? 'Salida administrativa de persona' : 'Registrar salida de persona'}
          subtitle={`${exitTarget.fullName} · ${exitTarget.documentNumber}`}
          primaryLabel="Confirmar salida"
          loading={savingExit}
          onClose={closeExitModal}
          onSubmit={exitPerson}
          showReason={adminExit}
        />
      )}
    </section>
  );
}

function Vehicles({
  data,
  updateData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [form, setForm] = useState({ plate: '', driver: '', type: 'Automovil', destination: '', parkingSpace: '', identificationMethod: 'manual' });
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const [exitTarget, setExitTarget] = useState<VehicleRecord | null>(null);
  const [adminExit, setAdminExit] = useState(false);
  const [savingExit, setSavingExit] = useState(false);
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!data.activeShift) { window.alert('Debes iniciar turno antes de registrar un vehículo.'); return; }
    const payload = {
      shift_id: data.activeShift?.id || null,
      plate: form.plate.toUpperCase(),
      driver_name: form.driver,
      vehicle_type: form.type,
      destination: form.destination,
      parking_space: form.parkingSpace || null,
      identification_method: form.identificationMethod,
    };
    if (apiOnline) {
      let createdOnline = false;
      try {
        const response = await api.createVehicle(payload);
        createdOnline = true;
        await uploadEvidenceFiles('vehicle', response.id, evidenceFiles);
        await refreshFromApi();
        setForm({ plate: '', driver: '', type: 'Automovil', destination: '', parkingSpace: '', identificationMethod: 'manual' });
        clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
        return;
      } catch {
        if (createdOnline) {
          setForm({ plate: '', driver: '', type: 'Automovil', destination: '', parkingSpace: '', identificationMethod: 'manual' });
          clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
          return;
        }
        queueOfflineAction({ method: 'createVehicle', args: [payload], label: `Ingreso vehiculo ${form.plate}` });
      }
    } else {
      queueOfflineAction({ method: 'createVehicle', args: [payload], label: `Ingreso vehiculo ${form.plate}` });
    }
    const vehicle: VehicleRecord = { id: newId('veh'), plate: form.plate.toUpperCase(), driver: form.driver, type: form.type, destination: form.destination, parkingSpace: form.parkingSpace || undefined, enteredAt: nowIso(), status: 'inside' };
    updateData((current) => ({ ...current, vehicles: [vehicle, ...current.vehicles] }), 'vehiculo.ingreso', `Ingreso vehiculo ${vehicle.plate}`);
    setForm({ plate: '', driver: '', type: 'Automovil', destination: '', parkingSpace: '', identificationMethod: 'manual' });
    clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
  };
  const exitVehicle = async (payload: EvidenceActionPayload) => {
    if (!exitTarget) return;
    setSavingExit(true);
    try {
      if (apiOnline) {
        if (adminExit) await api.adminExitVehicle(exitTarget.id, { reason: payload.reason || '', observations: payload.observations });
        else await api.exitVehicle(exitTarget.id, { observations: payload.observations });
        await uploadEvidenceFiles('vehicle_exit', exitTarget.id, payload.evidenceFiles);
        await refreshFromApi();
        setExitTarget(null);
        setAdminExit(false);
        return;
      }
      queueOfflineAccessEvent('vehicle_exit', {
        event_type: 'salida',
        vehicle_id: Number(exitTarget.id),
        plate: exitTarget.plate,
        observations: payload.observations || '',
        reason: payload.reason || '',
        exit_type: adminExit ? 'administrativa' : 'normal',
        identification_method: 'manual',
      }, `${adminExit ? 'Salida administrativa' : 'Salida'} de vehículo ${exitTarget.plate}`);
      updateData((current) => ({
        ...current,
        vehicles: current.vehicles.map((item) => item.id === exitTarget.id ? { ...item, status: 'exited', exitedAt: nowIso() } : item),
      }), 'vehiculo.salida', `Salida vehiculo ${exitTarget.plate}`);
      setExitTarget(null);
      setAdminExit(false);
    } finally {
      setSavingExit(false);
    }
  };
  const closeExitModal = () => {
    if (!savingExit) setExitTarget(null);
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Vehículos" subtitle="Ingreso y salida de carros, motos, proveedores y carga." />
        {!data.activeShift && <Alert tone="danger">Debes iniciar turno antes de registrar ingresos.</Alert>}
        <form onSubmit={submit} className="grid gap-3">
          <Input label="Placa" value={form.plate} onChange={(value) => setForm({ ...form, plate: value.toUpperCase() })} required />
          <Input label="Conductor" value={form.driver} onChange={(value) => setForm({ ...form, driver: value })} required />
          <Select label="Tipo" value={form.type} onChange={(value) => setForm({ ...form, type: value })} options={['Automóvil', 'Moto', 'Camioneta', 'Camión', 'Bicicleta']} />
          <Input label="Destino" value={form.destination} onChange={(value) => setForm({ ...form, destination: value })} required />
          <Select label="Método de identificación" value={form.identificationMethod} onChange={(value) => setForm({ ...form, identificationMethod: value })} options={['manual', 'cedula', 'qr', 'huella']} />
          <select className="field" value={form.parkingSpace} onChange={(event) => setForm({ ...form, parkingSpace: event.target.value })} aria-label="Parqueadero asignado">
            <option value="">Sin parqueadero (solo visitante)</option>
            {data.parkingSpaces.map((space: any) => <option key={space.id} value={space.space_code}>{space.space_code} · {space.assigned_to || 'Disponible'}</option>)}
          </select>
          <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} />
          <button className="btn-primary" disabled={!data.activeShift}><Icon name="directions_car" /> Registrar vehículo</button>
        </form>
      </section>
      <section className="panel">
        <DataTable headers={['Placa', 'Conductor', 'Tipo', 'Destino', 'Parqueadero', 'Ingreso', 'Acción']}>
          {data.vehicles.map((vehicle) => (
            <tr key={vehicle.id}>
              <td className="font-semibold">{vehicle.plate}</td>
              <td>{vehicle.driver}</td>
              <td>{vehicle.type}</td>
              <td>{vehicle.destination}</td>
              <td>{vehicle.parkingSpace || '—'}</td>
              <td>{shortTime(vehicle.enteredAt)}</td>
              <td>{vehicle.status === 'inside' ? <button className="btn-small" onClick={() => { setAdminExit(false); setExitTarget(vehicle); }}>Dar salida</button> : <Badge tone="muted">Salió</Badge>}</td>
            </tr>
          ))}
        </DataTable>
      </section>
      {exitTarget && (
        <EvidenceActionModal
          title={adminExit ? 'Salida administrativa de vehículo' : 'Registrar salida de vehículo'}
          subtitle={`${exitTarget.plate} · ${exitTarget.driver}`}
          primaryLabel="Confirmar salida"
          loading={savingExit}
          onClose={closeExitModal}
          onSubmit={exitVehicle}
          showReason={adminExit}
        />
      )}
    </div>
  );
}

function Parking({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState<any[]>([]);
  const [form, setForm] = useState({ space_code: '', assigned_to: '', plate: '', status: 'active' });
  const canAdmin = data.user.role === 'admin' || data.user.role === 'superadmin';

  const load = async (value = query) => {
    if (!apiOnline) return;
    const response = await api.parking(value);
    setRows(response.data);
  };

  useEffect(() => {
    load('').catch(() => undefined);
  }, [apiOnline]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createParking({
      site_id: data.user.siteId || 1,
      ...form,
      space_code: form.space_code.toUpperCase(),
      plate: form.plate.toUpperCase(),
    });
    setForm({ space_code: '', assigned_to: '', plate: '', status: 'active' });
    await load('');
  };

  const toggle = async (row: any) => {
    await api.updateParking(String(row.id), {
      site_id: row.site_id,
      space_code: row.space_code,
      assigned_to: row.assigned_to,
      plate: row.plate,
      status: row.status === 'active' ? 'inactive' : 'active',
    });
    await load(query);
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Parqueaderos" subtitle="Consulta por cupo, placa o asignado." />
        {!apiOnline && <Alert tone="warning">La consulta de parqueaderos requiere API.</Alert>}
        <div className="mb-4 flex gap-2">
          <input className="field" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar placa, cupo o asignado" />
          <button className="btn-primary" onClick={() => load(query)} disabled={!apiOnline}><Icon name="search" /> Buscar</button>
        </div>
        {canAdmin && apiOnline && (
          <form className="grid gap-3" onSubmit={submit}>
            <Input label="Cupo" value={form.space_code} onChange={(value) => setForm({ ...form, space_code: value.toUpperCase() })} required />
            <Input label="Asignado a" value={form.assigned_to} onChange={(value) => setForm({ ...form, assigned_to: value })} required />
            <Input label="Placa" value={form.plate} onChange={(value) => setForm({ ...form, plate: value.toUpperCase() })} />
            <Select label="Estado parqueadero" value={form.status} onChange={(value) => setForm({ ...form, status: value })} options={['active', 'inactive']} />
            <button className="btn-primary"><Icon name="add" /> Crear asignacion</button>
          </form>
        )}
      </section>
      <section className="panel">
        <DataTable headers={['Cupo', 'Asignado', 'Placa', 'Estado', 'Accion']}>
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="font-semibold">{row.space_code}</td>
              <td>{row.assigned_to}</td>
              <td>{row.plate || 'Sin placa'}</td>
              <td><Badge tone={row.status === 'active' ? 'success' : 'muted'}>{row.status === 'active' ? 'Activo' : 'Inactivo'}</Badge></td>
              <td>{canAdmin ? <button className="btn-small" onClick={() => toggle(row)}>{row.status === 'active' ? 'Desactivar' : 'Activar'}</button> : <span className="text-sm text-on-surface-variant">Consulta</span>}</td>
            </tr>
          ))}
        </DataTable>
      </section>
    </div>
  );
}

function Bookings({
  data,
  updateData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const canManage = ['admin', 'superadmin'].includes(data.user.role);
  const canCreate = canManage || data.user.role === 'resident';
  const firstResource = data.bookingResources[0];
  const [calendarDate, setCalendarDate] = useState(() => startOfMonth(new Date()));
  const [selectedDay, setSelectedDay] = useState(() => dateKey(new Date()));
  const [resourceFilter, setResourceFilter] = useState(firstResource?.id || '');
  const [form, setForm] = useState({
    resourceId: firstResource?.id || '',
    requesterName: '',
    requesterPhone: '',
    purpose: '',
    startsAt: defaultBookingStart(),
    endsAt: defaultBookingEnd(),
    amount: firstResource ? String(firstResource.pricePerHour) : '0',
    notes: '',
  });
  const [showForm, setShowForm] = useState(false);

  useEffect(() => {
    if (!form.resourceId && firstResource) {
      setForm((current) => ({ ...current, resourceId: firstResource.id, amount: String(firstResource.pricePerHour) }));
      setResourceFilter(firstResource.id);
    }
  }, [firstResource, form.resourceId]);

  const visibleBookings = data.bookings.filter((booking) => !resourceFilter || booking.resourceId === resourceFilter);
  const selectedBookings = visibleBookings
    .filter((booking) => dateKey(new Date(booking.startsAt)) === selectedDay)
    .sort((a, b) => a.startsAt.localeCompare(b.startsAt));
  const nextBookings = [...data.bookings]
    .filter((booking) => booking.status !== 'cancelled' && new Date(booking.endsAt) >= new Date())
    .sort((a, b) => a.startsAt.localeCompare(b.startsAt))
    .slice(0, 8);
  const calendarDays = monthCalendarDays(calendarDate);
  const selectedResource = data.bookingResources.find((resource) => resource.id === form.resourceId);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const payload = {
      resource_id: form.resourceId,
      requester_name: form.requesterName,
      requester_phone: form.requesterPhone,
      purpose: form.purpose,
      starts_at: toMysqlDateTime(form.startsAt),
      ends_at: toMysqlDateTime(form.endsAt),
      amount: Number(form.amount || 0),
      notes: form.notes,
    };

    if (hasBookingConflict(data.bookings, form.resourceId, payload.starts_at, payload.ends_at)) {
      window.alert('Ese horario ya esta reservado o bloqueado para el recurso seleccionado.');
      return;
    }

    if (apiOnline) {
      if (data.user.role === 'resident') {
        await api.portalCreateBooking({
          resource_id: payload.resource_id,
          purpose: payload.purpose,
          starts_at: payload.starts_at,
          ends_at: payload.ends_at,
          terms_accepted: true,
          notes: payload.notes,
        });
      } else {
        await api.createBooking(payload);
      }
      await refreshFromApi();
    } else {
      const resource = data.bookingResources.find((item) => item.id === form.resourceId);
      const booking: BookingRecord = {
        id: newId('book'),
        resourceId: form.resourceId,
        resourceName: resource?.name || 'Recurso',
        requesterName: form.requesterName,
        requesterPhone: form.requesterPhone,
        purpose: form.purpose,
        startsAt: payload.starts_at,
        endsAt: payload.ends_at,
        status: 'pending',
        amount: Number(form.amount || 0),
        notes: form.notes,
      };
      updateData((current) => ({ ...current, bookings: [...current.bookings, booking] }), 'reserva.creada', `Reserva ${booking.resourceName}`);
    }
    setForm((current) => ({ ...current, requesterName: '', requesterPhone: '', purpose: '', notes: '' }));
  };

  const updateStatus = async (booking: BookingRecord, status: 'confirmed' | 'paid' | 'cancelled') => {
    if (apiOnline) {
      await api.updateBookingStatus(booking.id, status);
      await refreshFromApi();
      return;
    }
    updateData((current) => ({
      ...current,
      bookings: current.bookings.map((item) => item.id === booking.id ? { ...item, status, paidAt: status === 'paid' ? nowIso() : item.paidAt } : item),
    }), 'reserva.estado', `Reserva ${booking.resourceName}: ${humanizeCode(status)}`);
  };

  const deleteBooking = async (booking: BookingRecord) => {
    if (!window.confirm('¿Eliminar esta reserva definitivamente?')) return;
    if (apiOnline) {
      await api.deleteBooking(booking.id);
      await refreshFromApi();
      return;
    }
    updateData((current) => ({ ...current, bookings: current.bookings.filter((item) => item.id !== booking.id) }), 'reserva.eliminada', `Reserva ${booking.resourceName}`);
  };

  const createResource = async () => {
    const name = window.prompt('Nombre del nuevo recurso reservable');
    if (!name) return;
    const payload = {
      site_id: data.user.siteId || 1,
      name,
      resource_type: window.prompt('Tipo de recurso', 'Zona social') || 'Zona social',
      capacity: Number(window.prompt('Capacidad', '20') || 0),
      price_per_hour: Number(window.prompt('Precio por hora', '0') || 0),
      price_per_day: Number(window.prompt('Precio por dia', '0') || 0),
      color: '#0f766e',
      rules: '',
    };
    if (apiOnline) {
      await api.createBookingResource(payload);
      await refreshFromApi();
    } else {
      const resource: BookingResource = {
        id: newId('res'),
        name: payload.name,
        type: payload.resource_type,
        capacity: payload.capacity,
        pricePerHour: payload.price_per_hour,
        pricePerDay: payload.price_per_day,
        color: payload.color,
      };
      updateData((current) => ({ ...current, bookingResources: [...current.bookingResources, resource] }), 'reserva.recurso_creado', resource.name);
    }
  };

  const addBookingGuest = async (booking: BookingRecord) => {
    if (!apiOnline) { window.alert('La gestión de invitados requiere conexión con la API.'); return; }
    const guestName = window.prompt('Nombre completo del invitado');
    if (!guestName) return;
    const guestDocument = window.prompt('Documento del invitado (opcional)') || '';
    const response = await api.createBookingGuest(booking.id, { guest_name: guestName, guest_document: guestDocument || null });
    window.alert(`Invitado agregado. Token QR: ${response.qr_token}`);
  };

  return (
    <div className="space-y-4">
      <PageTitle title="Reservas de espacios" subtitle="Calendario para zonas sociales, salones y salas empresariales por horas o días." />
      <div className="grid gap-4 xl:grid-cols-[0.78fr_1.22fr]">
        <section className="panel">
          <div className="mb-4 flex items-start justify-between gap-3">
            <PageTitle title={canCreate ? 'Nueva reserva' : 'Disponibilidad'} subtitle="El sistema bloquea horarios ocupados por recurso." />
            <div className="flex gap-2">{canCreate && <button className="btn-small" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="add" /> {showForm ? 'Ocultar' : 'Crear reserva'}</button>}{canManage && <button className="btn-small" type="button" onClick={createResource}><Icon name="add_business" /> Gestionar espacios</button>}</div>
          </div>
          {!canCreate && <Alert tone="warning">Tu rol puede consultar disponibilidad y reservas, pero no crear ni modificar reservas.</Alert>}
          {canCreate && showForm && <form onSubmit={submit} className="grid gap-3">
            <label className="label" htmlFor="booking-resource">Espacio</label>
            <select
              id="booking-resource"
              className="field"
              value={form.resourceId}
              onChange={(event) => {
                const resource = data.bookingResources.find((item) => item.id === event.target.value);
                setForm({ ...form, resourceId: event.target.value, amount: resource ? String(resource.pricePerHour) : form.amount });
              }}
              required
            >
              {data.bookingResources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name}</option>)}
            </select>
            {selectedResource && (
              <div className="rounded-xl border border-border bg-surface-container-low p-3 text-sm text-on-surface-variant">
                <strong className="text-text-primary">{selectedResource.type}</strong> · Capacidad {selectedResource.capacity} · Hora {money(selectedResource.pricePerHour)} · Dia {money(selectedResource.pricePerDay)}
                {selectedResource.rules && <p className="mt-1">{selectedResource.rules}</p>}
              </div>
            )}
            <Input label="Solicitante" value={form.requesterName} onChange={(value) => setForm({ ...form, requesterName: value })} required />
            <Input label="Teléfono" value={form.requesterPhone} onChange={(value) => setForm({ ...form, requesterPhone: value })} />
            <Input label="Motivo / evento" value={form.purpose} onChange={(value) => setForm({ ...form, purpose: value })} required />
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className="label" htmlFor="starts-at">Inicio</label>
                <input id="starts-at" className="field" type="datetime-local" value={form.startsAt} onChange={(event) => setForm({ ...form, startsAt: event.target.value })} required />
              </div>
              <div>
                <label className="label" htmlFor="ends-at">Fin</label>
                <input id="ends-at" className="field" type="datetime-local" value={form.endsAt} onChange={(event) => setForm({ ...form, endsAt: event.target.value })} required />
              </div>
            </div>
            <div>
              <label className="label" htmlFor="booking-amount">Valor a cobrar</label>
              <input id="booking-amount" className="field" type="number" min="0" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} readOnly={!canManage} />
            </div>
            <label className="label" htmlFor="booking-notes">Notas</label>
            <textarea id="booking-notes" className="field min-h-24" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="Deposito, inventario, reglamento, observaciones de pago..." />
            <button className="btn-primary"><Icon name="event_available" /> Crear reserva</button>
          </form>}
        </section>

        <section className="panel">
          <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 className="text-xl font-black text-text-primary">Calendario</h2>
              <p className="text-sm text-on-surface-variant">{monthLabel(calendarDate)}</p>
            </div>
            <div className="flex flex-wrap gap-2">
              <button className="btn-small" onClick={() => setCalendarDate(addMonths(calendarDate, -1))}><Icon name="chevron_left" /> Anterior</button>
              <button className="btn-small" onClick={() => { setCalendarDate(startOfMonth(new Date())); setSelectedDay(dateKey(new Date())); }}>Hoy</button>
              <button className="btn-small" onClick={() => setCalendarDate(addMonths(calendarDate, 1))}>Siguiente <Icon name="chevron_right" /></button>
            </div>
          </div>
          <div className="mb-4 max-w-sm">
            <label className="label" htmlFor="resource-filter">Filtrar recurso</label>
            <select id="resource-filter" className="field" value={resourceFilter} onChange={(event) => setResourceFilter(event.target.value)}>
              <option value="">Todos los recursos</option>
              {data.bookingResources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name}</option>)}
            </select>
          </div>
          <div className="grid grid-cols-7 gap-2 text-center text-xs font-bold uppercase tracking-[0.12em] text-on-surface-variant">
            {['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'].map((day) => <span key={day}>{day}</span>)}
          </div>
          <div className="mt-2 grid grid-cols-7 gap-2">
            {calendarDays.map((day) => {
              const key = dateKey(day);
              const dayBookings = visibleBookings.filter((booking) => dateKey(new Date(booking.startsAt)) === key && booking.status !== 'cancelled');
              const isSelected = key === selectedDay;
              const isCurrentMonth = day.getMonth() === calendarDate.getMonth();
              return (
                <button
                  key={key}
                  className={`min-h-24 rounded-xl border p-2 text-left transition ${isSelected ? 'border-primary bg-primary/10' : 'border-border bg-surface-container-low hover:border-primary/50'} ${!isCurrentMonth ? 'opacity-45' : ''}`}
                  onClick={() => setSelectedDay(key)}
                >
                  <span className="text-sm font-black text-text-primary">{day.getDate()}</span>
                  <div className="mt-2 space-y-1">
                    {dayBookings.slice(0, 2).map((booking) => (
                      <span key={booking.id} className="block truncate rounded-full px-2 py-1 text-[11px] font-bold text-white" style={{ backgroundColor: data.bookingResources.find((item) => item.id === booking.resourceId)?.color || '#2563eb' }}>
                        {shortTime(booking.startsAt)} {booking.resourceName}
                      </span>
                    ))}
                    {dayBookings.length > 2 && <span className="block text-xs text-on-surface-variant">+{dayBookings.length - 2} mas</span>}
                  </div>
                </button>
              );
            })}
          </div>
        </section>
      </div>

      <div className="grid gap-4 xl:grid-cols-[1fr_1fr]">
        <section className="panel">
          <div className="panel-head"><h3>Vista del dia seleccionado</h3></div>
          <BookingList bookings={selectedBookings} onStatus={updateStatus} onDelete={deleteBooking} onAddGuest={addBookingGuest} canManage={canManage} />
        </section>
        <section className="panel">
          <div className="panel-head"><h3>Próximas reservas</h3></div>
          <BookingList bookings={nextBookings} onStatus={updateStatus} onDelete={deleteBooking} onAddGuest={addBookingGuest} canManage={canManage} compact />
        </section>
      </div>
    </div>
  );
}

function BookingList({
  bookings,
  onStatus,
  onDelete,
  onAddGuest,
  canManage,
  compact = false,
}: {
  bookings: BookingRecord[];
  onStatus: (booking: BookingRecord, status: 'confirmed' | 'paid' | 'cancelled') => void;
  onDelete: (booking: BookingRecord) => void;
  onAddGuest: (booking: BookingRecord) => void;
  canManage: boolean;
  compact?: boolean;
}) {
  if (bookings.length === 0) {
    return <p className="text-sm text-on-surface-variant">Sin reservas para mostrar.</p>;
  }

  return (
    <div className="space-y-3">
      {bookings.map((booking) => (
        <article key={booking.id} className="rounded-xl border border-border bg-surface-container-low p-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h3 className="font-black text-text-primary">{booking.resourceName}</h3>
              <p className="text-sm text-on-surface-variant">{booking.purpose}</p>
              <p className="mt-2 text-sm"><strong>{booking.requesterName}</strong> · {booking.requesterPhone || 'Sin telefono'}</p>
              <p className="text-sm text-on-surface-variant">{shortDate(booking.startsAt)} - {shortTime(booking.endsAt)}</p>
              {booking.notes && !compact && <p className="mt-2 text-sm text-on-surface-variant">{booking.notes}</p>}
            </div>
            <div className="flex flex-wrap gap-2 sm:justify-end">
              <BookingBadge status={booking.status} />
              <Badge tone="primary">{money(booking.amount)}</Badge>
            </div>
          </div>
          {canManage && <div className="mt-3 flex flex-wrap gap-2">
            {booking.status === 'pending' && <button className="btn-small" onClick={() => onStatus(booking, 'confirmed')}><Icon name="check" /> Confirmar</button>}
            {booking.status !== 'paid' && booking.status !== 'cancelled' && <button className="btn-small" onClick={() => onStatus(booking, 'paid')}><Icon name="payments" /> Confirmar pago</button>}
            {booking.status !== 'cancelled' && <button className="btn-small" onClick={() => onStatus(booking, 'cancelled')}><Icon name="event_busy" /> Cancelar</button>}
            {booking.status !== 'cancelled' && <button className="btn-small" onClick={() => onAddGuest(booking)}><Icon name="group_add" /> Invitado QR</button>}
            <button className="btn-small" onClick={() => onDelete(booking)}><Icon name="delete" /> Eliminar</button>
          </div>}
        </article>
      ))}
    </div>
  );
}

function Packages({
  data,
  updateData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [form, setForm] = useState({ tracking: '', package_type: 'paquete', service_type: '', courier: '', courier_phone: '', recipient: '', destination: '', unit_id: '', recipient_resident_id: '' });
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const [deliveryTarget, setDeliveryTarget] = useState<PackageRecord | null>(null);
  const [savingDelivery, setSavingDelivery] = useState(false);
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!data.activeShift) { window.alert('Debes iniciar turno antes de recibir paquetes.'); return; }
    const payload = {
      shift_id: data.activeShift?.id || null,
      tracking: form.tracking || newId('guia'),
      package_type: form.package_type,
      service_type: form.service_type || null,
      courier: form.courier,
      courier_phone: form.courier_phone,
      recipient: form.recipient,
      destination: form.destination,
      unit_id: form.unit_id || null,
      recipient_resident_id: form.recipient_resident_id || null,
    };
    if (apiOnline) {
      let createdOnline = false;
      try {
        const response = await api.createPackage(payload);
        createdOnline = true;
        await uploadEvidenceFiles('package', response.id, evidenceFiles);
        await refreshFromApi();
        setForm({ tracking: '', package_type: 'paquete', service_type: '', courier: '', courier_phone: '', recipient: '', destination: '', unit_id: '', recipient_resident_id: '' });
        clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
        return;
      } catch {
        if (createdOnline) {
          setForm({ tracking: '', package_type: 'paquete', service_type: '', courier: '', courier_phone: '', recipient: '', destination: '', unit_id: '', recipient_resident_id: '' });
          clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
          return;
        }
        queueOfflineAction({ method: 'createPackage', args: [payload], label: `Paquete ${payload.tracking}` });
      }
    } else {
      queueOfflineAction({ method: 'createPackage', args: [payload], label: `Paquete ${payload.tracking}` });
    }
    const pack: PackageRecord = { id: newId('pkg'), tracking: form.tracking || newId('guia'), packageType: form.package_type, courier: form.courier, recipient: form.recipient, destination: form.destination, receivedAt: nowIso(), status: 'pending' };
    updateData((current) => ({ ...current, packages: [pack, ...current.packages] }), 'paquete.recibido', `Paquete ${pack.tracking} recibido`);
    setForm({ tracking: '', package_type: 'paquete', service_type: '', courier: '', courier_phone: '', recipient: '', destination: '', unit_id: '', recipient_resident_id: '' });
    clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
  };
  const deliver = async (payload: EvidenceActionPayload) => {
    if (!deliveryTarget) return;
    setSavingDelivery(true);
    const receiver = (payload.receiverName || '').trim();
    try {
      if (apiOnline) {
        try {
          if (receiver) {
            await api.signPackage(deliveryTarget.id, {
              receiver_name: receiver,
              signature_data_url: payload.signatureDataUrl || createTextSignature(receiver),
              observations: payload.observations,
            });
          } else {
            await api.deliverPackage(deliveryTarget.id, { observations: payload.observations });
          }
          await uploadEvidenceFiles('package_delivery', deliveryTarget.id, payload.evidenceFiles);
          await refreshFromApi();
          setDeliveryTarget(null);
          return;
        } catch {
          // Si la red se pierde durante la entrega, se conserva como acción
          // pendiente y el operador puede continuar trabajando localmente.
        }
      }
      if (/^\d+$/.test(String(deliveryTarget.id))) {
        if (receiver) {
          queueOfflineAction({ method: 'signPackage', args: [deliveryTarget.id, { receiver_name: receiver, signature_data_url: payload.signatureDataUrl || createTextSignature(receiver), observations: payload.observations }], label: `Entrega de paquete ${deliveryTarget.tracking}` });
        } else {
          queueOfflineAction({ method: 'deliverPackage', args: [deliveryTarget.id, { observations: payload.observations }], label: `Entrega de paquete ${deliveryTarget.tracking}` });
        }
      }
      updateData((current) => ({
        ...current,
        packages: current.packages.map((item) => item.id === deliveryTarget.id ? { ...item, status: 'delivered', deliveredAt: nowIso(), signatureName: receiver || item.signatureName } : item),
      }), 'paquete.entregado', `Paquete ${deliveryTarget.tracking} entregado`);
      setDeliveryTarget(null);
    } finally {
      setSavingDelivery(false);
    }
  };

  const changePackageStatus = async (pack: PackageRecord, status: 'rejected' | 'returned') => {
    const reason = window.prompt(status === 'rejected' ? 'Motivo del rechazo' : 'Motivo de devolución');
    if (!reason?.trim()) return;
    const method = status === 'rejected' ? 'rejectPackage' : 'returnPackage';
    if (apiOnline) {
      try {
        if (status === 'rejected') await api.rejectPackage(pack.id, reason.trim());
        else await api.returnPackage(pack.id, reason.trim());
        await refreshFromApi();
        return;
      } catch {
        // Continúa con cola local para no perder la decisión del operador.
      }
    }
    if (/^\d+$/.test(String(pack.id))) queueOfflineAction({ method, args: [pack.id, reason.trim()], label: `${status === 'rejected' ? 'Rechazo' : 'Devolución'} de paquete ${pack.tracking}` });
    updateData((current) => ({ ...current, packages: current.packages.map((item) => item.id === pack.id ? { ...item, status } : item) }), `paquete.${status}`, `${status === 'rejected' ? 'Paquete rechazado' : 'Paquete devuelto'}: ${pack.tracking}`);
  };

  const closeDeliveryModal = () => {
    if (!savingDelivery) setDeliveryTarget(null);
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Paquetería" subtitle="Recepción, custodia y entrega con trazabilidad." />
        {!data.activeShift && <Alert tone="danger">Debes iniciar turno antes de recibir paquetes.</Alert>}
        <form onSubmit={submit} className="grid gap-3">
          <Input label="Guia" value={form.tracking} onChange={(value) => setForm({ ...form, tracking: value })} />
          <Select label="Tipo de envío" value={form.package_type} onChange={(value) => setForm({ ...form, package_type: value })} options={['documento', 'paquete', 'mercancia', 'alimento', 'servicios', 'otro']} />
          {form.package_type === 'servicios' && <Input label="Tipo de servicio" value={form.service_type} onChange={(value) => setForm({ ...form, service_type: value })} required />}
          <Input label="Transportadora" value={form.courier} onChange={(value) => setForm({ ...form, courier: value })} required />
          <Input label="Teléfono transportadora" value={form.courier_phone} onChange={(value) => setForm({ ...form, courier_phone: value })} />
          <select className="field" value={form.unit_id} onChange={(event) => { const unit = data.units.find((item: any) => String(item.id) === event.target.value); setForm({ ...form, unit_id: event.target.value, destination: unit ? [unit.tower, unit.unit_code].filter(Boolean).join(' - ') : form.destination }); }} required aria-label="Unidad de destino">
            <option value="">Seleccionar unidad de destino</option>
            {data.units.map((unit: any) => <option key={unit.id} value={unit.id}>{[unit.tower, unit.unit_code].filter(Boolean).join(' - ')}</option>)}
          </select>
          <select className="field" value={form.recipient_resident_id} onChange={(event) => { const resident = data.residents.find((item: any) => String(item.id) === event.target.value); setForm({ ...form, recipient_resident_id: event.target.value, recipient: resident?.full_name || form.recipient }); }} required aria-label="Persona destinataria">
            <option value="">Seleccionar residente destinatario</option>
            {data.residents.filter((resident: any) => !form.unit_id || String(resident.unit_id) === String(form.unit_id)).map((resident: any) => <option key={resident.id} value={resident.id}>{resident.full_name}</option>)}
          </select>
          <Input label="Persona externa autorizada (opcional)" value={form.recipient} onChange={(value) => setForm({ ...form, recipient: value })} />
          <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} />
          <button className="btn-primary" disabled={!data.activeShift}><Icon name="inventory_2" /> Recibir paquete</button>
        </form>
      </section>
      <section className="panel">
        <DataTable headers={['Guia', 'Tipo', 'Transportadora', 'Destinatario', 'Recibido', 'Estado', 'Accion']}>
          {data.packages.map((pack) => (
            <tr key={pack.id}>
              <td className="font-semibold">{pack.tracking}</td>
              <td>{humanizeCode(pack.packageType || 'paquete')}</td>
              <td>{pack.courier}</td>
              <td>{pack.recipient}<span className="block text-xs text-on-surface-variant">{pack.destination}</span></td>
              <td>{shortTime(pack.receivedAt)}</td>
              <td><Badge tone={pack.status === 'pending' ? 'warning' : pack.status === 'delivered' ? 'success' : pack.status === 'rejected' ? 'danger' : 'muted'}>{humanizeCode(pack.status)}</Badge></td>
              <td>{pack.status === 'pending' ? <div className="flex flex-wrap gap-1"><button className="btn-small" onClick={() => setDeliveryTarget(pack)}>Entregar</button><button className="btn-small" onClick={() => changePackageStatus(pack, 'rejected')}>Rechazar</button><button className="btn-small" onClick={() => changePackageStatus(pack, 'returned')}>Devolver</button></div> : <span className="text-sm text-on-surface-variant">{pack.deliveredAt ? shortTime(pack.deliveredAt) : ''}</span>}</td>
            </tr>
          ))}
        </DataTable>
      </section>
      {deliveryTarget && (
        <EvidenceActionModal
          title="Entregar paquete"
          subtitle={`${deliveryTarget.tracking} · ${deliveryTarget.recipient}`}
          primaryLabel="Confirmar entrega"
          loading={savingDelivery}
          showReceiver
          showSignature
          defaultReceiverName={deliveryTarget.recipient}
          onClose={closeDeliveryModal}
          onSubmit={deliver}
        />
      )}
    </div>
  );
}
function Incidents({
  data,
  updateData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  updateData: (updater: (current: AppData) => AppData, action: string, detail: string) => void;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [form, setForm] = useState({ title: '', severity: 'media', area: '', description: '' });
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const [uploading, setUploading] = useState('');
  const [filesByIncident, setFilesByIncident] = useState<Record<string, any[]>>({});
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const payload = {
      shift_id: data.activeShift?.id || null,
      title: form.title,
      severity: form.severity,
      area: form.area,
      description: form.description,
    };
    if (apiOnline) {
      let createdOnline = false;
      try {
        const response = await api.createIncident(payload);
        createdOnline = true;
        await uploadEvidenceFiles('incident', response.id, evidenceFiles);
        await refreshFromApi();
        setForm({ title: '', severity: 'media', area: '', description: '' });
        clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
        return;
      } catch {
        if (createdOnline) {
          setForm({ title: '', severity: 'media', area: '', description: '' });
          clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
          return;
        }
        queueOfflineAction({ method: 'createIncident', args: [payload], label: `Incidente ${form.title}` });
      }
    } else {
      queueOfflineAction({ method: 'createIncident', args: [payload], label: `Incidente ${form.title}` });
    }
    const incident: IncidentRecord = { id: newId('inc'), title: form.title, severity: form.severity as IncidentRecord['severity'], area: form.area, description: form.description, createdAt: nowIso(), status: 'open' };
    updateData((current) => ({ ...current, incidents: [incident, ...current.incidents] }), 'incidente.creado', incident.title);
    setForm({ title: '', severity: 'media', area: '', description: '' });
    clearEvidenceFiles(evidenceFiles, setEvidenceFiles);
  };
  const close = async (incident: IncidentRecord) => {
    const resolution = window.prompt('Describe la solución aplicada (opcional)') || '';
    if (apiOnline) {
      try {
        await api.closeIncident(incident.id, resolution);
        await refreshFromApi();
        return;
      } catch {
        // La decisión se conserva en la cola si la conexión cayó.
      }
    }
    if (/^\d+$/.test(String(incident.id))) queueOfflineAction({ method: 'closeIncident', args: [incident.id, resolution], label: `Cierre de incidente ${incident.title}` });
    updateData((current) => ({
      ...current,
      incidents: current.incidents.map((item) => item.id === incident.id ? { ...item, status: 'closed' } : item),
    }), 'incidente.cerrado', incident.title);
  };

  const review = async (incident: IncidentRecord) => {
    if (apiOnline) {
      try {
        await api.reviewIncident(incident.id);
        await refreshFromApi();
        return;
      } catch {
        // Continúa en modo local y se reintentará automáticamente.
      }
    }
    if (/^\d+$/.test(String(incident.id))) queueOfflineAction({ method: 'reviewIncident', args: [incident.id], label: `Revisión de incidente ${incident.title}` });
    updateData((current) => ({ ...current, incidents: current.incidents.map((item) => item.id === incident.id ? { ...item, status: 'in_review' } : item) }), 'incidente.revision', incident.title);
  };

  const uploadEvidence = async (incident: IncidentRecord, file?: File) => {
    if (!file || !apiOnline) return;
    setUploading(incident.id);
    try {
      await api.uploadFile({ ownerType: 'incident', ownerId: incident.id, file });
      const files = await api.files('incident', incident.id);
      setFilesByIncident((current) => ({ ...current, [incident.id]: files.data }));
      await refreshFromApi();
    } finally {
      setUploading('');
    }
  };

  const loadEvidence = async (incident: IncidentRecord) => {
    if (!apiOnline) return;
    const files = await api.files('incident', incident.id);
    setFilesByIncident((current) => ({ ...current, [incident.id]: files.data }));
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
      <section className="panel">
        <PageTitle title="Incidentes" subtitle="Bitacora de seguridad y novedades del puesto." />
        {!data.activeShift && <Alert tone="danger">Debes iniciar turno antes de registrar un incidente.</Alert>}
        <form onSubmit={submit} className="grid gap-3">
          <Input label="Titulo" value={form.title} onChange={(value) => setForm({ ...form, title: value })} required />
          <Select label="Severidad" value={form.severity} onChange={(value) => setForm({ ...form, severity: value })} options={['baja', 'media', 'alta']} />
          <Input label="Área" value={form.area} onChange={(value) => setForm({ ...form, area: value })} required />
          <label className="label" htmlFor="description">Descripción</label>
          <textarea id="description" className="field min-h-28" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} required />
          <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} />
          <button className="btn-primary" disabled={!data.activeShift}><Icon name="warning" /> Registrar incidente</button>
        </form>
      </section>
      <section className="panel">
        <DataTable headers={['Incidente', 'Area', 'Severidad', 'Fecha', 'Evidencia', 'Accion']}>
          {data.incidents.map((incident) => (
            <tr key={incident.id}>
              <td>{incident.title}<span className="block text-xs text-on-surface-variant">{incident.description}</span></td>
              <td>{incident.area}</td>
              <td><Badge tone={incident.severity === 'alta' ? 'danger' : incident.severity === 'media' ? 'warning' : 'muted'}>{humanizeCode(incident.severity)}</Badge></td>
              <td>{shortDate(incident.createdAt)}</td>
              <td>
                {apiOnline ? (
                  <div className="flex flex-wrap gap-2">
                    <label className="btn-small cursor-pointer">
                      <Icon name="attach_file" /> {uploading === incident.id ? 'Subiendo' : 'Adjuntar'}
                      <input className="sr-only" type="file" accept="image/*,application/pdf" capture="environment" onChange={(event) => uploadEvidence(incident, event.target.files?.[0])} />
                    </label>
                    <button className="btn-small" onClick={() => loadEvidence(incident)}>Ver</button>
                  </div>
                ) : (
                  <span className="text-sm text-on-surface-variant">API requerida</span>
                )}
                {filesByIncident[incident.id]?.length > 0 && (
                  <div className="mt-2 space-y-1">
                    {filesByIncident[incident.id].map((file) => (
                      <button key={file.id} className="block text-xs text-primary hover:underline" onClick={() => api.downloadFile(String(file.id))}>
                        {file.original_name}
                      </button>
                    ))}
                  </div>
                )}
              </td>
              <td>{incident.status === 'open' && <button className="btn-small" onClick={() => review(incident)}>Poner en revisión</button>}{incident.status !== 'closed' && <button className="btn-small ml-1" onClick={() => close(incident)}>Cerrar</button>}{incident.status === 'closed' && <Badge tone="success">Cerrado</Badge>}{incident.status === 'in_review' && <Badge tone="warning">En revisión</Badge>}</td>
            </tr>
          ))}
        </DataTable>
      </section>
    </div>
  );
}

function Reports({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [range, setRange] = useState('hoy');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [status, setStatus] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [ops, setOps] = useState<{ imports: any[]; exports: any[]; notifications: any[] }>({ imports: [], exports: [], notifications: [] });
  const totals = useMemo(() => ({
    visitors: data.visitors.length,
    exits: data.visitors.filter((item) => item.status === 'exited').length,
    vehicles: data.vehicles.length,
    packages: data.packages.length,
    incidents: data.incidents.length,
  }), [data]);

  const exportCsv = () => {
    if (apiOnline) {
      api.exportVisitsCsv({ from, to, status });
      return;
    }

    const rows = [
      ['tipo', 'nombre', 'documento', 'destino', 'ingreso', 'salida'],
      ...data.visitors.map((item) => [item.kind, item.fullName, item.documentNumber, item.destination, item.enteredAt, item.exitedAt || '']),
    ];
    const csv = rows.map((row) => row.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `asiscontrol-reporte-${range}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  const openPrintableReport = () => {
    if (apiOnline) {
      api.openVisitsReport({ from, to, status });
    } else {
      window.print();
    }
  };

  useEffect(() => {
    if (!apiOnline) return;
    Promise.all([api.importJobs(), api.exportJobs(), api.notifications()])
      .then(([imports, exports, notifications]) => setOps({ imports: imports.data, exports: exports.data, notifications: notifications.data }))
      .catch(() => setOps({ imports: [], exports: [], notifications: [] }));
  }, [apiOnline]);

  return (
    <div className="space-y-4">
      <section className="panel">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <PageTitle title="Reportes" subtitle="Filtros base y exportación CSV/XLSX para auditoría operativa." />
          <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
            <select className="field" value={range} onChange={(event) => setRange(event.target.value)}>
              <option value="hoy">Hoy</option>
              <option value="semana">Semana</option>
              <option value="mes">Mes</option>
            </select>
            <input className="field" type="date" value={from} onChange={(event) => setFrom(event.target.value)} aria-label="Desde" />
            <input className="field" type="date" value={to} onChange={(event) => setTo(event.target.value)} aria-label="Hasta" />
            <select className="field" value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Estado">
              <option value="">Todos</option>
              <option value="inside">Dentro</option>
              <option value="exited">Salió</option>
            </select>
            <button className="btn-primary" onClick={exportCsv}><Icon name="download" /> CSV</button>
            <button className="btn-ghost" disabled={!apiOnline} onClick={() => api.exportVisitsXlsx({ from, to, status })}><Icon name="table_view" /> Excel</button>
            <button className="btn-ghost" onClick={openPrintableReport}><Icon name="picture_as_pdf" /> PDF</button>
          </div>
        </div>
      </section>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <Metric icon="person" label="Ingresos" value={totals.visitors} tone="primary" />
        <Metric icon="logout" label="Salidas" value={totals.exits} tone="success" />
          <Metric icon="directions_car" label="Vehículos" value={totals.vehicles} tone="info" />
        <Metric icon="inventory_2" label="Paquetes" value={totals.packages} tone="warning" />
        <Metric icon="warning" label="Incidentes" value={totals.incidents} tone="danger" />
      </div>
      <section className="panel">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <PageTitle title="Auditoría" subtitle="Exporta acciones sensibles para revisión administrativa." />
          <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-5">
            <input className="field" type="date" value={from} onChange={(event) => setFrom(event.target.value)} aria-label="Auditoria desde" />
            <input className="field" type="date" value={to} onChange={(event) => setTo(event.target.value)} aria-label="Auditoria hasta" />
            <input className="field" value={actionFilter} onChange={(event) => setActionFilter(event.target.value)} placeholder="Acción" />
            <button className="btn-primary" disabled={!apiOnline} onClick={() => api.exportAuditCsv({ from, to, action: actionFilter })}><Icon name="download" /> Auditoría CSV</button>
            <button className="btn-ghost" disabled={!apiOnline} onClick={() => api.openAuditReport({ from, to, action: actionFilter })}><Icon name="picture_as_pdf" /> Auditoría PDF</button>
          </div>
        </div>
      </section>
      {apiOnline && (
        <section className="panel">
          <PageTitle title="Operaciones" subtitle="Últimos procesos de importación, exportación y notificaciones." />
          <div className="grid gap-4 xl:grid-cols-3">
            <OpsList title="Importaciones" rows={ops.imports} primary="job_type" secondary="status" />
            <OpsList title="Exportaciones" rows={ops.exports} primary="report_type" secondary="status" />
            <OpsList title="Notificaciones" rows={ops.notifications} primary="recipient" secondary="status" />
          </div>
        </section>
      )}
      <AuditPanel audit={data.audit} />
    </div>
  );
}

function Settings({
  data,
  setData,
  apiOnline,
  refreshFromApi,
}: {
  data: AppData;
  setData: React.Dispatch<React.SetStateAction<AppData>>;
  apiOnline: boolean;
  refreshFromApi: () => Promise<void>;
}) {
  const [restriction, setRestriction] = useState({ documentNumber: '', reason: '', level: 'review' as 'informative' | 'review' | 'warning' | 'block' });
  const [users, setUsers] = useState<any[]>([]);
  const [sites, setSites] = useState<any[]>([]);
  const [units, setUnits] = useState<any[]>([]);
  const [residents, setResidents] = useState<any[]>([]);
  const [accessPoints, setAccessPoints] = useState<any[]>([]);
  const [devices, setDevices] = useState<any[]>([]);
  const [permissionMatrix, setPermissionMatrix] = useState<{ permissions: any[]; roles: Record<string, string[]> }>({ permissions: [], roles: {} });
  const [adminError, setAdminError] = useState('');
  const [siteForm, setSiteForm] = useState({ name: '', address: '', timezone: 'America/Bogota' });
  const [userForm, setUserForm] = useState({ name: '', email: '', password: 'demo1234', role: 'guard', site_id: '' });
  const [unitForm, setUnitForm] = useState({ site_id: '', tower: '', unit_code: '', floor: '' });
  const [residentForm, setResidentForm] = useState({ unit_id: '', document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', password: '', relationship: 'residente', is_primary: true });
  const [accessPointForm, setAccessPointForm] = useState({ site_id: '', code: '', name: '', mode: 'con_vigilancia', latitude: '', longitude: '', geofence_radius_m: '150', allowed_networks: '' });
  const [editingAccessPointId, setEditingAccessPointId] = useState('');
  const [deviceForm, setDeviceForm] = useState({ site_id: '', access_point_id: '', name: '' });
  const [deviceToken, setDeviceToken] = useState('');
  const [backupPolicy, setBackupPolicy] = useState({ schedule: 'monthly', retention_days: '365', encryption_required: false, enabled: true, destination_path: '' });
  const [importType, setImportType] = useState('restrictions');
  const [importResult, setImportResult] = useState('');
  const canAdmin = data.user.role === 'admin' || data.user.role === 'superadmin';
  const canManageDevices = data.user.role === 'supervisor' || data.user.role === 'superadmin';
  const canRevokeDevices = data.user.role === 'admin' || data.user.role === 'superadmin';
  const canManageBackups = data.user.role === 'supervisor' || data.user.role === 'superadmin';

  useEffect(() => {
    if (!apiOnline || (!canAdmin && !canManageDevices)) return;

    const load = async () => {
      if (canAdmin) {
        const [userResponse, siteResponse, unitResponse, residentResponse, permissionResponse, accessPointResponse, deviceResponse] = await Promise.all([api.users(), api.sites(), api.units(), api.residents(), api.permissions(), api.accessPoints(), api.devices()]);
        setUsers(userResponse.data);
        setSites(siteResponse.data);
        setUnits(unitResponse.data);
        setResidents(residentResponse.data);
        setPermissionMatrix(permissionResponse.data);
        setAccessPoints(accessPointResponse.data);
        setDevices(deviceResponse.data);
      } else {
        const [siteResponse, accessPointResponse, deviceResponse] = await Promise.all([api.sites(), api.accessPoints(), api.devices()]);
        setSites(siteResponse.data);
        setAccessPoints(accessPointResponse.data);
        setDevices(deviceResponse.data);
      }
      setAdminError('');
    };
    load().catch((error) => setAdminError(error instanceof Error ? error.message : 'No fue posible cargar administracion.'));
  }, [apiOnline, canAdmin, canManageDevices]);

  useEffect(() => {
    if (!apiOnline || !canManageBackups) return;
    api.backupPolicy().then((response) => setBackupPolicy({ schedule: response.data.schedule || 'monthly', retention_days: String(response.data.retention_days || 365), encryption_required: Boolean(Number(response.data.encryption_required ?? 0)), enabled: Boolean(Number(response.data.enabled ?? 1)), destination_path: response.data.destination_path || '' })).catch((error) => setAdminError(error instanceof Error ? error.message : 'No fue posible cargar la política de backup.'));
  }, [apiOnline, canManageBackups]);

  const addRestriction = async (event: React.FormEvent) => {
    event.preventDefault();
    if (apiOnline) {
      await api.createRestriction({
        document_number: restriction.documentNumber,
        reason: restriction.reason,
        level: restriction.level,
      });
      await refreshFromApi();
      setRestriction({ documentNumber: '', reason: '', level: 'review' });
      return;
    }
    setData((current) => ({ ...current, restrictions: [restriction, ...current.restrictions] }));
    setRestriction({ documentNumber: '', reason: '', level: 'review' });
  };

  const addSite = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createSite(siteForm);
    const response = await api.sites();
    setSites(response.data);
    setSiteForm({ name: '', address: '', timezone: 'America/Bogota' });
    await refreshFromApi();
  };

  const addUnit = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createUnit({
      site_id: unitForm.site_id || data.user.siteId || 1,
      tower: unitForm.tower || null,
      unit_code: unitForm.unit_code,
      floor: unitForm.floor || null,
      status: 'active',
    });
    const response = await api.units();
    setUnits(response.data);
    setUnitForm({ site_id: unitForm.site_id, tower: '', unit_code: '', floor: '' });
  };

  const addResident = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createResident({
      unit_id: residentForm.unit_id,
      document_type: residentForm.document_type,
      document_number: residentForm.document_number,
      full_name: residentForm.full_name,
      phone: residentForm.phone || null,
      email: residentForm.email || null,
      relationship: residentForm.relationship,
      is_primary: residentForm.is_primary,
      receives_notifications: true,
      status: 'active',
    });
    const response = await api.residents();
    const createdResident = response.data.find((resident: any) => String(resident.document_number) === String(residentForm.document_number) && String(resident.unit_id) === String(residentForm.unit_id));
    if (createdResident && residentForm.password) await api.setResidentPassword(String(createdResident.id), residentForm.password);
    setResidents(response.data);
    setResidentForm({ unit_id: residentForm.unit_id, document_type: 'CC', document_number: '', full_name: '', phone: '', email: '', password: '', relationship: 'residente', is_primary: true });
  };

  const addUser = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.createUser({
      ...userForm,
      site_id: userForm.site_id || null,
      is_active: true,
    });
    const response = await api.users();
    setUsers(response.data);
    setUserForm({ name: '', email: '', password: 'demo1234', role: 'guard', site_id: '' });
  };

  const addAccessPoint = async (event: React.FormEvent) => {
    event.preventDefault();
    const payload = {
      ...accessPointForm,
      site_id: accessPointForm.site_id || data.user.siteId || 1,
      latitude: accessPointForm.latitude || null,
      longitude: accessPointForm.longitude || null,
      geofence_radius_m: accessPointForm.geofence_radius_m || 150,
      allowed_networks: accessPointForm.allowed_networks.split(',').map((network) => network.trim()).filter(Boolean),
    };
    if (editingAccessPointId) await api.updateAccessPoint(editingAccessPointId, payload);
    else await api.createAccessPoint(payload);
    const response = await api.accessPoints();
    setAccessPoints(response.data);
    setEditingAccessPointId('');
    setAccessPointForm({ site_id: '', code: '', name: '', mode: 'con_vigilancia', latitude: '', longitude: '', geofence_radius_m: '150', allowed_networks: '' });
  };

  const addDevice = async (event: React.FormEvent) => {
    event.preventDefault();
    const response = await api.createDevice({ ...deviceForm, site_id: deviceForm.site_id || data.user.siteId || 1, access_point_id: deviceForm.access_point_id || null });
    setDeviceToken(response.device_token);
    const devicesResponse = await api.devices();
    setDevices(devicesResponse.data);
    setDeviceForm({ site_id: '', access_point_id: '', name: '' });
  };

  const revokeDevice = async (id: string) => {
    await api.revokeDevice(id);
    const response = await api.devices();
    setDevices(response.data);
  };

  const toggleUser = async (user: any) => {
    await api.updateUser(String(user.id), {
      name: user.name,
      role: user.role,
      site_id: user.site_id,
      is_active: !Boolean(Number(user.is_active)),
    });
    const response = await api.users();
    setUsers(response.data);
  };

  const togglePermission = async (role: string, permission: string) => {
    const current = new Set(permissionMatrix.roles[role] || []);
    if (current.has(permission)) {
      current.delete(permission);
    } else {
      current.add(permission);
    }
    const next = { ...permissionMatrix.roles, [role]: Array.from(current) };
    setPermissionMatrix({ ...permissionMatrix, roles: next });
  };

  const saveRolePermissions = async (role: string) => {
    await api.updatePermissions(role, permissionMatrix.roles[role] || []);
    const response = await api.permissions();
    setPermissionMatrix(response.data);
  };

  const importCsv = async (file?: File) => {
    if (!file) return;
    const response = await api.importCsv({ jobType: importType, file });
    setImportResult(`${humanizeCode(response.data.status)}: ${response.data.processed_rows}/${response.data.total_rows} filas procesadas`);
    await refreshFromApi();
  };

  const saveBackupPolicy = async (event: React.FormEvent) => {
    event.preventDefault();
    await api.updateBackupPolicy({ ...backupPolicy, retention_days: Number(backupPolicy.retention_days) });
    setAdminError('');
  };

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      <section className="panel">
        <PageTitle title="Configuración" subtitle="Datos del cliente, sitio, rol y preferencias visuales." />
        <div className="grid gap-3">
          <Input label="Cliente / tenant" value={data.user.tenant} onChange={(value) => setData((current) => ({ ...current, user: { ...current.user, tenant: value } }))} />
          <Input label="Sede / puesto" value={data.user.site} onChange={(value) => setData((current) => ({ ...current, user: { ...current.user, site: value } }))} />
          <Select label="Rol" value={data.user.role} onChange={(value) => setData((current) => ({ ...current, user: { ...current.user, role: value as Role } }))} options={['guard', 'supervisor', 'coordinator', 'admin', 'superadmin', 'auditor', 'resident', 'tech_operator']} />
        </div>
      </section>
      <section className="panel">
        <PageTitle title="Restricciones" subtitle="Alertas por documento para bloqueos o revision previa." />
        <form className="grid gap-3" onSubmit={addRestriction}>
          <Input label="Documento" value={restriction.documentNumber} onChange={(value) => setRestriction({ ...restriction, documentNumber: value })} required />
          <Input label="Motivo" value={restriction.reason} onChange={(value) => setRestriction({ ...restriction, reason: value })} required />
          <Select label="Nivel" value={restriction.level} onChange={(value) => setRestriction({ ...restriction, level: value as 'informative' | 'review' | 'warning' | 'block' })} options={['informative', 'review', 'warning', 'block']} />
          <button className="btn-primary"><Icon name="add" /> Agregar restriccion</button>
        </form>
      </section>
      {apiOnline && (canAdmin || canManageDevices) && (
        <>
          {canAdmin && <section className="panel">
            <PageTitle title="Sedes" subtitle="Puestos y ubicaciones operativas del tenant." />
            {adminError && <Alert tone="danger">{adminError}</Alert>}
            <form className="mb-4 grid gap-3" onSubmit={addSite}>
              <Input label="Nombre sede" value={siteForm.name} onChange={(value) => setSiteForm({ ...siteForm, name: value })} required />
              <Input label="Direccion" value={siteForm.address} onChange={(value) => setSiteForm({ ...siteForm, address: value })} />
              <Input label="Zona horaria" value={siteForm.timezone} onChange={(value) => setSiteForm({ ...siteForm, timezone: value })} required />
              <button className="btn-primary"><Icon name="add_business" /> Crear sede</button>
            </form>
            <DataTable headers={['Sede', 'Direccion', 'Zona']}>
              {sites.map((site) => (
                <tr key={site.id}>
                  <td>{site.name}</td>
                  <td>{site.address || 'Sin direccion'}</td>
                  <td>{site.timezone}</td>
                </tr>
              ))}
            </DataTable>
          </section>}
          {canAdmin && <section className="panel">
            <PageTitle title="Usuarios" subtitle="Alta de guardias, supervisores y administradores." />
            <form className="mb-4 grid gap-3 sm:grid-cols-2" onSubmit={addUser}>
              <Input label="Nombre usuario" value={userForm.name} onChange={(value) => setUserForm({ ...userForm, name: value })} required />
              <Input label="Correo usuario" value={userForm.email} onChange={(value) => setUserForm({ ...userForm, email: value })} required />
              <Input label="Contraseña inicial" value={userForm.password} onChange={(value) => setUserForm({ ...userForm, password: value })} required />
              <Select label="Rol usuario" value={userForm.role} onChange={(value) => setUserForm({ ...userForm, role: value })} options={['guard', 'supervisor', 'coordinator', 'admin', 'auditor', 'resident', 'tech_operator']} />
              <select className="field sm:col-span-2" value={userForm.site_id} onChange={(event) => setUserForm({ ...userForm, site_id: event.target.value })} aria-label="Sede del usuario">
                <option value="">Sin sede fija</option>
                {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
              </select>
              <button className="btn-primary sm:col-span-2"><Icon name="person_add" /> Crear usuario</button>
            </form>
            <DataTable headers={['Usuario', 'Correo', 'Rol', 'Estado', 'Accion']}>
              {users.map((user) => (
                <tr key={user.id}>
                  <td>{user.name}</td>
                  <td>{user.email}</td>
                  <td>{humanizeCode(user.role)}</td>
                  <td><Badge tone={Number(user.is_active) ? 'success' : 'muted'}>{Number(user.is_active) ? 'Activo' : 'Inactivo'}</Badge></td>
                  <td><button className="btn-small" onClick={() => toggleUser(user)}>{Number(user.is_active) ? 'Desactivar' : 'Activar'}</button></td>
                </tr>
              ))}
            </DataTable>
          </section>}
          <section className="panel">
            <PageTitle title="Puntos de acceso" subtitle="Define porterías, accesos peatonales y vehiculares con sus reglas operativas." />
            {canManageDevices && <form className="mb-4 grid gap-3" onSubmit={addAccessPoint}>
              <select className="field" value={accessPointForm.site_id} onChange={(event) => setAccessPointForm({ ...accessPointForm, site_id: event.target.value })} aria-label="Sede del punto de acceso">
                <option value="">Sede principal</option>
                {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
              </select>
              <Input label="Código" value={accessPointForm.code} onChange={(value) => setAccessPointForm({ ...accessPointForm, code: value })} required />
              <Input label="Nombre" value={accessPointForm.name} onChange={(value) => setAccessPointForm({ ...accessPointForm, name: value })} required />
              <Select label="Modo" value={accessPointForm.mode} onChange={(value) => setAccessPointForm({ ...accessPointForm, mode: value })} options={['con_vigilancia', 'sin_vigilancia']} />
              <div className="grid grid-cols-2 gap-3"><Input label="Latitud" value={accessPointForm.latitude} onChange={(value) => setAccessPointForm({ ...accessPointForm, latitude: value })} /><Input label="Longitud" value={accessPointForm.longitude} onChange={(value) => setAccessPointForm({ ...accessPointForm, longitude: value })} /></div>
              <div className="grid grid-cols-2 gap-3"><Input label="Radio geocerca (m)" type="number" value={accessPointForm.geofence_radius_m} onChange={(value) => setAccessPointForm({ ...accessPointForm, geofence_radius_m: value })} /><Input label="Redes/IP permitidas" value={accessPointForm.allowed_networks} onChange={(value) => setAccessPointForm({ ...accessPointForm, allowed_networks: value })} placeholder="192.168.1.0/24, 10.0.0.5" /></div>
              <button className="btn-primary"><Icon name={editingAccessPointId ? 'save' : 'add_location_alt'} /> {editingAccessPointId ? 'Guardar cambios' : 'Crear punto'}</button>
            </form>}
            <DataTable headers={['Código', 'Nombre', 'Sede', 'Modo', 'Estado', 'Acción']}>
              {accessPoints.map((point) => (
                <tr key={point.id}><td>{point.code}</td><td>{point.name}</td><td>{point.site_name || point.site_id}</td><td>{point.mode === 'sin_vigilancia' ? 'Sin vigilancia' : 'Con vigilancia'}</td><td><Badge tone={point.status === 'active' ? 'success' : 'muted'}>{humanizeCode(point.status)}</Badge></td><td><button className="btn-small" onClick={() => { setEditingAccessPointId(String(point.id)); setAccessPointForm({ site_id: String(point.site_id || ''), code: point.code || '', name: point.name || '', mode: point.mode || 'con_vigilancia', latitude: point.latitude || '', longitude: point.longitude || '', geofence_radius_m: String(point.geofence_radius_m || 150), allowed_networks: (() => { try { return JSON.parse(point.allowed_networks_json || '[]').join(', '); } catch { return ''; } })() }); }}><Icon name="edit" /> Editar</button></td></tr>
              ))}
            </DataTable>
          </section>
          <section className="panel">
            <PageTitle title="Dispositivos autorizados" subtitle="Tokens de dispositivo de un solo uso; guarda el token al crearlo porque no vuelve a mostrarse." />
            {canManageDevices && (
              <form className="mb-4 grid gap-3" onSubmit={addDevice}>
                <select className="field" value={deviceForm.site_id} onChange={(event) => setDeviceForm({ ...deviceForm, site_id: event.target.value })} aria-label="Sede del dispositivo">
                  <option value="">Sede principal</option>
                  {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                </select>
                <select className="field" value={deviceForm.access_point_id} onChange={(event) => setDeviceForm({ ...deviceForm, access_point_id: event.target.value })} aria-label="Punto de acceso del dispositivo">
                  <option value="">Sin punto asignado</option>
                  {accessPoints.map((point) => <option key={point.id} value={point.id}>{point.code} — {point.name}</option>)}
                </select>
                <Input label="Nombre del dispositivo" value={deviceForm.name} onChange={(value) => setDeviceForm({ ...deviceForm, name: value })} required />
                <button className="btn-primary"><Icon name="phonelink_lock" /> Autorizar dispositivo</button>
              </form>
            )}
            {deviceToken && <Alert tone="success">Token generado (cópialo ahora): <code className="break-all">{deviceToken}</code></Alert>}
            <DataTable headers={['Dispositivo', 'Sede', 'Punto', 'Token', 'Estado', 'Acción']}>
              {devices.map((device) => (
                <tr key={device.id}><td>{device.name}</td><td>{device.site_name || device.site_id}</td><td>{device.access_point_name || 'Sin asignar'}</td><td>••••{device.token_last4 || '????'}</td><td><Badge tone={device.status === 'active' ? 'success' : 'muted'}>{humanizeCode(device.status)}</Badge></td><td>{canRevokeDevices && device.status !== 'revoked' && <button className="btn-small" onClick={() => revokeDevice(String(device.id))}><Icon name="block" /> Revocar</button>}</td></tr>
              ))}
            </DataTable>
          </section>
          <section className="panel xl:col-span-2">
            <PageTitle title="Permisos por rol" subtitle="Matriz granular para habilitar modulos y acciones sensibles." />
            {data.user.role !== 'superadmin' && <Alert tone="warning">Puedes revisar permisos, pero solo superadmin puede guardarlos.</Alert>}
            <DataTable headers={['Permiso', 'Guardia', 'Supervisor', 'Coordinador', 'Auditor', 'Admin', 'Superadmin', 'Residente', 'Operador técnico']}>
              {permissionMatrix.permissions.map((permission) => (
                <tr key={permission.code}>
                  <td>
                    <span className="font-semibold">{permission.name}</span>
                    <span className="block text-xs text-on-surface-variant">{permission.description || humanizeCode(permission.code)}</span>
                  </td>
                  {(['guard', 'supervisor', 'coordinator', 'auditor', 'admin', 'superadmin', 'resident', 'tech_operator'] as const).map((role) => (
                    <td key={role}>
                      <label className="inline-flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={role === 'superadmin' || Boolean(permissionMatrix.roles[role]?.includes(permission.code))}
                          disabled={role === 'superadmin' || data.user.role !== 'superadmin'}
                          onChange={() => togglePermission(role, permission.code)}
                        />
                        {role === 'superadmin' ? 'Siempre' : 'Permitir'}
                      </label>
                    </td>
                  ))}
                </tr>
              ))}
            </DataTable>
            {data.user.role === 'superadmin' && (
              <div className="mt-4 flex flex-wrap gap-2">
                {(['guard', 'supervisor', 'coordinator', 'auditor', 'admin', 'resident', 'tech_operator'] as const).map((role) => (
                  <button key={role} className="btn-ghost" onClick={() => saveRolePermissions(role)}>
                    <Icon name="save" /> Guardar {humanizeCode(role)}
                  </button>
                ))}
              </div>
            )}
          </section>
          <section className="panel">
            <PageTitle title="Unidades" subtitle="Torres, apartamentos, oficinas o destinos autorizables." />
            <form className="mb-4 grid gap-3 sm:grid-cols-2" onSubmit={addUnit}>
              <select className="field sm:col-span-2" value={unitForm.site_id} onChange={(event) => setUnitForm({ ...unitForm, site_id: event.target.value })} aria-label="Sede de la unidad">
                <option value="">Sede principal</option>
                {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
              </select>
              <Input label="Torre / bloque" value={unitForm.tower} onChange={(value) => setUnitForm({ ...unitForm, tower: value })} />
              <Input label="Unidad / apartamento" value={unitForm.unit_code} onChange={(value) => setUnitForm({ ...unitForm, unit_code: value })} required />
              <Input label="Piso" value={unitForm.floor} onChange={(value) => setUnitForm({ ...unitForm, floor: value })} />
              <button className="btn-primary sm:col-span-2"><Icon name="apartment" /> Crear unidad</button>
            </form>
            <DataTable headers={['Unidad', 'Sede', 'Piso', 'Estado']}>
              {units.map((unit) => (
                <tr key={unit.id}>
                  <td>{[unit.tower, unit.unit_code].filter(Boolean).join(' - ')}</td>
                  <td>{unit.site_name}</td>
                  <td>{unit.floor || 'Sin dato'}</td>
                  <td><Badge tone={unit.status === 'active' ? 'success' : 'muted'}>{unit.status === 'active' ? 'Activa' : 'Inactiva'}</Badge></td>
                </tr>
              ))}
            </DataTable>
          </section>
          <section className="panel">
            <PageTitle title="Residentes" subtitle="Vincula personas residentes a una unidad para portal, paquetes e invitaciones." />
            <form className="mb-4 grid gap-3 sm:grid-cols-2" onSubmit={addResident}>
              <select className="field sm:col-span-2" value={residentForm.unit_id} onChange={(event) => setResidentForm({ ...residentForm, unit_id: event.target.value })} required aria-label="Unidad del residente">
                <option value="">Seleccionar unidad</option>
                {units.map((unit) => <option key={unit.id} value={unit.id}>{[unit.tower, unit.unit_code].filter(Boolean).join(' - ')}</option>)}
              </select>
              <Select label="Tipo documento" value={residentForm.document_type} onChange={(value) => setResidentForm({ ...residentForm, document_type: value })} options={['CC', 'CE', 'Pasaporte', 'NIT']} />
              <Input label="Documento" value={residentForm.document_number} onChange={(value) => setResidentForm({ ...residentForm, document_number: value })} required />
              <Input label="Nombre residente" value={residentForm.full_name} onChange={(value) => setResidentForm({ ...residentForm, full_name: value })} required />
              <Input label="Teléfono" value={residentForm.phone} onChange={(value) => setResidentForm({ ...residentForm, phone: value })} />
              <Input label="Correo" value={residentForm.email} onChange={(value) => setResidentForm({ ...residentForm, email: value })} />
              <Input label="Contraseña portal (opcional)" value={residentForm.password} onChange={(value) => setResidentForm({ ...residentForm, password: value })} type="password" />
              <Input label="Relacion" value={residentForm.relationship} onChange={(value) => setResidentForm({ ...residentForm, relationship: value })} />
              <label className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                <input type="checkbox" checked={residentForm.is_primary} onChange={(event) => setResidentForm({ ...residentForm, is_primary: event.target.checked })} />
                Contacto principal
              </label>
              <button className="btn-primary sm:col-span-2"><Icon name="person_add" /> Vincular residente</button>
            </form>
            <DataTable headers={['Residente', 'Unidad', 'Contacto', 'Estado']}>
              {residents.map((resident) => (
                <tr key={resident.id}>
                  <td>{resident.full_name}<span className="block text-xs text-on-surface-variant">{resident.document_type} {resident.document_number}</span></td>
                  <td>{[resident.tower, resident.unit_code].filter(Boolean).join(' - ')}</td>
                  <td>{resident.phone || resident.email || 'Sin contacto'}</td>
                  <td><Badge tone={resident.status === 'active' ? 'success' : 'muted'}>{resident.status === 'active' ? 'Activo' : 'Inactivo'}</Badge></td>
                </tr>
              ))}
            </DataTable>
          </section>
          <section className="panel xl:col-span-2">
            <PageTitle title="Importaciones CSV" subtitle="Carga masiva para restricciones, personas y usuarios." />
            <div className="grid gap-3 sm:grid-cols-[220px_1fr]">
              <select className="field" value={importType} onChange={(event) => setImportType(event.target.value)}>
                <option value="restrictions">Restricciones</option>
                <option value="people">Personas</option>
                <option value="users">Usuarios</option>
              </select>
              <label className="btn-ghost cursor-pointer">
                <Icon name="upload_file" /> Seleccionar CSV/XLSX
                <input className="sr-only" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(event) => importCsv(event.target.files?.[0])} />
              </label>
              <div className="flex flex-wrap gap-2 sm:col-span-2">
                <button type="button" className="btn-ghost" onClick={() => api.downloadImportTemplate(importType, 'csv')}><Icon name="download" /> Plantilla CSV</button>
                <button type="button" className="btn-ghost" onClick={() => api.downloadImportTemplate(importType, 'xlsx')}><Icon name="table_view" /> Plantilla Excel</button>
              </div>
            </div>
            <p className="mt-3 text-sm text-on-surface-variant">
              Restricciones: document_type, document_number, level, reason. Personas: person_type, document_type, document_number, full_name, phone, company. Usuarios: name, email, role, password, site_id.
            </p>
            {importResult && <Alert tone="warning">{importResult}</Alert>}
          </section>
        </>
      )}
      {apiOnline && canManageBackups && <section className="panel xl:col-span-2"><PageTitle title="Política de backups" subtitle="Configuración aprobada: ejecución mensual y retención de 365 días. El cifrado es opcional y depende de la configuración segura del servidor." /><form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" onSubmit={saveBackupPolicy}><Select label="Frecuencia" value={backupPolicy.schedule} onChange={(value) => setBackupPolicy({ ...backupPolicy, schedule: value })} options={['manual', 'daily', 'weekly', 'monthly']} /><Input label="Retención (días)" type="number" value={backupPolicy.retention_days} onChange={(value) => setBackupPolicy({ ...backupPolicy, retention_days: value })} required /><Input label="Destino (opcional)" value={backupPolicy.destination_path} onChange={(value) => setBackupPolicy({ ...backupPolicy, destination_path: value })} /><label className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm"><input type="checkbox" checked={backupPolicy.encryption_required} onChange={(event) => setBackupPolicy({ ...backupPolicy, encryption_required: event.target.checked })} /> Exigir cifrado</label><label className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm"><input type="checkbox" checked={backupPolicy.enabled} onChange={(event) => setBackupPolicy({ ...backupPolicy, enabled: event.target.checked })} /> Política activa</label><button className="btn-primary sm:col-span-2 lg:col-span-4"><Icon name="save" /> Guardar política</button></form></section>}
    </div>
  );
}

function Phases() {
  return (
    <section className="panel">
      <PageTitle title="Plan por fases" subtitle="Los archivos detallados quedaron en docs/phases para implementar sin perder alcance." />
      <div className="grid gap-3 md:grid-cols-3">
        <PhaseCard title="Fase 1" text="MVP: auth, tenants, turnos, visitas, vehiculos, paquetes, incidentes, reportes, auditoria y cPanel." />
        <PhaseCard title="Fase 2" text="Autoservicio, QR, notificaciones por email, importación CSV/XLSX, cola offline, firmas, analítica y objetos externos." />
        <PhaseCard title="Fase 3" text="Biometria, puente local, torniquetes, controladoras, LPR, API enterprise y alta disponibilidad." />
      </div>
    </section>
  );
}

function ManagedClients({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [children, setChildren] = useState<any[]>([]);
  const [error, setError] = useState('');
  const [token, setToken] = useState('');
  const [form, setForm] = useState({ client_type: 'residential', name: '', site_name: '', admin_name: '', admin_email: '', plan_code: 'starter' });
  const tenantId = data.user.tenantId || '';
  const reload = async () => {
    if (!apiOnline || !tenantId) return;
    try { setChildren((await api.platformChildren(tenantId)).data || []); setError(''); }
    catch (err) { setError(err instanceof Error ? err.message : 'No se pudieron consultar los clientes'); }
  };
  useEffect(() => { void reload(); }, [apiOnline, tenantId]);
  const createChild = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!tenantId || !apiOnline) return;
    try {
      const response = await api.createPlatformChildTenant(tenantId, form);
      setToken(response.setup_token);
      setForm({ ...form, name: '', site_name: '', admin_name: '', admin_email: '' });
      await reload();
    } catch (err) { setError(err instanceof Error ? err.message : 'No fue posible crear el cliente secundario'); }
  };
  return <section className="panel space-y-4">
    <PageTitle title="Clientes administrados" subtitle="Solo puedes crear y consultar clientes secundarios de tu empresa de seguridad." />
    {!apiOnline && <Alert tone="warning">Requiere conexión con la API.</Alert>}
    {!tenantId && <Alert tone="warning">No se ha identificado el cliente de la sesión.</Alert>}
    {error && <Alert tone="danger">{error}</Alert>}
    <form onSubmit={createChild} className="grid gap-3 md:grid-cols-2">
      <input className="field" placeholder="Nombre del cliente" required value={form.name} onChange={e=>setForm({ ...form, name:e.target.value })}/>
      <select className="field" value={form.client_type} onChange={e=>setForm({ ...form, client_type:e.target.value })}>
        <option value="residential">Propiedad horizontal</option><option value="industrial">Industrial</option>
        <option value="corporate">Corporativo</option><option value="custom">Personalizado</option>
      </select>
      <input className="field" placeholder="Nombre de sede" required value={form.site_name} onChange={e=>setForm({ ...form, site_name:e.target.value })}/>
      <input className="field" placeholder="Nombre del administrador" required value={form.admin_name} onChange={e=>setForm({ ...form, admin_name:e.target.value })}/>
      <input className="field" type="email" placeholder="Correo del administrador" required value={form.admin_email} onChange={e=>setForm({ ...form, admin_email:e.target.value })}/>
      <button className="btn-primary" disabled={!apiOnline || !tenantId} type="submit">Crear cliente secundario</button>
    </form>
    {token && <div className="rounded border border-warning bg-warning/10 p-3 text-sm">
      Token de activación, visible una sola vez: <code className="break-all">{token}</code>
    </div>}
    <div className="space-y-2">{children.map((child:any) => <div key={child.id} className="rounded border border-border p-3">
      <strong>{child.name}</strong><span className="ml-2 text-xs text-on-surface-variant">{humanizeCode(child.client_type)} · {humanizeCode(child.status)}</span>
    </div>)}</div>
  </section>;
}

function PlatformAdmin({ data, apiOnline }: { data: AppData; apiOnline: boolean }) {
  const [metrics, setMetrics] = useState<Record<string, number>>({});
  const [tenants, setTenants] = useState<any[]>([]);
  const [plans, setPlans] = useState<any[]>([]);
  const [clientTypes, setClientTypes] = useState<any[]>([]);
  const [parentTenantId, setParentTenantId] = useState('');
  const [platformNotifications, setPlatformNotifications] = useState<any[]>([]);
  const [platformAudit, setPlatformAudit] = useState<any[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [setupToken, setSetupToken] = useState('');
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({ name: '', legal_name: '', document_number: '', site_name: '', site_address: '', admin_name: '', admin_email: '', plan_code: 'starter', client_type: 'residential' });
  const [subscription, setSubscription] = useState({ tenantId: '', plan_code: 'starter', status: 'active', starts_on: new Date().toISOString().slice(0, 10), ends_on: '', auto_renew: false, notes: '' });

  const reload = async () => {
    if (!apiOnline || !data.user.isPlatformAdmin) return;
    setLoading(true);
    setError('');
    try {
      const [metricResponse, tenantResponse, planResponse, notificationResponse, auditResponse, typeResponse] = await Promise.all([api.platformMetrics(), api.platformTenants(), api.platformPlans(), api.platformNotifications(), api.platformAudit(), api.platformClientTypes()]);
      setMetrics(metricResponse.data || {});
      setTenants(tenantResponse.data || []);
      setPlans(planResponse.data || []);
      setClientTypes(typeResponse.data || []);
      setPlatformNotifications(notificationResponse.data || []);
      setPlatformAudit(auditResponse.data || []);
      if ((planResponse.data || []).length > 0 && !form.plan_code) setForm((current) => ({ ...current, plan_code: planResponse.data[0].code }));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'No fue posible cargar el control de clientes.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void reload(); }, [apiOnline, data.user.isPlatformAdmin]);

  const createTenant = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');
    try {
      const response = parentTenantId ? await api.createPlatformChildTenant(parentTenantId, form) : await api.createPlatformTenant(form);
      setSetupToken(response.setup_token);
      setNotice('Cliente creado. Entrega el token de activación al administrador y guárdalo en un canal seguro.');
      setForm({ name: '', legal_name: '', document_number: '', site_name: '', site_address: '', admin_name: '', admin_email: '', plan_code: form.plan_code, client_type: form.client_type });
      await reload();
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : 'No fue posible crear el cliente.');
    }
  };

  const toggleTenant = async (tenant: any) => {
    try {
      await api.updatePlatformTenantStatus(String(tenant.id), tenant.status === 'active' ? 'inactive' : 'active');
      await reload();
    } catch (toggleError) {
      setError(toggleError instanceof Error ? toggleError.message : 'No fue posible cambiar el estado.');
    }
  };

  const saveSubscription = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!subscription.tenantId) return;
    try {
      await api.updatePlatformSubscription(subscription.tenantId, { ...subscription, tenantId: undefined });
      setNotice('Suscripción actualizada. Los periodos pagados no se modifican ni se reembolsan.');
      await reload();
    } catch (subscriptionError) {
      setError(subscriptionError instanceof Error ? subscriptionError.message : 'No fue posible actualizar la suscripción.');
    }
  };

  if (!data.user.isPlatformAdmin && data.user.role === 'tech_operator') {
    return <ManagedClients data={data} apiOnline={apiOnline} />;
  }
  if (!data.user.isPlatformAdmin) {
    return <section className="panel"><PageTitle title="Administración de clientes" subtitle="Módulo restringido al equipo proveedor." /><Alert tone="warning">Tu cuenta no tiene acceso al control plane.</Alert></section>;
  }

  return (
    <div className="space-y-6">
      <section className="panel">
        <PageTitle title="Administración de clientes" subtitle="Cuentas, suscripciones, métricas y activación de administradores de Asiscontrol." />
        {!apiOnline && <Alert tone="warning">Conecta la API para administrar clientes. Este módulo no opera con datos demo.</Alert>}
        {error && <Alert tone="danger">{error}</Alert>}
        {notice && <Alert tone="success">{notice}</Alert>}
        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {[
            ['Clientes', metrics.tenants_total],
            ['Activos', metrics.tenants_active],
            ['Usuarios activos', metrics.users_active],
            ['Visitas 30 días', metrics.visits_30d],
            ['Eventos 30 días', metrics.access_events_30d],
            ['Reservas 30 días', metrics.bookings_30d],
            ['Invitaciones pendientes', metrics.pending_invites],
          ].map(([label, value]) => <div key={String(label)} className="rounded-lg border border-border bg-surface-container-low p-4"><p className="text-xs font-semibold uppercase tracking-[0.1em] text-on-surface-variant">{label}</p><p className="mt-2 text-2xl font-black text-primary">{Number(value || 0).toLocaleString('es-CO')}</p></div>)}
        </div>
      </section>

      <section className="panel">
        <PageTitle title="Crear cliente" subtitle="Genera una sede, administrador y token de activación de un solo uso (48 horas)." />
        <form onSubmit={createTenant} className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <select className="field" value={parentTenantId} onChange={event => setParentTenantId(event.target.value)}>
            <option value="">Cliente independiente de CREAMOX</option>
            {tenants.filter(t => t.client_type === 'security_company').map(t => <option key={t.id} value={String(t.id)}>Secundario de {t.name}</option>)}
          </select>
          <select className="field" value={form.client_type} onChange={event=>setForm({ ...form, client_type:event.target.value })}>
            {clientTypes.filter(t => !parentTenantId || t.code !== 'security_company').map(t => <option key={t.code} value={t.code}>{t.name}</option>)}
          </select>
          <input className="field" placeholder="Nombre del cliente" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
          <input className="field" placeholder="Razón social" value={form.legal_name} onChange={(event) => setForm({ ...form, legal_name: event.target.value })} />
          <input className="field" placeholder="NIT / documento" value={form.document_number} onChange={(event) => setForm({ ...form, document_number: event.target.value })} />
          <input className="field" placeholder="Nombre de sede" value={form.site_name} onChange={(event) => setForm({ ...form, site_name: event.target.value })} required />
          <input className="field" placeholder="Dirección de sede" value={form.site_address} onChange={(event) => setForm({ ...form, site_address: event.target.value })} />
          <input className="field" placeholder="Nombre del administrador" value={form.admin_name} onChange={(event) => setForm({ ...form, admin_name: event.target.value })} required />
          <input className="field" type="email" placeholder="Correo del administrador" value={form.admin_email} onChange={(event) => setForm({ ...form, admin_email: event.target.value })} required />
          <select className="field" value={form.plan_code} onChange={(event) => setForm({ ...form, plan_code: event.target.value })}>{plans.map((plan) => <option key={plan.code} value={plan.code}>{plan.name} · {money(Number(plan.price))}</option>)}</select>
          <button className="btn-primary md:col-span-2 xl:col-span-4" type="submit" disabled={!apiOnline || loading}><Icon name="add_business" /> Crear cliente</button>
        </form>
        {setupToken && <div className="mt-4 rounded-lg border border-warning bg-warning/10 p-4 text-sm"><strong>Token de activación (mostrar una sola vez):</strong><code className="mt-2 block break-all rounded bg-surface p-2">{setupToken}</code></div>}
      </section>

      <section className="panel">
        <PageTitle title="Clientes registrados" subtitle="Suspende cuentas, revisa límites y actualiza el periodo de suscripción." />
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[900px] text-left text-sm"><thead className="bg-surface-container-low text-xs uppercase tracking-[0.08em] text-on-surface-variant"><tr><th className="p-3">Cliente</th><th className="p-3">Administrador</th><th className="p-3">Plan</th><th className="p-3">Uso</th><th className="p-3">Estado</th><th className="p-3">Acciones</th></tr></thead><tbody>
            {tenants.map((tenant) => <tr key={tenant.id} className="border-t border-border"><td className="p-3"><strong>{tenant.name}</strong><span className="block text-xs text-on-surface-variant">{tenant.site_name || 'Sin sede'}</span></td><td className="p-3">{tenant.admin_name || '—'}<span className="block text-xs text-on-surface-variant">{tenant.admin_email || '—'}</span></td><td className="p-3">{tenant.plan_name || 'Sin plan'}<span className="block text-xs text-on-surface-variant">{humanizeCode(tenant.subscription_status)}</span></td><td className="p-3">{tenant.active_users || 0} usuarios · {tenant.active_units || 0} unidades</td><td className="p-3"><Badge tone={tenant.status === 'active' ? 'success' : 'danger'}>{humanizeCode(tenant.status)}</Badge></td><td className="p-3"><div className="flex flex-wrap gap-2"><button className="btn-small" onClick={() => toggleTenant(tenant)}><Icon name={tenant.status === 'active' ? 'pause_circle' : 'play_circle'} /> {tenant.status === 'active' ? 'Suspender' : 'Activar'}</button><button className="btn-small" onClick={() => setSubscription({ tenantId: String(tenant.id), plan_code: tenant.plan_code || 'starter', status: tenant.subscription_status === 'suspended' ? 'active' : (tenant.subscription_status || 'active'), starts_on: tenant.starts_on || new Date().toISOString().slice(0, 10), ends_on: tenant.ends_on || '', auto_renew: Boolean(tenant.auto_renew), notes: '' })}><Icon name="edit_calendar" /> Suscripción</button></div></td></tr>)}
            {tenants.length === 0 && <tr><td className="p-4 text-on-surface-variant" colSpan={6}>{loading ? 'Cargando…' : 'No hay clientes registrados.'}</td></tr>}
          </tbody></table>
        </div>
      </section>

      {subscription.tenantId && <section className="panel"><PageTitle title="Actualizar suscripción" subtitle="La pasarela de pago queda desacoplada; registra aquí el estado confirmado por administración." /><form onSubmit={saveSubscription} className="grid gap-3 md:grid-cols-2 xl:grid-cols-4"><select className="field" value={subscription.plan_code} onChange={(event) => setSubscription({ ...subscription, plan_code: event.target.value })}>{plans.map((plan) => <option key={plan.code} value={plan.code}>{plan.name}</option>)}</select><select className="field" value={subscription.status} onChange={(event) => setSubscription({ ...subscription, status: event.target.value })}>{['trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'].map((status) => <option key={status} value={status}>{humanizeCode(status)}</option>)}</select><input className="field" type="date" value={subscription.starts_on} onChange={(event) => setSubscription({ ...subscription, starts_on: event.target.value })} required /><input className="field" type="date" value={subscription.ends_on} onChange={(event) => setSubscription({ ...subscription, ends_on: event.target.value })} /><textarea className="field md:col-span-2" placeholder="Notas administrativas" value={subscription.notes} onChange={(event) => setSubscription({ ...subscription, notes: event.target.value })} /><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={subscription.auto_renew} onChange={(event) => setSubscription({ ...subscription, auto_renew: event.target.checked })} /> Renovación automática configurada</label><div className="flex gap-2"><button className="btn-primary" type="submit"><Icon name="save" /> Guardar</button><button className="btn-ghost" type="button" onClick={() => setSubscription({ ...subscription, tenantId: '' })}>Cerrar</button></div></form></section>}
      <div className="grid gap-4 xl:grid-cols-2">
        <section className="panel"><div className="panel-head"><h3>Notificaciones del sistema</h3><Badge tone="primary">{platformNotifications.length}</Badge></div><div className="space-y-2">{platformNotifications.slice(0, 12).map((item) => <div key={item.id} className="rounded-lg border border-border bg-surface-container-low p-3"><div className="flex items-center justify-between gap-2"><strong className="text-sm">{item.subject || 'Notificación'}</strong><Badge tone={item.status === 'sent' ? 'success' : item.status === 'failed' ? 'danger' : 'warning'}>{humanizeCode(item.status || 'pending')}</Badge></div><p className="mt-1 text-xs text-on-surface-variant">{item.tenant_name || 'Plataforma'} · {item.recipient || 'Sin destinatario'}</p><p className="mt-1 text-xs text-on-surface-variant">{item.created_at}</p></div>)}{platformNotifications.length === 0 && <p className="text-sm text-on-surface-variant">No hay notificaciones registradas.</p>}</div></section>
        <section className="panel"><div className="panel-head"><h3>Auditoría del proveedor</h3><Badge tone="primary">{platformAudit.length}</Badge></div><div className="space-y-2">{platformAudit.slice(0, 12).map((item) => <div key={item.id} className="rounded-lg border border-border bg-surface-container-low p-3"><div className="flex items-center justify-between gap-2"><strong className="text-sm">{humanizeCode(item.action)}</strong><span className="text-xs text-on-surface-variant">{item.created_at}</span></div><p className="mt-1 text-xs text-on-surface-variant">{item.actor_name || 'Sistema'} · {item.tenant_name || 'Plataforma'}</p><p className="mt-1 text-sm">{item.detail}</p></div>)}{platformAudit.length === 0 && <p className="text-sm text-on-surface-variant">No hay acciones registradas.</p>}</div></section>
      </div>
    </div>
  );
}

function Plans() {
  const plans = [
    {
      name: 'Starter',
      scope: 'Hasta 50 unidades / 1 sede',
      monthly: '$180.000',
      annual: '$1.95M',
      lifetime: '$4.8M',
      icon: 'rocket_launch',
      highlight: false,
    },
    {
      name: 'Básico',
      scope: '51 – 150 unidades / 1-2 sedes',
      monthly: '$280.000',
      annual: '$3.0M',
      lifetime: '$7.2M',
      icon: 'domain',
      highlight: false,
    },
    {
      name: 'Estándar',
      scope: '151 – 400 unidades / 3 sedes',
      monthly: '$420.000',
      annual: '$4.5M',
      lifetime: '$9.8M',
      icon: 'workspace_premium',
      highlight: true,
    },
    {
      name: 'Avanzado',
      scope: '401 – 800 unidades / 4-6 sedes',
      monthly: '$650.000',
      annual: '$7.0M',
      lifetime: '$14.5M',
      icon: 'monitoring',
      highlight: false,
    },
    {
      name: 'Enterprise',
      scope: '801+ unidades o multi-cliente ilimitado',
      monthly: '$1.500.000',
      annual: '$12M',
      lifetime: '$30M',
      icon: 'corporate_fare',
      highlight: false,
    },
  ];

  return (
    <div className="space-y-6">
      <section className="panel overflow-hidden">
        <PageTitle title="Planes y precios" subtitle="Control de acceso en la nube. Precios en pesos colombianos (COP), IVA no incluido." />
      </section>

      <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-5">
        {plans.map((plan) => (
          <article key={plan.name} className={`rounded-xl border p-5 shadow-sm ${plan.highlight ? 'border-warning bg-warning/10' : 'border-border bg-surface'}`}>
            <div className="mb-4 flex items-start justify-between gap-3">
              <span className={`grid h-11 w-11 place-items-center rounded-lg ${plan.highlight ? 'bg-warning text-white' : 'bg-surface-container-high text-primary'}`}>
                <Icon name={plan.icon} />
              </span>
              {plan.highlight && <Badge tone="warning">Más vendido</Badge>}
            </div>
            <h3 className="text-xl font-black text-text-primary">{plan.name}</h3>
            <p className="mt-2 min-h-12 text-sm text-on-surface-variant">{plan.scope}</p>
            <div className="mt-5 rounded-lg bg-surface-container-low p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-on-surface-variant">Mensual</p>
              <p className="mt-1 text-3xl font-black text-primary">{plan.monthly}</p>
            </div>
            <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg border border-border p-3">
                <span className="block text-xs text-on-surface-variant">Anual</span>
                <strong className="text-text-primary">{plan.annual}</strong>
              </div>
              <div className="rounded-lg border border-border p-3">
                <span className="block text-xs text-on-surface-variant">Vitalicia</span>
                <strong className="text-text-primary">{plan.lifetime}</strong>
              </div>
            </div>
          </article>
        ))}
      </div>

      <section className="panel">
        <PageTitle title="Tabla comparativa" subtitle="Referencia rápida de alcance y precios por nivel de servicio." />
        <DataTable headers={['Nivel / Plan', 'Cantidad de unidades / residentes / sedes', 'Precio mensual', 'Precio anual', 'Precio vitalicia']}>
          {plans.map((plan) => (
            <tr key={plan.name} className={plan.highlight ? 'bg-warning/10' : ''}>
              <td>
                <span className="font-black text-text-primary">{plan.name}</span>
                {plan.highlight && <span className="ml-2 text-xs font-bold text-warning">Más vendido</span>}
              </td>
              <td>{plan.scope}</td>
              <td><span className="text-lg font-black text-primary">{plan.monthly}</span></td>
              <td>{plan.annual}</td>
              <td>{plan.lifetime}</td>
            </tr>
          ))}
        </DataTable>
      </section>

    </div>
  );
}

function AuditPanel({ audit }: { audit: AuditRecord[] }) {
  return (
    <section className="panel">
      <div className="panel-head"><h3>Auditoría</h3></div>
      <div className="space-y-2">
        {audit.slice(0, 12).map((item) => (
          <div key={item.id} className="rounded-lg border border-border bg-surface-container-low p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="font-semibold">{humanizeCode(item.action)}</p>
              <span className="text-xs text-on-surface-variant">{shortDate(item.at)}</span>
            </div>
            <p className="text-sm text-on-surface-variant">{item.actor}: {humanizeCodeText(item.detail)}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

function OpsList({ title, rows, primary, secondary }: { title: string; rows: any[]; primary: string; secondary: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface-container-low p-3">
      <h3 className="font-semibold text-text-primary">{title}</h3>
      <div className="mt-3 space-y-2">
        {rows.slice(0, 5).map((row) => (
          <div key={row.id} className="rounded-lg bg-surface p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold">{['job_type', 'report_type', 'backup_type'].includes(primary) ? humanizeCode(row[primary]) : (row[primary] || `#${row.id}`)}</span>
              <Badge tone={row[secondary] === 'failed' ? 'danger' : row[secondary] === 'sent' || row[secondary] === 'completed' ? 'success' : 'warning'}>{humanizeCode(row[secondary])}</Badge>
            </div>
            <p className="mt-1 text-xs text-on-surface-variant">{row.created_at || row.finished_at || 'Sin fecha'}</p>
          </div>
        ))}
        {rows.length === 0 && <p className="text-sm text-on-surface-variant">Sin registros recientes.</p>}
      </div>
    </div>
  );
}

function PageTitle({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <div className="mb-4">
      <h1 className="text-2xl font-semibold tracking-tight text-text-primary">{title}</h1>
      <p className="mt-1 text-sm text-on-surface-variant">{subtitle}</p>
    </div>
  );
}

function Metric({ icon, label, value, tone, onClick }: { icon: string; label: string; value: number; tone: 'primary' | 'success' | 'warning' | 'danger' | 'info'; onClick?: () => void }) {
  const content = <><div><p className="text-xs font-semibold uppercase tracking-[0.12em] text-on-surface-variant">{label}</p><p className="mt-2 text-3xl font-semibold text-text-primary">{value}</p></div><span className={`metric-icon tone-${tone}`}><Icon name={icon} /></span></>;
  if (onClick) return <button type="button" onClick={onClick} className="panel flex w-full items-center justify-between text-left transition hover:border-primary">{content}</button>;
  return (
    <section className="panel flex items-center justify-between">
      {content}
    </section>
  );
}

function QuickAction({ icon, title, onClick }: { icon: string; title: string; onClick: () => void }) {
  return (
    <button className="quick-action" onClick={onClick}>
      <Icon name={icon} className="text-[32px]" />
      <span>{title}</span>
    </button>
  );
}

function createEvidenceFiles(fileList: FileList | File[]): EvidenceFile[] {
  return Array.from(fileList).map((file) => ({
    id: `evi-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    file,
    previewUrl: URL.createObjectURL(file),
    isImage: file.type.startsWith('image/'),
  }));
}

function revokeEvidenceFiles(files: EvidenceFile[]): void {
  files.forEach((item) => URL.revokeObjectURL(item.previewUrl));
}

function clearEvidenceFiles(files: EvidenceFile[], onChange: (files: EvidenceFile[]) => void): void {
  revokeEvidenceFiles(files);
  onChange([]);
}

async function uploadEvidenceFiles(ownerType: string, ownerId: string, files: EvidenceFile[]): Promise<void> {
  for (const item of files) {
    try {
      await api.uploadFile({ ownerType, ownerId, file: item.file });
    } catch (error) {
      console.warn('No fue posible subir una evidencia', { ownerType, ownerId, file: item.file.name, error });
    }
  }
}

function EvidencePicker({
  files,
  onChange,
  label = 'Evidencias',
}: {
  files: EvidenceFile[];
  onChange: (files: EvidenceFile[]) => void;
  label?: string;
}) {
  const addFiles = (fileList?: FileList | null) => {
    if (!fileList?.length) return;
    onChange([...files, ...createEvidenceFiles(fileList)]);
  };

  const removeFile = (id: string) => {
    const target = files.find((item) => item.id === id);
    if (target) URL.revokeObjectURL(target.previewUrl);
    onChange(files.filter((item) => item.id !== id));
  };

  return (
    <div className="grid gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="label mb-0">{label}</p>
          <p className="text-xs text-on-surface-variant">Agrega una o varias fotos o PDFs. Las imágenes se guardan como WebP en el servidor.</p>
        </div>
        <label className="btn-ghost cursor-pointer">
          <Icon name="add_photo_alternate" /> Agregar evidencia
          <input
            className="sr-only"
            type="file"
            accept="image/*,application/pdf"
            capture="environment"
            multiple
            onChange={(event) => {
              addFiles(event.target.files);
              event.currentTarget.value = '';
            }}
          />
        </label>
      </div>
      {files.length > 0 ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {files.map((item) => (
            <div key={item.id} className="relative overflow-hidden rounded-xl border border-border bg-surface-container-low">
              <div className="grid aspect-[4/3] place-items-center bg-surface-container">
                {item.isImage ? (
                  <img src={item.previewUrl} alt={item.file.name} className="h-full w-full object-cover" />
                ) : (
                  <div className="grid place-items-center gap-2 text-on-surface-variant">
                    <Icon name="picture_as_pdf" className="text-4xl text-danger" />
                    <span className="text-xs font-semibold">PDF</span>
                  </div>
                )}
              </div>
              <div className="flex items-center justify-between gap-2 p-2">
                <span className="truncate text-xs font-medium text-text-primary" title={item.file.name}>{item.file.name}</span>
                <button type="button" className="rounded-full p-1 text-danger hover:bg-danger/10" onClick={() => removeFile(item.id)} aria-label={`Eliminar ${item.file.name}`}>
                  <Icon name="delete" />
                </button>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="rounded-xl border border-dashed border-border bg-surface-container-low p-4 text-sm text-on-surface-variant">
          Sin evidencias agregadas todavía.
        </div>
      )}
    </div>
  );
}

function EvidenceActionModal({
  title,
  subtitle,
  primaryLabel,
  loading = false,
  showReceiver = false,
  showSignature = false,
  showReason = false,
  defaultReceiverName = '',
  onClose,
  onSubmit,
}: {
  title: string;
  subtitle?: string;
  primaryLabel: string;
  loading?: boolean;
  showReceiver?: boolean;
  showSignature?: boolean;
  showReason?: boolean;
  defaultReceiverName?: string;
  onClose: () => void;
  onSubmit: (payload: EvidenceActionPayload) => Promise<void> | void;
}) {
  const [observations, setObservations] = useState('');
  const [reason, setReason] = useState('');
  const [receiverName, setReceiverName] = useState(defaultReceiverName);
  const [signatureDataUrl, setSignatureDataUrl] = useState('');
  const [evidenceFiles, setEvidenceFiles] = useState<EvidenceFile[]>([]);
  const evidenceRef = useRef<EvidenceFile[]>([]);

  useEffect(() => {
    evidenceRef.current = evidenceFiles;
  }, [evidenceFiles]);

  useEffect(() => () => revokeEvidenceFiles(evidenceRef.current), []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    await onSubmit({ observations, reason, receiverName, signatureDataUrl, evidenceFiles });
  };

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true">
      <form onSubmit={submit} className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-border bg-surface p-5 shadow-2xl">
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 className="text-xl font-black text-text-primary">{title}</h2>
            {subtitle && <p className="mt-1 text-sm text-on-surface-variant">{subtitle}</p>}
          </div>
          <button type="button" className="rounded-full p-2 text-on-surface-variant hover:bg-surface-container-high" onClick={onClose} disabled={loading} aria-label="Cerrar">
            <Icon name="close" />
          </button>
        </div>
        <div className="grid gap-4">
          {showReason && <Input label="Motivo obligatorio" value={reason} onChange={setReason} required />}
          {showReceiver && (
            <Input label="Nombre de quien recibe" value={receiverName} onChange={setReceiverName} required={showSignature} />
          )}
          <label className="label" htmlFor="action-observations">Observaciones</label>
          <textarea
            id="action-observations"
            className="field min-h-28"
            value={observations}
            onChange={(event) => setObservations(event.target.value)}
            placeholder="Escribe novedades, estado de la persona/vehiculo/paquete o comentarios relevantes."
          />
          {showSignature && (
            <div className="grid gap-2">
              <p className="label mb-0">Firma opcional</p>
              <SignaturePad onChange={setSignatureDataUrl} />
            </div>
          )}
          <EvidencePicker files={evidenceFiles} onChange={setEvidenceFiles} label="Fotos / evidencias de la operación" />
        </div>
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" className="btn-ghost" onClick={onClose} disabled={loading}>Cancelar</button>
          <button className="btn-primary" disabled={loading}>
            <Icon name={loading ? 'hourglass_empty' : 'check_circle'} /> {loading ? 'Guardando...' : primaryLabel}
          </button>
        </div>
      </form>
    </div>
  );
}

function DataTable({ headers, children }: { headers: string[]; children: React.ReactNode }) {
  const labeledRows = React.Children.map(children, (row) => {
    if (!React.isValidElement(row)) return row;

    const rowElement = row as React.ReactElement<{ children?: React.ReactNode }>;
    const cells = React.Children.map(rowElement.props.children, (cell, index) => {
      if (!React.isValidElement(cell)) return cell;

      return React.cloneElement(cell as React.ReactElement<Record<string, unknown>>, {
        'data-label': headers[index] || '',
      });
    });

    return React.cloneElement(rowElement, {}, cells);
  });

  return (
    <div className="responsive-table">
      <table className="data-table">
        <thead>
          <tr>{headers.map((header) => <th key={header}>{header}</th>)}</tr>
        </thead>
        <tbody>{labeledRows}</tbody>
      </table>
    </div>
  );
}

function Badge({ children, tone }: { children: React.ReactNode; tone: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'muted' }) {
  return <span className={`badge tone-${tone}`}>{children}</span>;
}

function BookingBadge({ status }: { status: BookingStatus }) {
  const labels: Record<BookingStatus, string> = {
    pre_reservation: 'Pre-reserva',
    pending: 'Pendiente',
    pending_payment: 'Pendiente de pago',
    confirmed: 'Confirmada',
    paid: 'Pagada',
    cancelled: 'Cancelada',
    expired: 'Vencida',
  };
  const tones: Record<BookingStatus, 'warning' | 'info' | 'success' | 'muted'> = {
    pre_reservation: 'warning',
    pending: 'warning',
    pending_payment: 'warning',
    confirmed: 'info',
    paid: 'success',
    cancelled: 'muted',
    expired: 'muted',
  };
  return <Badge tone={tones[status]}>{labels[status]}</Badge>;
}

function Alert({ children, tone }: { children: React.ReactNode; tone: 'success' | 'warning' | 'danger' }) {
  return <div className={`mb-4 rounded-lg border p-3 text-sm ${tone === 'danger' ? 'border-danger/30 bg-danger/10 text-danger' : 'border-warning/30 bg-warning/10 text-warning'}`}>{children}</div>;
}

function SignaturePad({ onChange }: { onChange: (dataUrl: string) => void }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const drawing = useRef(false);

  useEffect(() => {
    const canvas = canvasRef.current;
    const context = canvas?.getContext('2d');
    if (!canvas || !context) return;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.strokeStyle = '#101828';
    context.lineWidth = 3;
    context.lineCap = 'round';
    context.lineJoin = 'round';
  }, []);

  const point = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = canvasRef.current;
    if (!canvas) return { x: 0, y: 0 };
    const rect = canvas.getBoundingClientRect();
    return {
      x: ((event.clientX - rect.left) / rect.width) * canvas.width,
      y: ((event.clientY - rect.top) / rect.height) * canvas.height,
    };
  };

  const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = canvasRef.current;
    const context = canvas?.getContext('2d');
    if (!canvas || !context) return;
    drawing.current = true;
    canvas.setPointerCapture(event.pointerId);
    const current = point(event);
    context.beginPath();
    context.moveTo(current.x, current.y);
  };

  const move = (event: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return;
    const canvas = canvasRef.current;
    const context = canvas?.getContext('2d');
    if (!canvas || !context) return;
    const current = point(event);
    context.lineTo(current.x, current.y);
    context.stroke();
    onChange(canvas.toDataURL('image/png'));
  };

  const end = () => {
    drawing.current = false;
  };

  const clear = () => {
    const canvas = canvasRef.current;
    const context = canvas?.getContext('2d');
    if (!canvas || !context) return;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.strokeStyle = '#101828';
    onChange('');
  };

  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-2">
        <span className="text-sm font-semibold text-text-primary">Firma de entrega</span>
        <button type="button" className="btn-small" onClick={clear}><Icon name="backspace" /> Limpiar</button>
      </div>
      <canvas
        ref={canvasRef}
        width={640}
        height={220}
        className="signature-canvas"
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={end}
        onPointerCancel={end}
        aria-label="Area de firma"
      />
    </div>
  );
}

function QrCanvas({ value }: { value: string }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    if (!canvasRef.current || !value) return;
    QRCode.toCanvas(canvasRef.current, value, { width: 180, margin: 2, errorCorrectionLevel: 'M' }).catch(() => undefined);
  }, [value]);

  return <canvas ref={canvasRef} className="qr-canvas" aria-label="Codigo QR de invitacion" />;
}

function QrScanner({ onScan, onClose }: { onScan: (value: string) => void; onClose: () => void }) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let stream: MediaStream | null = null;
    let stopped = false;
    let detector: any = null;

    const start = async () => {
      try {
        const BarcodeDetectorCtor = (window as any).BarcodeDetector;
        if (!BarcodeDetectorCtor) {
          setError('Este navegador no soporta escaneo QR nativo. Usa el campo manual.');
          return;
        }

        detector = new BarcodeDetectorCtor({ formats: ['qr_code'] });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
        }

        const scan = async () => {
          if (stopped || !videoRef.current) return;
          try {
            const codes = await detector.detect(videoRef.current);
            if (codes.length > 0) {
              onScan(codes[0].rawValue);
              onClose();
              return;
            }
          } catch {
            setError('No fue posible leer el QR en este momento.');
          }
          requestAnimationFrame(scan);
        };
        requestAnimationFrame(scan);
      } catch {
        setError('No fue posible abrir la camara.');
      }
    };

    start();
    return () => {
      stopped = true;
      stream?.getTracks().forEach((track) => track.stop());
    };
  }, [onClose, onScan]);

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4">
      <div className="w-full max-w-lg rounded-lg bg-surface p-4 shadow-xl">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-semibold text-text-primary">Escanear invitacion</h3>
          <button className="btn-small" onClick={onClose}>Cerrar</button>
        </div>
        {error ? <Alert tone="warning">{error}</Alert> : <video ref={videoRef} className="qr-video" playsInline muted />}
      </div>
    </div>
  );
}

function Input({ label, value, onChange, required = false, type = 'text', placeholder, list }: { label: string; value: string; onChange: (value: string) => void; required?: boolean; type?: string; placeholder?: string; list?: string }) {
  const id = label.toLowerCase().replace(/\s+/g, '-');
  return (
    <div>
      <label className="label" htmlFor={id}>{label}</label>
      <input id={id} className="field" type={type} placeholder={placeholder} value={value} onChange={(event) => onChange(event.target.value)} required={required} list={list} />
    </div>
  );
}

function Select({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
  const id = label.toLowerCase().replace(/\s+/g, '-');
  return (
    <div>
      <label className="label" htmlFor={id}>{label}</label>
      <select id={id} className="field" value={value} onChange={(event) => onChange(event.target.value)}>
        {options.map((option) => <option key={option} value={option}>{humanizeCode(option)}</option>)}
      </select>
    </div>
  );
}

function PhaseCard({ title, text }: { title: string; text: string }) {
  return (
    <article className="rounded-lg border border-border bg-surface-container-low p-4">
      <h3 className="font-semibold text-text-primary">{title}</h3>
      <p className="mt-2 text-sm text-on-surface-variant">{text}</p>
    </article>
  );
}

export default App;
