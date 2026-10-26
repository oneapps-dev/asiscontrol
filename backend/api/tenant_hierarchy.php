<?php
declare(strict_types=1);

/** Separate client ownership from delegated management by a security company. */
function authorize_parent_management(array $actor,int $parentId): void
{
    if (is_platform_admin($actor)) return;
    if ((string)($actor['role']??'')!=='tech_operator' || (int)$actor['tenant_id']!==$parentId) {
        json_response(['error'=>'Sin autoridad para este cliente','error_code'=>'PARENT_SCOPE_DENIED'],403);
    }
    require_permission('clients.manage');
}

function handle_tenant_hierarchy_routes(string $method,string $path): void
{
    if (!preg_match('#^/platform/tenants/(\d+)/children$#',$path,$match)) return;
    if (!in_array($method,['GET','POST'],true)) return;
    $actor=require_auth();
    $parentId=(int)$match[1];
    authorize_parent_management($actor,$parentId);
    $parent=one_row(
        'SELECT t.id,t.root_tenant_id,t.status,ct.code AS client_type,ct.allows_children
           FROM tenants t JOIN client_types ct ON ct.id=t.client_type_id
          WHERE t.id=:id LIMIT 1', ['id'=>$parentId]
    );
    if (!$parent || $parent['status']!=='active' || !(int)$parent['allows_children'] || $parent['client_type']!=='security_company') {
        json_response(['error'=>'Este cliente no puede crear clientes secundarios'],403);
    }
    if ($method==='GET') {
        json_response(['data'=>list_rows(
            'SELECT t.id,t.name,t.status,t.created_at,ct.code AS client_type,
                CASE WHEN t.parent_tenant_id=:parent_for_type
                     THEN "secondary_of" ELSE "managed_by" END AS relationship_type
             FROM tenants t JOIN client_types ct ON ct.id=t.client_type_id
             WHERE t.parent_tenant_id=:parent OR EXISTS (
                 SELECT 1 FROM tenant_relationships tr
                  WHERE tr.owner_tenant_id=t.id AND tr.operator_tenant_id=:operator
                    AND tr.relationship_type IN ("managed_by","secondary_of")
                    AND tr.status="active"
                    AND (tr.ends_at IS NULL OR tr.ends_at>NOW())
             ) ORDER BY t.name',
            ['parent'=>$parentId,'operator'=>$parentId,'parent_for_type'=>$parentId]
        )]);
    }

    $data=request_json();
    require_fields($data,['name','site_name','admin_name','admin_email','client_type']);
    $childType=(string)$data['client_type'];
    if (!in_array($childType,['residential','industrial','corporate','custom'],true)) {
        json_response(['error'=>'Tipo de cliente secundario no permitido'],422);
    }
    $email=strtolower(trim((string)$data['admin_email']));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Correo de administrador inválido'],422);
    $planCode=trim((string)($data['plan_code']??'starter'));
    $pdo=db();
    $pdo->beginTransaction();
    try {
        lock_plan_tenant($parentId);
        assert_plan_capacity($parentId,'secondary_tenants');
        $stmt=$pdo->prepare('SELECT id FROM client_types WHERE code=:code');
        $stmt->execute(['code'=>$childType]);
        $clientTypeId=(int)$stmt->fetchColumn();
        if (!$clientTypeId) throw new RuntimeException('Tipo de cliente no disponible');
        $plan=one_row('SELECT id FROM subscription_plans WHERE code=:code AND status="active" ORDER BY id LIMIT 1',
            ['code'=>$planCode]);
        if (!$plan) throw new InvalidArgumentException('PLAN_NOT_FOUND');
        $stmt=$pdo->prepare(
            'INSERT INTO tenants(name,legal_name,document_number,status,client_type_id,parent_tenant_id,root_tenant_id)
             VALUES(:name,:legal_name,:document_number,"active",:client_type,:parent,:root)'
        );
        $stmt->execute([
            'name'=>trim((string)$data['name']),
            'legal_name'=>trim((string)($data['legal_name']??''))?:null,
            'document_number'=>trim((string)($data['document_number']??''))?:null,
            'client_type'=>$clientTypeId,'parent'=>$parentId,'root'=>(int)$parent['root_tenant_id']?:$parentId
        ]);
        $childId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO tenant_relationships(owner_tenant_id,operator_tenant_id,relationship_type,created_by)
          VALUES(:owner,:operator,"secondary_of",:actor)');
        $stmt->execute(['owner'=>$childId,'operator'=>$parentId,'actor'=>$actor['id']]);
        $stmt=$pdo->prepare('INSERT INTO sites(tenant_id,name,address,timezone)
          VALUES(:tenant,:name,:address,:timezone)');
        $stmt->execute(['tenant'=>$childId,'name'=>trim((string)$data['site_name']),
          'address'=>trim((string)($data['site_address']??''))?:null,
          'timezone'=>'America/Bogota']);
        $siteId=(int)$pdo->lastInsertId();
        $initialHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $stmt=$pdo->prepare('INSERT INTO users(tenant_id,site_id,name,email,password_hash,role,is_active)
          VALUES(:tenant,:site,:name,:email,:hash,"admin",1)');
        $stmt->execute(['tenant'=>$childId,'site'=>$siteId,'name'=>trim((string)$data['admin_name']),
          'email'=>$email,'hash'=>$initialHash]);
        $adminId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO role_permissions(tenant_id,role,permission_code,granted)
          SELECT :child,role,permission_code,granted FROM role_permissions WHERE tenant_id=1')
          ->execute(['child'=>$childId]);
        $pdo->prepare('INSERT INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
          VALUES(:tenant,:user,"tenant",:scope,:actor)')
          ->execute(['tenant'=>$childId,'user'=>$adminId,'scope'=>$childId,'actor'=>$actor['id']]);
        $pdo->prepare('INSERT INTO tenant_subscriptions(tenant_id,plan_id,status,starts_on,created_by)
          VALUES(:tenant,:plan,"trial",CURRENT_DATE,:actor)')
          ->execute(['tenant'=>$childId,'plan'=>$plan['id'],'actor'=>$actor['id']]);
        $token=bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO platform_account_invites(tenant_id,user_id,token_hash,expires_at,created_by)
          VALUES(:tenant,:user,:hash,DATE_ADD(NOW(),INTERVAL 48 HOUR),:actor)')
          ->execute(['tenant'=>$childId,'user'=>$adminId,'hash'=>hash('sha256',$token),'actor'=>$actor['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof DomainException) json_response(['error'=>'Límite de clientes secundarios alcanzado','error_code'=>'PLAN_LIMIT_REACHED'],409);
        if ($error instanceof InvalidArgumentException && $error->getMessage()==='PLAN_NOT_FOUND')
            json_response(['error'=>'Plan no disponible'],422);
        if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()),'duplicate'))
            json_response(['error'=>'El correo del administrador ya existe'],409);
        throw $error;
    }
    platform_audit($actor,$childId,'cliente.secundario.creado',
        'Cliente #'.$childId.' administrado por empresa #'.$parentId,
        ['parent_tenant_id'=>$parentId,'client_type'=>$childType]);
    json_response(['id'=>$childId,'admin_user_id'=>$adminId,'setup_token'=>$token,
      'setup_expires_at'=>date('c',time()+172800)],201);
}
