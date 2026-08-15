<?php

declare(strict_types=1);

// @php-cs-fixer-ignore final_class

namespace Trollbus\TrollbusBundle\Attribute;

use Trollbus\MessageBus\Middleware\Middleware;

/**
 * @psalm-suppress ClassMustBeFinal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WithMiddleware
{
    /**
     * @param non-empty-string|Middleware $middleware
     */
    public function __construct(
        public readonly string|Middleware $middleware,
    ) {}
}
