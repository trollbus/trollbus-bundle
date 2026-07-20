<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\Attribute;

use Trollbus\Message\Message;

abstract class BaseHandler
{
    // Using @readonly annotation instead of native readonly modifier because
    // the property must be mutable (changeable) inside AttributePass.
    //
    // The native readonly modifier blocks modification after initialization,
    // which doesn't suit our case. The @readonly annotation preserves hints
    // for IDEs and static analyzers (PHPStan, Psalm) but does not impose
    // runtime mutation restrictions.
    //
    // Using "clone with" from PHP 8.5 or the `kenny1911/php-clone-with` package
    // doesn't solve the problem because native readonly properties have
    // protected(set) access level by default, and such a property can only be
    // modified inside the class or its descendants. In our case, mutation
    // happens in AttributePass — outside the class.
    //
    // Moreover, even if we specified public(set), it only works starting from
    // PHP 8.4, and we need backward compatibility.

    /**
     * @readonly
     *
     * @var list<class-string<Message>>|null
     */
    public ?array $messages;

    /**
     * @param non-empty-string|null $id
     * @param class-string<Message>|non-empty-list<class-string<Message>>|null $message
     * @param non-empty-string|null $serviceId
     */
    public function __construct(
        public readonly ?string $id = null,
        null|string|array $message = null,
        public readonly ?string $serviceId = null,
    ) {
        if (null === $message) {
            $this->messages = null;
        } else {
            $this->messages = (array) $message;
        }
    }
}
