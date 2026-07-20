<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Middleware
{
    /**
     * @param non-empty-string|null $serviceId
     */
    public function __construct(
        public readonly ?string $serviceId = null,
        public readonly bool $global = false,
        public readonly int $priority = 0,
    ) {}
}
