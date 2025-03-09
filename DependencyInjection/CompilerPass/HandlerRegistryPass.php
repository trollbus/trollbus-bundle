<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Trollbus\Message\Event;
use Trollbus\Message\Message;
use Trollbus\MessageBus\Handler\EventHandler;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfigurator;
use Trollbus\TrollbusBundle\HandlerRegistry\ContainerHandlerRegistry;

final class HandlerRegistryPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        /** @var array<class-string<Message>, Reference> $messageToHandlerMap */
        $messageToHandlerMap = [];

        foreach ($this->getMessageToHandlerIdsMap($container) as $message => $ids) {
            if (1 === \count($ids)) {
                $messageToHandlerMap[$message] = new Reference($ids[0]);

                continue;
            }

            if (!is_subclass_of($message, Event::class)) {
                throw new LogicException(\sprintf('Non-event message %s must have 1 handler, got %s', $message, \count($ids)));
            }

            $id = MessageBusConfigurator::nextHandlerService();
            $container->setDefinition($id, new Definition(EventHandler::class, [array_map(static fn($handlerId) => new Reference($handlerId), $ids)]));
            $messageToHandlerMap[$message] = new Reference($id);
        }

        $container->setDefinition(
            MessageBusConfigurator::HANDLER_REGISTRY,
            new Definition(
                ContainerHandlerRegistry::class,
                [
                    ServiceLocatorTagPass::register($container, $messageToHandlerMap),
                ],
            ),
        );
    }

    /**
     * @return array<class-string<Message>, list<non-empty-string>>
     */
    private function getMessageToHandlerIdsMap(ContainerBuilder $container): array
    {
        $handlerTag = MessageBusConfigurator::HANDLER_TAG;

        /** @var array<class-string<Message>, list<non-empty-string>> $messageToHandlerIdsMap */
        $messageToHandlerIdsMap = [];

        /** @var non-empty-string $id */
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->hasTag($handlerTag)) {
                continue;
            }

            /** @var array $tag */
            foreach ($definition->getTag($handlerTag) as $tag) {
                $messageClass = (string) ($tag[MessageBusConfigurator::HANDLER_TAG_MESSAGE]
                    ?? throw new LogicException(\sprintf(
                        'Service "%s" tagged by "%s" requires tag attribute "%s".',
                        $id,
                        $handlerTag,
                        MessageBusConfigurator::HANDLER_TAG_MESSAGE,
                    )));

                $messageClass = self::getFqcn($messageClass);

                if (!is_subclass_of($messageClass, Message::class)) {
                    throw new LogicException(\sprintf(
                        'Service "%s" tagged by "%s" contains invalid message class "%s" in attribute "%s".',
                        $id,
                        $handlerTag,
                        $messageClass,
                        MessageBusConfigurator::HANDLER_TAG_MESSAGE,
                    ));
                }

                $messageToHandlerIdsMap[$messageClass][] = $id;
                $messageToHandlerIdsMap[$messageClass] = array_values(array_unique($messageToHandlerIdsMap[$messageClass] ?? []));
            }
        }

        return $messageToHandlerIdsMap;
    }

    /**
     * @return class-string
     */
    private static function getFqcn(string $class): string
    {
        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            return (new \ReflectionClass($class))->getName();
        } catch (\ReflectionException $e) {
            throw new LogicException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
