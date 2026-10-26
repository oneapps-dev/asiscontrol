<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceAdapterInterface.php';

/** Contract used by the React Native/Capacitor bridge for SecuGen Unity 20 BLE. */
final class NativeBluetoothAdapter implements DeviceAdapterInterface
{
    public function capabilities(): array
    {
        return ['mode' => 'native_wrapper', 'vendor' => 'SecuGen', 'product' => 'Unity 20 Bluetooth', 'transports' => ['bluetooth', 'usb'], 'platforms' => ['android', 'ios', 'windows']];
    }

    public function health(): array
    {
        return ['ok' => false, 'reason' => 'La app nativa debe inyectar el estado BLE/USB'];
    }

    public function identify(array $request): array
    {
        return ['ok' => false, 'reason' => 'La app nativa debe ejecutar el SDK y devolver solo un identificador derivado', 'request' => $request];
    }
}
