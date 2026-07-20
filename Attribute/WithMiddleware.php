<?php

declare(strict_types=1);

// @php-cs-fixer-ignore final_class

namespace Trollbus\TrollbusBundle\Attribute;

/**
 * @psalm-suppress ClassMustBeFinal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WithMiddleware
{
    /**
     * @param non-empty-string $serviceId
     */
    public function __construct(
        public readonly string $serviceId,
    ) {}
}
