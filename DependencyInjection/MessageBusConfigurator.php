<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\Message\Message;
use Trollbus\MessageBus\EntityHandler\CriteriaResolver;
use Trollbus\MessageBus\EntityHandler\EntityFactoryHandler;
use Trollbus\MessageBus\EntityHandler\EntityFinder;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\EntityHandler\EntitySaver;
use Trollbus\MessageBus\Handler\CallableHandler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * @deprecated Will be removed in 0.4.0. Use attributes to configure message handlers and middlewares.
 */
final class MessageBusConfigurator
{
    public function __construct(
        private readonly ContainerConfigurator $di,
    ) {}

    public static function create(ContainerConfigurator $di): self
    {
        return new self($di);
    }

    /**
     * @param class-string<Message> $message
     * @param non-empty-string $service
     * @param list<non-empty-string> $middlewares
     */
    public function handler(string $message, string $service, array $middlewares = []): self
    {
        if (\count($middlewares) > 0) {
            $decoratedService = MessageBusConfiguration::nextHandlerId();
            $this->di
                ->services()
                ->set($decoratedService, HandlerWithMiddlewares::class)
                    ->decorate($service)
                    ->args([
                        service('.inner'),
                        array_map(static fn(string $m) => service($m), $middlewares),
                    ]);
            $service = $decoratedService;
        }

        $this->di
            ->services()
            ->get($service)
                ->tag(MessageBusConfiguration::HANDLER_TAG, [MessageBusConfiguration::HANDLER_TAG_MESSAGE => $message]);

        return $this;
    }

    /**
     * @param class-string<Message> $message
     * @param non-empty-string $service
     * @param non-empty-string $method
     * @param non-empty-string|null $handlerId
     * @param list<non-empty-string> $middlewares
     */
    public function callableHandler(
        string $message,
        string $service,
        string $method = '__invoke',
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = MessageBusConfiguration::nextHandlerId();
        $this->di
            ->services()
            ->set($handlerService, CallableHandler::class)
                ->args([
                    $handlerId ?? $handlerService,
                    [service($service), $method],
                ]);

        return $this->handler($message, $handlerService, $middlewares);
    }

    /**
     * @param class-string<Message> $message
     * @param class-string $entityClass
     * @param non-empty-string $handlerMethod
     * @param non-empty-array<non-empty-string, non-empty-string> $findBy
     * @param non-empty-string|null $factoryMethod
     * @param non-empty-string|null $handlerId
     * @param list<non-empty-string> $middlewares
     */
    public function entityHandler(
        string $message,
        string $entityClass,
        string $handlerMethod,
        array $findBy,
        ?string $factoryMethod = null,
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = MessageBusConfiguration::nextHandlerId();
        $this->di
            ->services()
            ->set($handlerService, EntityHandler::class)
                ->args([
                    $handlerId ?? $handlerService,
                    service(EntityFinder::class),
                    service(CriteriaResolver::class),
                    service(EntitySaver::class),
                    $entityClass,
                    $handlerMethod,
                    $findBy,
                    $factoryMethod,
                ]);

        return $this->handler($message, $handlerService, $middlewares);
    }

    /**
     * @param class-string<Message<void>> $message
     * @param class-string $entityClass
     * @param non-empty-string $handlerMethod static handler method, that handle `Trollbus\Message\Message<void>`
     *                                        message and return entity instance
     * @param non-empty-string|null $handlerId
     * @param list<non-empty-string> $middlewares
     */
    public function entityFactoryHandler(
        string $message,
        string $entityClass,
        string $handlerMethod,
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = MessageBusConfiguration::nextHandlerId();
        $this->di
            ->services()
            ->set($handlerService, EntityFactoryHandler::class)
                ->args([
                    $handlerId ?? $handlerService,
                    service(EntitySaver::class),
                    $entityClass,
                    $handlerMethod,
                ]);

        return $this->handler($message, $handlerService, $middlewares);
    }

    /**
     * @param non-empty-string $service
     */
    public function middleware(string $service, int $priority = 0): self
    {
        $this->di
            ->services()
            ->get($service)
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => $priority]);

        return $this;
    }
}
