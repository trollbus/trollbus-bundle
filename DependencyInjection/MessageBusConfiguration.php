<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection;

final class MessageBusConfiguration
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
