<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Trollbus\MessageBus\DeferredEvent\DeferEventsMiddleware;
use Trollbus\MessageBus\DeferredEvent\DeferredEventsStorage;
use Trollbus\MessageBus\DeferredEvent\HandleDeferredEventsMiddleware;
use Trollbus\MessageBus\EntityHandler\EntityFactoryHandler;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfiguration;

final class DeferredEventPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition(
            id: DeferredEventsStorage::class,
            definition: new Definition(DeferredEventsStorage::class),
        );

        $container->setDefinition(
            id: DeferEventsMiddleware::class,
            definition: new Definition(
                class: DeferEventsMiddleware::class,
                arguments: [
                    new Reference(id: DeferredEventsStorage::class),
                ],
            ),
        )->addTag(name: MessageBusConfiguration::MIDDLEWARE_TAG);

        $container->setDefinition(
            id: HandleDeferredEventsMiddleware::class,
            definition: new Definition(
                class: HandleDeferredEventsMiddleware::class,
                arguments: [
                    new Reference(id: DeferredEventsStorage::class),
                ],
            ),
        );

        foreach ($container->findTaggedServiceIds(MessageBusConfiguration::HANDLER_TAG) as $serviceId => $tag) {
            $definition = $container->getDefinition($serviceId);

            if (self::isEntityHandler($container, $definition)) {
                $decoratorServiceId = $serviceId . '.deferred_event_handler';
                $container->setDefinition(
                    id: $decoratorServiceId,
                    definition: new Definition(
                        class: HandlerWithMiddlewares::class,
                        arguments: [
                            new Reference($decoratorServiceId . '.inner'),
                            [
                                new Reference(id: HandleDeferredEventsMiddleware::class),
                            ],
                        ],
                    ),
                )->setDecoratedService($serviceId);
            }
        }
    }

    private static function isEntityHandler(ContainerBuilder $container, Definition $definition): bool
    {
        if ($definition->isAbstract() || $definition->isSynthetic()) {
            return false;
        }

        $class = $definition->getClass();

        if (null === $class) {
            return false;
        }

        if (is_a($class, HandlerWithMiddlewares::class, true)) {
            /** @psalm-suppress MixedAssignment */
            $innerArg = $definition->getArgument(0);

            if (null !== ($decoratedService = $definition->getDecoratedService())) {
                $inner = $container->getDefinition((string) $decoratedService[0]);
            } elseif ($innerArg instanceof Reference) {
                $inner = $container->getDefinition((string) $innerArg);
            } elseif ($innerArg instanceof Definition) {
                $inner = $innerArg;
            } else {
                throw new \LogicException('Invalid inner handler of HandlerWithMiddlewares. Expects Reference of Definition.');
            }

            return self::isEntityHandler($container, $inner);
        }

        return is_a($class, EntityHandler::class, true)
            || is_a($class, EntityFactoryHandler::class, true);
    }
}
