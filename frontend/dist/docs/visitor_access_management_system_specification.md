# Visitor, Access, Vehicle, Delivery, and Security Log Management Platform

## 1. Document purpose

This document defines the functional and technical requirements for building a web platform used by companies, offices, industrial facilities, residential complexes, and private security teams to manage:

- Visitor entrances and exits
- Vehicle entrances and exits
- Deliveries, packages, correspondence, and mail
- Residents, employees, contractors, suppliers, and recurring visitors
- Security guards and active shifts
- Access authorizations
- Hardware-assisted registration
- Real-time occupancy
- Reports, imports, exports, and audit logs
- Multi-client operation on a centralized platform

The system will be developed using:

- **Frontend:** React with TypeScript
- **Backend:** PHP
- **Database:** MySQL
- **Hosting:** Shared hosting with cPanel
- **Access:** Web browser on desktop computers, tablets, and mobile phones
- **Optional behavior:** Progressive Web App

---

# 2. Product vision

The platform must provide a fast, secure, and auditable way to register every person, vehicle, package, and access event at a company or residential complex.

The system must reduce manual data entry through:

- Colombian identification document barcode scanning
- QR code scanning
- Camera-assisted document and code reading
- Visitor photograph capture
- Geolocation capture from supported mobile devices
- Integration with fingerprint devices when technically supported
- Automatic reuse of previously registered person information

The platform must operate as a **multi-tenant system**.

This means:

- One central application
- Multiple independent clients
- Multiple sites per client
- Complete separation of each client's operational information
- Shared global person deduplication only when authorized
- No unrestricted cross-client visit-history visibility

---

# 3. Important architectural principle

## 3.1 Central platform with tenant isolation

The platform may use one centralized MySQL database for all clients, but every operational record must include a `tenant_id`.

Examples:

- Companies
- Residential complexes
- Branches
- Buildings
- Apartments
- Offices
- Departments
- Users
- Guards
- Visitors
- Visits
- Vehicles
- Deliveries
- Logs
- Reports

Every backend query must validate the tenant of the authenticated user.

React must never connect directly to MySQL.

All access must pass through the PHP API.

## 3.2 Global person deduplication

A person may have one global identity record based on:

- Country
- Document type
- Document number

The same person should not need to be registered repeatedly.

However:

- Each client has its own local person profile
- Each client keeps its own notes
- Each client keeps its own restrictions
- Each client sees only visits made to its own organization
- Cross-client movement history must remain hidden

Recommended structure:

```text
Global person
    ↓
Client-specific profile
    ↓
Visit record
```

---

# 4. Main user roles

## 4.1 Platform super administrator

Permissions:

- Create and suspend clients
- Configure plans and limits
- View global technical statistics
- Manage system-wide settings
- Review technical logs
- Manage global document types
- Manage supported hardware profiles
- Manage application versions
- Manage backups and maintenance
- Access client data only under documented support procedures

## 4.2 Client administrator

Permissions:

- Configure company or residential complex
- Manage sites, branches, towers, departments, and apartments
- Manage users and roles
- Manage guards
- Configure access points
- Configure visitor requirements
- View all client reports
- Export client information
- Configure retention policies
- Review audit logs
- Configure alerts and notifications

## 4.3 Security supervisor

Permissions:

- Start and close supervisor shift
- See active guards
- Review entrances and exits
- Approve corrections
- Review alerts
- View people currently inside
- Manage restrictions
- Review pending exits
- Review package incidents
- Access operational reports

## 4.4 Guard or receptionist

Permissions:

- Start shift
- Register visitors
- Register vehicles
- Register packages
- Register entries and exits
- Capture visitor photographs
- Scan documents and QR codes
- Request authorization
- Record observations
- Review current occupancy
- Close shift
- View only operational data required for assigned location

## 4.5 Resident or employee

Optional portal permissions:

- Pre-authorize visitors
- Create visit invitations
- Approve or reject arrivals
- View packages addressed to them
- Confirm package collection
- Review personal visit history
- Manage recurring visitors
- Configure authorized persons

## 4.6 Auditor

Permissions:

- Read-only access
- Review logs
- Review access records
- View selected reports
- No ability to edit operational records

---

# 5. Client and location management

The platform must support:

