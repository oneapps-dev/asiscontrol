<?php

declare(strict_types=1);

interface DeviceAdapterInterface
{
    /** @return array<string, mixed> */
    public function capabilities(): array;

    /** @return array<string, mixed> */
    public function health(): array;

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function identify(array $request): array;
}
