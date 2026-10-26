const apiBase = import.meta.env.VITE_API_BASE || '/api';
// A production build must fail visibly when the backend is unavailable. The
// local demo fallback remains opt-in during development or with `false`.
export const apiRequired = import.meta.env.PROD
  ? import.meta.env.VITE_API_REQUIRED !== 'false'
  : import.meta.env.VITE_API_REQUIRED === 'true';
let csrfToken = sessionStorage.getItem('asiscontrol.csrf') || '';
const offlineQueueKey = 'asiscontrol.offlineQueue.v1';

function createUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = Math.random() * 16 | 0;
    const value = char === 'x' ? random : (random & 0x3 | 0x8);
    return value.toString(16);
  });
}

export interface OfflineAction {
  id: string;
  eventUuid: string;
  label: string;
  method: keyof typeof api;
  args: any[];
  createdAt: string;
  attempts?: number;
}

export class ApiError extends Error {
  status: number;
  requestId?: string;
  code?: string;

  constructor(message: string, status: number, requestId?: string, code?: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.requestId = requestId;
    this.code = code;
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers || {});
  const isFormData = options.body instanceof FormData;

  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  if (!isFormData && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  if (csrfToken && (options.method || 'GET').toUpperCase() !== 'GET') headers.set('X-CSRF-Token', csrfToken);

  const response = await fetch(`${apiBase}${path}`, {
    credentials: 'include',
    headers,
    ...options,
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(payload.error || 'Error de API', response.status, payload.request_id || response.headers.get('X-Request-Id') || undefined, payload.error_code || undefined);
  }

  return payload as T;
}

function captureCsrf<T extends { csrf_token?: string }>(payload: T): T {
  if (payload.csrf_token) {
    csrfToken = payload.csrf_token;
    sessionStorage.setItem('asiscontrol.csrf', csrfToken);
  }

  return payload;
}

function download(path: string, params?: Record<string, string>): void {
  const url = new URL(`${apiBase}${path}`, window.location.origin);
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value) url.searchParams.set(key, value);
  });
  window.location.href = url.toString();
}