- Companies
- Residential complexes
- Branches
- Headquarters
- Sites
- Buildings
- Towers
- Blocks
- Floors
- Offices
- Departments
- Apartments
- Warehouses
- Parking areas
- Access points
- Reception desks
- Gates
- Vehicle entrances
- Pedestrian entrances

Recommended hierarchy:

```text
Tenant
├── Site
│   ├── Building or tower
│   │   ├── Floor
│   │   │   ├── Office
│   │   │   └── Apartment
│   ├── Department
│   ├── Parking area
│   └── Access point
```

Each access point should store:

- Name
- Type
- Site
- Allowed access types
- Status
- Assigned devices
- Assigned guards
- Latitude and longitude when applicable

---

# 6. Guard shift management

Every registration must be linked to the guard or receptionist who performed it.

## 6.1 Shift start

A guard must:

1. Sign in
2. Select the client location
3. Select the access point
4. Start a shift
5. Confirm date and time
6. Optionally capture current location
7. Optionally capture a start-of-shift photograph
8. Receive the previous shift's pending issues

Shift record:

- Guard
- Client
- Site
- Access point
- Start date and time
- End date and time
- Start location
- End location
- Device
- IP address
- Browser
- Shift status
- Previous guard
- Next guard
- Opening notes
- Closing notes

## 6.2 Shift handover

The system should support:

- Pending visitors without exit
- Vehicles still inside
- Undelivered packages
- Restricted-person alerts
- Open incidents
- Lost items
- Equipment issues
- Unresolved observations

The receiving guard should acknowledge the handover.

## 6.3 Shift closing

A shift cannot close without warning the guard about:

- People still inside
- Vehicles still inside
- Pending package deliveries
- Open incidents
- Incomplete records

A supervisor may force-close a shift with a reason.

---

# 7. Person management

## 7.1 Person categories

The system should support:

- Visitor
- Resident
- Employee
- Contractor
- Supplier
- Courier
- Delivery person
- Domestic worker
- Service technician
- Frequent visitor
- Temporary worker
- Minor
- Driver
- Passenger
- Restricted person
- Other

## 7.2 Person fields

Recommended fields:

- Internal person ID
- Tenant profile ID
- Document country
- Document type
- Document number
- First names
- Last names
- Full display name
- Date of birth
- Document issue date
- Document expiration date
- Sex when legally justified
- Blood type when available and justified
- Phone number
- Email
- Address
- Photograph
- Signature
- Fingerprint template reference
- Emergency contact
- Notes
- Status
- Verification status
- Created date
- Updated date

## 7.3 Verification statuses

```text
UNVERIFIED
MANUALLY_ENTERED
BARCODE_SCANNED
VISUALLY_CONFIRMED
FINGERPRINT_ENROLLED
EXTERNALLY_VERIFIED
RESTRICTED
```

Do not represent barcode scanning as official government verification.

---

# 8. Colombian document barcode scanning

## 8.1 Supported scanner type

The recommended device is a USB scanner compatible with:

- PDF417
- QR
- Common one-dimensional barcodes
- USB HID keyboard mode
- Optional serial or virtual COM mode

## 8.2 USB keyboard mode

Many scanners behave as a keyboard.

Flow:

```text
Scan document
    ↓
Scanner sends characters
    ↓
React captures scan buffer
    ↓
PHP parser validates and normalizes
    ↓
Form fields are filled
```

Possible fields:

- Document number
- First names
- Last names
- Date of birth
- Sex
- Blood type
- Other available data

The issue date must be treated as optional because it may not be available in every document format.

## 8.3 Scanner compatibility strategy

Do not advertise universal hardware compatibility.

Maintain a configuration table:

- Manufacturer
- Model
- Connection type
- Barcode types
- Prefix
- Suffix
- Encoding
- Tested status
- Parser profile
- Notes

## 8.4 Mobile camera scanning

When opened on a phone or tablet, the application should allow:

- Use of rear camera
- QR scanning
- Barcode scanning
- PDF417 scanning when browser and camera library support it
- Manual fallback
- Photograph capture

Camera permissions must be requested only when needed.

---

# 9. Camera integration

The web application should support:

