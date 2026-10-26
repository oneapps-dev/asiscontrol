<?php
declare(strict_types=1);

/**
 * Cross-tenant assignments are separate from user identities and from CREAMOX
 * support previews. A delegated assignment must remain attached to an active
 * relationship with the source security company on EVERY request.
 */
function member_target_row(int $userId,int $tenantId): ?array
{
    return one_row(
        'SELECT m.id,m.user_id,m.tenant_id,m.role_code,m.site_id,m.origin,
                m.operator_tenant_id,m.status,m.expires_at,
                t.name AS tenant_name,t.status AS tenant_status,t.commercial_status,
                s.tenant_id AS site_tenant_id,
                (SELECT ts.status FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id
                   ORDER BY ts.id DESC LIMIT 1) AS subscription_status,
                (SELECT ts.ends_on FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id
                   ORDER BY ts.id DESC LIMIT 1) AS subscription_ends_on,
                (SELECT COUNT(*) FROM tenant_relationships tr
                   WHERE tr.owner_tenant_id=t.id
                   AND tr.operator_tenant_id=m.operator_tenant_id
                   AND tr.relationship_type IN ("managed_by","secondary_of")
                   AND tr.status="active"
                   AND (tr.ends_at IS NULL OR tr.ends_at>NOW())) AS active_relation
           FROM tenant_user_memberships m
           JOIN tenants t ON t.id=m.tenant_id
           JOIN sites s ON s.id=m.site_id
          WHERE m.user_id=:user AND m.tenant_id=:tenant LIMIT 1',
        ['user'=>$userId,'tenant'=>$tenantId]
    );
}

function member_target_valid(?array $row): bool
{
    if (!$row || $row['status']!=='active' || $row['tenant_status']!=='active'
        || $row['commercial_status']!=='active'
        || (int)$row['site_tenant_id']!==(int)$row['tenant_id']
        || !in_array((string)$row['role_code'],['coordinator','supervisor','guard','auditor'],true)
        || !in_array((string)$row['subscription_status'],['active','trial'],true)
        || ($row['subscription_ends_on']!==null && $row['subscription_ends_on']<date('Y-m-d'))
        || ($row['expires_at']!==null && strtotime((string)$row['expires_at'])<=time())
        || ($row['origin']==='delegated' && !(int)$row['active_relation'])) return false;
    return true;
}

/** Called from require_auth() after the home user and subscription are checked. */
function member_effective_user(array $principal): array
{
    $context=$_SESSION['active_tenant_context']??null;
    if (!is_array($context)) return $principal;
    // Never switch a CREAMOX superadministrator or a resident by this route.
    if (!empty($principal['platform_admin']) || in_array((string)$principal['role'],['superadmin','resident'],true)) {
        unset($_SESSION['active_tenant_context'],$_SESSION['selected_site_context']);
        json_response(['error'=>'No autorizado para cambiar de cliente','error_code'=>'TENANT_CONTEXT_REVOKED'],403);
    }
    $targetId=(int)($context['tenant_id']??0);
    if ($targetId<1 || $targetId===(int)$principal['tenant_id']) {
        unset($_SESSION['active_tenant_context'],$_SESSION['selected_site_context']);
        json_response(['error'=>'Contexto de cliente inválido','error_code'=>'TENANT_CONTEXT_REVOKED'],403);
    }
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if (str_ends_with(rtrim($path,'/'),'/me/tenant/clear')
        || str_ends_with(rtrim($path,'/'),'/auth/logout')) return $principal;
    if (time()>=(int)($context['expires_ts']??0)) {
        // Do NOT silently fall back to the home tenant on a pending write.
        json_response(['error'=>'El contexto seleccionado ha vencido. Restablécelo primero.',
            'error_code'=>'TENANT_CONTEXT_EXPIRED'],409);
    }
    $member=member_target_row((int)$principal['id'],$targetId);
    if (!member_target_valid($member)) {
        json_response(['error'=>'Acceso al cliente revocado. Restablece el contexto.',
            'error_code'=>'TENANT_CONTEXT_REVOKED'],403);
    }
    // An active scope is mandatory, even if the tenant role has broad grants.
    if (!valid_scope_resource($targetId,'site',(int)$member['site_id'])
        || !scope_covers(scopes_for_user((int)$principal['id'],$targetId),
            $targetId,'site',(int)$member['site_id'])) {
        json_response(['error'=>'Alcance del cliente revocado','error_code'=>'TENANT_SCOPE_REVOKED'],403);
    }
    return array_merge($principal,[
        'home_tenant_id'=>(int)$principal['tenant_id'],
        'tenant_id'=>$targetId,
        'tenant_name'=>$member['tenant_name'],
        'role'=>$member['role_code'],
        'site_id'=>(int)$member['site_id'],
        'membership_id'=>(int)$member['id'],
        'platform_admin'=>false
    ]);
}

