<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

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
}
