<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

final class MessageBusConfiguration
{
    public const MESSAGE_BUS = 'trollbus';
    public const HANDLER_REGISTRY = 'trollbus.handler_registry';
    public const HANDLER_TAG = 'trollbus.handler';
    public const HANDLER_TAG_MESSAGE = 'message';
    public const MIDDLEWARE_TAG = 'trollbus.middleware';

    private static int $counter = 0;

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
