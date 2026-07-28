<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Trollbus\Message\Message;
use Trollbus\MessageBus\EntityHandler\CriteriaResolver;
use Trollbus\MessageBus\EntityHandler\EntityFactoryHandler;
use Trollbus\MessageBus\EntityHandler\EntityFinder;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\EntityHandler\EntitySaver;
use Trollbus\MessageBus\Handler\CallableHandler;
use Trollbus\MessageBus\MessageContext;
use Trollbus\MessageBus\Middleware\CallableMiddleware;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use Trollbus\MessageBus\Middleware\Pipeline;
use Trollbus\TrollbusBundle\Attribute;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfiguration;

final class AttributePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->processHandlers($container);
        $this->processEntityHandlers($container);
        $this->processMiddlewares($container);
    }

    private function processHandlers(ContainerBuilder $container): void
    {
        foreach (self::iterateServices($container) as $serviceId => $definition) {
            $refClass = self::getDefinitionClass($serviceId, $definition);

            foreach (self::iterateClassPublicMethods($refClass, false) as $refMethod) {
                self::doProcessHandlers(
                    container: $container,
                    refMethod: $refMethod,
                    handlerAttributeClass: Attribute\Handler::class,
                    handlerType: 'callable',
                    createDefinition: static fn(string $handlerId) => new Definition(
                        class: CallableHandler::class,
                        arguments: [
                            '$id' => $handlerId,
                            '$handler' => [new Reference($serviceId), $refMethod->getName()],
                        ],
                    ),
                );
            }
        }
    }

    private function processEntityHandlers(ContainerBuilder $container): void
    {
        if (!$container->getParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_ENABLED)) {
            return;
        }

        /** @var list<class-string> $classes */
        $classes = $container->getParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES);

        foreach ($classes as $class) {
            $refClass = new \ReflectionClass($class);

            foreach (self::iterateClassPublicMethods($refClass, true) as $refMethod) {
                self::doProcessHandlers(
                    container: $container,
                    refMethod: $refMethod,
                    handlerAttributeClass: Attribute\EntityFactoryHandler::class,
                    handlerType: 'entityFactory',
                    createDefinition: static fn(string $handlerId) => new Definition(
                        class: EntityFactoryHandler::class,
                        arguments: [
                            '$id' => $handlerId,
                            '$saver' => new Reference(EntitySaver::class),
                            '$entityClass' => $refMethod->getDeclaringClass()->getName(),
                            '$handlerMethod' => $refMethod->getName(),
                        ],
                    ),
                );
            }

            foreach (self::iterateClassPublicMethods($refClass, false) as $refMethod) {
                self::doProcessHandlers(
                    container: $container,
                    refMethod: $refMethod,
                    handlerAttributeClass: Attribute\EntityHandler::class,
                    handlerType: 'entity',
                    createDefinition: static fn(string $handlerId, Attribute\EntityHandler $attribute) => new Definition(
                        class: EntityHandler::class,
                        arguments: [
                            '$id' => $handlerId,
                            '$finder' => new Reference(EntityFinder::class),
                            '$criteriaResolver' => new Reference(CriteriaResolver::class),
                            '$saver' => new Reference(EntitySaver::class),
                            '$entityClass' => $refMethod->getDeclaringClass()->getName(),
                            '$handlerMethod' => $refMethod->getName(),
                            '$findBy' => $attribute->findBy,
                            '$factoryMethod' => $attribute->factoryMethod,
                        ],
                    ),
                );
            }
        }
    }

    /**
     * @template T of Attribute\BaseHandler
     *
     * @param class-string<T> $handlerAttributeClass
     * @param non-empty-string $handlerType
     * @param callable(non-empty-string, T): Definition $createDefinition
     */
    private static function doProcessHandlers(
        ContainerBuilder $container,
        \ReflectionMethod $refMethod,
        string $handlerAttributeClass,
        string $handlerType,
        callable $createDefinition,
    ): void {
        [$handlerAttribute, $withMiddlewareAttributes] = self::extractHandler($refMethod, $handlerAttributeClass, $container);

        if (null === $handlerAttribute || null === $withMiddlewareAttributes) {
            return;
        }

        $handlerServiceId = $handlerAttribute->serviceId ?? MessageBusConfiguration::nextHandlerId();
        $handlerId = $handlerAttribute->id ?? $handlerServiceId;

        if ($container->has($handlerServiceId)) {
            throw new LogicException(\sprintf(
                'Can not register message handler "%s". Service "%s" already exists.',
                self::stringifyMethod($refMethod),
                $handlerServiceId,
            ));
        }

        $definition = $createDefinition($handlerId, $handlerAttribute);

        if ([] !== $withMiddlewareAttributes) {
            $definition = new Definition(
                class: HandlerWithMiddlewares::class,
                arguments: [
                    '$inner' => $definition,
                    '$middlewares' => array_map(
                        static fn(Attribute\WithMiddleware $a) => new Reference($a->serviceId),
                        $withMiddlewareAttributes,
                    ),
                ],
            );
        }

        foreach ($handlerAttribute->messages ?? [] as $message) {
            $definition->addTag(
                name: MessageBusConfiguration::HANDLER_TAG,
                attributes: [
                    MessageBusConfiguration::HANDLER_TAG_MESSAGE => $message,
                    MessageBusConfiguration::HANDLER_TAG_TYPE => $handlerType,
                    MessageBusConfiguration::HANDLER_TAG_CLASS => $refMethod->getDeclaringClass()->getName(),
                    MessageBusConfiguration::HANDLER_TAG_METHOD => $refMethod->getName(),
                ],
            );
        }

        $container->setDefinition(
            id: $handlerServiceId,
            definition: $definition,
        );
    }

    /**
     * @template T of Attribute\BaseHandler
     *
     * @param class-string<T> $handlerAttributeClass
     *
     * @return array{0: T, 1: list<Attribute\WithMiddleware>}|null
     */
    private static function extractHandler(\ReflectionMethod $refMethod, string $handlerAttributeClass, ContainerBuilder $container): ?array
    {
        $handlerAttribute = ($refMethod->getAttributes($handlerAttributeClass)[0] ?? null)?->newInstance() ?? null;

        // Skip if no Handler-like attribute on method
        if (null === $handlerAttribute) {
            return null;
        }

        // Check signature of handler method
        if ($refMethod->getNumberOfParameters() > 2) {
            throw new LogicException(\sprintf('Too many arguments of handler method "%s".', self::stringifyMethod($refMethod)));
        }

        // Check Message types
        $messageArg = $refMethod->getParameters()[0] ?? null;
        $messageArgType = $messageArg?->getType();

        if (null === $handlerAttribute->messages && null === $messageArgType) {
            throw new LogicException(\sprintf(
                'Cannot determine message type for handler "%s". Please add a type-hint to the handler method 1-st parameter or specify the message type in the #[%s] attribute.',
                self::stringifyMethod($refMethod),
                $handlerAttributeClass,
            ));
        }

        if (null !== $handlerAttribute->messages && null !== $messageArgType) {
            throw new LogicException(\sprintf(
                'Ambiguous message type for handler "%s": declared in both #[%s] attribute and method parameter type-hint. The message type must be declared exactly once.',
                self::stringifyMethod($refMethod),
                $handlerAttributeClass,
            ));
        }

        if ($messageArgType instanceof \ReflectionNamedType) {
            /** @psalm-suppress InaccessibleProperty,PropertyTypeCoercion */
            $handlerAttribute->messages = [$messageArgType->getName()];
        } elseif ($messageArgType instanceof \ReflectionUnionType) {
            /** @psalm-suppress InaccessibleProperty,PropertyTypeCoercion */
            $handlerAttribute->messages = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                $messageArgType->getTypes(),
            );
        } elseif ($messageArgType instanceof \ReflectionIntersectionType) {
            throw new LogicException(\sprintf(
                'Invalid message type in handler "%s": intersection types are not supported.',
                self::stringifyMethod($refMethod),
            ));
        }

        if (null === $handlerAttribute->messages) {
            throw new LogicException(\sprintf('Message type of handler "%s" not specified.', self::stringifyMethod($refMethod)));
        }

        foreach ($handlerAttribute->messages as $message) {
            if (!class_exists($message)) {
                throw new LogicException(\sprintf(
                    'Invalid message type of handler "%s". Class "%s" not exists.',
                    self::stringifyMethod($refMethod),
                    $message,
                ));
            }

            if (!is_subclass_of($message, Message::class, true)) {
                throw new LogicException(\sprintf(
                    'Invalid message type of handler "%s". Class "%s" must be implements "%s".',
                    self::stringifyMethod($refMethod),
                    $message,
                    Message::class,
                ));
            }
        }

        // Check MessageContext type
        $contextArg = $refMethod->getParameters()[1] ?? null;
        $contextArgType = $contextArg?->getType();

        if (!(
            null === $contextArgType
            || $contextArgType instanceof \ReflectionNamedType && MessageContext::class === $contextArgType->getName()
        )) {
            throw new LogicException(\sprintf(
                'Invalid type of second parameter in "%s". Expected "%s".',
                self::stringifyMethod($refMethod),
                MessageContext::class,
            ));
        }

        // Get handler middlewares
        $withMiddlewareAttributes = array_map(
            static fn(\ReflectionAttribute $r) => $r->newInstance(),
            $refMethod->getAttributes(Attribute\WithMiddleware::class, \ReflectionAttribute::IS_INSTANCEOF),
        );

        return [$handlerAttribute, $withMiddlewareAttributes];
    }

    private function processMiddlewares(ContainerBuilder $container): void
    {
        foreach (self::iterateServices($container) as $serviceId => $definition) {
            $refClass = self::getDefinitionClass($serviceId, $definition);

            foreach (self::iterateClassPublicMethods($refClass, false) as $refMethod) {
                $middlewareAttribute = self::extractMiddleware($refMethod);

                if (null === $middlewareAttribute) {
                    continue;
                }

                $middlewareServiceId = $middlewareAttribute->serviceId ?? MessageBusConfiguration::nextMiddlewareId();

                if ($container->has($middlewareServiceId)) {
                    throw new LogicException(\sprintf(
                        'Can not register middleware "%s". Service "%s" already exists.',
                        self::stringifyMethod($refMethod),
                        $middlewareServiceId,
                    ));
                }

                $definition = new Definition(
                    class: CallableMiddleware::class,
                    arguments: [
                        '$callable' => [new Reference($serviceId), $refMethod->getName()],
                    ],
                );

                if ($middlewareAttribute->global) {
                    $definition->addTag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => $middlewareAttribute->priority]);
                }

                $container->setDefinition(
                    id: $middlewareServiceId,
                    definition: $definition,
                );
            }
        }
    }

    private static function extractMiddleware(\ReflectionMethod $refMethod): ?Attribute\Middleware
    {
        $middlewareAttribute = ($refMethod->getAttributes(Attribute\Middleware::class)[0] ?? null)?->newInstance() ?? null;

        if (null === $middlewareAttribute) {
            return null;
        }

        if ($refMethod->getNumberOfParameters() > 2) {
            throw new LogicException(\sprintf('Too many arguments of middleware method "%s".', self::stringifyMethod($refMethod)));
        }

        // Check first argument $pipeline
        $pipelineArgumentType = ($refMethod->getParameters()[0] ?? null)?->getType();

        if (!(
            null === $pipelineArgumentType
            || $pipelineArgumentType instanceof \ReflectionNamedType && Pipeline::class === $pipelineArgumentType->getName()
        )) {
            throw new LogicException(\sprintf(
                'Invalid first argument of middleware method "%s". Expected "%s".',
                self::stringifyMethod($refMethod),
                Pipeline::class,
            ));
        }

        // Check second argument $messageContext
        $contextArgumentType = ($refMethod->getParameters()[1] ?? null)?->getType();

        if (!(
            null === $contextArgumentType
            || $contextArgumentType instanceof \ReflectionNamedType && MessageContext::class === $contextArgumentType->getName()
        )) {
            throw new LogicException(\sprintf(
                'Invalid second argument of middleware method "%s". Expected "%s".',
                self::stringifyMethod($refMethod),
                MessageContext::class,
            ));
        }

        return $middlewareAttribute;
    }

    /**
     * @return iterable<non-empty-string, Definition>
     */
    private static function iterateServices(ContainerBuilder $container): iterable
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            \assert('' !== $serviceId); // Check, that non-empty-string

            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }

            if (!$definition->isAutoconfigured()) {
                continue;
            }

            try {
                self::getDefinitionClass($serviceId, $definition);
            } catch (LogicException) {
                continue;
            }

            yield $serviceId => $definition;
        }
    }

    /**
     * @param non-empty-string $serviceId
     */
    private static function getDefinitionClass(string $serviceId, Definition $definition): \ReflectionClass
    {
        $class = $definition->getClass();

        if (null === $class || false === class_exists($class)) {
            throw new LogicException(\sprintf('Class of service definition "%s" not exists.', $serviceId));
        }

        try {
            return new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new LogicException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return list<\ReflectionMethod>
     */
    private static function iterateClassPublicMethods(\ReflectionClass $refClass, bool $static): array
    {
        $refMethods = [];

        foreach ($refClass->getMethods() as $refMethod) {
            if (!$refMethod->isPublic()) {
                continue;
            }

            if ($refMethod->isStatic() !== $static) {
                continue;
            }

            $refMethods[] = $refMethod;
        }

        return $refMethods;
    }

    /**
     * @return non-empty-string
     */
    private static function stringifyMethod(\ReflectionMethod $method): string
    {
        return \sprintf('%s::%s()', $method->getDeclaringClass()->getName(), $method->getName());
    }
}
