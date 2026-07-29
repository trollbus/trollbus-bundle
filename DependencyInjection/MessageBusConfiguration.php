<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessageBusConfiguration
{
    // Service names
    public const MESSAGE_BUS = 'trollbus';
    public const HANDLER_REGISTRY = 'trollbus.handler_registry';

    // Handler tag
    public const HANDLER_TAG = 'trollbus.handler';
    public const HANDLER_TAG_MESSAGE = 'message';
    public const HANDLER_TAG_TYPE = 'handlerType';
    public const HANDLER_TAG_CLASS = 'handlerClass';
    public const HANDLER_TAG_METHOD = 'handlerMethod';

    // Middleware tags
    public const MIDDLEWARE_TAG = 'trollbus.middleware';

    // Params
    public const PARAM_DOCTRINE_BRIDGE_ENABLED = '.trollbus.doctrine_orm_bridge.enabled';
    public const PARAM_ENTITY_HANDLER_ENABLED = '.trollbus.entity_handler.enabled';
    public const PARAM_ENTITY_HANDLER_CLASSES = '.trollbus.entity_handler.classes';

    /**
     * @return non-empty-string
     */
    public static function nextHandlerId(): string
    {
        /** @var int $counter */
        static $counter = 0;
        $serviceId = \sprintf('trollbus.handler.%s', $counter);
        ++$counter;

        return $serviceId;
    }

    /**
     * @return non-empty-string
     */
    public static function nextMiddlewareId(): string
    {
        /** @var int $counter */
        static $counter = 0;
        $serviceId = \sprintf('trollbus.middleware.%s', $counter);
        ++$counter;

        return $serviceId;
    }

    public static function getParamDoctrineBridgeEnabled(ContainerBuilder $container): bool
    {
        if ($container->hasParameter(self::PARAM_DOCTRINE_BRIDGE_ENABLED)) {
            return (bool) $container->getParameter(self::PARAM_DOCTRINE_BRIDGE_ENABLED);
        }

        return false;
    }

    public static function setParamDoctrineBridgeEnabled(ContainerBuilder $container, bool $value): void
    {
        $container->setParameter(self::PARAM_DOCTRINE_BRIDGE_ENABLED, $value);
    }

    public static function getParamEntityHandlerEnabled(ContainerBuilder $container): bool
    {
        if ($container->hasParameter(self::PARAM_ENTITY_HANDLER_ENABLED)) {
            return (bool) $container->getParameter(self::PARAM_ENTITY_HANDLER_ENABLED);
        }

        return false;
    }

    public static function setParamEntityHandlerEnabled(ContainerBuilder $container, bool $value): void
    {
        $container->setParameter(self::PARAM_ENTITY_HANDLER_ENABLED, $value);
    }

    /**
     * @return list<class-string>
     */
    public static function getParamEntityHandlerClasses(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::PARAM_ENTITY_HANDLER_CLASSES)) {
            return [];
        }

        $classes = $container->getParameter(self::PARAM_ENTITY_HANDLER_CLASSES);

        if (!\is_array($classes)) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    $classes,
                    static fn(mixed $class) => \is_string($class) && class_exists($class),
                ),
            ),
        );
    }

    /**
     * @param list<class-string> $classes
     */
    public static function setParamEntityHandlerClasses(ContainerBuilder $container, array $classes): void
    {
        $container->setParameter(self::PARAM_ENTITY_HANDLER_CLASSES, $classes);
    }

    /**
     * @param list<class-string> $classes
     */
    public static function addParamEntityHandlerClasses(ContainerBuilder $container, array $classes): void
    {
        self::setParamEntityHandlerClasses(
            container: $container,
            classes: [...self::getParamEntityHandlerClasses($container), ...$classes],
        );
    }
}
