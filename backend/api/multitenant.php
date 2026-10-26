<?php
declare(strict_types=1);

/**
 * Multi-tenant policy. The actor remains authenticated via the existing
 * session; a scope assignment never elevates the user's master role.
 */
function effective_role_permissions(int $tenantId, string $role): array
{
    $stmt = db()->prepare(
        'SELECT rp.permission_code
           FROM role_permissions rp
           JOIN master_role_permissions mp
             ON mp.role_code=rp.role AND mp.permission_code=rp.permission_code
           LEFT JOIN tenant_role_restrictions tr
             ON tr.tenant_id=rp.tenant_id AND tr.role_code=rp.role AND tr.permission_code=rp.permission_code
          WHERE rp.tenant_id=:tenant AND rp.role=:role AND rp.granted=1
            AND tr.permission_code IS NULL
          ORDER BY rp.permission_code'
    );
    $stmt->execute(['tenant'=>$tenantId,'role'=>$role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function validate_role_assignment(array $actor, int $tenantId, string $role): void
{
    if ($role === 'superadmin' || !in_array($role, ['admin','supervisor','coordinator','guard','auditor','resident','tech_operator'], true)) {
        json_response(['error'=>'No se puede asignar este rol desde un cliente','error_code'=>'ROLE_NOT_ASSIGNABLE'],403);
    }
    if (!is_platform_admin($actor)) {
        $their = effective_role_permissions($tenantId, (string) $actor['role']);
        $requested = effective_role_permissions($tenantId, $role);
        if (array_diff($requested, $their)) {
            json_response(['error'=>'El rol supera los permisos del administrador','error_code'=>'ROLE_ESCALATION'],403);
        }
    }
}

function scopes_for_user(int $userId, int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT scope_type,scope_id FROM user_scopes
          WHERE user_id=:user_id AND tenant_id=:tenant_id ORDER BY scope_type,scope_id'
    );
    $stmt->execute(['user_id'=>$userId,'tenant_id'=>$tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function valid_scope_resource(int $tenantId, string $type, int $id): bool
{
    if ($id<=0) return false;
    if ($type==='tenant') return $tenantId===$id;
    $map=[
        'site'=>['sites','id'],
        'access_point'=>['access_points','id'],
        'unit'=>['units','id']
    ];
    if (!isset($map[$type])) return false;
    [$table,$column]=$map[$type];
    $stmt=db()->prepare("SELECT 1 FROM {$table} WHERE {$column}=:id AND tenant_id=:tenant_id LIMIT 1");
    $stmt->execute(['id'=>$id,'tenant_id'=>$tenantId]);
    return (bool)$stmt->fetchColumn();
}

/** Scope coverage is checked server-side; grantors cannot assign larger scopes. */
function scope_covers(array $scopes, int $tenantId, string $type, int $id): bool
{
    foreach ($scopes as $scope) {
        $ownType=(string)$scope['scope_type'];
        $ownId=(int)$scope['scope_id'];
        if ($ownType==='tenant' && $ownId===$tenantId) return true;
        if ($ownType===$type && $ownId===$id) return true;
        if ($ownType==='site' && in_array($type,['access_point','unit'],true)) {
            $table=$type==='access_point'?'access_points':'units';
            $stmt=db()->prepare("SELECT 1 FROM {$table} WHERE id=:id AND site_id=:site_id AND tenant_id=:tenant_id LIMIT 1");
            $stmt->execute(['id'=>$id,'site_id'=>$ownId,'tenant_id'=>$tenantId]);
            if ($stmt->fetchColumn()) return true;
        }
    }
    return false;
}

function require_tenant_scope(string $type, int $id): array
{
    $actor=require_auth();
    $tenantId=(int)$actor['tenant_id'];
    if (!valid_scope_resource($tenantId,$type,$id)) {
        json_response(['error'=>'Recurso fuera del cliente','error_code'=>'SCOPE_DENIED'],403);
    }
    if (!is_platform_admin($actor) && !scope_covers(scopes_for_user((int)$actor['id'],$tenantId),$tenantId,$type,$id)) {
        json_response(['error'=>'Alcance insuficiente','error_code'=>'SCOPE_DENIED'],403);
    }
    $selected=actor_selected_site_id($actor);
    if ($selected!==null) {
        $siteId=$type==='site'?$id:0;
        if (in_array($type,['unit','access_point'],true)) {
            $table=$type==='unit'?'units':'access_points';
            $site=one_row("SELECT site_id FROM {$table} WHERE id=:id AND tenant_id=:tenant",
                ['id'=>$id,'tenant'=>$tenantId]);
            $siteId=(int)($site['site_id']??0);
        }
        if ($siteId!==0 && $siteId!==$selected) {
            json_response(['error'=>'Recurso fuera de la sede seleccionada','error_code'=>'SITE_CONTEXT_DENIED'],403);
        }
    }
    return $actor;
}

function handle_multitenant_routes(string $method,string $path): void
{
    handle_contextual_dashboard($method,$path);
    if ($method==='GET' && $path==='/me/contexts') {
        $actor=require_auth();
        if (($actor['role']??'')==='resident') json_response(['error'=>'El residente debe usar su portal'],403);
        json_response(['data'=>site_context_payload($actor)]);
    }
    if ($method==='POST' && $path==='/me/context/select') {
        $actor=require_auth();
        if (($actor['role']??'')==='resident') json_response(['error'=>'No autorizado'],403);
        $data=request_json();
        $siteId=filter_var($data['site_id']??null,FILTER_VALIDATE_INT);
        if ($siteId===false || $siteId===null || (int)$siteId<=0) {
            json_response(['error'=>'Debes seleccionar una sede autorizada'],422);
        }
        $tenantId=(int)$actor['tenant_id'];
        $allowed=actor_base_site_ids($actor);
        if (!valid_scope_resource($tenantId,'site',(int)$siteId)
            || ($allowed!==null && !in_array((int)$siteId,$allowed,true))) {
            json_response(['error'=>'Sede fuera de tu alcance','error_code'=>'SCOPE_DENIED'],403);
        }
        $old=actor_selected_site_id($actor);
        $_SESSION['selected_site_context']=['site_id'=>(int)$siteId,'tenant_id'=>$tenantId,'expires_ts'=>time()+1800];
        audit('contexto.sede_cambiada','Sede seleccionada: #'.$siteId);
        platform_audit($actor,$tenantId,'contexto.sede_cambiada','Cambio de sede operativa',
            ['previous_site'=>$old,'selected_site_id'=>(int)$siteId]);
        json_response(['data'=>site_context_payload($actor)]);
    }
    if ($method==='POST' && $path==='/me/context/clear') {
        $actor=require_auth();
        if (($actor['role']??'')==='resident') json_response(['error'=>'No autorizado'],403);
        $old=actor_selected_site_id($actor);
        unset($_SESSION['selected_site_context']);
        audit('contexto.sede_restablecida','Filtro de sede retirado');
        platform_audit($actor,(int)$actor['tenant_id'],'contexto.sede_restablecida',
            'Filtro de sede retirado',['previous_site'=>$old]);
        json_response(['data'=>site_context_payload($actor)]);
    }
    if ($method==='GET' && ($path==='/me/context' || $path==='/me/effective-permissions')) {
        $actor=require_auth();
        $tenantId=(int)$actor['tenant_id'];
        $permissions=permissions_for_role($tenantId,(string)$actor['role']);
        if ($path==='/me/effective-permissions') json_response(['data'=>['permissions'=>$permissions]]);
        $tenant=one_row(
            'SELECT t.id,t.name,t.status,t.parent_tenant_id,t.root_tenant_id,ct.code AS client_type
             FROM tenants t LEFT JOIN client_types ct ON ct.id=t.client_type_id
             WHERE t.id=:id', ['id'=>$tenantId]
        );
        json_response(['data'=>[
            'tenant'=>$tenant,
            'role'=>$actor['role'],
            'user_id'=>(int)$actor['id'],
            'platform_admin'=>is_platform_admin($actor),
            'scopes'=>scopes_for_user((int)$actor['id'],$tenantId),
            'permissions'=>$permissions
        ]]);
    }

    if ($method==='GET' && $path==='/roles/master') {
        require_platform_admin();
        json_response(['data'=>list_rows(
            'SELECT mr.code,mr.name,mr.is_operational,mp.permission_code
             FROM master_roles mr LEFT JOIN master_role_permissions mp ON mp.role_code=mr.code
             ORDER BY mr.code,mp.permission_code'
        )]);
    }

    if ($method==='GET' && $path==='/platform/client-types') {
        require_platform_admin();
        json_response(['data'=>list_rows('SELECT id,code,name,allows_children FROM client_types ORDER BY id')]);
    }

    if ($method==='GET' && preg_match('#^/users/(\d+)/scopes$#',$path,$match)) {
        $actor=require_permission('settings.manage');
        $tenantId=(int)$actor['tenant_id'];
        $target=one_row('SELECT id FROM users WHERE id=:id AND tenant_id=:tenant_id',
            ['id'=>$match[1],'tenant_id'=>$tenantId]);
        if (!$target) json_response(['error'=>'Usuario no encontrado'],404);
        json_response(['data'=>scopes_for_user((int)$target['id'],$tenantId)]);
    }

    if ($method==='PUT' && preg_match('#^/users/(\d+)/scopes$#',$path,$match)) {
        $actor=require_permission('settings.manage');
        $tenantId=(int)$actor['tenant_id'];
        $targetId=(int)$match[1];
        $target=one_row('SELECT id FROM users WHERE id=:id AND tenant_id=:tenant_id',
            ['id'=>$targetId,'tenant_id'=>$tenantId]);
        if (!$target) json_response(['error'=>'Usuario no encontrado'],404);
        if (is_platform_admin_target($targetId)) json_response(['error'=>'Cuenta global protegida'],403);
        $payload=request_json();
        $requested=$payload['scopes']??null;
        if (!is_array($requested) || count($requested)>100) json_response(['error'=>'Lista de alcances inválida'],422);
        $actorScopes=scopes_for_user((int)$actor['id'],$tenantId);
        $new=[];
        foreach ($requested as $scope) {
            if (!is_array($scope)) json_response(['error'=>'Alcance inválido'],422);
            $type=(string)($scope['scope_type']??'');
            $id=filter_var($scope['scope_id']??null,FILTER_VALIDATE_INT);
            if (!$id || !valid_scope_resource($tenantId,$type,(int)$id)) json_response(['error'=>'Recurso fuera del cliente'],403);
            if (!is_platform_admin($actor) && !scope_covers($actorScopes,$tenantId,$type,(int)$id)) {
                json_response(['error'=>'No puedes otorgar un alcance superior al tuyo'],403);
            }
            $new[$type.':'.$id]=[$type,(int)$id];
        }
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_scopes WHERE tenant_id=:tenant AND user_id=:user')
                ->execute(['tenant'=>$tenantId,'user'=>$targetId]);
            $stmt=$pdo->prepare('INSERT INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
                VALUES(:tenant,:user,:type,:id,:actor)');
            foreach ($new as [$type,$id]) $stmt->execute(['tenant'=>$tenantId,'user'=>$targetId,
                'type'=>$type,'id'=>$id,'actor'=>(int)$actor['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        platform_audit($actor,$tenantId,'alcance.actualizado','Alcances del usuario #'.$targetId,
            ['scope_count'=>count($new)]);
        json_response(['ok'=>true,'count'=>count($new)]);
    }

    if ($method==='POST' && preg_match('#^/users/(\d+)/role-change$#',$path,$match)) {
        $actor=require_permission('settings.manage');
        $tenantId=(int)$actor['tenant_id'];
        $targetId=(int)$match[1];
        if (is_platform_admin_target($targetId)) json_response(['error'=>'Cuenta global protegida'],403);
        $target=one_row('SELECT id,role,site_id,is_active FROM users WHERE id=:id AND tenant_id=:tenant',
            ['id'=>$targetId,'tenant'=>$tenantId]);
        if (!$target) json_response(['error'=>'Usuario no encontrado'],404);
        $payload=request_json();
        $role=(string)($payload['role']??'');
        validate_role_assignment($actor,$tenantId,$role);
        $operational=in_array($role,['guard','supervisor','coordinator','tech_operator'],true);
        if ($operational && empty($target['site_id'])) {
            json_response(['error'=>'El usuario requiere una sede asignada'],422);
        }
        start_user_capacity_guard($tenantId,$role,(bool)$target['is_active'],
            (string)$target['role'],(bool)$target['is_active']);
        db()->prepare('UPDATE users SET role=:role WHERE id=:id AND tenant_id=:tenant')
            ->execute(['role'=>$role,'id'=>$targetId,'tenant'=>$tenantId]);
        if ($operational && $role!==$target['role']) {
            db()->prepare('DELETE FROM user_scopes WHERE user_id=:user AND tenant_id=:tenant AND scope_type="tenant"')
                ->execute(['user'=>$targetId,'tenant'=>$tenantId]);
        }
        if ($operational) {
            db()->prepare('INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
                VALUES(:tenant,:user,"site",:site,:actor)')
                ->execute(['tenant'=>$tenantId,'user'=>$targetId,
                    'site'=>$target['site_id'],'actor'=>$actor['id']]);
        }
        finish_plan_capacity_guard();
        platform_audit($actor,$tenantId,'rol.actualizado','Cambio de rol para usuario #'.$targetId,
            ['previous'=>$target['role'],'new'=>$role]);
        json_response(['ok'=>true]);
    }

    // Downward-only, additive restrictions. Only CREAMOX can remove previous
    // restrictions through a separate future audited maintenance operation.
    if ($method==='PUT' && preg_match('#^/tenants/(\d+)/role-restrictions/(admin|supervisor|coordinator|guard|auditor|resident|tech_operator)$#',$path,$match)) {
        $actor=require_permission('settings.manage');
        $tenantId=(int)$match[1];
        if ($tenantId!==(int)$actor['tenant_id']) json_response(['error'=>'Cliente fuera del alcance'],403);
        $role=$match[2];
        $payload=request_json();
        if (!is_array($payload['restrict']??null) || count($payload['restrict'])>200) json_response(['error'=>'Permisos inválidos'],422);
        $stmt=db()->prepare('INSERT IGNORE INTO tenant_role_restrictions
            (tenant_id,role_code,permission_code,restricted_by)
            VALUES(:tenant,:role,:permission,:actor)');
        $ceiling=array_column(list_rows('SELECT permission_code FROM master_role_permissions WHERE role_code=:role',
            ['role'=>$role]),'permission_code');
        foreach (array_unique(array_map('strval',$payload['restrict'])) as $permission) {
            if (!in_array($permission,$ceiling,true)) json_response(['error'=>'Permiso desconocido'],422);
        }
        foreach (array_unique(array_map('strval',$payload['restrict'])) as $permission) {
            $stmt->execute(['tenant'=>$tenantId,'role'=>$role,'permission'=>$permission,'actor'=>$actor['id']]);
        }
        platform_audit($actor,$tenantId,'permisos.restringidos','Restricciones adicionales para '.$role);
        json_response(['ok'=>true]);
    }
}


/** NULL means tenant-wide visibility; [] means no authorized sites. */
function actor_base_site_ids(array $actor): ?array
{
    if (is_platform_admin($actor)) return null;
    $tenantId=(int)$actor['tenant_id'];
    $scopes=scopes_for_user((int)$actor['id'],$tenantId);
    foreach($scopes as $scope) {
        if ($scope['scope_type']==='tenant' && (int)$scope['scope_id']===$tenantId) return null;
    }
    $allowed=[];
    foreach($scopes as $scope) {
        $type=(string)$scope['scope_type'];
        $id=(int)$scope['scope_id'];
        if ($type==='site' && valid_scope_resource($tenantId,'site',$id)) $allowed[$id]=true;
        if ($type==='access_point' || $type==='unit') {
            $table=$type==='access_point'?'access_points':'units';
            $stmt=db()->prepare("SELECT site_id FROM {$table} WHERE id=:id AND tenant_id=:tenant");
            $stmt->execute(['id'=>$id,'tenant'=>$tenantId]);
            $siteId=(int)$stmt->fetchColumn();
            if ($siteId>0) $allowed[$siteId]=true;
        }
    }
    return array_map('intval',array_keys($allowed));
}


/**
 * Selected site is a temporary DISPLAY/OPERATION filter, never a new grant.
 * A preview target cannot inherit CREAMOX's current site selection.
 */
function actor_selected_site_id(array $actor): ?int
{
    $context=$_SESSION['selected_site_context']??null;
    if (!is_array($context)) return null;
    $user=$_SESSION['user']??[];
    if ((int)($user['id']??0)!==(int)$actor['id']
        || (int)($user['tenant_id']??0)!==(int)($actor['home_tenant_id']??$actor['tenant_id'])
        || (int)($context['tenant_id']??$user['tenant_id'])!==(int)$actor['tenant_id']) return null;
    if (time()>=(int)($context['expires_ts']??0)) {
        unset($_SESSION['selected_site_context']);
        return null;
    }
    $siteId=(int)($context['site_id']??0);
    return $siteId>0?$siteId:null;
}

function actor_visible_site_ids(array $actor): ?array
{
    $base=actor_base_site_ids($actor);
    $selected=actor_selected_site_id($actor);
    if ($selected===null) return $base;
    $tenantId=(int)$actor['tenant_id'];
    if (!valid_scope_resource($tenantId,'site',$selected)
        || ($base!==null && !in_array($selected,$base,true))) {
        unset($_SESSION['selected_site_context']);
        return []; // Fail closed on the request that detects revoked scope.
    }
    return [$selected];
}

function site_context_payload(array $actor): array
{
    $tenantId=(int)$actor['tenant_id'];
    $base=actor_base_site_ids($actor);
    $rows=list_rows(
        'SELECT id,name,timezone FROM sites WHERE tenant_id=:tenant ORDER BY name',
        ['tenant'=>$tenantId]
    );
    if ($base!==null) {
        $rows=array_values(array_filter($rows,static fn($row):bool =>
            in_array((int)$row['id'],$base,true)));
    }
    $selected=actor_selected_site_id($actor);
    if ($selected!==null && !in_array($selected,array_map(static fn($r)=>(int)$r['id'],$rows),true)) {
        unset($_SESSION['selected_site_context']);
        $selected=null;
    }
    $tenant=one_row('SELECT t.id,t.name,t.parent_tenant_id,p.name AS parent_name
        FROM tenants t LEFT JOIN tenants p ON p.id=t.parent_tenant_id
        WHERE t.id=:id LIMIT 1',['id'=>$tenantId]);
    return [
        'tenant'=>$tenant,
        'scope'=>$base===null?'tenant':'sites',
        'sites'=>$rows,
        'selected_site_id'=>$selected,
        'expires_at'=>$selected===null?null:date('c',(int)$_SESSION['selected_site_context']['expires_ts']),
    ];
}

/**
 * Resolve the tenant-owned site to avoid revealing records from other sites.
 * Unattributed records are intentionally hidden from limited users.
 */
function scoped_row_site(string $resource,array $row,int $tenantId): int
{
    if (in_array($resource,['sites','access_points','devices','units','invitations','parking','booking_resources','shifts','access_events','organization_nodes'],true)) {
        return (int)($resource==='sites'?($row['id']??0):($row['site_id']??0));
    }
    if ($resource==='residents') {
        $reference='units'; $id=(int)($row['unit_id']??0); $field='site_id';
    } elseif ($resource==='bookings') {
        $reference='booking_resources'; $id=(int)($row['resource_id']??0); $field='site_id';
    } elseif (in_array($resource,['visits','vehicles','packages','incidents'],true)) {
        if (!empty($row['site_id'])) return (int)$row['site_id'];
        $reference='guard_shifts'; $id=(int)($row['shift_id']??0); $field='site_id';
    } else {
        return 0;
    }
    if ($id<=0) return 0;
    static $cache=[];
    $cacheKey=$tenantId.':'.$reference.':'.$id;
    if (!array_key_exists($cacheKey,$cache)) {
        $stmt=db()->prepare("SELECT {$field} FROM {$reference} WHERE id=:id AND tenant_id=:tenant LIMIT 1");
        $stmt->execute(['id'=>$id,'tenant'=>$tenantId]);
        $cache[$cacheKey]=(int)$stmt->fetchColumn();
    }
    return $cache[$cacheKey];
}

function scope_filter_rows(string $resource,array $rows,array $actor): array
{
    if (($actor['role']??'')==='resident') return $rows; // Existing portal-owned filters apply.
    $visible=actor_visible_site_ids($actor);
    if ($visible===null) return $rows;
    $tenantId=(int)$actor['tenant_id'];
    return array_values(array_filter($rows,static function($row)use($resource,$tenantId,$visible):bool {
        if (!is_array($row)) return false;
        return in_array(scoped_row_site($resource,$row,$tenantId),$visible,true);
    }));
}

function scope_filter_bootstrap_payload(array $payload,array $actor): array
{
    if (($actor['role']??'')==='resident') return $payload;
    if (actor_visible_site_ids($actor)===null) return $payload;
    $resources=[
        'sites'=>'sites','access_points'=>'access_points','devices'=>'devices',
        'visits'=>'visits','vehicles'=>'vehicles','packages'=>'packages',
        'booking_resources'=>'booking_resources','bookings'=>'bookings',
        'units'=>'units','residents'=>'residents','parking'=>'parking',
        'invitations'=>'invitations','incidents'=>'incidents'
    ];
    foreach ($resources as $key=>$resource) {
        if (isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
            $payload['data'][$key]=scope_filter_rows($resource,$payload['data'][$key],$actor);
        }
    }
    // No unscoped tenant-wide exports of PII, restrictions or audit records.
    foreach (['restrictions','audit'] as $key) $payload['data'][$key]=[];
    if (isset($payload['data']['active_shift']) && is_array($payload['data']['active_shift'])) {
        $shift=$payload['data']['active_shift'];
        $payload['data']['active_shift']=scope_filter_rows('shifts',[$shift],$actor)[0]??null;
    }
    return $payload;
}


function require_scoped_record(string $resource,int $id): array
{
    $actor=require_auth();
    $tables=['visits'=>'visits','vehicles'=>'vehicles','packages'=>'packages',
        'incidents'=>'incidents','invitations'=>'invitations'];
    if (!isset($tables[$resource]) || $id<=0) json_response(['error'=>'Recurso inválido'],422);
    $table=$tables[$resource];
    $stmt=db()->prepare("SELECT * FROM {$table} WHERE id=:id AND tenant_id=:tenant LIMIT 1");
    $stmt->execute(['id'=>$id,'tenant'=>(int)$actor['tenant_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_response(['error'=>'Registro no encontrado'],404);
    $allowed=actor_visible_site_ids($actor);
    if ($allowed!==null && !in_array(scoped_row_site($resource,$row,(int)$actor['tenant_id']),$allowed,true)) {
        json_response(['error'=>'Registro fuera de tus sedes','error_code'=>'SCOPE_DENIED'],403);
    }
    return $row;
}

function validated_shift_site(int $shiftId,array $actor,?int $requestedSite=null): int
{
    $shift=one_row('SELECT id,site_id FROM guard_shifts WHERE id=:id AND tenant_id=:tenant',
        ['id'=>$shiftId,'tenant'=>(int)$actor['tenant_id']]);
    if (!$shift) json_response(['error'=>'Turno fuera del cliente'],422);
    $siteId=(int)$shift['site_id'];
    if ($requestedSite!==null && $requestedSite!==$siteId) {
        json_response(['error'=>'La sede y el turno no coinciden','error_code'=>'SHIFT_SITE_MISMATCH'],422);
    }
    require_tenant_scope('site',$siteId);
    return $siteId;
}


/** Never expose tenant-global datasets to site-scoped operators. */
function require_tenant_wide_scope(): void
{
    $actor=require_auth();
    if (($actor['role']??'')==='resident' || actor_visible_site_ids($actor)!==null) {
        json_response(['error'=>'Se requiere alcance de cliente completo',
            'error_code'=>'TENANT_WIDE_SCOPE_REQUIRED'],403);
    }
}



/** The attachment's parent record is the authorization boundary, not its file id. */
function require_attachment_parent(string $type,int $recordId,array $actor): array
{
    $types=[
        'shift_start'=>['guard_shifts','shifts'],
        'visit'=>['visits','visits'],
        'visit_exit'=>['visits','visits'],
        'vehicle'=>['vehicles','vehicles'],
        'vehicle_exit'=>['vehicles','vehicles'],
        'package'=>['packages','packages'],
        'package_delivery'=>['packages','packages'],
        'incident'=>['incidents','incidents'],
        'booking'=>['bookings','bookings'],
    ];
    if (!isset($types[$type]) || $recordId<1) {
        json_response(['error'=>'Tipo o identificador de evidencia no permitido',
            'error_code'=>'ATTACHMENT_OWNER_INVALID'],422);
    }
    [$table,$resource]=$types[$type];
    $tenantId=(int)$actor['tenant_id'];
    $row=one_row("SELECT * FROM {$table} WHERE id=:id AND tenant_id=:tenant LIMIT 1",
        ['id'=>$recordId,'tenant'=>$tenantId]);
    if (!$row) json_response(['error'=>'Registro asociado no encontrado'],404);
    $visible=actor_visible_site_ids($actor);
    if ($visible!==null) {
        $site=scoped_row_site($resource,$row,$tenantId);
        if ($site<=0 || !in_array($site,$visible,true)) {
            json_response(['error'=>'Evidencia fuera de tus sedes',
                'error_code'=>'SCOPE_DENIED'],403);
        }
    }
    return $row;
}

/** Normalize legacy offline IDs and verify authorized site before queueing. */
function normalize_offline_scope(array $actor,array $envelope,array $payload): array
{
    $tenantId=(int)$actor['tenant_id'];
    $action=(string)($envelope['action']??'');
    $row=null;
    $resource='';
    if ($action==='visit_exit') {
        $visitId=(int)($payload['visit_id']??0);
        if (!$visitId && !empty($payload['person_id'])) {
            // Historic React clients queued visit.id under person_id.
            $legacy=(int)$payload['person_id'];
            $test=one_row('SELECT id FROM visits WHERE id=:id AND tenant_id=:tenant AND status="inside"',
                ['id'=>$legacy,'tenant'=>$tenantId]);
            if ($test) $visitId=$legacy;
        }
        if ($visitId) {
            $row=require_scoped_record('visits',$visitId);
            $payload['visit_id']=$visitId;
            $payload['person_id']=(int)$row['person_id'];
            $resource='visits';
        }
    } elseif ($action==='vehicle_exit') {
        $vehicleId=(int)($payload['vehicle_id']??0);
        if (!$vehicleId && trim((string)($payload['plate']??''))!=='') {
            $vehicle=one_row('SELECT id FROM vehicles WHERE tenant_id=:tenant AND plate=:plate AND status="inside" ORDER BY entered_at DESC,id DESC LIMIT 1',
                ['tenant'=>$tenantId,'plate'=>strtoupper(trim((string)$payload['plate']))]);
            $vehicleId=(int)($vehicle['id']??0);
        }
        if ($vehicleId) {
            $row=require_scoped_record('vehicles',$vehicleId);
            $payload['vehicle_id']=$vehicleId;
            $payload['plate']=(string)$row['plate'];
            $resource='vehicles';
        }
    } elseif ($action==='visit' && !empty($payload['shift_id'])) {
        $site=validated_shift_site((int)$payload['shift_id'],$actor);
        $resource='shift';
        $row=['site_id'=>$site];
    }
    $derived=$row?($resource==='shift'?(int)$row['site_id']:scoped_row_site($resource,$row,$tenantId)):0;
    $siteId=(int)($payload['site_id']??0);
    if ($derived && $siteId && $derived!==$siteId) {
        json_response(['error'=>'Inconsistencia de sede offline','error_code'=>'SHIFT_SITE_MISMATCH'],422);
    }
    $siteId=$derived?:$siteId;
    if ($siteId) {
        require_tenant_scope('site',$siteId);
        $payload['site_id']=$siteId;
    } elseif (actor_visible_site_ids($actor)!==null) {
        json_response(['error'=>'Evento offline sin sede verificable','error_code'=>'SCOPE_DENIED'],403);
    }
    if (!empty($payload['access_point_id'])) {
        $point=one_row('SELECT site_id FROM access_points WHERE id=:id AND tenant_id=:tenant',
            ['id'=>(int)$payload['access_point_id'],'tenant'=>$tenantId]);
        if (!$point || $siteId!==(int)$point['site_id']) {
            json_response(['error'=>'Punto offline fuera de la sede','error_code'=>'SCOPE_DENIED'],403);
        }
    }
    if (!empty($payload['person_id'])) {
        $person=one_row('SELECT id FROM people WHERE id=:id AND tenant_id=:tenant',
            ['id'=>(int)$payload['person_id'],'tenant'=>$tenantId]);
        if (!$person) json_response(['error'=>'Persona fuera del cliente','error_code'=>'SCOPE_DENIED'],403);
    }
    return $payload;
}


/** Fail closed for site-scoped principals until each legacy route is verified. */
function enforce_legacy_scope_gate(string $method,string $path): void
{
    if ($path==='/health' || str_starts_with($path,'/auth/') && $path!=='/auth/logout') return;
    if (empty($_SESSION['user'])) return; // Portal-only sessions use require_portal_auth().
    $actor=require_auth();
    if (($actor['role']??'')==='resident') {
        if (str_starts_with($path,'/portal/') || in_array($path,['/me','/bootstrap','/me/context','/me/effective-permissions','/auth/logout'],true)) return;
        json_response(['error'=>'Solo está autorizado el portal del residente',
            'error_code'=>'ROLE_SCOPE_DENIED'],403);
    }
    if (actor_visible_site_ids($actor)===null) return;
    if (str_starts_with($path,'/organization/') && in_array($method,['GET','POST','PATCH','DELETE'],true)) return;
    if (in_array($path,['/me/tenants','/me/tenant/select','/me/tenant/clear'],true)) return;
    if (($actor['role']??'')==='tech_operator' &&
        preg_match('#^/platform/tenants/[0-9]+/(?:memberships(?:/[0-9]+)?|membership-options)$#',$path) &&
        in_array($method,['GET','POST','DELETE'],true)) return;
    if (($actor['role']??'')==='tech_operator' &&
        preg_match('#^/platform/tenants/[0-9]+/children$#',$path) &&
        in_array($method,['GET','POST'],true)) return;

    $reads=[
        '/me','/me/context','/me/contexts','/me/effective-permissions','/bootstrap',
        '/dashboard/contextual',
        '/sites','/visits','/vehicles','/packages','/units','/invitations',
        '/incidents','/shifts','/access-points','/devices','/booking-resources',
        '/bookings','/parking','/access-events','/files'
    ];
    if ($method==='GET' && in_array($path,$reads,true)) return;
    if ($method==='POST' && in_array($path,[
        '/auth/logout','/me/context/select','/me/context/clear','/shifts','/visits','/vehicles','/packages','/incidents',
        '/invitations/redeem','/access-events','/offline/events','/files'
    ],true)) return;
    if ($method==='GET' && preg_match('#^/files/[0-9]+/download$#',$path)) return;
    if ($method==='POST' && (
        preg_match('#^/shifts/[0-9]+/close$#',$path)
        || preg_match('#^/(visits|vehicles)/[0-9]+/exit$#',$path)
        || preg_match('#^/packages/[0-9]+/(deliver|reject|return|signature)$#',$path)
        || preg_match('#^/incidents/[0-9]+/(review|close)$#',$path)
    )) return;
    json_response(['error'=>'Ruta pendiente de autorización por sede',
        'error_code'=>'SITE_SCOPE_ROUTE_NOT_ENABLED'],403);
}


/** Context-specific counts run against MySQL, never against truncated React arrays. */
function contextual_metric_count(int $tenantId,?array $sites,string $resource): int
{
    $sources=[
        'people_inside'=>['visits v','v','LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id','v.status="inside"','COALESCE(v.site_id,gs.site_id)'],
        'vehicles_inside'=>['vehicles v','v','LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id','v.status="inside"','COALESCE(v.site_id,gs.site_id)'],
        'packages_pending'=>['packages p','p','LEFT JOIN guard_shifts gs ON gs.id=p.shift_id AND gs.tenant_id=p.tenant_id','p.status="pending"','gs.site_id'],
        'incidents_open'=>['incidents i','i','LEFT JOIN guard_shifts gs ON gs.id=i.shift_id AND gs.tenant_id=i.tenant_id','i.status="open"','gs.site_id'],
        'active_shifts'=>['guard_shifts gs','gs','','gs.ended_at IS NULL','gs.site_id'],
        'units_active'=>['units u','u','','u.status="active"','u.site_id'],
        'visits_today'=>['visits v','v','LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id',
            'v.entered_at>=CURRENT_DATE AND v.entered_at<DATE_ADD(CURRENT_DATE,INTERVAL 1 DAY)',
            'COALESCE(v.site_id,gs.site_id)'],
        'active_access_points'=>['access_points ap','ap','','ap.status="active"','ap.site_id'],
        'residents_active'=>['residents r','r','JOIN units u ON u.id=r.unit_id AND u.tenant_id=r.tenant_id',
            'r.status="active"','u.site_id'],
        'bookings_pending'=>['bookings b','b',
            'JOIN booking_resources br ON br.id=b.resource_id AND br.tenant_id=b.tenant_id',
            'b.status IN ("pending","pre_reservation")','br.site_id'],
        'employee_present'=>['visits v','v',
            'JOIN people p ON p.id=v.person_id AND p.tenant_id=v.tenant_id LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id',
            'v.status="inside" AND p.person_type="empleado"','COALESCE(v.site_id,gs.site_id)'],
        'contractors_inside'=>['visits v','v',
            'JOIN people p ON p.id=v.person_id AND p.tenant_id=v.tenant_id LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id',
            'v.status="inside" AND p.person_type="contratista"','COALESCE(v.site_id,gs.site_id)'],
        'attendance_entries_today'=>['attendance_records a','a','',
            'a.event_type="entrada" AND a.occurred_at>=CURRENT_DATE AND a.occurred_at<DATE_ADD(CURRENT_DATE,INTERVAL 1 DAY)',
            'a.site_id'],
        'attendance_late_today'=>['attendance_records a','a','',
            'a.event_type="entrada" AND a.status="tarde" AND a.occurred_at>=CURRENT_DATE AND a.occurred_at<DATE_ADD(CURRENT_DATE,INTERVAL 1 DAY)',
            'a.site_id']
    ];
    if (!isset($sources[$resource])) throw new InvalidArgumentException('Unknown metric');
    [$table,$alias,$join,$condition,$siteExpr]=$sources[$resource];
    $where="$alias.tenant_id=:tenant AND $condition";
    $params=['tenant'=>$tenantId];
    if ($sites!==null) {
        if ($sites===[]) return 0;
        $placeholders=[];
        foreach($sites as $index=>$siteId) {
            $key='site'.$index;
            $placeholders[]=':'.$key;
            $params[$key]=(int)$siteId;
        }
        $where.=" AND $siteExpr IN (".implode(',',$placeholders).")";
    }
    $stmt=db()->prepare("SELECT COUNT(*) FROM $table $join WHERE $where");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function handle_contextual_dashboard(string $method,string $path): void
{
    if ($method!=='GET' || $path!=='/dashboard/contextual') return;
    $actor=require_auth();
    if (($actor['role']??'')==='resident') json_response(['error'=>'Usa el portal del residente'],403);
    $tenantId=(int)$actor['tenant_id'];
    $tenant=one_row(
        'SELECT t.id,t.name,ct.code AS client_type,ct.name AS client_type_name
           FROM tenants t LEFT JOIN client_types ct ON ct.id=t.client_type_id
          WHERE t.id=:id LIMIT 1',['id'=>$tenantId]);
    if (!$tenant) json_response(['error'=>'Cliente no encontrado'],404);
    $sites=actor_visible_site_ids($actor);
    $metrics=[];
    foreach(['people_inside','vehicles_inside','packages_pending','incidents_open','active_shifts','units_active'] as $key) {
        $metrics[$key]=contextual_metric_count($tenantId,$sites,$key);
    }
    $clientType=(string)($tenant['client_type']??'custom');
    if (in_array((string)$actor['role'],['admin','supervisor','coordinator','auditor','tech_operator'],true)) {
        $metrics['visits_today']=contextual_metric_count($tenantId,$sites,'visits_today');
        $metrics['active_access_points']=contextual_metric_count($tenantId,$sites,'active_access_points');
    }
    if (in_array($clientType,['industrial','corporate'],true)) {
        foreach(['employee_present','contractors_inside'] as $key) {
            $metrics[$key]=contextual_metric_count($tenantId,$sites,$key);
        }
        if (in_array((string)$actor['role'],['admin','supervisor','coordinator','auditor'],true)) {
            foreach(['attendance_entries_today','attendance_late_today'] as $key) {
                $metrics[$key]=contextual_metric_count($tenantId,$sites,$key);
            }
        }
    }
    if (residential_modules_enabled($tenantId)) {
        if (in_array((string)$actor['role'],['admin','supervisor','coordinator','auditor'],true)) {
            $metrics['bookings_pending']=contextual_metric_count($tenantId,$sites,'bookings_pending');
        }
        if (in_array((string)$actor['role'],['admin','auditor'],true)) {
            $metrics['residents_active']=contextual_metric_count($tenantId,$sites,'residents_active');
        }
    }
    if ($clientType==='security_company' && in_array((string)$actor['role'],['tech_operator','admin','superadmin'],true)) {
        $children=one_row(
            'SELECT COUNT(DISTINCT t.id) AS n FROM tenants t
             WHERE t.status="active" AND (
                t.parent_tenant_id=:parent
                OR EXISTS(SELECT 1 FROM tenant_relationships tr
                    WHERE tr.owner_tenant_id=t.id
                      AND tr.operator_tenant_id=:operator
                      AND tr.relationship_type IN ("secondary_of","managed_by")
                      AND tr.status="active"
                      AND (tr.ends_at IS NULL OR tr.ends_at>NOW()))
             )',
            ['parent'=>$tenantId,'operator'=>$tenantId]
        );
        $metrics['managed_clients']=(int)($children['n']??0);
    }
    json_response(['data'=>[
        'tenant_id'=>$tenantId,
        'tenant_name'=>$tenant['name'],
        'client_type'=>$tenant['client_type']??'custom',
        'client_type_name'=>$tenant['client_type_name']??'Personalizado',
        'role'=>(string)$actor['role'],
        'scope'=>$sites===null?'tenant':'sites',
        'authorized_sites'=>$sites,
        'metrics'=>$metrics
    ]]);
}
