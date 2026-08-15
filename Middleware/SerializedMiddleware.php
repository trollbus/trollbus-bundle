<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\Middleware;

use Trollbus\MessageBus\MessageContext;
use Trollbus\MessageBus\Middleware\Middleware;
use Trollbus\MessageBus\Middleware\Pipeline;

final class SerializedMiddleware implements Middleware
{
    private readonly Middleware $middleware;

    public function __construct(string $serializedMiddleware)
    {
        $middleware = @unserialize($serializedMiddleware);

        if (!$middleware instanceof Middleware) {
            throw new \RuntimeException(\sprintf('Invalid serialized middleware. Expected instance of "%s", "%s" given.', Middleware::class, get_debug_type($middleware)));
        }

        $this->middleware = $middleware;
    }

    public static function serializeMiddleware(Middleware $middleware): string
    {
        return serialize($middleware);
    }

    public function handle(MessageContext $messageContext, Pipeline $pipeline): mixed
    {
        return $this->middleware->handle($messageContext, $pipeline);
    }
}