function member_allowed_targets(array $actor): array
{
    if (($actor['role']??'')==='resident' || is_platform_admin($actor)) return [];
    $userId=(int)$actor['id'];
    $members=list_rows(
        'SELECT m.id,m.tenant_id,m.role_code,m.site_id,m.origin,m.operator_tenant_id,
                m.status,m.expires_at,t.name AS tenant_name,t.status AS tenant_status,
                t.commercial_status,s.tenant_id AS site_tenant_id,
                (SELECT ts.status FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id
                   ORDER BY ts.id DESC LIMIT 1) AS subscription_status,
                (SELECT ts.ends_on FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id
                   ORDER BY ts.id DESC LIMIT 1) AS subscription_ends_on,
                (SELECT COUNT(*) FROM tenant_relationships tr
                  WHERE tr.owner_tenant_id=t.id AND tr.operator_tenant_id=m.operator_tenant_id
                  AND tr.relationship_type IN ("managed_by","secondary_of")
                  AND tr.status="active"
                  AND (tr.ends_at IS NULL OR tr.ends_at>NOW())) AS active_relation
         FROM tenant_user_memberships m JOIN tenants t ON t.id=m.tenant_id
         JOIN sites s ON s.id=m.site_id WHERE m.user_id=:user ORDER BY t.name',
        ['user'=>$userId]
    );
    return array_values(array_map(static fn($r)=>[
        'tenant_id'=>(int)$r['tenant_id'],'tenant_name'=>$r['tenant_name'],
        'role'=>$r['role_code'],'site_id'=>(int)$r['site_id'],
        'membership_id'=>(int)$r['id'],'origin'=>$r['origin']
    ],array_filter($members,static fn($row)=>member_target_valid($row)
        && valid_scope_resource((int)$row['tenant_id'],'site',(int)$row['site_id'])
        && scope_covers(scopes_for_user($userId,(int)$row['tenant_id']),
            (int)$row['tenant_id'],'site',(int)$row['site_id']))));
}

function member_authorize_grant(array $actor,int $targetTenant): array
{
    $target=one_row(
        'SELECT t.id,t.name,t.parent_tenant_id,t.status,t.commercial_status,
                ct.code AS client_type FROM tenants t
         JOIN client_types ct ON ct.id=t.client_type_id
         WHERE t.id=:id',['id'=>$targetTenant]
    );
    if (!$target || $target['status']!=='active' || $target['commercial_status']!=='active') {
        json_response(['error'=>'Cliente destinatario inactivo o inexistente'],404);
    }
    if (is_platform_admin($actor)) {
        if (!empty($_SESSION['active_tenant_context'])) json_response(['error'=>'No permitido en contexto delegado'],403);
        return ['target'=>$target,'origin'=>'platform','operator_tenant_id'=>null];
    }
    if (($actor['role']??'')!=='tech_operator'
        || (int)$actor['tenant_id']!==(int)($_SESSION['user']['tenant_id']??0)) {
        json_response(['error'=>'Solo CREAMOX o el operador autorizado puede asignar usuarios',
            'error_code'=>'MEMBERSHIP_GRANT_DENIED'],403);
    }
    require_permission('clients.manage');
    $source=(int)$actor['tenant_id'];
    $relation=one_row(
        'SELECT tr.id FROM tenant_relationships tr
         JOIN tenants op ON op.id=tr.operator_tenant_id
         JOIN client_types ct ON ct.id=op.client_type_id
         WHERE tr.owner_tenant_id=:target AND tr.operator_tenant_id=:source
         AND tr.relationship_type IN ("managed_by","secondary_of")
         AND tr.status="active" AND (tr.ends_at IS NULL OR tr.ends_at>NOW())
         AND op.status="active" AND ct.code="security_company" LIMIT 1',
        ['target'=>$targetTenant,'source'=>$source]
    );
    if (!$relation) {
        json_response(['error'=>'Cliente no administrado por la empresa de seguridad',
            'error_code'=>'MEMBERSHIP_RELATION_DENIED'],403);
    }
    return ['target'=>$target,'origin'=>'delegated','operator_tenant_id'=>$source];
}

