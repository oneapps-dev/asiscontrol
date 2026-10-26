<?php
declare(strict_types=1);

/** Type-based module eligibility, with an explicit CREAMOX override. */
function residential_modules_enabled(int $tenantId): bool
{
    $row=one_row(
        'SELECT ct.code AS type_code, tf.enabled AS override_enabled
         FROM tenants t LEFT JOIN client_types ct ON ct.id=t.client_type_id
         LEFT JOIN tenant_features tf ON tf.tenant_id=t.id AND tf.feature_code="residential_modules"
         WHERE t.id=:id LIMIT 1',
        ['id'=>$tenantId]
    );
    if (!$row) return false;
    if (in_array((string)($row['type_code']??''),['residential','custom'],true)) return true;
    return $row['override_enabled']!==null && (int)$row['override_enabled']===1;
}

function enforce_residential_api_route(string $method,string $path): void
{
    if (!preg_match('#^/(units|residents)(?:/|$)#',$path)) return;
    $actor=require_auth();
    if (!residential_modules_enabled((int)$actor['tenant_id'])) {
        json_response(['error'=>'Módulo residencial no habilitado para este cliente',
            'error_code'=>'CLIENT_MODULE_DISABLED'],403);
    }
}

function handle_tenant_feature_routes(string $method,string $path): void
{
    if (!preg_match('#^/platform/tenants/([0-9]+)/features$#',$path,$match)) return;
    $actor=require_platform_admin();
    $tenantId=(int)$match[1];
    $tenant=one_row(
        'SELECT t.id,t.name,ct.code AS type_code FROM tenants t
         LEFT JOIN client_types ct ON ct.id=t.client_type_id WHERE t.id=:id',
         ['id'=>$tenantId]
    );
    if (!$tenant) json_response(['error'=>'Cliente no encontrado'],404);
    if ($method==='GET') {
        $override=one_row(
            'SELECT enabled FROM tenant_features WHERE tenant_id=:tenant AND feature_code="residential_modules"',
            ['tenant'=>$tenantId]
        );
        json_response(['data'=>[
            'tenant_id'=>$tenantId,'client_type'=>$tenant['type_code']??'custom',
            'residential_modules'=>residential_modules_enabled($tenantId),
            'residential_modules_override'=>$override===null?null:(bool)$override['enabled'],
            'built_in_residential'=>in_array($tenant['type_code']??'custom',['residential','custom'],true)
        ]]);
    }
    if ($method==='PUT') {
        $input=request_json();
        if (!array_key_exists('residential_modules',$input)
            || !is_bool($input['residential_modules'])) {
            json_response(['error'=>'Indica residential_modules como booleano'],422);
        }
        $enabled=$input['residential_modules']?1:0;
        db()->prepare(
            'INSERT INTO tenant_features(tenant_id,feature_code,enabled)
             VALUES(:tenant,"residential_modules",:enabled)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)'
        )->execute(['tenant'=>$tenantId,'enabled'=>$enabled]);
        platform_audit($actor,$tenantId,'cliente.modulo_residencial',
            'Configuración de módulos residenciales',
            ['residential_modules'=>$enabled===1]);
        json_response(['ok'=>true,'residential_modules'=>residential_modules_enabled($tenantId)]);
    }
}
