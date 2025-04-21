<?php

declare(strict_types=1);

namespace Trollbus\TrollbusBundle\Command;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trollbus\Message\Event;
use Trollbus\Message\Message;
use Trollbus\MessageBus\EntityHandler\EntityFactoryHandler;
use Trollbus\MessageBus\EntityHandler\EntityHandler;
use Trollbus\MessageBus\Handler;
use Trollbus\MessageBus\Middleware\HandlerWithMiddlewares;

#[AsCommand('trollbus:debug:handler')]
final class DebugCommand extends Command
{
    /**
     * @param array<class-string<Message>, non-empty-list<non-empty-string>> $messageClassToHandlerServiceIds
     * @param list<non-empty-string> $globalMiddlewares
     * @param array<non-empty-string, list<non-empty-string>> $handlerServiceIdMiddlewares
     */
    public function __construct(
        private readonly ContainerInterface $handlerLocator,
        private readonly array $messageClassToHandlerServiceIds,
        private readonly array $globalMiddlewares,
        private readonly array $handlerServiceIdMiddlewares,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::OPTIONAL, description: 'Message class');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var non-empty-string|null $messageClass */
        $messageClass = $input->getArgument('message');

        if (null === $messageClass) {
            $messageClass = (string) $io->choice('Select message class', array_keys($this->messageClassToHandlerServiceIds));
        }

        if (false === is_subclass_of($messageClass, Message::class)) {
            $io->error(\sprintf('Message class must be instance of %s. Got %s', Message::class, $messageClass));

            return self::FAILURE;
        }

        if (false === isset($this->messageClassToHandlerServiceIds[$messageClass])) {
            if (is_subclass_of($messageClass, Event::class)) {
                $io->info(\sprintf('No handler of event %s', $messageClass));

                return self::SUCCESS;
            }

            $io->error(\sprintf('No handler of message %s', $messageClass));

            return self::FAILURE;
        }

        $io->success(\sprintf('Handler(s) of message %s', $messageClass));

        foreach ($this->messageClassToHandlerServiceIds[$messageClass] as $serviceId) {
            $handler = $this->handlerLocator->get($serviceId);

            if (false === $handler instanceof Handler) {
                $io->error(\sprintf('Invalid handler type of service %s. Expected %s, actual %s.', $serviceId, Handler::class, get_debug_type($handler)));

                return self::FAILURE;
            }

            $handlerInfo = $this->getHandlerInfo($handler);
            $handlerInfo['Service ID'] = $serviceId;
            $handlerInfo['Global middlewares'] = implode("\n", $this->globalMiddlewares) ?: '<info>No middlewares</info>';
            $handlerInfo['Middlewares'] = implode("\n", $this->handlerServiceIdMiddlewares[$serviceId] ?? []) ?: '<info>No middlewares</info>';

            $io->definitionList(...array_chunk($handlerInfo, 1, true));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function getHandlerInfo(Handler $handler): array
    {
        $handlerInfo = [
            'ID' => $handler->id(),
        ];

        if ($handler instanceof HandlerWithMiddlewares) {
            /** @var Handler $handler */
            $handler = (new \ReflectionProperty(HandlerWithMiddlewares::class, 'inner'))->getValue($handler);
        }

        if ($handler instanceof Handler\CallableHandler) {
            $handlerInfo['type'] = 'callable';

            /** @var callable $callable */
            $callable = (new \ReflectionProperty(Handler\CallableHandler::class, 'handler'))->getValue($handler);
            $handlerInfo['callback'] = $this->callableDump($callable);
        } elseif ($handler instanceof EntityHandler) {
            $handlerInfo['type'] = 'entity';
            $handlerInfo['entity'] = (string) (new \ReflectionProperty(EntityHandler::class, 'entityClass'))->getValue($handler);
            $handlerInfo['handlerMethod'] = (string) (new \ReflectionProperty(EntityHandler::class, 'handlerMethod'))->getValue($handler);
            $handlerInfo['findBy'] = (string) json_encode((new \ReflectionProperty(EntityHandler::class, 'findBy'))->getValue($handler));
            $handlerInfo['factoryMethod'] = (string) ((new \ReflectionProperty(EntityHandler::class, 'factoryMethod'))->getValue($handler) ?? 'Not set');
        } elseif ($handler instanceof EntityFactoryHandler) {
            $handlerInfo['type'] = 'entity factory';
            $handlerInfo['entityClass'] = (string) (new \ReflectionProperty(EntityFactoryHandler::class, 'entityClass'))->getValue($handler);
            $handlerInfo['handlerMethod'] = (string) (new \ReflectionProperty(EntityFactoryHandler::class, 'handlerMethod'))->getValue($handler);
        } else {
            $handlerInfo['type'] = 'service';
        }

        return $handlerInfo;
    }

    private function callableDump(callable $callable): string
    {
        if ($callable instanceof \Closure) {
            /** @noinspection PhpUnhandledExceptionInspection */
            return (new \ReflectionFunction($callable))->name;
        }

        if (\is_string($callable) && \function_exists($callable)) {
            return $callable;
        }

        if (\is_string($callable) && false !== mb_strpos($callable, '::')) {
            return $callable;
        }

        if (\is_object($callable) && method_exists($callable, '__invoke')) {
            return $callable::class;
        }

        if (\is_array($callable)) {
            if (\is_object($callable[0])) {
                return $callable[0]::class . '::' . $callable[1];
            }

            return $callable[0] . '::' . $callable[1];
        }

        return get_debug_type($callable);
    }
}