- Visitor photograph capture
- Vehicle photograph capture
- License plate photograph
- Package photograph
- Incident photograph
- Signature image capture
- QR and barcode scanning
- Optional document front and back capture

## 9.1 Browser camera requirements

Use secure HTTPS.

Browser camera access normally requires:

- HTTPS
- User permission
- Supported browser
- Supported device
- Active camera not used by another application

## 9.2 Storage rules

Images should:

- Be compressed before upload
- Have maximum file-size limits
- Use private server paths
- Not be directly guessable by URL
- Be served through authorized PHP endpoints
- Store metadata and checksums
- Respect retention policies

Because shared hosting storage may be limited, image retention and compression are critical.

---

# 10. Fingerprint device integration

Fingerprint integration is possible, but it is the most hardware-dependent part of the project.

## 10.1 Browser limitation

A React web application cannot reliably communicate with every fingerprint scanner directly.

Possible approaches:

### Option A: Manufacturer SDK

Use the manufacturer's supported SDK if it provides:

- JavaScript browser support
- WebSocket support
- Local service
- Windows service
- Linux service
- REST interface

### Option B: Local hardware bridge

Install a small local desktop service on the reception computer.

Architecture:

```text
React browser
    ↓ HTTPS or WebSocket
Local hardware bridge
    ↓
Fingerprint SDK
    ↓
Fingerprint reader
```

The local bridge should return a controlled response such as:

```json
{
  "success": true,
  "device": "scanner-model",
  "templateId": "fp_12345",
  "quality": 89
}
```

## 10.2 Biometric recommendations

The system should avoid storing raw fingerprint images.

Prefer:

- Encrypted biometric templates
- Device-generated references
- Tenant-specific authorization
- Explicit consent
- Strict access controls
- Detailed logs
- Defined retention periods

Fingerprint integration should be considered an optional enterprise module.

---

# 11. Mobile geolocation

When the system is opened on a phone or tablet, supported actions may capture:

- Latitude
- Longitude
- Accuracy
- Timestamp
- Device information

Geolocation may be used for:

- Shift start
- Shift end
- Mobile access registration
- Patrol or checkpoint registration
- Delivery verification
- Incident reporting

Location must not be mandatory when:

- The device does not support it
- Permission is denied
- Accuracy is insufficient
- The client does not require it

The system must clearly indicate:

- Location captured
- Location denied
- Location unavailable
- Low accuracy

---

# 12. Visit management

## 12.1 Visit fields

Recommended visit record:

- Visit ID
- Tenant
- Site
- Access point
- Visitor
- Visitor category
- Destination type
- Destination
- Host person
- Subject of visit
- Entry date and time
- Exit date and time
- Expected exit date and time
- Authorization status
- Authorized by
- Authorization date and time
- Guard who registered entry
- Guard who registered exit
- Shift ID
- Vehicle
- Observations
- Entry photograph
- Exit photograph
- Entry method
- Exit method
- Current status
- Created date
- Updated date

## 12.2 Optional fields

The following may be optional according to client configuration:

- Visit subject
- Host person
- Department
- Apartment
- Office
- Vehicle
- Expected exit
- Phone
- Email
- Photograph
- Signature

## 12.3 Required minimum fields

Recommended minimum:

- Visitor identity or temporary anonymous person
- Tenant
- Site
- Entry point
- Entry date and time
- Guard
- Shift
- Status

## 12.4 Visit statuses

```text
PRE_REGISTERED
PENDING_AUTHORIZATION
AUTHORIZED
REJECTED
INSIDE
EXITED
CANCELLED
EXPIRED
BLOCKED
```

## 12.5 Visit workflows

### Standard entry

1. Scan or enter document
2. Search existing person
3. Confirm person
4. Capture photograph when required
5. Select destination
6. Enter visit subject
7. Request or confirm authorization
8. Add vehicle when applicable
9. Register entry
10. Print or display access credential
11. Notify destination

### Standard exit

1. Scan document, QR, credential, or search person
2. Find active visit
3. Confirm person
4. Register exit
5. Register vehicle exit when applicable
6. Calculate duration
7. Mark visit as exited

### Quick exit

Allow fast exit from:

- Active visit list
- Barcode scan
- QR scan
- Vehicle plate search
- Visitor document search

---

