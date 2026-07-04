<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\MessageId;

use Symfony\Component\Uid\Factory\UuidFactory;
use Trollbus\MessageBus\MessageId\MessageIdGenerator;

final class SymfonyUidMessageIdGenerator implements MessageIdGenerator
{
    public function __construct(
        private readonly UuidFactory $uuidFactory,
    ) {}

    public function generate(): string
    {
        return $this->uuidFactory->create()->toString();
    }
}