function member_expires_at(mixed $value): ?string
{
    if ($value===null || $value==='') return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)) {
        json_response(['error'=>'La vigencia debe usar AAAA-MM-DD'],422);
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value ||
        $date<new DateTimeImmutable('today') ||
        $date>new DateTimeImmutable('+365 days')) {
        json_response(['error'=>'Vigencia fuera de los próximos 365 días'],422);
    }
    return $value.' 23:59:59';
}

/** Management routes never rely on an actor-provided tenant role or scopes. */
function handle_member_routes(string $method,string $path): void
{
    if ($method==='GET' && $path==='/me/tenants') {
        $actor=require_auth();
        if (($actor['role']??'')==='resident') json_response(['error'=>'No autorizado'],403);
        $principal=$_SESSION['user'];
        $home=[
            'tenant_id'=>(int)$principal['tenant_id'],
            'tenant_name'=>$principal['tenant_name']??'Cliente principal',
            'role'=>$principal['role'],
            'site_id'=>$principal['site_id']??null,
            'home'=>true
        ];
        $current=(int)$actor['tenant_id'];
        json_response(['data'=>['current_tenant_id'=>$current,'home_tenant_id'=>$home['tenant_id'],
            'tenants'=>array_merge([$home],member_allowed_targets($actor))]]);
    }

    if ($method==='POST' && $path==='/me/tenant/clear') {
        $principal=require_auth();
        $old=$_SESSION['active_tenant_context']['tenant_id']??null;
        unset($_SESSION['active_tenant_context'],$_SESSION['selected_site_context']);
        session_regenerate_id(true);
        if ($old!==null) platform_audit($principal,(int)$old,'contexto.cliente_restaurado',
            'Regreso a cliente principal',['primary_tenant_id'=>(int)$principal['tenant_id']]);
        json_response(['user'=>$principal,'current_tenant_id'=>(int)$principal['tenant_id'],
            'csrf_token'=>csrf_token()]);
    }

    if ($method==='POST' && $path==='/me/tenant/select') {
        $principal=require_auth();
        $home=$_SESSION['user'];
        if (($home['role']??'')==='resident' || is_platform_admin($home)
            || !empty($_SESSION['platform_preview'])) {
            json_response(['error'=>'Selección de cliente no autorizada'],403);
        }
        $input=request_json();
        $tenantId=filter_var($input['tenant_id']??null,FILTER_VALIDATE_INT);
        if (!$tenantId || (int)$tenantId<1) json_response(['error'=>'Cliente inválido'],422);
        if ((int)$tenantId===(int)$home['tenant_id']) {
            json_response(['error'=>'Usa restablecer para volver al cliente principal'],422);
        }
        $target=member_target_row((int)$home['id'],(int)$tenantId);
        if (!member_target_valid($target) ||
            !scope_covers(scopes_for_user((int)$home['id'],(int)$tenantId),
                (int)$tenantId,'site',(int)($target['site_id']??0))) {
            json_response(['error'=>'No existe una asignación activa para este cliente',
                'error_code'=>'TENANT_MEMBERSHIP_DENIED'],403);
        }
        $_SESSION['active_tenant_context']=[
            'tenant_id'=>(int)$tenantId,
            'expires_ts'=>time()+1800
        ];
        unset($_SESSION['selected_site_context']);
        session_regenerate_id(true);
        $effective=member_effective_user($_SESSION['user']);
        platform_audit($home,(int)$tenantId,'contexto.cliente_seleccionado',
            'Selección explícita de cliente autorizado',
            ['primary_tenant_id'=>(int)$home['tenant_id'],'membership_id'=>(int)$target['id']]);
        json_response(['user'=>$effective,'current_tenant_id'=>(int)$tenantId,
            'expires_at'=>date('c',(int)$_SESSION['active_tenant_context']['expires_ts']),
            'csrf_token'=>csrf_token()]);
    }

    if (!preg_match('#^/platform/tenants/(\d+)/memberships(?:/(\d+))?$#',$path,$m)) return;
    if (!in_array($method,['GET','POST','DELETE'],true)) return;
    $actor=require_auth();
    $tenantId=(int)$m[1];
    $authority=member_authorize_grant($actor,$tenantId);
    $origin=$authority['origin'];
    $operatorId=$authority['operator_tenant_id'];

    if ($method==='GET' && !isset($m[2])) {
        $filter=$origin==='delegated'?' AND m.operator_tenant_id=:operator':'';
        $params=['tenant'=>$tenantId];
        if ($origin==='delegated') $params['operator']=$operatorId;
        json_response(['data'=>list_rows(
            'SELECT m.id,m.user_id,m.tenant_id,m.role_code,m.site_id,
                    m.origin,m.status,m.expires_at,m.created_at,
                    u.name AS user_name,u.email AS user_email,s.name AS site_name
             FROM tenant_user_memberships m
             JOIN users u ON u.id=m.user_id
             JOIN sites s ON s.id=m.site_id
             WHERE m.tenant_id=:tenant'.$filter.'
             ORDER BY m.created_at DESC LIMIT 300',$params
        )]);
    }

    if ($method==='POST' && !isset($m[2])) {
        $input=request_json();
        $userId=filter_var($input['user_id']??null,FILTER_VALIDATE_INT);
        $siteId=filter_var($input['site_id']??null,FILTER_VALIDATE_INT);
        $role=(string)($input['role']??'');
        if (!$userId || !$siteId || !in_array($role,
            $origin==='platform'
                ? ['coordinator','supervisor','guard','auditor']
                : ['coordinator','supervisor','guard'],true)) {
            json_response(['error'=>'Usuario, sede o rol no autorizado'],422);
        }
        $user=one_row('SELECT id,tenant_id,role,is_active FROM users WHERE id=:id LIMIT 1',
            ['id'=>$userId]);
        if (!$user || !(int)$user['is_active'] || is_platform_admin_target((int)$userId)
            || $user['role']==='resident' || $user['role']==='superadmin'
            || (int)$user['tenant_id']===$tenantId ||
            ($origin==='delegated' && (int)$user['tenant_id']!==$operatorId)) {
            json_response(['error'=>'Usuario principal no habilitado para la delegación',
                'error_code'=>'MEMBERSHIP_USER_DENIED'],403);
        }
        if (!valid_scope_resource($tenantId,'site',(int)$siteId)) {
            json_response(['error'=>'La sede debe pertenecer al cliente destinatario',
                'error_code'=>'SCOPE_DENIED'],403);
        }
        $expires=member_expires_at($input['expires_on']??null);
        $pdo=db();
        $pdo->beginTransaction();
        try {
            lock_plan_tenant($tenantId);
            $existing=member_target_row((int)$userId,$tenantId);
            // Different operators may not silently seize an existing assignment.
            if ($existing && $origin==='delegated'
                && ($existing['origin']!=='delegated'
                    || (int)$existing['operator_tenant_id']!==$operatorId)) {
                json_response(['error'=>'Asignación gestionada por otro proveedor',
                    'error_code'=>'MEMBERSHIP_OWNERSHIP_DENIED'],403);
            }
            $previousActive=$existing && $existing['status']==='active'
                && ($existing['expires_at']===null || strtotime((string)$existing['expires_at'])>time());
            assert_plan_user_transition($tenantId,$role,true,
                $existing['role_code']??null,(bool)$previousActive);
            $stmt=$pdo->prepare(
                'INSERT INTO tenant_user_memberships
                  (user_id,tenant_id,role_code,site_id,origin,operator_tenant_id,status,expires_at,created_by)
                 VALUES(:user,:tenant,:role,:site,:origin,:operator,"active",:expires,:actor)
                 ON DUPLICATE KEY UPDATE role_code=VALUES(role_code),site_id=VALUES(site_id),
                  status="active",expires_at=VALUES(expires_at),origin=VALUES(origin),
                  operator_tenant_id=VALUES(operator_tenant_id),
                  updated_by=VALUES(created_by),revoked_at=NULL'
            );
            $stmt->execute([
                'user'=>$userId,'tenant'=>$tenantId,'role'=>$role,'site'=>$siteId,
                'origin'=>$origin,'operator'=>$operatorId,'expires'=>$expires,'actor'=>$actor['id']
            ]);
            $pdo->prepare(
                'DELETE FROM user_scopes WHERE user_id=:user AND tenant_id=:tenant'
            )->execute(['user'=>$userId,'tenant'=>$tenantId]);
            $pdo->prepare(
                'INSERT INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
                 VALUES(:tenant,:user,"site",:site,:actor)'
            )->execute([
                'tenant'=>$tenantId,'user'=>$userId,'site'=>$siteId,'actor'=>$actor['id']
            ]);
            $pdo->commit();
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof DomainException) json_response([
                'error'=>'Se alcanzó el cupo del plan para este rol',
                'error_code'=>'PLAN_LIMIT_REACHED'
            ],409);
            throw $e;
        }
        platform_audit($actor,$tenantId,'membresia.creada',
            'Asignación autorizada de usuario a cliente',
            ['user_id'=>(int)$userId,'role'=>$role,'site_id'=>(int)$siteId,
             'origin'=>$origin,'operator_tenant_id'=>$operatorId]);
        json_response(['ok'=>true,'tenant_id'=>$tenantId],201);
    }

    if ($method==='DELETE' && isset($m[2])) {
        $id=(int)$m[2];
        $pdo=db();
        $pdo->beginTransaction();
        try {
            lock_plan_tenant($tenantId);
            $member=one_row(
                'SELECT id,user_id,operator_tenant_id,origin,status FROM tenant_user_memberships
                 WHERE id=:id AND tenant_id=:tenant FOR UPDATE',
                ['id'=>$id,'tenant'=>$tenantId]
            );
            if (!$member || ($origin==='delegated' && ($member['origin']!=='delegated'
                || (int)$member['operator_tenant_id']!==$operatorId))) {
                json_response(['error'=>'Asignación fuera de la autoridad'],403);
            }
            $pdo->prepare('UPDATE tenant_user_memberships
                 SET status="revoked",revoked_at=NOW(),updated_by=:actor
                 WHERE id=:id AND tenant_id=:tenant')
                ->execute(['actor'=>$actor['id'],'id'=>$id,'tenant'=>$tenantId]);
            $pdo->prepare('DELETE FROM user_scopes WHERE tenant_id=:tenant AND user_id=:user')
                ->execute(['tenant'=>$tenantId,'user'=>$member['user_id']]);
            $pdo->commit();
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        platform_audit($actor,$tenantId,'membresia.revocada',
            'Revocación de acceso del usuario a otro cliente',
            ['membership_id'=>$id,'user_id'=>(int)$member['user_id']]);
        json_response(['ok'=>true]);
    }
}
