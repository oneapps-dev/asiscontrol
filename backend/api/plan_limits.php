<?php
declare(strict_types=1);

/** Tenant locks serialize competing capacity changes across web requests. */
function plan_resource_usage(int $tenantId, string $resource): int
{
    $sql=[
        'sites'=>'SELECT COUNT(*) FROM sites WHERE tenant_id=:tenant',
        'access_points'=>'SELECT COUNT(*) FROM access_points WHERE tenant_id=:tenant',
        'units'=>'SELECT COUNT(*) FROM units WHERE tenant_id=:tenant AND status="active"',
        'users'=>'SELECT (SELECT COUNT(*) FROM users WHERE tenant_id=:tenant AND is_active=1) + (SELECT COUNT(*) FROM tenant_user_memberships WHERE tenant_id=:tenant2 AND status="active" AND (expires_at IS NULL OR expires_at>NOW()))',
        'users_administrative'=>'SELECT (SELECT COUNT(*) FROM users WHERE tenant_id=:tenant AND is_active=1 AND role IN ("admin","auditor")) + (SELECT COUNT(*) FROM tenant_user_memberships WHERE tenant_id=:tenant2 AND status="active" AND role_code="auditor" AND (expires_at IS NULL OR expires_at>NOW()))',
        'users_operational'=>'SELECT (SELECT COUNT(*) FROM users WHERE tenant_id=:tenant AND is_active=1 AND role IN ("guard","supervisor","coordinator","tech_operator")) + (SELECT COUNT(*) FROM tenant_user_memberships WHERE tenant_id=:tenant2 AND status="active" AND role_code IN ("guard","supervisor","coordinator") AND (expires_at IS NULL OR expires_at>NOW()))',
        'secondary_tenants'=>'SELECT COUNT(*) FROM tenants WHERE parent_tenant_id=:tenant',
    ];
    if (!isset($sql[$resource])) throw new InvalidArgumentException('Resource capacity code not supported');
    $stmt=db()->prepare($sql[$resource]);
    $params=['tenant'=>$tenantId];
    if (in_array($resource,['users','users_administrative','users_operational'],true)) $params['tenant2']=$tenantId;
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function plan_capacity(int $tenantId,string $resource): ?int
{
    $stmt=db()->prepare(
        'SELECT pl.capacity FROM tenant_subscriptions ts
           LEFT JOIN plan_limits pl ON pl.plan_id=ts.plan_id AND pl.resource_code=:resource
          WHERE ts.tenant_id=:tenant ORDER BY ts.id DESC LIMIT 1'
    );
    $stmt->execute(['tenant'=>$tenantId,'resource'=>$resource]);
    $capacity=$stmt->fetchColumn();
    return $capacity===false || $capacity===null ? null : (int)$capacity;
}

function assert_plan_capacity(int $tenantId,string $resource,int $quantity=1): void
{
    $capacity=plan_capacity($tenantId,$resource);
    if ($capacity===null || $capacity===0) return;
    if ($quantity<1 || plan_resource_usage($tenantId,$resource)+$quantity>$capacity) {
        throw new DomainException('PLAN_LIMIT_REACHED: '.$resource);
    }
}

function lock_plan_tenant(int $tenantId): void
{
    $stmt=db()->prepare('SELECT id FROM tenants WHERE id=:tenant FOR UPDATE');
    $stmt->execute(['tenant'=>$tenantId]);
    if (!$stmt->fetchColumn()) throw new DomainException('TENANT_NOT_FOUND');
}

/** Call before INSERT and finish with finish_plan_capacity_guard() after INSERT. */
function start_plan_capacity_guard(int $tenantId,string $resource): void
{
    $pdo=db();
    if ($pdo->inTransaction()) throw new LogicException('Nested capacity transaction');
    $pdo->beginTransaction();
    try {
        lock_plan_tenant($tenantId);
        assert_plan_capacity($tenantId,$resource);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof DomainException) {
            json_response(['error'=>'Límite contratado alcanzado','error_code'=>'PLAN_LIMIT_REACHED','resource'=>$resource],409);
        }
        throw $exception;
    }
}

