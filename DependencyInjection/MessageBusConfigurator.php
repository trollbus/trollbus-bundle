<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\Message\Message;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\Handler\CallableHandler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MessageBusConfigurator
{
    public const MESSAGE_BUS = 'trollbus';
    public const HANDLER_REGISTRY = 'trollbus.handler_registry';
    public const HANDLER_TAG = 'trollbus.handler';
    public const HANDLER_TAG_MESSAGE = 'message';
    public const HANDLER_TAG_MIDDLEWARES = 'middlewares';
    public const MIDDLEWARE_TAG = 'trollbus.middleware';
    public const DEFAULT_MESSAGE_ID_GENERATOR = 'trollbus.message_id.default_generator';
    public const DEFAULT_TRANSACTION_PROVIDER = 'trollbus.transaction.default_transaction_provider';
    public const DEFAULT_ENTITY_FINDER = 'trollbus.entity_handler.default_entity_finder';
    public const DEFAULT_ENTITY_SAVER = 'trollbus.entity_handler.default_entity_saver';
    public const DEFAULT_CRITERIA_RESOLVER = 'trollbus.entity_handler.default_criteria_resolver';

    private static int $counter = 0;

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
            $this->di
                ->services()
                ->set(self::nextHandlerService(), HandlerWithMiddlewares::class)
                    ->decorate($service)
                    ->args([
                        service('.inner'),
                        array_map(static fn(string $m) => service($m), $middlewares),
                    ]);
        }

        $this->di
            ->services()
            ->get($service)
                ->tag(self::HANDLER_TAG, [self::HANDLER_TAG_MESSAGE => $message]);

        return $this;
    }

    /**
     * @param class-string<Message> $message
     * @param non-empty-string $service
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
        $handlerService = self::nextHandlerService();
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
     * @param non-empty-array<non-empty-string, non-empty-string> $findBy
     * @param list<non-empty-string> $middlewares
     */
    public function entityHandler(
        string $message,
        string $entityClass,
        string $handlerMethod,
        array $findBy,
        ?string $factoryMethod = null,
        string $entityFinder = self::DEFAULT_ENTITY_FINDER,
        string $entitySaver = self::DEFAULT_ENTITY_SAVER,
        string $criteriaResolver = self::DEFAULT_CRITERIA_RESOLVER,
        ?string $handlerId = null,
        array $middlewares = [],
    ): self {
        $handlerService = self::nextHandlerService();
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
     * @param non-empty-string $service
     */
    public function middleware(string $service, int $priority = 0): self
    {
        $this->di
            ->services()
            ->get($service)
                ->tag(self::MIDDLEWARE_TAG, ['priority' => $priority]);

        return $this;
    }

    /**
     * @return non-empty-string
     */
    public static function nextHandlerService(): string
    {
        $serviceId = \sprintf('trollbus.handler.%s', self::$counter);
        ++self::$counter;

        return $serviceId;
    }
}
