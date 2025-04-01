<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\Message\Message;
use Trollbus\MessageBus\EntityHandler\EntityFactoryHandler;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\Handler\CallableHandler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MessageBusConfigurator
{
    /**
     * @deprecated Use {@see MessageBusConfiguration::MESSAGE_BUS}, will be remove in `0.3.0`.
     */
    public const MESSAGE_BUS = MessageBusConfiguration::MESSAGE_BUS;

    /**
     * @deprecated Use {@see MessageBusConfiguration::HANDLER_REGISTRY}, will be remove in `0.3.0`.
     */
    public const HANDLER_REGISTRY = MessageBusConfiguration::HANDLER_REGISTRY;

    /**
     * @deprecated Use {@see MessageBusConfiguration::HANDLER_TAG}, will be remove in `0.3.0`.
     */
    public const HANDLER_TAG = MessageBusConfiguration::HANDLER_TAG;

    /**
     * @deprecated Use {@see MessageBusConfiguration::HANDLER_TAG_MESSAGE}, will be remove in `0.3.0`.
     */
    public const HANDLER_TAG_MESSAGE = MessageBusConfiguration::HANDLER_TAG_MESSAGE;

    /**
     * @deprecated Use {@see MessageBusConfiguration::HANDLER_TAG_MIDDLEWARES}, will be remove in `0.3.0`.
     */
    public const HANDLER_TAG_MIDDLEWARES = MessageBusConfiguration::HANDLER_TAG_MIDDLEWARES;

    /**
     * @deprecated Use {@see MessageBusConfiguration::MIDDLEWARE_TAG}, will be remove in `0.3.0`.
     */
    public const MIDDLEWARE_TAG = MessageBusConfiguration::MIDDLEWARE_TAG;

    /**
     * @deprecated Use {@see MessageBusConfiguration::DEFAULT_MESSAGE_ID_GENERATOR}, will be remove in `0.3.0`.
     */
    public const DEFAULT_MESSAGE_ID_GENERATOR = MessageBusConfiguration::DEFAULT_MESSAGE_ID_GENERATOR;

    /**
     * @deprecated Use {@see MessageBusConfiguration::DEFAULT_TRANSACTION_PROVIDER}, will be remove in `0.3.0`.
     */
    public const DEFAULT_TRANSACTION_PROVIDER = MessageBusConfiguration::DEFAULT_TRANSACTION_PROVIDER;

    /**
     * @deprecated Use {@see MessageBusConfiguration::DEFAULT_ENTITY_FINDER}, will be remove in `0.3.0`.
     */
    public const DEFAULT_ENTITY_FINDER = MessageBusConfiguration::DEFAULT_ENTITY_FINDER;

    /**
     * @deprecated Use {@see MessageBusConfiguration::DEFAULT_ENTITY_SAVER}, will be remove in `0.3.0`.
     */
    public const DEFAULT_ENTITY_SAVER = MessageBusConfiguration::DEFAULT_ENTITY_SAVER;

    /**
     * @deprecated Use {@see MessageBusConfiguration::DEFAULT_CRITERIA_RESOLVER}, will be remove in `0.3.0`.
     */
    public const DEFAULT_CRITERIA_RESOLVER = MessageBusConfiguration::DEFAULT_CRITERIA_RESOLVER;

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
            $decoratedService = MessageBusConfiguration::nextHandlerService();
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
        $handlerService = MessageBusConfiguration::nextHandlerService();
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
     * @param non-empty-string $entityFinder
     * @param non-empty-string $entitySaver
     * @param non-empty-string $criteriaResolver
     * @param non-empty-string|null $handlerId
     * @param list<non-empty-string> $middlewares
     */
    public function entityHandler(
        string $message,
        string $entityClass,
        string $handlerMethod,
        array $findBy,
        ?string $factoryMethod = null,
        string $entityFinder = MessageBusConfiguration::DEFAULT_ENTITY_FINDER,
        string $entitySaver = MessageBusConfiguration::DEFAULT_ENTITY_SAVER,
        string $criteriaResolver = MessageBusConfiguration::DEFAULT_CRITERIA_RESOLVER,
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = MessageBusConfiguration::nextHandlerService();
        $this->di
            ->services()
            ->set($handlerService, EntityHandler::class)
                ->args([
                    $handlerId ?? $handlerService,
                    service($entityFinder),
                    service($criteriaResolver),
                    service($entitySaver),
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
     * @param non-empty-string $entitySaver
     * @param non-empty-string|null $handlerId
     * @param list<non-empty-string> $middlewares
     */
    public function entityFactoryHandler(
        string $message,
        string $entityClass,
        string $handlerMethod,
        string $entitySaver = MessageBusConfiguration::DEFAULT_ENTITY_SAVER,
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = MessageBusConfiguration::nextHandlerService();
        $this->di
            ->services()
            ->set($handlerService, EntityFactoryHandler::class)
                ->args([
                    $handlerId ?? $handlerService,
                    service($entitySaver),
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

    /**
     * @return non-empty-string
     *
     * @deprecated Use {@see MessageBusConfiguration::nextHandlerService()}, will be remove in `0.3.0`.
     */
    public static function nextHandlerService(): string
    {
        return MessageBusConfiguration::nextHandlerService();
    }
}
