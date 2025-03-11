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

## Usage

Configure, using `MessageBusConfigurator` (Supports only PHP format):

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfigurator;

return static function(ContainerConfigurator $container): void {
    $messageBusConfigurator = new MessageBusConfigurator($container);
    
    // Create CallableHandler from some service and method
    $container
        ->services()
        ->set(UserManager::class) // Register service
    $messageBusConfigurator->callableHandler(
        message: RegisterUser::class,
        service: UserManager::class,
        method: 'registerUser',
    );
    
    // Create Entity handler for using with rich models
    $messageBusConfigurator->entityHandler(
        message: CreateOrder::class,
        entityClass: Order::class,
        handlerMethod: 'createOrder', // Entity method for handle message
        findBy: ['id' => 'id'], // Map between message properties and entity properties for find entity
        factoryMethod: 'create', // Optional static entity method for create new entity instance, if entity wasn't found
    );
    
    // Create and register global Message Bus middleware
    $container
        ->services()
        ->set(ValidationMiddleware::class);
    $messageBusConfigurator->middleware(ValidationMiddleware::class);
};
```

Same configuration, using tags:

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\Handler\CallableHandler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function(ContainerConfigurator $container): void {
    $container
        ->services()
        
        // Create CallableHandler from some service and method
        ->set(UserManager::class)
        ->set('app.register_user.handler', CallableHandler::class)
            ->args([
                'register_user',
                [service(UserManager::class, 'registerUser')]
            ])
            ->tag('trollbus.handler', ['message' => RegisterUser::class])
        
        // Create Entity handler for using with rich models
        ->set('app.create_order.handler', EntityHandler::class)
            ->args([
                'create_order',
                service('trollbus.entity_handler.default_entity_finder'),
                service('trollbus.entity_handler.default_entity_saver'),
                service('trollbus.entity_handler.default_criteria_resolver'),
                Order::class,
                'createOrder',
                ['id' => 'id'],
                'create',
            ])
            ->tag('trollbus.handler', ['message' => CreateOrder::class])
        
        // Create and register global Message Bus middleware
        ->set(ValidationMiddleware::class)
            ->tag('trollbus.middleware')''
};
```

```yaml
services:
  # Create CallableHandler from some service and method
  UserManager: ~

  app.register_user.handler:
    class: Trollbus\MessageBus\Handler\CallableHandler
    args:
      - 'register_user'
      - ['@UserManager', 'registerUser']
    tags:
      - name: trollbus.handler
        message: RegisterUser

  # Create Entity handler for using with rich models
  app.create_order.handler:
    class: Trollbus\MessageBus\EntityHandler\EntityHandler
    args:
      - 'create_order'
      - '@trollbus.entity_handler.default_entity_finder'
      - '@trollbus.entity_handler.default_entity_saver'
      - '@trollbus.entity_handler.default_criteria_resolver'
      - Order
      - 'createOrder'
      - id: id
      - 'create'
    tags:
      - name: trollbus.handler
        message: CreateOrder

  # Create and register global Message Bus middleware
  ValidationMiddleware:
    tags:
      - name: trollbus.middleware
```