# 13. Pre-authorized visitors

Residents or employees may create invitations.

Invitation fields:

- Visitor
- Document number
- Visit date
- Allowed time range
- Host
- Destination
- Subject
- Vehicle plate
- Number of accompanying persons
- QR code
- Invitation status
- Expiration date
- Notes

The system may send invitations through:

- Email
- SMS through external provider
- WhatsApp through an approved API
- In-app notification

---

# 14. Vehicle management

## 14.1 Vehicle fields

- Vehicle ID
- Plate
- Country
- Vehicle type
- Brand
- Model
- Color
- Owner
- Driver
- Photograph
- Plate photograph
- Notes
- Status

## 14.2 Plate normalization

Before searching:

- Convert to uppercase
- Remove spaces
- Remove hyphens
- Remove unsupported characters

Example:

```text
ABC-123 → ABC123
```

## 14.3 Vehicle access fields

- Visit
- Vehicle
- Driver
- Passengers
- Entry gate
- Exit gate
- Entry time
- Exit time
- Parking space
- Guard
- Shift
- Entry photograph
- Exit photograph
- Observations

## 14.4 Vehicle workflows

Support:

- Driver only
- Driver plus passengers
- Passenger registered separately
- Company vehicle
- Taxi
- Motorcycle
- Bicycle
- Delivery vehicle
- Vehicle without known owner
- Vehicle entering and leaving independently from visitor

---

# 15. Delivery, package, correspondence, and mail module

## 15.1 Delivery types

```text
PACKAGE
MAIL
DOCUMENT
FOOD_DELIVERY
E_COMMERCE
MEDICINE
SUPPLIES
OTHER
```

## 15.2 Delivery fields

- Delivery ID
- Tenant
- Site
- Access point
- Type
- Carrier
- Tracking number
- Sender
- Recipient type
- Recipient
- Apartment
- Office
- Department
- Delivery person
- Delivery person's document
- Vehicle
- Received date and time
- Received by guard
- Shift
- Photograph
- Condition
- Observations
- Notification date
- Delivered date
- Delivered to
- Collector document
- Signature
- Current status

## 15.3 Delivery statuses

```text
EXPECTED
RECEIVED
NOTIFIED
READY_FOR_COLLECTION
DELIVERED
REJECTED
RETURNED
DAMAGED
LOST
CANCELLED
```

## 15.4 Package workflow

1. Register courier or delivery person
2. Scan or enter tracking number
3. Select recipient
4. Capture package photograph
5. Register package condition
6. Register reception
7. Notify recipient
8. Confirm collection
9. Verify collector
10. Capture signature or photograph
11. Mark delivered

## 15.5 Special cases

Support:

- Delivery person does not enter
- Multiple packages in one delivery
- One package for multiple recipients
- Third-party collection
- Package refused
- Package damaged
- Package returned
- Package never collected

---

# 16. Restrictions and alerts

The system should support tenant-specific alerts.

Restriction fields:

- Person
- Vehicle
- Restriction type
- Reason
- Effective date
- Expiration date
- Created by
- Approved by
- Evidence
- Status

Alert examples:

- Restricted visitor
- Restricted vehicle
- Expired contractor authorization
- Invalid invitation
- Duplicate active entry
- Visitor already inside
- Vehicle already inside
- Visit exceeds expected duration
- Person without exit
- Package pending too long
- Attempt outside permitted schedule
- Guard shift not active
- Access from unexpected location
- Document expired
- Document data mismatch

Global restrictions should not be shared across clients unless there is a documented legal and contractual basis.

---

# 17. Real-time operational dashboard

The dashboard should show:

- Guards currently on shift
- Open access points
- People currently inside
- Vehicles currently inside
- Entries today
- Exits today
- Pending authorizations
- Visitors without exit
- Visits exceeding expected duration
- Packages received today
- Packages pending collection
- Open incidents
- Alerts
- Most active access points
- Peak entry hours

Filters:

- Tenant
- Site
- Building
- Tower
- Department
- Apartment
- Access point
- Guard
- Shift
- Date range
- Visitor category
- Visit status
- Vehicle type

---

# 18. Reports and metrics

Required reports:

## 18.1 Visitor reports

