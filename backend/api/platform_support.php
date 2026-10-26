<?php
declare(strict_types=1);

/**
 * CREAMOX support preview: explicit read-only projection of a REAL user's
 * authorized context. This NEVER replaces $_SESSION['user'] or issues a
 * credential/token for the target user.
 */
const PLATFORM_PREVIEW_TTL_SECONDS = 600;

function platform_preview_terminate(string $reason='stopped'): void
{
    $preview=$_SESSION['platform_preview']??null;
    if (!is_array($preview)) return;
    unset($_SESSION['platform_preview']);
    $status=$reason==='expired'?'expired':($reason==='revoked'?'revoked':'stopped');
    try {
        db()->prepare(
            'UPDATE platform_preview_sessions
             SET status=:status, ended_at=NOW(), end_ip=:ip, end_reason=:reason
             WHERE id=:id AND actor_user_id=:actor AND nonce_hash=:hash AND status="active"'
        )->execute([
            'status'=>$status,'ip'=>client_ip(),'reason'=>substr($reason,0,80),
            'id'=>(int)$preview['id'],'actor'=>(int)$preview['actor_user_id'],
            'hash'=>hash('sha256',(string)$preview['nonce'])
        ]);
    } catch(Throwable $e) {
        error_log('[Asiscontrol] No fue posible finalizar vista de soporte: '.$e->getMessage());
    }
}

function platform_preview_readonly_guard(string $method,string $path): void
{
    if (empty($_SESSION['platform_preview'])) return;
    if ($path==='/auth/logout') {
        platform_preview_terminate('logout');
        return;
    }
    if ($method==='POST' && $path==='/platform/impersonation/stop') return;
    if (time()>=(int)($_SESSION['platform_preview']['expires_ts']??0)) {
        platform_preview_terminate('expired');
        // Explicitly reject the first attempted write after expiration.
        if (!in_array($method,['GET','HEAD','OPTIONS'],true)) {
            json_response(['error'=>'Vista de soporte vencida; operación no ejecutada',
                'error_code'=>'PREVIEW_EXPIRED'],409);
        }
        return;
    }
    if (!in_array($method,['GET','HEAD','OPTIONS'],true)) {
        json_response(['error'=>'Modo Ver como: operación de escritura bloqueada',
            'error_code'=>'PREVIEW_READ_ONLY'],403);
    }
}

function platform_preview_current(array $actor): ?array
{
    $preview=$_SESSION['platform_preview']??null;
    if (!is_array($preview)) return null;
    if ((int)($preview['actor_user_id']??0)!==(int)$actor['id']) {
        platform_preview_terminate('revoked');
        return null;
    }
    if (time()>=(int)($preview['expires_ts']??0)) {
        platform_preview_terminate('expired');
        return null;
    }
    $row=one_row(
        'SELECT ps.id,ps.actor_user_id,ps.target_tenant_id,ps.target_user_id,ps.target_role,
                ps.reason,ps.started_at,ps.expires_at,ps.status,
                u.name AS target_name,u.role AS current_role,u.is_active,
                t.name AS tenant_name,t.status AS tenant_status, ct.code AS client_type,
                (SELECT ts.status FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id ORDER BY ts.id DESC LIMIT 1) AS subscription_status,
                (SELECT ts.ends_on FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id ORDER BY ts.id DESC LIMIT 1) AS subscription_ends_on
         FROM platform_preview_sessions ps
         JOIN users u ON u.id=ps.target_user_id AND u.tenant_id=ps.target_tenant_id
         JOIN tenants t ON t.id=ps.target_tenant_id
         LEFT JOIN client_types ct ON ct.id=t.client_type_id
         WHERE ps.id=:id AND ps.actor_user_id=:actor AND ps.nonce_hash=:nonce
         LIMIT 1',
        ['id'=>(int)$preview['id'],'actor'=>(int)$actor['id'],
            'nonce'=>hash('sha256',(string)$preview['nonce'])]
    );
    if (!$row || $row['status']!=='active'
        || !(int)$row['is_active'] || $row['current_role']!==$row['target_role']
        || $row['tenant_status']!=='active'
        || in_array((string)$row['subscription_status'],['suspended','cancelled','expired','past_due'],true)
        || (!empty($row['subscription_ends_on']) && $row['subscription_ends_on']<date('Y-m-d'))) {
        platform_preview_terminate('revoked');
        return null;
    }
    if (strtotime((string)$row['expires_at'])<=time()) {
        platform_preview_terminate('expired');
        return null;
    }
    return $row;
}