export function queueOfflineAction(action: Omit<OfflineAction, 'id' | 'eventUuid' | 'createdAt'>): OfflineAction {
  const queued: OfflineAction = {
    ...action,
    id: `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    eventUuid: createUuid(),
    createdAt: new Date().toISOString(),
    attempts: 0,
  };
  const current = getOfflineQueue();
  try {
    localStorage.setItem(offlineQueueKey, JSON.stringify([...current, queued].slice(-10000)));
    if (navigator.storage?.persist) navigator.storage.persist().catch(() => undefined);
  } catch {
    // A quota or private-mode error must not prevent the guard from continuing.
  }
  window.dispatchEvent(new CustomEvent('asiscontrol-offline-queue'));
  return queued;
}

export function getOfflineQueue(): OfflineAction[] {
  try {
    const raw = localStorage.getItem(offlineQueueKey);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((item) => item && item.id && item.method).slice(-10000) : [];
  } catch {
    return [];
  }
}

export async function syncOfflineQueue(): Promise<{ synced: number; failed: number }> {
  const queue = getOfflineQueue();
  const remaining: OfflineAction[] = [];
  let synced = 0;
  let failed = 0;

  for (const item of queue) {
    try {
      if (item.method === 'createVisit') {
        const originalPayload = item.args[0] || {};
        const eventUuid = item.eventUuid || createUuid();
        const syncResponse = await api.offlineEvents({
          event_uuid: eventUuid,
          idempotency_key: item.id,
          action: 'visit',
          client_occurred_at: item.createdAt,
          payload: {
            ...originalPayload,
            event_type: 'entrada',
            occurred_at: item.createdAt,
            identification_method: originalPayload.identification_method || 'manual',
          },
        });
        if (syncResponse.status === 'superseded') {
          synced++;
          continue;
        }
        // The offline envelope is already persisted and applied by the
        // server. Calling createVisit again here would duplicate the visit.
        synced++;
        continue;
      }
      const fn = api[item.method] as (...args: any[]) => Promise<any>;
      await fn(...item.args);
      synced++;
    } catch {
      remaining.push({ ...item, attempts: (item.attempts || 0) + 1 });
      failed++;
    }
  }

  localStorage.setItem(offlineQueueKey, JSON.stringify(remaining));
  window.dispatchEvent(new CustomEvent('asiscontrol-offline-queue'));
  return { synced, failed };
}

export const api = {
  login: (email: string, password: string) =>
    request<{ user: any; csrf_token?: string }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    }).then(captureCsrf),
  logout: () => {
    const result = request<{ ok: true }>('/auth/logout', { method: 'POST' });
    csrfToken = '';
    sessionStorage.removeItem('asiscontrol.csrf');
    return result;
  },
  passwordRequest: (email: string) => request<{ ok: true; message: string }>('/auth/password/request', { method: 'POST', body: JSON.stringify({ email }) }),
  bootstrap: () => request<{ data: any; platform_admin?: boolean; csrf_token?: string }>('/bootstrap').then(captureCsrf),
  startShift: (payload: any) => request<{ id: string }>('/shifts', { method: 'POST', body: JSON.stringify(payload) }),
  closeShift: (id: string) => request<{ ok: true }>(`/shifts/${id}/close`, { method: 'POST' }),
  shifts: () => request<{ data: any[] }>('/shifts'),
  forceCloseShift: (id: string, reason: string) => request<{ ok: true }>(`/shifts/${id}/force-close`, { method: 'POST', body: JSON.stringify({ reason }) }),
  createVisit: (payload: any) => request<{ id: string }>('/visits', { method: 'POST', body: JSON.stringify(payload) }),
  exitVisit: (id: string, payload: { observations?: string } = {}) =>
    request<{ ok: true }>(`/visits/${id}/exit`, { method: 'POST', body: JSON.stringify(payload) }),
  adminExitVisit: (id: string, payload: { reason: string; observations?: string }) => request<{ ok: true }>(`/visits/${id}/admin-exit`, { method: 'POST', body: JSON.stringify(payload) }),
  adminExitEmployees: (payload: { reason: string; observations?: string }) => request<{ ok: true; count: number }>('/visits/admin-exit-employees', { method: 'POST', body: JSON.stringify(payload) }),
  createVehicle: (payload: any) => request<{ id: string }>('/vehicles', { method: 'POST', body: JSON.stringify(payload) }),
  exitVehicle: (id: string, payload: { observations?: string } = {}) =>
    request<{ ok: true }>(`/vehicles/${id}/exit`, { method: 'POST', body: JSON.stringify(payload) }),
  adminExitVehicle: (id: string, payload: { reason: string; observations?: string }) => request<{ ok: true }>(`/vehicles/${id}/admin-exit`, { method: 'POST', body: JSON.stringify(payload) }),
  parking: (q = '') => request<{ data: any[] }>(`/parking${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  createParking: (payload: any) => request<{ id: string }>('/parking', { method: 'POST', body: JSON.stringify(payload) }),
  updateParking: (id: string, payload: any) => request<{ ok: true }>(`/parking/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  bookingResources: () => request<{ data: any[] }>('/booking-resources'),
  createBookingResource: (payload: any) => request<{ id: string }>('/booking-resources', { method: 'POST', body: JSON.stringify(payload) }),
  bookings: (params?: { from?: string; to?: string; resource_id?: string }) => {
    const query = new URLSearchParams();
    if (params?.from) query.set('from', params.from);
    if (params?.to) query.set('to', params.to);
    if (params?.resource_id) query.set('resource_id', params.resource_id);
    return request<{ data: any[] }>(`/bookings${query.toString() ? `?${query.toString()}` : ''}`);
  },
  createBooking: (payload: any) => request<{ id: string }>('/bookings', { method: 'POST', body: JSON.stringify(payload) }),
  updateBookingStatus: (id: string, status: 'confirmed' | 'paid' | 'cancelled', cancellation_reason?: string) =>
    request<{ ok: true }>(`/bookings/${id}/status`, { method: 'POST', body: JSON.stringify({ status, cancellation_reason }) }),
  deleteBooking: (id: string) => request<{ ok: true }>(`/bookings/${id}`, { method: 'DELETE' }),
  bookingGuests: (id: string) => request<{ data: any[] }>(`/bookings/${id}/guests`),
  createBookingGuest: (id: string, payload: any) => request<{ id: string; qr_token: string }>(`/bookings/${id}/guests`, { method: 'POST', body: JSON.stringify(payload) }),
  checkInBookingGuest: (id: string) => request<{ ok: true }>(`/reservation-guests/${id}/check-in`, { method: 'POST' }),
  checkOutBookingGuest: (id: string) => request<{ ok: true }>(`/reservation-guests/${id}/check-out`, { method: 'POST' }),
  createPackage: (payload: any) => request<{ id: string }>('/packages', { method: 'POST', body: JSON.stringify(payload) }),
  portalAuthRequest: (email: string) => request<{ ok: true; message: string; debug_token?: string }>('/portal/auth/request', { method: 'POST', body: JSON.stringify({ email }) }),
  portalAuthVerify: (token: string) => request<{ resident: any; csrf_token?: string }>('/portal/auth/verify', { method: 'POST', body: JSON.stringify({ token }) }).then(captureCsrf),
  portalLogin: (email: string, password: string) => request<{ resident: any; csrf_token?: string }>('/portal/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }).then(captureCsrf),
  portalMe: () => request<{ resident: any; csrf_token?: string }>('/portal/me').then(captureCsrf),
  portalLogout: () => request<{ ok: true }>('/portal/auth/logout', { method: 'POST' }),
  portalPackages: () => request<{ data: any[] }>('/portal/packages'),
  portalInvitations: () => request<{ data: any[] }>('/portal/invitations'),
  portalBookings: () => request<{ data: any[] }>('/portal/bookings'),
  portalCreateBooking: (payload: any) => request<{ id: string; status: string }>('/portal/bookings', { method: 'POST', body: JSON.stringify(payload) }),
  portalActivity: () => request<{ data: any[] }>('/portal/activity'),
  portalCreateInvitation: (payload: any) => request<{ id: string; qr_token: string }>('/portal/invitations', { method: 'POST', body: JSON.stringify(payload) }),
  deliverPackage: (id: string, payload: { receiver_name?: string; observations?: string } = {}) =>
    request<{ ok: true }>(`/packages/${id}/deliver`, { method: 'POST', body: JSON.stringify(payload) }),
  rejectPackage: (id: string, reason: string) => request<{ ok: true }>(`/packages/${id}/reject`, { method: 'POST', body: JSON.stringify({ reason }) }),
  returnPackage: (id: string, reason: string) => request<{ ok: true }>(`/packages/${id}/return`, { method: 'POST', body: JSON.stringify({ reason }) }),
  signPackage: (id: string, payload: { receiver_name: string; signature_data_url: string; observations?: string }) =>
    request<{ id: string }>(`/packages/${id}/signature`, { method: 'POST', body: JSON.stringify(payload) }),
  createInvitation: (payload: any) => request<{ id: string; qr_token: string }>('/invitations', { method: 'POST', body: JSON.stringify(payload) }),
  cancelInvitation: (id: string) => request<{ ok: true }>(`/invitations/${id}/cancel`, { method: 'POST' }),
  redeemInvitation: (payload: any) => request<{ id: string }>('/invitations/redeem', { method: 'POST', body: JSON.stringify(payload) }),
  approvals: () => request<{ data: any[] }>('/approvals'),
  createApproval: (payload: any) => request<{ id: string }>('/approvals', { method: 'POST', body: JSON.stringify(payload) }),
  decideApproval: (id: string, decision: 'approve' | 'reject') => request<{ ok: true }>(`/approvals/${id}/${decision}`, { method: 'POST' }),
  createIncident: (payload: any) => request<{ id: string }>('/incidents', { method: 'POST', body: JSON.stringify(payload) }),
  reviewIncident: (id: string) => request<{ ok: true }>(`/incidents/${id}/review`, { method: 'POST' }),
  closeIncident: (id: string, resolution?: string) => request<{ ok: true }>(`/incidents/${id}/close`, { method: 'POST', body: JSON.stringify({ resolution }) }),
  createRestriction: (payload: any) => request<{ id: string }>('/restrictions', { method: 'POST', body: JSON.stringify(payload) }),
  users: () => request<{ data: any[] }>('/users'),
  createUser: (payload: any) => request<{ id: string }>('/users', { method: 'POST', body: JSON.stringify(payload) }),
  updateUser: (id: string, payload: any) => request<{ ok: true }>(`/users/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  permissions: () => request<{ data: { permissions: any[]; roles: Record<string, string[]> } }>('/permissions'),
  updatePermissions: (role: string, permissions: string[]) => request<{ ok: true }>(`/permissions/${role}`, { method: 'PUT', body: JSON.stringify({ permissions }) }),
  employees: () => request<{ data: any[] }>('/employees'),
  createEmployee: (payload: any) => request<{ id: string; employee_profile_id: string }>('/employees', { method: 'POST', body: JSON.stringify(payload) }),
  updateEmployee: (id: string, payload: any) => request<{ ok: true }>(`/employees/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  updateEmployeeSchedule: (id: string, payload: any) => request<{ ok: true }>(`/employees/${id}/schedule`, { method: 'PUT', body: JSON.stringify(payload) }),
  attendance: (params?: { from?: string; to?: string; employee_id?: string }) => {
    const query = new URLSearchParams();
    if (params?.from) query.set('from', params.from);
    if (params?.to) query.set('to', params.to);
    if (params?.employee_id) query.set('employee_id', params.employee_id);
    return request<{ data: any[] }>(`/attendance${query.toString() ? `?${query.toString()}` : ''}`);
  },
  createAttendance: (payload: any) => request<{ id: string; status: string }>('/attendance', { method: 'POST', body: JSON.stringify(payload) }),
  kioskAttendance: (payload: any) => request<{ id: string; status: string; duplicate?: boolean }>('/kiosk/attendance', { method: 'POST', body: JSON.stringify(payload) }),
  contractors: () => request<{ data: any[] }>('/contractors'),
  createContractor: (payload: any) => request<{ id: string }>('/contractors', { method: 'POST', body: JSON.stringify(payload) }),
  domesticStaff: () => request<{ data: any[] }>('/domestic-staff'),
  createDomesticStaff: (payload: any) => request<{ id: string }>('/domestic-staff', { method: 'POST', body: JSON.stringify(payload) }),
  corrections: () => request<{ data: any[] }>('/corrections'),
  createCorrection: (payload: any) => request<{ ok: true }>('/corrections', { method: 'POST', body: JSON.stringify(payload) }),
  sites: () => request<{ data: any[] }>('/sites'),
  createSite: (payload: any) => request<{ id: string }>('/sites', { method: 'POST', body: JSON.stringify(payload) }),
  updateSite: (id: string, payload: any) => request<{ ok: true }>(`/sites/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  accessPoints: () => request<{ data: any[] }>('/access-points'),
  createAccessPoint: (payload: any) => request<{ id: string }>('/access-points', { method: 'POST', body: JSON.stringify(payload) }),
  updateAccessPoint: (id: string, payload: any) => request<{ ok: true }>(`/access-points/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  devices: () => request<{ data: any[] }>('/devices'),
  createDevice: (payload: any) => request<{ id: string; device_token: string }>('/devices', { method: 'POST', body: JSON.stringify(payload) }),
  revokeDevice: (id: string) => request<{ ok: true }>(`/devices/${id}/revoke`, { method: 'POST' }),
  units: () => request<{ data: any[] }>('/units'),
  createUnit: (payload: any) => request<{ id: string }>('/units', { method: 'POST', body: JSON.stringify(payload) }),
  residents: () => request<{ data: any[] }>('/residents'),
  createResident: (payload: any) => request<{ id: string }>('/residents', { method: 'POST', body: JSON.stringify(payload) }),
  setResidentPassword: (id: string, password: string) => request<{ ok: true }>(`/residents/${id}/password`, { method: 'POST', body: JSON.stringify({ password }) }),
  people: () => request<{ data: any[] }>('/people'),
  archivePerson: (id: string, payload: { status?: 'archived' | 'deleted'; reason?: string } = {}) =>
    request<{ ok: true }>(`/people/${id}/archive`, { method: 'POST', body: JSON.stringify(payload) }),
  requestPasswordReset: (email: string) => request<{ ok: true; message: string }>('/auth/password/request', { method: 'POST', body: JSON.stringify({ email }) }),
  resetPassword: (token: string, password: string) => request<{ ok: true }>('/auth/password/reset', { method: 'POST', body: JSON.stringify({ token, password }) }),
  uploadFile: (payload: { ownerType: string; ownerId: string; file: File }) => {
    const body = new FormData();
    body.append('owner_type', payload.ownerType);
    body.append('owner_id', payload.ownerId);
    body.append('file', payload.file);
    return request<{ id: string }>('/files', { method: 'POST', body });
  },
  files: (ownerType: string, ownerId: string) => request<{ data: any[] }>(`/files?owner_type=${encodeURIComponent(ownerType)}&owner_id=${encodeURIComponent(ownerId)}`),
  exportVisitsCsv: (params?: { from?: string; to?: string; status?: string }) => download('/reports/visits.csv', params),
  exportVisitsXlsx: (params?: { from?: string; to?: string; status?: string }) => download('/reports/visits.xlsx', params),
  openVisitsReport: (params?: { from?: string; to?: string; status?: string }) => download('/reports/visits.html', params),
  exportAuditCsv: (params?: { from?: string; to?: string; action?: string }) => download('/audit.csv', params),
  openAuditReport: (params?: { from?: string; to?: string; action?: string }) => download('/audit.html', params),
  importJobs: () => request<{ data: any[] }>('/jobs/imports'),
  exportJobs: () => request<{ data: any[] }>('/jobs/exports'),
  notifications: () => request<{ data: any[] }>('/notifications'),
  registerAccessEvent: (payload: any) => request<{ id: string; status: string }>('/access-events', { method: 'POST', body: JSON.stringify(payload) }),
  accessEvents: (params?: { from?: string; to?: string }) => {
    const query = new URLSearchParams();
    if (params?.from) query.set('from', params.from);
    if (params?.to) query.set('to', params.to);
    return request<{ data: any[] }>(`/access-events${query.toString() ? `?${query.toString()}` : ''}`);
  },
  offlineEvents: (payload: any) => request<{ id: string; status: string; duplicate?: boolean }>('/offline/events', { method: 'POST', body: JSON.stringify(payload) }),
  offlineConflicts: () => request<{ data: any[] }>('/offline/conflicts'),
  resolveOfflineConflict: (id: string, payload: { status?: 'resolved' | 'dismissed'; resolution_notes: string }) =>
    request<{ ok: true }>(`/offline/conflicts/${id}/resolve`, { method: 'POST', body: JSON.stringify(payload) }),
  backups: () => request<{ data: any[] }>('/backups'),
  backupPolicy: () => request<{ data: any }>('/backups/policy'),
  updateBackupPolicy: (payload: any) => request<{ ok: true }>('/backups/policy', { method: 'PUT', body: JSON.stringify(payload) }),
  requestBackup: (payload: { backup_type?: 'technical' | 'operational' | 'consolidated'; scope?: Record<string, unknown> } = {}) =>
    request<{ id: string; status: string }>('/backups', { method: 'POST', body: JSON.stringify(payload) }),
  backupRestores: () => request<{ data: any[] }>('/backups/restores'),
  requestBackupRestore: (id: string, reason: string) => request<{ id: string; status: string }>(`/backups/${id}/restore/request`, { method: 'POST', body: JSON.stringify({ confirmation: 'RESTAURAR BACKUP', reason }) }),
  approveBackupRestore: (id: string) => request<{ ok: true; status: string }>(`/backups/${id}/restore/approve`, { method: 'POST', body: JSON.stringify({ confirmation: 'APROBAR RESTAURACION' }) }),
  offlineStatus: () => request<{ data: any }>('/offline/status'),
  exportAttendanceCsv: (params?: { from?: string; to?: string }) => download('/reports/attendance.csv', params),
  exportAttendanceXlsx: (params?: { from?: string; to?: string }) => download('/reports/attendance.xlsx', params),
  openAttendanceReport: (params?: { from?: string; to?: string; employee_id?: string }) => download('/reports/attendance.html', params),
  platformMe: () => request<{ data: { user: any; platform_admin: boolean } }>('/platform/me'),
  platformPlans: () => request<{ data: any[] }>('/platform/plans'),
  platformMetrics: () => request<{ data: Record<string, number> }>('/platform/metrics'),
  platformNotifications: () => request<{ data: any[] }>('/platform/notifications'),
  platformAudit: () => request<{ data: any[] }>('/platform/audit'),
  platformTenants: () => request<{ data: any[] }>('/platform/tenants'),
  platformClientTypes: () => request<{ data: any[] }>('/platform/client-types'),
  platformChildren: (parentId: string) => request<{ data: any[] }>(`/platform/tenants/${parentId}/children`),
  createPlatformChildTenant: (parentId: string, payload: any) => request<{ id: string; setup_token: string }>(`/platform/tenants/${parentId}/children`, { method: 'POST', body: JSON.stringify(payload) }),
  platformTenantLimits: (tenantId: string) => request<{ data: any[] }>(`/platform/tenants/${tenantId}/limits`),
  updatePlatformPlanLimits: (planId: string, limits: Record<string, number>) => request<{ ok: true }>(`/platform/plans/${planId}/limits`, { method: 'PUT', body: JSON.stringify({ limits }) }),
  createPlatformTenant: (payload: any) => request<{ id: string; admin_user_id: string; setup_token: string; setup_expires_at: string }>('/platform/tenants', { method: 'POST', body: JSON.stringify(payload) }),
  updatePlatformTenantStatus: (id: string, status: 'active' | 'inactive') => request<{ ok: true }>(`/platform/tenants/${id}/status`, { method: 'POST', body: JSON.stringify({ status }) }),
  updatePlatformSubscription: (id: string, payload: any) => request<{ ok: true }>(`/platform/tenants/${id}/subscription`, { method: 'POST', body: JSON.stringify(payload) }),
  setupPlatformAccount: (payload: { token: string; password: string; name?: string }) => request<{ ok: true }>('/platform/account/setup', { method: 'POST', body: JSON.stringify(payload) }),
  openInvitationPrint: (id: string) => download(`/invitations/${id}/print.html`),
  downloadFile: (id: string) => download(`/files/${id}/download`),
  downloadImportTemplate: (jobType: string, format: 'csv' | 'xlsx' = 'csv') => download(`/imports/templates/${jobType}.${format}`),
  importCsv: (payload: { jobType: string; file: File }) => {
    const body = new FormData();
    body.append('job_type', payload.jobType);
    body.append('file', payload.file);
    return request<{ data: any }>('/imports', { method: 'POST', body });
  },
};