- Visit history
- Current occupancy
- Visitors without exit
- Frequent visitors
- Visits by destination
- Visits by host
- Visits by department
- Visits by apartment
- Visits by guard
- Visits by access point
- Visit duration
- Rejected entries
- Restricted access attempts

## 18.2 Vehicle reports

- Vehicle entries and exits
- Vehicles currently inside
- Frequent vehicles
- Vehicles by site
- Parking duration
- Vehicle access by guard
- Restricted vehicles

## 18.3 Delivery reports

- Packages received
- Packages delivered
- Packages pending
- Packages by carrier
- Packages by recipient
- Delivery time
- Returned packages
- Damaged or lost packages

## 18.4 Guard reports

- Shifts by guard
- Hours worked
- Entries registered
- Exits registered
- Packages registered
- Incidents reported
- Corrections requested
- Shift punctuality
- Handover compliance

## 18.5 Metrics

- Average registration time
- Average visit duration
- Peak hours
- Most visited destinations
- Most active sites
- Entries by day
- Entries by hour
- Occupancy trends
- Package collection time
- Percentage of visits pre-authorized
- Percentage of records scanned versus manual
- Number of corrected records
- Number of alerts

---

# 19. Import and export

## 19.1 Import formats

Support:

- CSV
- XLSX when server libraries allow
- Optional JSON for integrations

Importable data:

- Employees
- Residents
- Apartments
- Offices
- Departments
- Vehicles
- Authorized visitors
- Contractors
- Users
- Access points
- Packages
- Historical visits when migrating

## 19.2 Import process

Every import should include:

1. Upload file
2. Select data type
3. Map columns
4. Validate records
5. Show errors
6. Preview result
7. Confirm import
8. Process in batches
9. Generate import report
10. Record audit log

Invalid records must not silently fail.

## 19.3 Export formats

Support:

- CSV
- XLSX
- PDF
- JSON for integrations

Exports must respect:

- User role
- Tenant isolation
- Active filters
- Date ranges
- Data masking
- Retention rules
- Maximum record limits

Every sensitive export must create an audit log.

---

# 20. Audit logs

Audit logs are mandatory.

## 20.1 Events to log

- Login
- Failed login
- Logout
- Password reset
- User creation
- User update
- Role changes
- Shift start
- Shift end
- Visitor registration
- Visit update
- Entry registration
- Exit registration
- Record deletion
- Correction approval
- Restriction creation
- Restriction removal
- Package registration
- Package delivery
- Import
- Export
- Photograph access
- Biometric action
- Configuration change
- Support access
- API error
- Hardware integration event

## 20.2 Audit log fields

- Log ID
- Tenant
- User
- Guard
- Shift
- Event type
- Entity type
- Entity ID
- Previous values
- New values
- Date and time
- IP address
- Device
- Browser
- Site
- Access point
- Latitude
- Longitude
- Result
- Error code
- Reason

Audit logs should not be editable from the normal application.

---

# 21. Correction and deletion rules

Operational records should not be directly overwritten without traceability.

Recommended behavior:

- Corrections create new versions
- Previous values remain in audit logs
- Deletion should usually be soft deletion
- Permanent deletion only under authorized privacy procedures
- Supervisor approval may be required
- Reason is mandatory

Fields:

- `deleted_at`
- `deleted_by`
- `deletion_reason`
- `version_number`
- `corrected_from_id`

---

# 22. Notifications

Possible notification channels:

- In-app
- Email
- SMS through external provider
- WhatsApp through approved provider
- Browser push notifications

Events:

- Visitor arrived
- Authorization requested
- Authorization approved
- Authorization rejected
- Package received
- Package ready for collection
- Package overdue
- Visit exceeded duration
- Restricted person attempt
- Guard shift started
- Guard shift not closed
- Vehicle still inside
- Emergency alert

Notification delivery must be recorded.

---

# 23. Offline and connectivity behavior

Shared hosting and browser-based operation depend on internet access.

A limited offline mode may be implemented as a later phase using:

- Service workers
- IndexedDB
- Locally queued records
- Pending image uploads
- Synchronization identifiers
- Conflict resolution

Minimum first version behavior:

- Clear offline banner
- Do not lose partially completed forms
- Retry failed requests
- Prevent duplicate registration
- Allow manual emergency log outside system as a contingency
- Synchronize only after authentication