function handle_platform_preview_routes(string $method,string $path): void
{
    if (!str_starts_with($path,'/platform/impersonation/')) return;
    if (!in_array($path,[
        '/platform/impersonation/start',
        '/platform/impersonation/context',
        '/platform/impersonation/stop',
        '/platform/impersonation/audit'
    ],true)) return;
    $actor=require_platform_admin();

    if ($method==='POST' && $path==='/platform/impersonation/start') {
        if (!empty($_SESSION['platform_preview'])) {
            json_response(['error'=>'Sal del modo Ver como antes de iniciar otro',
                'error_code'=>'PREVIEW_ALREADY_ACTIVE'],409);
        }
        $input=request_json();
        $tenantId=filter_var($input['tenant_id']??null,FILTER_VALIDATE_INT);
        $userId=filter_var($input['user_id']??null,FILTER_VALIDATE_INT);
        $reason=trim((string)($input['reason']??''));
        if (!$tenantId || !$userId || mb_strlen($reason)<10 || mb_strlen($reason)>250) {
            json_response(['error'=>'Indica cliente, usuario y motivo de 10 a 250 caracteres'],422);
        }
        $target=one_row(
            'SELECT u.id,u.tenant_id,u.role,u.name,u.is_active,t.status AS tenant_status,
                    (SELECT ts.status FROM tenant_subscriptions ts WHERE ts.tenant_id=t.id ORDER BY ts.id DESC LIMIT 1) AS subscription_status
             FROM users u JOIN tenants t ON t.id=u.tenant_id
             WHERE u.id=:user_id AND u.tenant_id=:tenant_id LIMIT 1',
            ['user_id'=>$userId,'tenant_id'=>$tenantId]
        );
        if (!$target || !(int)$target['is_active'] || $target['tenant_status']!=='active'
            || !in_array((string)$target['subscription_status'],['active','trial'],true)
            || $target['role']==='superadmin'
            || is_platform_admin($target)) {
            json_response(['error'=>'Usuario de referencia no disponible o no autorizado',
                'error_code'=>'PREVIEW_TARGET_DENIED'],403);
        }
        $nonce=bin2hex(random_bytes(32));
        $expires=time()+PLATFORM_PREVIEW_TTL_SECONDS;
        $scopeSnapshot=scopes_for_user((int)$target['id'],(int)$target['tenant_id']);
        $pdo=db();
        $stmt=$pdo->prepare(
            'INSERT INTO platform_preview_sessions
             (actor_user_id,target_tenant_id,target_user_id,target_role,nonce_hash,
              reason,expires_at,start_ip,context_json)
             VALUES(:actor,:tenant,:target,:role,:hash,:reason,FROM_UNIXTIME(:expires),:ip,:context)'
        );
        $stmt->execute([
            'actor'=>$actor['id'],'tenant'=>$tenantId,'target'=>$userId,
            'role'=>$target['role'],'hash'=>hash('sha256',$nonce),'reason'=>$reason,
            'expires'=>$expires,'ip'=>client_ip(),
            'context'=>json_encode(['scope_count'=>count($scopeSnapshot)],JSON_UNESCAPED_UNICODE)
        ]);
        $id=(int)$pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['platform_preview']=[
            'id'=>$id,'nonce'=>$nonce,'actor_user_id'=>(int)$actor['id'],'expires_ts'=>$expires
        ];
        platform_audit($actor,(int)$tenantId,'soporte.preview.iniciado',
            'Modo Ver como iniciado sobre usuario #'.$userId,
            ['preview_id'=>$id,'target_user_id'=>(int)$userId,'role'=>$target['role']]);
        json_response(['active'=>true,'preview_id'=>$id,'expires_at'=>date('c',$expires)],201);
    }

    if ($method==='POST' && $path==='/platform/impersonation/stop') {
        $preview=$_SESSION['platform_preview']??null;
        platform_preview_terminate('stopped');
        if (is_array($preview)) platform_audit($actor,null,'soporte.preview.finalizado',
            'Modo Ver como finalizado',['preview_id'=>(int)$preview['id']]);
        json_response(['active'=>false]);
    }

    if ($method==='GET' && $path==='/platform/impersonation/context') {
        $preview=platform_preview_current($actor);
        if (!$preview) json_response(['active'=>false,'data'=>null]);
        $target=[
            'id'=>(int)$preview['target_user_id'],
            'tenant_id'=>(int)$preview['target_tenant_id'],
            'role'=>(string)$preview['target_role']
        ];
        $sites=actor_visible_site_ids($target);
        $metrics=[];
        foreach(['people_inside','vehicles_inside','packages_pending','incidents_open','active_shifts','units_active'] as $name) {
            $metrics[$name]=contextual_metric_count((int)$target['tenant_id'],$sites,$name);
        }
        db()->prepare('UPDATE platform_preview_sessions SET last_accessed_at=NOW() WHERE id=:id')
            ->execute(['id'=>$preview['id']]);
        json_response(['active'=>true,'data'=>[
            'preview_id'=>(int)$preview['id'],'actor_user_id'=>(int)$actor['id'],
            'tenant_id'=>(int)$target['tenant_id'],'tenant_name'=>$preview['tenant_name'],
            'client_type'=>$preview['client_type']??'custom',
            'target_user_id'=>(int)$target['id'],'target_name'=>$preview['target_name'],
            'role'=>$target['role'],'permissions'=>permissions_for_role((int)$target['tenant_id'],$target['role']),
            'scopes'=>scopes_for_user($target['id'],$target['tenant_id']),
            'scope'=>$sites===null?'tenant':'sites',
            'authorized_sites'=>$sites,'metrics'=>$metrics,
            'started_at'=>$preview['started_at'],'expires_at'=>$preview['expires_at'],
            'reason'=>$preview['reason'],'read_only'=>true
        ]]);
    }

    if ($method==='GET' && $path==='/platform/impersonation/audit') {
        json_response(['data'=>list_rows(
            'SELECT ps.id,ps.actor_user_id,ps.target_tenant_id,ps.target_user_id,
                    ps.target_role,ps.reason,ps.status,ps.started_at,
                    ps.expires_at,ps.last_accessed_at,ps.ended_at,ps.end_reason,
                    TIMESTAMPDIFF(SECOND,ps.started_at,COALESCE(ps.ended_at,NOW())) AS duration_seconds
             FROM platform_preview_sessions ps
             ORDER BY ps.started_at DESC LIMIT 100'
        )]);
    }
}
