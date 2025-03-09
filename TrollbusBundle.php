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
use Trollbus\DoctrineORMBridge\DoctrineORMBridge;
use Trollbus\DoctrineORMBridge\EntityHandler\DoctrineEntityFinder;
use Trollbus\DoctrineORMBridge\EntityHandler\DoctrineEntitySaver;
use Trollbus\DoctrineORMBridge\Flusher\FlusherMiddleware;
use Trollbus\DoctrineORMBridge\Transaction\DoctrineTransactionProvider;
use Trollbus\MessageBus\CreatedAt\CreatedAtMiddleware;
use Trollbus\MessageBus\EntityHandler\PropertyCriteriaResolver;
use Trollbus\MessageBus\Logging\LogMiddleware;
use Trollbus\MessageBus\MessageBus;
use Trollbus\MessageBus\MessageId\CausationIdMiddleware;
use Trollbus\MessageBus\MessageId\CorrelationIdMiddleware;
use Trollbus\MessageBus\MessageId\MessageIdMiddleware;
use Trollbus\MessageBus\MessageId\RandomMessageIdGenerator;
use Trollbus\MessageBus\Transaction\WrapInTransactionMiddleware;
use Trollbus\TrollbusBundle\DependencyInjection\CompilerPass\HandlerRegistryPass;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfigurator;
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
 *         criteria_resolver: non-empty-string
 *     },
 *     doctrine_orm_bridge: array{
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
        $container->addCompilerPass(new HandlerRegistryPass());
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
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $this->loadCreatedAt($config, $services);
        $this->loadLogger($config, $services);
        $this->loadMessageId($config, $services, $builder);
        $this->loadTransaction($config, $services);
        $this->loadEntityHandler($config, $services);
        $this->loadDoctrineOrmBridge($config, $services, $builder);

        $container
            ->services()
            ->set(MessageBus::class)
                ->args([
                    service(MessageBusConfigurator::HANDLER_REGISTRY),
                    tagged_iterator(MessageBusConfigurator::MIDDLEWARE_TAG),
                ])
            ->alias(MessageBusConfigurator::MESSAGE_BUS, MessageBus::class)
                ->public();
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureCreatedAt(NodeBuilder $config): void
    {
        $config
            ->arrayNode('created_at')
                ->canBeDisabled()
                ->children()
                    ->scalarNode('clock')
                        ->defaultNull();
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
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 1000]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureLogger(NodeBuilder $config): void
    {
        $config
            ->arrayNode('logger')
                ->canBeEnabled()
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
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 900]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureMessageId(NodeBuilder $config): void
    {
        $config
            ->arrayNode('message_id')
                ->canBeDisabled()
                ->children()
                    ->scalarNode('generator')
                        ->defaultValue(MessageBusConfigurator::DEFAULT_MESSAGE_ID_GENERATOR);
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

        if (!$builder->has(MessageBusConfigurator::DEFAULT_MESSAGE_ID_GENERATOR)) {
            $services->alias(MessageBusConfigurator::DEFAULT_MESSAGE_ID_GENERATOR, RandomMessageIdGenerator::class);
        }

        $services
            ->set(MessageIdMiddleware::class)
                ->args([
                    service($config['message_id']['generator']),
                ])
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 810])

            ->set(CorrelationIdMiddleware::class)
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 800])

            ->set(CausationIdMiddleware::class)
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 800]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureTransaction(NodeBuilder $config): void
    {
        $config
            ->arrayNode('transaction')
            ->canBeEnabled()
                ->children()
                    ->scalarNode('transaction_provider')
                        ->defaultValue(MessageBusConfigurator::DEFAULT_TRANSACTION_PROVIDER);
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
                ->tag(MessageBusConfigurator::MIDDLEWARE_TAG, ['priority' => 700]);
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureEntityHandler(NodeBuilder $config): void
    {
        $config
            ->arrayNode('entity_handler')
            ->canBeEnabled()
            ->children()
                ->scalarNode('entity_finder')
                    ->defaultValue(MessageBusConfigurator::DEFAULT_ENTITY_FINDER)
                    ->end()
                ->scalarNode('entity_saver')
                    ->defaultValue(MessageBusConfigurator::DEFAULT_ENTITY_SAVER)
                    ->end()
                ->scalarNode('criteria_resolver')
                    ->defaultValue(MessageBusConfigurator::DEFAULT_CRITERIA_RESOLVER);
    }

    /**
     * @psalm-param Config $config
     */
    private function loadEntityHandler(array $config, ServicesConfigurator $services): void
    {
        if (false === $config['entity_handler']['enabled']) {
            return;
        }

        if (MessageBusConfigurator::DEFAULT_ENTITY_FINDER !== $config['entity_handler']['entity_finder']) {
            $services->alias(MessageBusConfigurator::DEFAULT_ENTITY_FINDER, $config['entity_handler']['entity_finder']);
        }

        if (MessageBusConfigurator::DEFAULT_ENTITY_SAVER !== $config['entity_handler']['entity_saver']) {
            $services->alias(MessageBusConfigurator::DEFAULT_ENTITY_SAVER, $config['entity_handler']['entity_saver']);
        }

        $services->set(PropertyCriteriaResolver::class);

        if (MessageBusConfigurator::DEFAULT_CRITERIA_RESOLVER === $config['entity_handler']['criteria_resolver']) {
            $services->alias(MessageBusConfigurator::DEFAULT_CRITERIA_RESOLVER, PropertyCriteriaResolver::class);
        } else {
            $services->alias(MessageBusConfigurator::DEFAULT_CRITERIA_RESOLVER, $config['entity_handler']['criteria_resolver']);
        }
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod, MixedMethodCall
     */
    private function configureDoctrineOrmBridge(NodeBuilder $config): void
    {
        $config
            ->arrayNode('doctrine_orm_bridge')
                ->canBeEnabled()
                ->children()
                    ->scalarNode('manager_registry')
                        ->cannotBeEmpty()
                        ->defaultValue('doctrine')
                        ->end()
                    ->scalarNode('manager')
                        ->defaultNull()
                        ->end()
                    ->booleanNode('entity_saver_flush')
                        ->defaultFalse()
                        ->end()
                    ->booleanNode('flusher')
                        ->defaultTrue();
    }

    /**
     * @psalm-param Config $config
     */
    private function loadDoctrineOrmBridge(array &$config, ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        if (false === $config['doctrine_orm_bridge']['enabled']) {
            return;
        }

        if (false === class_exists(DoctrineORMBridge::class)) {
            throw new LogicException('Package "trollbus/doctrine-orm-bridge" is not installed.');
        }

        $services
            ->set(DoctrineTransactionProvider::class)
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

        if (!$builder->has(MessageBusConfigurator::DEFAULT_TRANSACTION_PROVIDER)) {
            $services->alias(MessageBusConfigurator::DEFAULT_TRANSACTION_PROVIDER, DoctrineTransactionProvider::class);
        }

        if (!$builder->has(MessageBusConfigurator::DEFAULT_ENTITY_FINDER)) {
            $services->alias(MessageBusConfigurator::DEFAULT_ENTITY_FINDER, DoctrineEntityFinder::class);
        }

        if (!$builder->has(MessageBusConfigurator::DEFAULT_ENTITY_SAVER)) {
            $services->alias(MessageBusConfigurator::DEFAULT_ENTITY_SAVER, DoctrineEntitySaver::class);
        }

        if ($config['doctrine_orm_bridge']['flusher']) {
            $services
                ->set(FlusherMiddleware::class)
                ->args([
                    service($config['doctrine_orm_bridge']['manager_registry']),
                    $config['doctrine_orm_bridge']['manager'],
                ])
                ->tag('trollbus.middleware', ['priority' => 500]);
        }
    }
}
