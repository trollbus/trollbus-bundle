<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Trollbus\DoctrineORMBridge\EntityHandler\DoctrineEntityFinder;
use Trollbus\DoctrineORMBridge\EntityHandler\DoctrineEntitySaver;
use Trollbus\DoctrineORMBridge\Flusher\FlusherMiddleware;
use Trollbus\DoctrineORMBridge\Transaction\DoctrineTransactionProvider;
use Trollbus\MessageBus\CreatedAt\CreatedAtMiddleware;
use Trollbus\MessageBus\EntityHandler\CriteriaResolver;
use Trollbus\MessageBus\EntityHandler\EntityFinder;
use Trollbus\MessageBus\EntityHandler\EntitySaver;
use Trollbus\MessageBus\EntityHandler\PropertyCriteriaResolver;
use Trollbus\MessageBus\Logging\LogMiddleware;
use Trollbus\MessageBus\MessageBus;
use Trollbus\MessageBus\MessageId\CausationIdMiddleware;
use Trollbus\MessageBus\MessageId\CorrelationIdMiddleware;
use Trollbus\MessageBus\MessageId\MessageIdGenerator;
use Trollbus\MessageBus\MessageId\MessageIdMiddleware;
use Trollbus\MessageBus\MessageId\RandomMessageIdGenerator;
use Trollbus\MessageBus\Transaction\WrapInTransactionMiddleware;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\AttributePass;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\DebugHandlerPass;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\DeferredEventPass;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\DoctrineEntityClassPass;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\HandlerRegistryPass;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfiguration;
use Trollbus\TrollbusBundle\MessageId\SymfonyUidMessageIdGenerator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * @psalm-type Config = array{
 *     created_at: array{
 *         enabled: bool,
 *         clock: non-empty-string|null
 *     },
 *     logger: array{
 *         enabled: bool,
 *         logger: non-empty-string
 *     },
 *     message_id: array{
 *         enabled: bool,
 *         generator: non-empty-string
 *     },
 *     transaction: array{
 *         enabled: bool,
 *         transaction_provider: non-empty-string
 *     },
 *     entity_handler: array{
 *         enabled: bool,
 *         entity_finder: non-empty-string,
 *         entity_saver: non-empty-string,
 *         criteria_resolver: non-empty-string,
 *         classes: list<class-string>,
 *     },
 *     doctrine_orm_bridge?: array{
 *         enabled: bool,
 *         manager_registry: non-empty-string,
 *         manager: non-empty-string|null,
 *         entity_saver_flush: bool,
 *         flusher: bool
 *     }
 * }
 */