Full offline mode is complex and should not be included in the first MVP unless essential.

---

# 24. Technical architecture

## 24.1 Frontend

Recommended:

- React
- TypeScript
- Vite
- React Router
- TanStack Query
- React Hook Form
- Zod or equivalent validation
- PWA plugin
- Camera and scanner libraries
- Chart library
- Internationalization
- Responsive interface

Frontend responsibilities:

- Forms
- Device permissions
- Scan buffer capture
- Camera interface
- Geolocation
- Local validation
- Dashboard
- Reports
- Import mapping
- Export requests

Frontend must not contain security-sensitive authorization logic as the only control.

## 24.2 Backend

Recommended PHP structure:

- PHP 8.2 or compatible version available on hosting
- REST API
- Composer
- PSR standards
- Laravel if hosting resources allow
- Slim, Symfony components, or custom modular API if Laravel is too heavy

Preferred option:

- Laravel with optimized production configuration
- Queue fallback using cron jobs
- Database-backed jobs if Redis is unavailable

Backend responsibilities:

- Authentication
- Authorization
- Tenant validation
- Business rules
- Data normalization
- Barcode parsing
- File handling
- Import and export
- Reporting
- Audit logs
- Notification dispatch
- Hardware-bridge communication rules
- Security checks

## 24.3 Database

Use MySQL with:

- InnoDB
- Foreign keys
- UTF8MB4
- Indexed tenant columns
- Composite indexes
- Soft delete fields
- Timestamps
- Transaction support

Avoid large unindexed text searches.

---

# 25. Suggested database entities

Core tables:

```text
tenants
tenant_settings
sites
buildings
floors
departments
units
access_points
users
roles
permissions
user_roles
guards
guard_shifts
shift_handovers
persons
tenant_person_profiles
person_documents
person_photos
person_biometrics
visits
visit_events
visit_authorizations
visit_guests
vehicles
visit_vehicles
deliveries
delivery_events
restrictions
alerts
incidents
files
notifications
imports
import_rows
exports
audit_logs
hardware_devices
hardware_profiles
api_tokens
login_attempts
password_resets
```

---

# 26. Example key tables

## 26.1 Persons

```sql
persons
- id
- country_code
- document_type
- document_number_hash
- encrypted_document_number
- first_names
- last_names
- birth_date
- verification_status
- created_at
- updated_at
```

Unique logical rule:

```text
country_code + document_type + document_number
```

## 26.2 Tenant person profiles

```sql
tenant_person_profiles
- id
- tenant_id
- person_id
- category
- local_status
- local_notes
- first_visit_at
- last_visit_at
- visit_count
- created_at
- updated_at
```

## 26.3 Visits

```sql
visits
- id
- tenant_id
- site_id
- access_point_id
- person_id
- tenant_person_profile_id
- destination_type
- destination_id
- host_person_id
- subject
- entered_at
- exited_at
- expected_exit_at
- entry_guard_id
- exit_guard_id
- entry_shift_id
- exit_shift_id
- status
- observations
- created_at
- updated_at
- deleted_at
```

## 26.4 Visit events

```sql
visit_events
- id
- tenant_id
- visit_id
- event_type
- event_at
- guard_id
- shift_id
- access_point_id
- latitude
- longitude
- device_info
- notes
```

## 26.5 Deliveries

```sql
deliveries
- id
- tenant_id
- site_id
- access_point_id
- type
- carrier
- tracking_number
- recipient_type
- recipient_id
- received_at
- received_by_guard_id
- received_shift_id
- delivered_at
- delivered_to_person_id
- status
- observations
```

---

# 27. Authentication and security

Required controls:

- HTTPS
- Secure password hashing
- Session expiration
- Refresh-token rotation or secure server sessions
- CSRF protection
- CORS restrictions
- Rate limiting
- Login-attempt throttling
- Two-factor authentication for administrators
- Role-based permissions
- Tenant isolation
- Input validation
- SQL injection protection
- File upload validation
- MIME validation
- Antivirus integration when available
- Secure headers
- Content Security Policy
- Secure cookies
- Audit logs
- Backup policy
- Data-retention policy

Never store:

