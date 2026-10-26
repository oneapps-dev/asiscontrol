<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceAdapterInterface.php';

/**
 * Adapter boundary for the signed SecuGen WebAPI local agent.
 * The agent URL is environment-only; no hardware credential is persisted here.
 */
final class SecuGenWebApiAdapter implements DeviceAdapterInterface
{
    public function __construct(private readonly string $baseUrl = '')
    {
    }

    public function capabilities(): array
    {
        return ['mode' => 'local_agent', 'vendor' => 'SecuGen', 'product' => 'WebAPI', 'transports' => ['usb'], 'platforms' => ['windows']];
    }

    public function health(): array
    {
        if ($this->baseUrl === '') return ['ok' => false, 'reason' => 'SECUGEN_WEBAPI_URL no configurada'];
        return $this->request('/health', 'GET');
    }

    public function identify(array $request): array
    {
        if ($this->baseUrl === '') return ['ok' => false, 'reason' => 'SECUGEN_WEBAPI_URL no configurada'];
        return $this->request('/identify', 'POST', $request);
    }

    private function request(string $path, string $method, array $payload = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5, 'header' => "Content-Type: application/json\r\n", 'content' => $method === 'POST' ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]]);
        $raw = @file_get_contents($url, false, $context);
        $result = json_decode((string) $raw, true);
        return is_array($result) ? $result : ['ok' => false, 'reason' => 'Agente SecuGen sin respuesta válida'];
    }
}