final class TrollbusBundle extends AbstractBundle
{
    protected string $extensionAlias = 'trollbus';

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineEntityClassPass());
        $container->addCompilerPass(new AttributePass());
        $container->addCompilerPass(new HandlerRegistryPass());
        $container->addCompilerPass(new DeferredEventPass());
        $container->addCompilerPass(new DebugHandlerPass());
    }

    /**
     * @psalm-suppress UndefinedMethod
     */
    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var NodeBuilder $config */
        $config = $definition->rootNode()->children();

        $this->configureCreatedAt($config);
        $this->configureLogger($config);
        $this->configureMessageId($config);
        $this->configureTransaction($config);
        $this->configureEntityHandler($config);
        $this->configureDoctrineOrmBridge($config);
    }

    /**
     * @psalm-param Config $config
     * @psalm-suppress MoreSpecificImplementedParamType
     * @psalm-suppress ParamNameMismatch In symfony 7.4.9 parameters was renamed. See more: https://github.com/symfony/symfony/commit/a0e2df8273003b8a1437263a7cad6d61295fa15b
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        $this->loadCreatedAt($config, $services);
        $this->loadLogger($config, $services);
        $this->loadMessageId($config, $services, $container);
        $this->loadTransaction($config, $services);
        $this->loadEntityHandler($config, $services, $container);
        $this->loadDoctrineOrmBridge($config, $services, $container);

        $configurator
            ->services()
            ->set(MessageBus::class)
                ->args([
                    service(MessageBusConfiguration::HANDLER_REGISTRY),
                    tagged_iterator(MessageBusConfiguration::MIDDLEWARE_TAG),
                ])
            ->alias(MessageBusConfiguration::MESSAGE_BUS, MessageBus::class)
                ->public();
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureCreatedAt(NodeBuilder $config): void
    {
        $node = $config
            ->arrayNode('created_at')
                ->canBeDisabled();

        $clockNode = $node
            ->children()
                ->scalarNode('clock');

        // Check, that symfony/clock installed
        if (interface_exists('Symfony\Component\Clock\ClockInterface')) {
            $clockNode->defaultValue('clock');
        } else {
            $clockNode->defaultNull();
        }
    }

    /**
     * @psalm-param Config $config
     */
    private function loadCreatedAt(array $config, ServicesConfigurator $services): void
    {
        if (false === $config['created_at']['enabled']) {
            return;
        }

        $services
            ->set(CreatedAtMiddleware::class)
                ->args([
                    isset($config['created_at']['clock']) ? service($config['created_at']['clock']) : null,
                ])
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 1_000]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureLogger(NodeBuilder $config): void
    {
        $config
            ->arrayNode('logger')
                ->canBeDisabled()
                ->children()
                    ->scalarNode('logger')
                        ->defaultValue('logger');
    }

    /**
     * @psalm-param Config $config
     */
    private function loadLogger(array $config, ServicesConfigurator $services): void
    {
        if (false === $config['logger']['enabled']) {
            return;
        }

        $services
            ->set(LogMiddleware::class)
                ->args([
                    service($config['logger']['logger']),
                ])
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 500]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureMessageId(NodeBuilder $config): void
    {
        if ($this->isSymfonyUidInstalled()) {
            $messageGeneratorId = SymfonyUidMessageIdGenerator::class;
        } else {
            $messageGeneratorId = RandomMessageIdGenerator::class;
        }

        $config
            ->arrayNode('message_id')
                ->canBeDisabled()
                ->children()
                    ->scalarNode('generator')
                        ->defaultValue($messageGeneratorId);
    }

    /**
     * @psalm-param Config $config
     */
    private function loadMessageId(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        if (false === $config['message_id']['enabled']) {
            return;
        }

        $services->set(RandomMessageIdGenerator::class);

        if ($this->isSymfonyUidInstalled()) {
            $services->set(SymfonyUidMessageIdGenerator::class)
                ->args([
                    service('uuid.factory'),
                ]);
        }

        $services->alias(MessageIdGenerator::class, $config['message_id']['generator']);

        $services
            ->set(MessageIdMiddleware::class)
                ->args([
                    service($config['message_id']['generator']),
                ])
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 810])

            ->set(CorrelationIdMiddleware::class)
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 800])

            ->set(CausationIdMiddleware::class)
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 800]);
    }

    private function isSymfonyUidInstalled(): bool
    {
        return class_exists('Symfony\Component\Uid\Uuid');
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureTransaction(NodeBuilder $config): void
    {
        $isDoctrineOrmBridgeInstalled = $this->isDoctrineOrmBridgeInstalled();

        $node = $config->arrayNode('transaction');

        if ($this->isDoctrineOrmBridgeInstalled()) {
            $node->canBeDisabled();
        } else {
            $node->canBeEnabled();
        }

        $transactionProviderNode = $node->children()
            ->scalarNode('transaction_provider');

        if ($isDoctrineOrmBridgeInstalled) {
            $transactionProviderNode->defaultValue(DoctrineTransactionProvider::class);
        }
    }

    /**
     * @psalm-param Config $config
     */
    private function loadTransaction(array $config, ServicesConfigurator $services): void
    {
        if (false === $config['transaction']['enabled']) {
            return;
        }

        $services
            ->set(WrapInTransactionMiddleware::class)
                ->args([
                    service($config['transaction']['transaction_provider']),
                ])
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 400]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureEntityHandler(NodeBuilder $config): void
    {
        $isDoctrineOrmBridgeInstalled = $this->isDoctrineOrmBridgeInstalled();
        /** @psalm-suppress PossiblyNullReference In symfony 6.4 end() return nullable value */
        $node = $config->arrayNode('entity_handler');

        if ($isDoctrineOrmBridgeInstalled) {
            $node->canBeDisabled();
        } else {
            $node->canBeEnabled();
        }

        $entityFinderNode = $node->children()->scalarNode('entity_finder');
        $entitySaverNode = $node->children()->scalarNode('entity_saver');

        if ($isDoctrineOrmBridgeInstalled) {
            $entityFinderNode->defaultValue(DoctrineEntityFinder::class);
            $entitySaverNode->defaultValue(DoctrineEntitySaver::class);
        }

        $node->children()->scalarNode('criteria_resolver')->defaultValue(PropertyCriteriaResolver::class);

        $node->children()->arrayNode('classes')
            ->stringPrototype()
                ->validate()
                    ->ifFalse(class_exists(...))
                    ->thenInvalid('Invalid entity class %s.');
    }

    /**
     * @psalm-param Config $config
     */
    private function loadEntityHandler(array $config, ServicesConfigurator $services, ContainerBuilder $container): void
    {
        $container->setParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_ENABLED, $config['entity_handler']['enabled']);

        if (!$container->hasParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES)) {
            $container->setParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES, []);
        }

        if (false === $config['entity_handler']['enabled']) {
            return;
        }

        if ($container->hasParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES)) {
            $container->setParameter(
                MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES,
                array_values(array_unique(array_merge(
                    $container->getParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES),
                    $config['entity_handler']['classes'],
                ))),
            );
        } else {
            $container->setParameter(MessageBusConfiguration::PARAM_ENTITY_HANDLER_CLASSES, $config['entity_handler']['classes']);
        }

        $services->set(PropertyCriteriaResolver::class);

        $services->alias(EntityFinder::class, $config['entity_handler']['entity_finder']);
        $services->alias(EntitySaver::class, $config['entity_handler']['entity_saver']);
        $services->alias(CriteriaResolver::class, $config['entity_handler']['criteria_resolver']);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureDoctrineOrmBridge(NodeBuilder $config): void
    {
        // Skip, if trollbus/doctrine-orm-bridge not installed
        if (false === $this->isDoctrineOrmBridgeInstalled()) {
            return;
        }

        /** @psalm-suppress PossiblyNullReference In symfony 6.4 end() return nullable value */
        $config
            ->arrayNode('doctrine_orm_bridge')
                ->canBeDisabled()
                ->children()
                    ->scalarNode('manager_registry')
                        ->cannotBeEmpty()
                        ->defaultValue('doctrine')
                        ->end()
                    ->scalarNode('manager')
                        ->defaultNull()
                        ->end()
                    ->booleanNode('entity_saver_flush')
                        ->defaultTrue()
                        ->end()
                    ->booleanNode('flusher')
                        ->defaultTrue()
                        ->end();
    }

    /**
     * @psalm-param Config $config
     */
    private function loadDoctrineOrmBridge(array $config, ServicesConfigurator $services, ContainerBuilder $container): void
    {
        if (false === isset($config['doctrine_orm_bridge'])) {
            $container->setParameter(MessageBusConfiguration::PARAM_DOCTRINE_BRIDGE_ENABLED, false);

            return;
        }

        $container->setParameter(MessageBusConfiguration::PARAM_DOCTRINE_BRIDGE_ENABLED, $config['doctrine_orm_bridge']['enabled']);

        if (false === $config['doctrine_orm_bridge']['enabled']) {
            return;
        }

        if (false === $this->isDoctrineOrmBridgeInstalled()) {
            throw new LogicException('Package "trollbus/doctrine-orm-bridge" is not installed.');
        }

        $services
            ->set(DoctrineTransactionProvider::class)
                ->factory([DoctrineTransactionProvider::class, 'fromEntityManagerName'])
                ->args([
                    service($config['doctrine_orm_bridge']['manager_registry']),
                    $config['doctrine_orm_bridge']['manager'],
                ])
            ->set(DoctrineEntityFinder::class)
                ->args([
                    service($config['doctrine_orm_bridge']['manager_registry']),
                ])
            ->set(DoctrineEntitySaver::class)
                ->args([
                    service($config['doctrine_orm_bridge']['manager_registry']),
                    $config['doctrine_orm_bridge']['entity_saver_flush'],
                ]);

        if ($config['doctrine_orm_bridge']['flusher']) {
            $services
                ->set(FlusherMiddleware::class)
                ->args([
                    service($config['doctrine_orm_bridge']['manager_registry']),
                    $config['doctrine_orm_bridge']['manager'],
                ])
                ->tag(MessageBusConfiguration::MIDDLEWARE_TAG, ['priority' => 300]);
        }
    }

    public function isDoctrineOrmBridgeInstalled(): bool
    {
        return class_exists('Trollbus\DoctrineORMBridge\DoctrineORMBridge');
    }
}