- Plaintext passwords
- Raw access tokens
- Unencrypted sensitive document information
- Raw fingerprint images unless strictly necessary

---

# 28. Shared cPanel hosting considerations

The system must be designed around shared-hosting limitations.

Possible limitations:

- No persistent WebSocket process
- No Node.js server
- Limited RAM and CPU
- Limited execution time
- Limited cron frequency
- No Redis
- No supervisor process
- Limited file storage
- Limited database connections
- No root access
- Restrictions on background workers

## 28.1 Recommended adaptations

- Build React locally and upload static files
- Serve React build from public web directory
- Use PHP REST API
- Use MySQL
- Use cPanel cron jobs
- Use database-backed job queue
- Process imports in batches
- Process exports in batches
- Compress images before upload
- Use pagination everywhere
- Avoid server-side video processing
- Avoid permanent background processes
- Use polling instead of WebSockets when necessary
- Use external object storage in future if volume grows

## 28.2 Real-time behavior

On shared hosting, implement near-real-time updates using:

- Polling every 15 to 60 seconds
- Manual refresh
- Server-Sent Events only if hosting supports it
- External real-time service as optional future integration

---

# 29. API structure

Suggested route groups:

```text
/api/auth
/api/tenants
/api/sites
/api/access-points
/api/users
/api/guards
/api/shifts
/api/persons
/api/visits
/api/vehicles
/api/deliveries
/api/authorizations
/api/restrictions
/api/alerts
/api/incidents
/api/reports
/api/imports
/api/exports
/api/files
/api/hardware
/api/logs
```

Every request should include authenticated tenant context derived from the session or token.

Do not trust `tenant_id` from the frontend without backend verification.

---

# 30. Hardware bridge API

For fingerprint and specialized devices, define a local bridge.

Possible local endpoints:

```text
GET  /health
GET  /devices
POST /fingerprint/capture
POST /fingerprint/verify
POST /fingerprint/enroll
POST /scanner/read
POST /printer/print
```

Security requirements:

- Localhost-only by default
- Signed requests
- Short-lived tokens
- Allowed-origin list
- Device allowlist
- Audit logs
- No direct database access
- No storage of application passwords

---

# 31. File management

Supported files:

- Visitor photographs
- Documents
- Vehicle photographs
- Package photographs
- Signatures
- Incident evidence
- Import files
- Export files

Rules:

- Random file names
- Private directories
- Authorized download endpoint
- Maximum size
- Allowed MIME types
- Image compression
- Optional thumbnails
- Retention dates
- Audit file access
- Automatic deletion job

---

# 32. Search requirements

Search should support:

- Document number
- Name
- Last name
- Phone
- Vehicle plate
- Tracking number
- Apartment
- Office
- Department
- Host
- Guard
- Shift
- Date range
- Visit status
- Package status

Sensitive search fields should be logged when appropriate.

---

# 33. User interface requirements

## 33.1 Guard interface

The guard interface should be fast and simple.

Main actions:

- Start shift
- Scan visitor
- Register manual visitor
- Register vehicle
- Register package
- Register exit
- View people inside
- View vehicles inside
- View pending packages
- Report incident
- End shift

## 33.2 Accessibility

- Large buttons
- Keyboard navigation
- High contrast
- Clear status colors
- Touch-friendly controls
- Responsive design
- Spanish-first interface
- Optional English
- Confirmation before destructive actions

## 33.3 Scanner-ready forms

Forms should:

- Automatically focus scan input
- Detect rapid scanner input
- Distinguish scanner from manual typing
- Support Enter suffix
- Prevent accidental duplicate scans
- Show parsed data before confirmation
- Allow manual correction with audit trail

---

# 34. Privacy and data protection

The platform must be designed for compliance with applicable Colombian personal-data requirements.

Required capabilities:

- Privacy notice
- Purpose of collection
- Authorization records when required
- Data-controller identification
- Data-processor identification
- Data-retention settings
- Data-subject request workflow
- Correction requests
- Deletion or anonymization requests
- Export of a person's data
- Access history
- Sensitive-data controls
- Biometric-data controls
- Photograph controls

Cross-client visit history must not be visible.

---

# 35. Backup and recovery

Minimum backup requirements:

