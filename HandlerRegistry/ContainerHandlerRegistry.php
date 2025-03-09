<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\HandlerRegistry;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Trollbus\Message\Message;
use Trollbus\MessageBus\Handler;
use Trollbus\MessageBus\HandlerRegistry\BaseHandlerRegistry;

final class ContainerHandlerRegistry extends BaseHandlerRegistry
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     *
     * @param class-string<TMessage> $messageClass
     *
     * @return Handler<TResult, TMessage>|null
     *
     * @throws ContainerExceptionInterface
     */
    #[\Override]
    protected function find(string $messageClass): ?Handler
    {
        try {
            /** @psalm-suppress MixedAssignment */
            $handler = $this->container->get($messageClass);
        } catch (NotFoundExceptionInterface $e) {
            return null;
        }

        if ($handler instanceof Handler) {
            /** @var Handler<TResult, TMessage> */
            return $handler;
        }

        throw new \LogicException(\sprintf('Invalid handler type. Expects instance of %s, actual %s', Handler::class, get_debug_type($handler)));
    }
}
