<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

final class MessageBusConfiguration
{
    public const MESSAGE_BUS = 'trollbus';
    public const HANDLER_REGISTRY = 'trollbus.handler_registry';
    public const HANDLER_TAG = 'trollbus.handler';
    public const HANDLER_TAG_MESSAGE = 'message';
    public const HANDLER_TAG_TYPE = 'handlerType';
    public const HANDLER_TAG_CLASS = 'handlerClass';
    public const HANDLER_TAG_METHOD = 'handlerMethod';
    public const MIDDLEWARE_TAG = 'trollbus.middleware';

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
