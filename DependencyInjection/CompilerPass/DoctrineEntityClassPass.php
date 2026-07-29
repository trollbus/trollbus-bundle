<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\DependencyInjection\CompilerPass;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Trollbus\TrollbusBundle\DependencyInjection\MessageBusConfiguration;

/**
 * Automatic add Doctrine ORM entity classes if Doctrie ORM Bridge is enabled.
 */
final class DoctrineEntityClassPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!MessageBusConfiguration::getParamDoctrineBridgeEnabled($container)) {
            return;
        }

        $mappingDriver = new MappingDriverChain();

        foreach (self::iterateDoctrineMappingsConfig($container) as $mapping) {
            $dir = $container->getParameterBag()->resolveValue($mapping['dir']);

            if ('attribute' === $mapping['type']) {
                $mappingDriver->addDriver(new AttributeDriver([$dir]), $mapping['prefix']);
            } elseif ('xml' === $mapping['type']) {
                $mappingDriver->addDriver(new SimplifiedXmlDriver([$dir]), $mapping['prefix']);
            }
        }

        MessageBusConfiguration::addParamEntityHandlerClasses($container, $mappingDriver->getAllClassNames());
    }

    /**
     * @return iterable<array{type: string, dir: string, prefix: string}>
     */
    private static function iterateDoctrineMappingsConfig(ContainerBuilder $container): iterable
    {
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            foreach ((array) ($config['orm']['mappings'] ?? []) as $mapping) {
                yield $mapping;
            }

            foreach ((array) ($config['orm']['entity_managers'] ?? []) as $managerConfig) {
                foreach ((array) ($managerConfig['mappings'] ?? []) as $mapping) {
                    yield $mapping;
                }
            }

        }
    }
}