- Daily database backup
- Scheduled file backup
- Backup encryption
- Retention schedule
- Off-host backup copy
- Restore testing
- Backup logs
- Client export option

Shared-hosting backups alone should not be considered sufficient for a production security platform.

---

# 36. Error handling

The system must:

- Show user-friendly errors
- Store technical errors in logs
- Generate correlation IDs
- Avoid exposing stack traces
- Retry recoverable operations
- Prevent duplicate submissions
- Preserve unfinished form data
- Notify supervisors of critical failures

---

# 37. Performance requirements

- Pagination for all large lists
- Indexed searches
- Lazy loading
- Image thumbnails
- Compressed uploads
- Batched imports
- Batched exports
- Report date-range limits
- Cached configuration
- Optimized React production build
- Database query monitoring

---

# 38. MVP scope

Recommended first release:

1. Multi-tenant foundation
2. Client, site, building, department, and apartment management
3. Users and roles
4. Guard shifts
5. Person registration
6. Manual document entry
7. USB PDF417 scanner support in keyboard mode
8. Visitor photographs
9. Visit registration
10. Entry and exit
11. Destination and host
12. Vehicle registration
13. Package and mail registration
14. People currently inside
15. Vehicles currently inside
16. Basic dashboard
17. Reports
18. CSV import
19. CSV and PDF export
20. Audit logs
21. Restrictions and alerts
22. Responsive mobile interface
23. Camera access
24. Optional geolocation
25. cPanel deployment

---

# 39. Phase 2

- QR invitations
- Resident and employee self-service portal
- Approval notifications
- XLSX import and export
- Package signature collection
- Better analytics
- External WhatsApp integration
- Offline queue
- Contractor document management
- Parking assignment
- License plate recognition integration
- External object storage
- Multi-language support

---

# 40. Phase 3

- Fingerprint device integration
- Local hardware bridge
- Turnstile integration
- Door-controller integration
- Facial recognition where legally permitted
- Advanced mobile patrol module
- Dedicated databases for large clients
- White-label domains
- External API for enterprise clients
- Advanced anomaly detection
- Automatic retention and anonymization
- High-availability cloud migration

---

# 41. Acceptance criteria

The MVP is accepted when:

- Multiple tenants can operate without data leakage
- A guard can start and close a shift
- Every access record identifies the responsible guard and shift
- Visitors can be registered manually or by compatible scanner
- Existing people can be reused without duplicate registration
- Visits can be entered and exited
- Vehicles can be registered
- Packages can be received and delivered
- Mobile camera capture works on supported browsers
- Optional geolocation works with permission
- Reports can be filtered
- Data can be imported and exported
- Audit logs record sensitive actions
- Unauthorized users cannot access another tenant
- The system works from the configured domain on cPanel shared hosting

---

# 42. Critical implementation warnings

1. A browser cannot guarantee compatibility with every fingerprint reader.
2. Fingerprint support will usually require a manufacturer SDK or local hardware bridge.
3. PDF417 scanner output differs by model and must be tested.
4. Mobile PDF417 camera scanning depends on browser and library support.
5. Shared hosting limits real-time services and background processing.
6. Global person deduplication must not expose cross-client visit history.
7. Barcode scanning is data capture, not official identity verification.
8. Biometric data requires stronger legal, contractual, and technical controls.
9. Every tenant-sensitive query must be validated in PHP.
10. Every important modification must create an audit log.

---

# 43. Recommended initial development order

1. Database and tenant model
2. Authentication and roles
3. Client and location configuration
4. Guard shift module
5. Person and document module
6. Visitor entry and exit
7. Vehicle module
8. Package and mail module
9. Dashboard
10. Reports
11. Import and export
12. Audit logs
13. Camera integration
14. Barcode scanner integration
15. Mobile geolocation
16. Alerts and restrictions
17. Security review
18. cPanel deployment
19. Hardware compatibility testing
20. Pilot implementation with one client

---

# 44. Final product definition

The final platform should be presented as:

> A centralized, multi-client access-management platform for companies and residential complexes that registers visitors, vehicles, packages, guards, shifts, entrances, exits, authorizations, and incidents, with document scanning, mobile camera support, optional geolocation, hardware integrations, analytics, imports, exports, and complete auditability.