function finish_plan_capacity_guard(): void
{
    db()->commit();
}

function handle_plan_routes(string $method,string $path): void
{
    if ($method==='GET' && preg_match('#^/platform/tenants/(\d+)/limits$#',$path,$match)) {
        require_platform_admin();
        $tenantId=(int)$match[1];
        if (!one_row('SELECT id FROM tenants WHERE id=:id',['id'=>$tenantId])) json_response(['error'=>'Cliente no encontrado'],404);
        $limits=[];
        foreach (['sites','access_points','units','users','users_administrative','users_operational','secondary_tenants'] as $resource) {
            $limits[]=['resource'=>$resource,'capacity'=>plan_capacity($tenantId,$resource),
                'usage'=>plan_resource_usage($tenantId,$resource)];
        }
        json_response(['data'=>$limits]);
    }

    if ($method==='PUT' && preg_match('#^/platform/plans/(\d+)/limits$#',$path,$match)) {
        $actor=require_platform_admin();
        $planId=(int)$match[1];
        $payload=request_json();
        if (!is_array($payload['limits']??null)) json_response(['error'=>'Límites inválidos'],422);
        $allowed=['sites','access_points','units','users','users_administrative','users_operational','secondary_tenants'];
        $limits=$payload['limits'];
        if (count($limits)>count($allowed)) json_response(['error'=>'Demasiados límites'],422);
        foreach ($limits as $resource=>$capacity) {
            if (!in_array((string)$resource,$allowed,true) || filter_var($capacity,FILTER_VALIDATE_INT)===false || (int)$capacity<0) {
                json_response(['error'=>'Capacidad inválida para '.$resource],422);
            }
        }
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT id FROM subscription_plans WHERE id=:id FOR UPDATE');
            $stmt->execute(['id'=>$planId]);
            if (!$stmt->fetchColumn()) {
                $pdo->rollBack();
                json_response(['error'=>'Plan no encontrado'],404);
            }
            $write=$pdo->prepare('INSERT INTO plan_limits(plan_id,resource_code,capacity)
                VALUES(:plan,:resource,:capacity) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)');
            foreach ($limits as $resource=>$capacity) {
                $write->execute(['plan'=>$planId,'resource'=>(string)$resource,'capacity'=>(int)$capacity]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        platform_audit($actor,null,'plan.limites_actualizados','Límites del plan #'.$planId,
            ['resources'=>array_keys($limits)]);
        json_response(['ok'=>true]);
    }
}


/** A role consumes exactly one optional user-category capacity. */
function plan_user_category(string $role): ?string
{
    if (in_array($role,['admin','auditor'],true)) return 'users_administrative';
    if (in_array($role,['guard','supervisor','coordinator','tech_operator'],true)) return 'users_operational';
    return null;
}

/** Must execute under the tenant row lock for changes affecting active users. */
function assert_plan_user_transition(
    int $tenantId,string $newRole,bool $newActive,
    ?string $oldRole=null,bool $oldActive=false
): void {
    if (!$newActive) return;
    if (!$oldActive) assert_plan_capacity($tenantId,'users');
    $newCategory=plan_user_category($newRole);
    $oldCategory=$oldActive?plan_user_category($oldRole??''):null;
    if ($newCategory!==null && $newCategory!==$oldCategory) {
        assert_plan_capacity($tenantId,$newCategory);
    }
}

function start_user_capacity_guard(
    int $tenantId,string $role,bool $active,
    ?string $oldRole=null,bool $oldActive=false
): void {
    $pdo=db();
    if ($pdo->inTransaction()) throw new LogicException('Nested user transaction');
    $pdo->beginTransaction();
    try {
        lock_plan_tenant($tenantId);
        assert_plan_user_transition($tenantId,$role,$active,$oldRole,$oldActive);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) {
            json_response(['error'=>'Se alcanzó el cupo de usuarios del plan',
                'error_code'=>'PLAN_LIMIT_REACHED'],409);
        }
        throw $e;
    }
}
