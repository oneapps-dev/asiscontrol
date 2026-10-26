<?php
declare(strict_types=1);

/** Fields containing credentials or authentication material are never persisted. */
function structured_audit_redact(mixed $value, int $depth=0): mixed
{
    if ($depth>8) return '[truncated]';
    if (!is_array($value)) {
        if (is_string($value)) return mb_substr($value,0,1000);
        if (is_scalar($value) || $value===null) return $value;
        return '[unsupported]';
    }
    $out=[];
    foreach(array_slice($value,0,100,true) as $key=>$entry) {
        $label=(string)$key;
        if (preg_match('/(?:password|passwd|passphrase|token|secret|hash|cookie|authorization|session|otp|api_key|private_key|credentials)/i',$label)) {
            $out[$label]='[REDACTED]';
        } else {
            $out[$label]=structured_audit_redact($entry,$depth+1);
        }
    }
    return $out;
}

function structured_audit_json(mixed $data): ?string
{
    if ($data===null) return null;
    return json_encode(structured_audit_redact($data),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:null;
}

function structured_audit_event(
    string $origin, ?array $actor, ?int $tenantId,
    string $action, string $summary, array $metadata=[],
    ?string $resourceType=null, ?int $resourceId=null,
    mixed $previous=null, mixed $newValue=null
): void {
    try {
        if ($tenantId===null && !empty($actor['tenant_id'])) $tenantId=(int)$actor['tenant_id'];
        $rootId=null;
        if ($tenantId!==null) {
            $root=one_row('SELECT root_tenant_id FROM tenants WHERE id=:id',['id'=>$tenantId]);
            $rootId=!empty($root['root_tenant_id'])?(int)$root['root_tenant_id']:$tenantId;
        }
        $contextSite=$actor!==null?actor_selected_site_id($actor):null;
        $preview=$_SESSION['platform_preview']??null;
        $stmt=db()->prepare(
            'INSERT INTO structured_audit_events
             (origin,actor_user_id,root_tenant_id,tenant_id,selected_site_id,
              access_point_id,resource_type,resource_id,action,summary,reason,
              previous_json,new_json,metadata_json,request_ip,user_agent,support_preview_id)
             VALUES(:origin,:actor,:root,:tenant,:site,:access_point,:resource,:resource_id,
               :action,:summary,:reason,:previous,:new_value,:metadata,:ip,:agent,:preview)'
        );
        $stmt->execute([
            'origin'=>$origin==='platform'?'platform':'tenant',
            'actor'=>$actor['id']??null,
            'root'=>$rootId,
            'tenant'=>$tenantId,
            'site'=>$contextSite,
            'access_point'=>$metadata['access_point_id']??null,
            'resource'=>$resourceType===null?null:substr($resourceType,0,70),
            'resource_id'=>$resourceId,
            'action'=>substr($action,0,100),
            'summary'=>mb_substr($summary,0,255),
            'reason'=>isset($metadata['reason'])?mb_substr((string)$metadata['reason'],0,255):null,
            'previous'=>structured_audit_json($previous),
            'new_value'=>structured_audit_json($newValue),
            'metadata'=>structured_audit_json($metadata),
            'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??'cli'),0,45),
            'agent'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT']??'cli'),0,255),
            'preview'=>is_array($preview)?(int)$preview['id']:null
        ]);
    } catch(Throwable $e) {
        // Historical audit_logs remain the compatibility ledger. Do not
        // silently hide structured-audit infrastructure failures in PHP logs.
        error_log('[Asiscontrol] Structured audit unavailable: '.$e->getMessage());
    }
}

function structured_audit_rows(?int $tenantId, array $query): array
{
    $where=[];
    $params=[];
    if ($tenantId!==null) {
        $where[]='ae.tenant_id=:tenant';
        $params['tenant']=$tenantId;
    } elseif (!empty($query['tenant_id'])) {
        $id=filter_var($query['tenant_id'],FILTER_VALIDATE_INT);
        if ($id===false || (int)$id<1) json_response(['error'=>'Cliente inválido'],422);
        $where[]='ae.tenant_id=:tenant';
        $params['tenant']=(int)$id;
    }
    if (!empty($query['action'])) {
        $action=trim((string)$query['action']);
        if (strlen($action)>100 || !preg_match('/^[\pL\pN._:-]+$/u',$action)) json_response(['error'=>'Acción inválida'],422);
        $where[]='ae.action=:action';
        $params['action']=$action;
    }
    if (!empty($query['actor_id'])) {
        $actorId=filter_var($query['actor_id'],FILTER_VALIDATE_INT);
        if ($actorId===false || (int)$actorId<1) json_response(['error'=>'Actor inválido'],422);
        $where[]='ae.actor_user_id=:actor';
        $params['actor']=(int)$actorId;
    }
    foreach(['from','to'] as $endpoint) {
        if (empty($query[$endpoint])) continue;
        $day=(string)$query[$endpoint];
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        if (!$date || $date->format('Y-m-d')!==$day) json_response(['error'=>'Fecha inválida'],422);
        $key=$endpoint==='from'?'from_date':'to_date';
        $params[$key]=$day.($endpoint==='from'?' 00:00:00':' 23:59:59');
        $where[]='ae.created_at '.($endpoint==='from'?'>=':'<=').' :'.$key;
    }
    $sql='SELECT ae.id,ae.origin,ae.actor_user_id,ae.root_tenant_id,ae.tenant_id,ae.selected_site_id,
             ae.access_point_id,ae.resource_type,ae.resource_id,ae.action,ae.summary,ae.reason,
             ae.previous_json,ae.new_json,ae.metadata_json,ae.request_ip,ae.user_agent,
             ae.support_preview_id,ae.created_at
          FROM structured_audit_events ae WHERE '.($where?implode(' AND ',$where):'1=1').
         ' ORDER BY ae.created_at DESC,ae.id DESC LIMIT 200';
    return list_rows($sql,$params);
}

function handle_structured_audit_routes(string $method, string $path): void
{
    if ($method!=='GET') return;
    if ($path==='/audit/structured') {
        require_permission('audit.view');
        require_tenant_wide_scope();
        json_response(['data'=>structured_audit_rows((int)tenant_id(),$_GET)]);
    }
    if ($path==='/platform/audit/structured') {
        require_platform_admin();
        json_response(['data'=>structured_audit_rows(null,$_GET)]);
    }
}
