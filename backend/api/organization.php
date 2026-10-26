<?php
declare(strict_types=1);

/**
 * Corporate site structure. Every parent and assignment is validated inside
 * its tenant and site; a tenant lock serializes potentially cyclic reparenting.
 */
function organization_actor(): array
{
    $actor=require_permission('settings.manage');
    $type=one_row(
        'SELECT ct.code FROM tenants t JOIN client_types ct ON ct.id=t.client_type_id
         WHERE t.id=:tenant LIMIT 1',['tenant'=>(int)$actor['tenant_id']]
    );
    if (!$type || !in_array($type['code'],['industrial','corporate'],true)) {
        json_response(['error'=>'La estructura empresarial no está habilitada para este tipo de cliente',
            'error_code'=>'CLIENT_TYPE_NOT_SUPPORTED'],403);
    }
    return $actor;
}

function organization_node(int $nodeId,int $tenantId): array
{
    $node=one_row(
        'SELECT * FROM organization_nodes WHERE id=:id AND tenant_id=:tenant LIMIT 1',
        ['id'=>$nodeId,'tenant'=>$tenantId]
    );
    if (!$node) json_response(['error'=>'Dependencia no encontrada'],404);
    return $node;
}

function organization_parent_validate(int $tenantId,int $siteId,?int $parentId,int $nodeId=0): void
{
    if ($parentId===null) return;
    $seen=[];
    $current=$parentId;
    for($depth=0;$current!==null && $depth<64;$depth++) {
        if ($current===$nodeId || isset($seen[$current])) {
            json_response(['error'=>'Jerarquía circular no permitida','error_code'=>'ORG_STRUCTURE_CYCLE'],422);
        }
        $seen[$current]=true;
        $parent=organization_node($current,$tenantId);
        if ((int)$parent['site_id']!==$siteId || $parent['status']!=='active') {
            json_response(['error'=>'La dependencia superior debe pertenecer a la misma sede y estar activa'],422);
        }
        $current=$parent['parent_id']===null?null:(int)$parent['parent_id'];
    }
    if ($current!==null) {
        json_response(['error'=>'La estructura supera 64 niveles','error_code'=>'ORG_STRUCTURE_DEPTH'],422);
    }
}

function organization_payload_validate(array $input): array
{
    $types=['building','floor','area','department','office','section','other'];
    $type=(string)($input['node_type']??'');
    $name=trim((string)($input['name']??''));
    $code=strtoupper(trim((string)($input['code']??'')));
    if (!in_array($type,$types,true) || strlen($name)<2 || strlen($name)>160
        || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/',$code)) {
        json_response(['error'=>'Tipo, nombre o código de dependencia inválido'],422);
    }
    return ['type'=>$type,'name'=>$name,'code'=>$code];
}

function organization_check_deactivation(int $id,int $tenantId): void
{
    $children=one_row('SELECT COUNT(*) AS n FROM organization_nodes WHERE parent_id=:id AND tenant_id=:tenant AND status="active"',
        ['id'=>$id,'tenant'=>$tenantId]);
    $personnel=one_row('SELECT COUNT(*) AS n FROM organization_people WHERE node_id=:id AND tenant_id=:tenant AND status="active"',
        ['id'=>$id,'tenant'=>$tenantId]);
    if ((int)($children['n']??0)>0 || (int)($personnel['n']??0)>0) {
        json_response(['error'=>'Primero desactiva dependencias subordinadas o reasigna el personal',
            'error_code'=>'ORG_STRUCTURE_NOT_EMPTY'],409);
    }
}

