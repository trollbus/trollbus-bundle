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

### Attributes

Since `0.3.0` release Trollbus supports PHP 8.1+ attributes for configuring message handlers and middleware directly in
your code. This provides better IDE support, type safety, and keeps configuration close to the implementation.

#### `#[Handler]` - Basic Callable Message Handler

Marks a service method as a message handler.

```php
use Trollbus\MessageBus\Attribute\Handler;
use Trollbus\MessageBus\MessageContext;

class OrderService
{
    // Simplest usage — message type is inferred from the first argument
    // The second argument is Message Context.
    #[Handler]
    public function createOrder(CreateOrder $message, MessageContext $context): void
    {
        // $message is available
        
        $context->dispatch(new OrderCreated());
    }
    
    // If the argument is not needed — specify the type in the attribute
    #[Handler(message: OrderCreatedEvent::class)]
    public function onOrderCreated(): void
    {
        // No parameters, IDE won't complain
        $this->cache->invalidate();
    }
    
    // With logical ID (for routing and debugging)
    #[Handler(id: 'order.create')]
    public function create(CreateOrder $message): void
    {
        // ...
    }
    
    // With custom service ID (for decoration)
    #[Handler(serviceId: 'custom.order.handler')]
    public function process(CreateOrder $message): void
    {
        // ...
    }
}
```

#### `#[EntityHandler]` - Entity Handler

Loads an entity from the database and passes it to the handler method.

```php
use Trollbus\MessageBus\Attribute\EntityHandler;

final class Product
{
    #[EntityHandler(
        findBy: ['productId' => 'id'],  // [messageProperty => entityProperty]
    )]
    public function updateProduct(UpdateProduct $command): void
    {
        // $product is already loaded from the database
        $product->setName($command->name);
    }
}
```

For upsert entity, You can use optional argument `factoryMethod`:

```php
use Trollbus\MessageBus\Attribute\EntityHandler;

final class Product
{
    // MUST be static
    // MUST return instance of entity
    public static function createIfNotExists(UpsertProduct $command): self
    {
        return new self();
    }

    #[EntityHandler(
        findBy: ['productId' => 'id'],  // [messageProperty => entityProperty]
        factoryMethod: 'createIfNotExists', // Will be called, if entity not found via `findBy`
    )]
    public function updateProduct(UpsertProduct $command): void
    {
        // $product is already loaded from the database
        $product->setName($command->name);
    }
}
```

#### `#[EntityFactoryHandler]` - Create new entity handler

Creates a new entity from the message. 

```php
use Trollbus\MessageBus\Attribute\EntityFactoryHandler;

final class Product
{
    // MUST be static
    // MUST return instance of entity
    #[EntityFactoryHandler]
    public static function createProduct(CreateProduct $command): self
    {
        return new self();
    }
}
```

#### Handle multiple messages via one handler

This is useful when the same logic applies to multiple message types, or when you want to dispatch to different internal
methods based on the concrete type.

Trollbus supports **union types** (PHP 8.1+) in handler arguments. This allows a handler to accept messages of multiple
types.

```php
use Trollbus\MessageBus\Attribute\Handler;

final class OrderService
{
    #[Handler]
    public function onOrderEvent(OrderCreated|OrderUpdated $event): void
    {
        $this->cache->invalidateByOrderId($event->id);
    }
}
```

Alternative way, using repeated `Handler` attributes:

```php
use Trollbus\MessageBus\Attribute\Handler;

final class OrderService
{
    #[Handler(message: OrderCreated::class)]
    #[Handler(message: OrderUpdated::class)]
    public function onOrderEvent(): void
    {
        $this->cache->invalidate();
    }
}
```

#### `#[Middleware]` - Method as Middleware

Turns a service method into middleware.

```php
use Trollbus\MessageBus\Attribute\Middleware;
use Trollbus\MessageBus\MessageContext;
use Trollbus\MessageBus\Middleware\Pipeline;

final class SecurityService
{
    // Global middleware — executed for ALL handlers
    // The second argument $context is optional
    #[Middleware(
        global: true,
        priority: 100,
        serviceId: 'trollbus.middleware.security.auth', // Manual set service id. If value not set, then will be generated.
    )]
    public function authenticate(Pipeline $pipeline, MessageContext $context): mixed
    {
        if (!$this->isAuthenticated()) {
            throw new AuthException();
        }
        
        $pipeline->continue();
    }
    
    // Local middleware — only used via WithMiddleware
    // Create middleware service, but not register as global.
    #[Middleware(serviceId: 'trollbus.middleware.audit.log')]
    public function log(Pipeline $pipeline): mixed
    {
        $this->logger->info('Processing: ' . get_class($message));
        $result = $pipeline->continue();
        $this->logger->info('Done');
        
        return $result;
    }
}
```

#### `#[WithMiddleware]` - Applying Local Middleware

Attaches local middleware to a specific handler method.

```php
use Trollbus\MessageBus\Attribute\WithMiddleware;
use Trollbus\MessageBus\Attribute\Handler;

final class OrderService
{
    #[Handler(id: 'order.create')]
    #[WithMiddleware('audit.log')] // By service ID
    #[WithMiddleware('validation.order')]
    public function createOrder(CreateOrder $message): Order
    {
        // Middleware execute in declaration order:
        // 1. audit.log
        // 2. validation.order
        // Then this handler
    }
}
```

#### Difference between Handler and Middleware IDs

### Handler ID (Logical ID)

The Handler ID is logical identifier, that used for routing and debugging. If handler id is not set, it is generated.

```php
#[Handler(id: 'custom.handler')]  // Handler ID
```

The Service ID is technical identifier in the Symfony DI container. Used for dependency injection and decoration. If
service id is not set, it is generated.

```php
#[Handler(serviceId: 'app.trollbus.handler.custom')]  // Container ID
```

#### Migration from `MessageBusConfigurator` to Attributes

| `MessageBusConfigurator` Method | Attribute                                    |
|---------------------------------|----------------------------------------------|
| `callableHandler()`             | `#[Handler]` on method                       |
| `entityHandler()`               | `#[EntityHandler]` on method                 |
| `entityFactoryHandler()`        | `#[EntityFactoryHandler]` on method          |
| `middleware()` with service     | `#[Middleware]` on method with `global=true` |

**Before (`MessageBusConfigurator`):**
```php
$config->callableHandler(
    message: RegisterUser::class,
    service: UserManager::class,
    method: 'registerUser',
    middlewares: ['audit.log']
);
```

**After (Attributes):**
```php
final class UserManager
{
    #[Handler]
    #[WithMiddleware('audit.log')]
    public function registerUser(RegisterUser $message): void
    {
        // ...
    }
}
```

### MessageBusConfigurator

> **DEPRECATED!!!** This method will be removed in `0.4.0` release!.

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

### Manual

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Trollbus\MessageBus\EntityHandler\EntityFinder;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\EntityHandler\EntitySaver;
use Trollbus\MessageBus\EntityHandler\CriteriaResolver;
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
                service(EntityFinder::class),
                service(EntitySaver::class),
                service(CriteriaResolver::class),
                Order::class,
                'createOrder',
                ['id' => 'id'],
                'create',
            ])
            ->tag('trollbus.handler', ['message' => CreateOrder::class])
        
        // Create and register global Message Bus middleware
        ->set(ValidationMiddleware::class)
            ->tag('trollbus.middleware')
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
      - '@Trollbus\MessageBus\EntityHandler\EntityFinder'
      - '@Trollbus\MessageBus\EntityHandler\EntitySaver'
      - '@Trollbus\MessageBus\EntityHandler\CriteriaResolver'
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
