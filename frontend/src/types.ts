export type ViewState = 
  | 'login'
  | 'start_shift'
  | 'guard_dashboard'
  | 'admin_dashboard'
  | 'new_visitor'
  | 'people_on_site'
  | 'success'
  | 'vehicles'
  | 'new_vehicle'
  | 'packages'
  | 'new_package'
  | 'reports'
  | 'new_incident';

export interface User {
  name: string;
  role: string;
  avatar?: string;
}