function handle_organization_routes(string $method,string $path): void
{
    if (!str_starts_with($path,'/organization/')) return;
    $actor=organization_actor();
    $tenantId=(int)$actor['tenant_id'];

    if ($method==='GET' && $path==='/organization/nodes') {
        $siteId=(int)($_GET['site_id']??0);
        $where='tenant_id=:tenant';
        $params=['tenant'=>$tenantId];
        if ($siteId>0) {
            require_tenant_scope('site',$siteId);
            $where.=' AND site_id=:site';
            $params['site']=$siteId;
        }
        $nodes=list_rows(
            'SELECT id,tenant_id,site_id,parent_id,node_type,code,name,status,sort_order,created_at,updated_at
             FROM organization_nodes WHERE '.$where.'
             ORDER BY site_id,sort_order,name LIMIT 2000',$params
        );
        json_response(['data'=>scope_filter_rows('organization_nodes',$nodes,$actor)]);
    }

    if ($method==='POST' && $path==='/organization/nodes') {
        $input=request_json();
        require_fields($input,['site_id','node_type','name','code']);
        $fields=organization_payload_validate($input);
        $siteId=(int)$input['site_id'];
        require_tenant_scope('site',$siteId);
        $parentId=!empty($input['parent_id'])?(int)$input['parent_id']:null;
        organization_parent_validate($tenantId,$siteId,$parentId);
        $attributes=$input['attributes']??null;
        if ($attributes!==null && !is_array($attributes)) json_response(['error'=>'Atributos inválidos'],422);
        try {
            $stmt=db()->prepare(
                'INSERT INTO organization_nodes
                  (tenant_id,site_id,parent_id,node_type,code,name,sort_order,attributes_json,created_by)
                 VALUES(:tenant,:site,:parent,:type,:code,:name,:sort,:attributes,:actor)'
            );
            $stmt->execute([
                'tenant'=>$tenantId,'site'=>$siteId,'parent'=>$parentId,
                'type'=>$fields['type'],'code'=>$fields['code'],'name'=>$fields['name'],
                'sort'=>(int)($input['sort_order']??0),
                'attributes'=>$attributes===null?null:json_encode($attributes,JSON_UNESCAPED_UNICODE),
                'actor'=>$actor['id']
            ]);
        } catch(PDOException $e) {
            if ($e->getCode()==='23000') json_response(['error'=>'El código ya existe en esta sede'],409);
            throw $e;
        }
        $id=(int)db()->lastInsertId();
        platform_audit($actor,$tenantId,'estructura.creada','Dependencia #'.$id,
            ['site_id'=>$siteId,'parent_id'=>$parentId,'type'=>$fields['type']]);
        json_response(['id'=>$id],201);
    }

    if (preg_match('#^/organization/nodes/([0-9]+)$#',$path,$matches)
        && in_array($method,['PATCH','DELETE'],true)) {
        $id=(int)$matches[1];
        $pdo=db();
        $pdo->beginTransaction();
        try {
            lock_plan_tenant($tenantId);
            $node=organization_node($id,$tenantId);
            require_tenant_scope('site',(int)$node['site_id']);
            if ($method==='DELETE') {
                organization_check_deactivation($id,$tenantId);
                $pdo->prepare('UPDATE organization_nodes SET status="inactive" WHERE id=:id AND tenant_id=:tenant')
                    ->execute(['id'=>$id,'tenant'=>$tenantId]);
            } else {
                $input=request_json();
                if (isset($input['site_id']) && (int)$input['site_id']!==(int)$node['site_id']) {
                    json_response(['error'=>'No se permite mover dependencias entre sedes'],422);
                }
                $fields=organization_payload_validate([
                    'node_type'=>$input['node_type']??$node['node_type'],
                    'name'=>$input['name']??$node['name'],
                    'code'=>$input['code']??$node['code']
                ]);
                $parentId=array_key_exists('parent_id',$input)
                    ? ($input['parent_id']===null || $input['parent_id']===''?null:(int)$input['parent_id'])
                    : ($node['parent_id']===null?null:(int)$node['parent_id']);
                organization_parent_validate($tenantId,(int)$node['site_id'],$parentId,$id);
                $status=(string)($input['status']??$node['status']);
                if (!in_array($status,['active','inactive'],true)) json_response(['error'=>'Estado inválido'],422);
                if ($status==='inactive') organization_check_deactivation($id,$tenantId);
                $pdo->prepare(
                    'UPDATE organization_nodes SET parent_id=:parent,node_type=:type,
                     name=:name,code=:code,status=:status,sort_order=:sort
                     WHERE id=:id AND tenant_id=:tenant'
                )->execute([
                    'parent'=>$parentId,'type'=>$fields['type'],'name'=>$fields['name'],
                    'code'=>$fields['code'],'status'=>$status,
                    'sort'=>(int)($input['sort_order']??$node['sort_order']),
                    'id'=>$id,'tenant'=>$tenantId
                ]);
            }
            $pdo->commit();
        } catch(PDOException $e) {
            if ($pdo->inTransaction())$pdo->rollBack();
            if ($e->getCode()==='23000')json_response(['error'=>'Código duplicado o dependencia referenciada'],409);
            throw $e;
        } catch(Throwable $e) {
            if ($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        platform_audit($actor,$tenantId,$method==='DELETE'?'estructura.desactivada':'estructura.actualizada',
            'Dependencia #'.$id,['site_id'=>(int)$node['site_id']]);
        json_response(['ok'=>true]);
    }

    if ($method==='GET' && $path==='/organization/people') {
        $nodeId=(int)($_GET['node_id']??0);
        if (!$nodeId) json_response(['error'=>'Indica la dependencia'],422);
        $node=organization_node($nodeId,$tenantId);
        require_tenant_scope('site',(int)$node['site_id']);
        json_response(['data'=>list_rows(
            'SELECT op.id,op.node_id,op.person_id,op.relationship,op.status,
                    op.starts_on,op.ends_on,p.full_name,p.person_type
             FROM organization_people op
             JOIN people p ON p.id=op.person_id AND p.tenant_id=op.tenant_id
             WHERE op.node_id=:node AND op.tenant_id=:tenant ORDER BY p.full_name LIMIT 500',
            ['node'=>$nodeId,'tenant'=>$tenantId]
        )]);
    }

    if ($method==='POST' && $path==='/organization/people') {
        $input=request_json();
        require_fields($input,['node_id','person_id','relationship']);
        $nodeId=(int)$input['node_id'];
        $personId=(int)$input['person_id'];
        $node=organization_node($nodeId,$tenantId);
        require_tenant_scope('site',(int)$node['site_id']);
        if ($node['status']!=='active') json_response(['error'=>'Dependencia inactiva'],422);
        $person=one_row(
            'SELECT id,person_type FROM people WHERE id=:id AND tenant_id=:tenant AND record_status="active"',
            ['id'=>$personId,'tenant'=>$tenantId]
        );
        if (!$person || !in_array($person['person_type'],['empleado','contratista'],true)) {
            json_response(['error'=>'Se requiere empleado o contratista del mismo cliente'],422);
        }
        $relationship=(string)$input['relationship'];
        if (!in_array($relationship,['employee','contractor','supervisor','other'],true)) {
            json_response(['error'=>'Tipo de asignación inválido'],422);
        }
        db()->prepare(
            'INSERT INTO organization_people(tenant_id,node_id,person_id,relationship,status,created_by)
             VALUES(:tenant,:node,:person,:relationship,"active",:actor)
             ON DUPLICATE KEY UPDATE relationship=VALUES(relationship),status="active"'
        )->execute([
            'tenant'=>$tenantId,'node'=>$nodeId,'person'=>$personId,
            'relationship'=>$relationship,'actor'=>$actor['id']
        ]);
        platform_audit($actor,$tenantId,'estructura.personal_asignado',
            'Persona #'.$personId.' a dependencia #'.$nodeId);
        json_response(['ok'=>true],201);
    }

    if ($method==='DELETE' && preg_match('#^/organization/people/([0-9]+)$#',$path,$matches)) {
        $assignment=one_row(
            'SELECT op.id,n.site_id FROM organization_people op
             JOIN organization_nodes n ON n.id=op.node_id AND n.tenant_id=op.tenant_id
             WHERE op.id=:id AND op.tenant_id=:tenant',
            ['id'=>(int)$matches[1],'tenant'=>$tenantId]
        );
        if (!$assignment) json_response(['error'=>'Asignación no encontrada'],404);
        require_tenant_scope('site',(int)$assignment['site_id']);
        db()->prepare('UPDATE organization_people SET status="inactive" WHERE id=:id AND tenant_id=:tenant')
            ->execute(['id'=>(int)$matches[1],'tenant'=>$tenantId]);
        platform_audit($actor,$tenantId,'estructura.personal_desasignado',
            'Asignación #'.$matches[1]);
        json_response(['ok'=>true]);
    }
}
