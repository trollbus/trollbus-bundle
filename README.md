# TrollbusBundle

Integrate `Trollbus` with [Symfony Framework](https://symfony.com/).

## Installation

```bash
composer require trollbus/trollbus-bundle
```

Add bundle to `config/bundles.php` (if it wasn't done automatically):

```php
return [
    // ... Other bundles

    Trollbus\TrollbusBundle\TrollbusBundle::class => ['all' => true],
];
```

Init default config:

```bash
bin/console debug:config trollbus --format yaml > config/packages/trollbus.yaml
```

Configure:

```yaml
trollbus:
    created_at:
        enabled: true
        clock: null # Optional \Psr\Clock\ClockInterface service id.

    logger:
        enabled: true
        logger: logger # \Psr\Log\LoggerInterface service id. Use symfony logger by default.

    message_id:
        enabled: true
        generator: trollbus.message_id.default_generator

    transaction:
        enabled: false
        transaction_provider: trollbus.transaction.default_transaction_provider

    entity_handler:
        enabled: false
        entity_finder: trollbus.entity_handler.default_entity_finder
        entity_saver: trollbus.entity_handler.default_entity_saver
        criteria_resolver: trollbus.entity_handler.default_criteria_resolver

    doctrine_orm_bridge:
        # Automatic configure transaction and entity_handler, if enabled
        enabled: false
        manager_registry: doctrine
        manager: null
        entity_saver_flush: false # Automatic flush after save entity
        flusher: true # Automatic flush after handle message
```
