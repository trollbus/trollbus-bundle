<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\Attribute;

use Trollbus\Message\Message;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class EntityHandler extends BaseHandler
{
    /**
     * @param non-empty-array<non-empty-string, non-empty-string> $findBy
     * @param non-empty-string|null $id
     * @param class-string<Message>|non-empty-list<class-string<Message>>|null $message
     * @param non-empty-string|null $factoryMethod
     * @param non-empty-string|null $serviceId
     */
    public function __construct(
        public readonly array $findBy,
        ?string $id = null,
        null|string|array $message = null,
        public readonly ?string $factoryMethod = null,
        ?string $serviceId = null,
    ) {
        parent::__construct(
            id: $id,
            message: $message,
            serviceId: $serviceId,
        );
    }
}
