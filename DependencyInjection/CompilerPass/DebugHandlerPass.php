<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Trollbus\Message\Message;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use Trollbus\TrollbusBundle\Command\DebugCommand;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfigurator;

final class DebugHandlerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        /** @var list<non-empty-string> $handlerServiceIds */
        $handlerServiceIds = [];

        /** @var array<class-string<Message>, non-empty-list<non-empty-string>> $messageClassToHandlerServiceIds */
        $messageClassToHandlerServiceIds = [];

        /** @var list<non-empty-string> $globalMiddlewares */
        $globalMiddlewares = $this->getGlobalMiddlewares($container);

        /** @var array<non-empty-string, list<non-empty-string>> $handlerServiceIdMiddlewares */
        $handlerServiceIdMiddlewares = [];

        foreach ($container->findTaggedServiceIds(MessageBusConfigurator::HANDLER_TAG) as $serviceId => $tags) {
            $handlerServiceIds[] = $serviceId;

            /** @var array $tag */
            foreach ($tags as $tag) {
                /** @var class-string<Message> $messageClass */
                $messageClass = (string) ($tag[MessageBusConfigurator::HANDLER_TAG_MESSAGE] ?? '');

                $messageClassToHandlerServiceIds[$messageClass][] = $serviceId;
            }

            $definition = $container->getDefinition($serviceId);
            $handlerServiceIdMiddlewares[$serviceId] = $this->getMiddlewaresFromDefinition($definition, $container);
        }

        $container->setDefinition(
            id: DebugCommand::class,
            definition: (new Definition(
                class: DebugCommand::class,
                arguments: [
                    ServiceLocatorTagPass::register($container, array_combine($handlerServiceIds, array_map(static fn(string $id) => new Reference($id), $handlerServiceIds))),
                    $messageClassToHandlerServiceIds,
                    $globalMiddlewares,
                    $handlerServiceIdMiddlewares,
                ],
            ))->setAutoconfigured(true)
                ->addTag('console.command'),
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function getGlobalMiddlewares(ContainerBuilder $container): array
    {
        $middlewares = [];

        /** @var non-empty-string $serviceId */
        foreach ($container->findTaggedServiceIds(MessageBusConfigurator::MIDDLEWARE_TAG) as $serviceId => $tags) {
            $middlewares[] = $serviceId;
        }

        return array_values(array_unique($middlewares));
    }

    /**
     * @return list<non-empty-string>
     */
    private function getMiddlewaresFromDefinition(Definition $definition, ContainerBuilder $container): array
    {
        if (HandlerWithMiddlewares::class !== $definition->getClass()) {
            return [];
        }

        /** @psalm-suppress MixedAssignment */
        $middlewaresArgument = $definition->getArgument(1);

        if ($middlewaresArgument instanceof TaggedIteratorArgument) {
            /** @var list<non-empty-string> */
            return array_keys($container->findTaggedServiceIds($middlewaresArgument->getTag()));
        }

        /** @var list<non-empty-string> $middlewares */
        $middlewares = [];

        if (\is_array($middlewaresArgument)) {
            /** @psalm-suppress MixedAssignment */
            foreach ($middlewaresArgument as $middleware) {
                if ($middleware instanceof Reference) {
                    /** @var non-empty-string $middlewareService */
                    $middlewareService = (string) $middleware;
                    $middlewares[] = $middlewareService;
                } elseif ($middleware instanceof Definition) {
                    $class = $middleware->getClass();
                    $middlewares[] = null !== $class ? \sprintf('inline_service(%s)', $class) : 'inline_service';
                }
            }
        }

        return $middlewares;
    }
}
