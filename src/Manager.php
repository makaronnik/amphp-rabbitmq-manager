<?php

namespace Makaronnik\RabbitManager;

use SplQueue;
use stdClass;
use Throwable;
use Amp\Delayed;
use Amp\Promise;
use Amp\Deferred;
use PHPinnacle\Ridge\Client;
use Psr\Log\LoggerInterface;
use PHPinnacle\Ridge\Channel;
use Makaronnik\RabbitManager\Helpers\LogHelper;
use Cspray\Labrador\AsyncEvent\AmpEventEmitter;
use Cspray\Labrador\Exception\InvalidTypeException;
use Cspray\Labrador\AsyncEvent\StandardEventFactory;
use Makaronnik\RabbitManager\Events\RabbitClientConnectedEvent;
use Makaronnik\RabbitManager\Events\RabbitClientDisconnectedEvent;
use Makaronnik\RabbitManager\Exceptions\FailedConnectionException;

use function Amp\call;
use function Amp\asyncCall;

final class Manager
{
    protected const MAX_ATTEMPTS = 50;

    /** @var array<Channel>  */
    protected array $channels = [];
    protected int $numberOfAttempts = 0;
    protected bool $isConnectionInProgress = false;

    /**
     * @param Client $client
     * @param SplQueue $pendingConnectionQueue
     * @param int $maxAttempts
     * @param LoggerInterface|null $logger
     * @param AmpEventEmitter|null $eventEmitter
     * @param StandardEventFactory|null $eventFactory
     */
    public function __construct(
        protected Client $client,
        protected SplQueue $pendingConnectionQueue,
        protected int $maxAttempts = self::MAX_ATTEMPTS,
        protected ?LoggerInterface $logger = null,
        protected ?AmpEventEmitter $eventEmitter = null,
        protected ?StandardEventFactory $eventFactory = null
    ) {
    }

    /**
     * @param string $chanelName
     * @return Promise<Channel>
     * @throws FailedConnectionException
     */
    public function getChanel(string $chanelName): Promise
    {
        return call(function () use ($chanelName) {
            if (isset($this->channels[$chanelName])) {
                return $this->channels[$chanelName];
            }

            $client = yield $this->getConnectedClient();

            return $this->channels[$chanelName] = yield $client->channel();
        });
    }

    /**
     * @return Promise<Client>
     * @throws FailedConnectionException
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    public function getConnectedClient(): Promise
    {
        return call(function () {
            if ($this->client->isConnected()) {
                return $this->client;
            }

            $promise = $this->promiseConnectedClient();

            if ($this->isConnectionInProgress === false) {
                asyncCall([$this, 'performNewConnection']);
            }

            return $promise;
        });
    }

    /**
     * @param string $reason
     * @return Promise<void>
     */
    public function disconnect(string $reason = ''): Promise
    {
        return call(function () use ($reason) {
            if ($this->client->isConnected()) {
                yield $this->client->disconnect(reason: $reason);
                $this->resetState();
                $this->emit(RabbitClientDisconnectedEvent::getName());
            }
        });
    }

    /**
     * @param Throwable|null $throwable
     * @return Promise<void>
     */
    public function handleConnectionBreak(?Throwable $throwable = null): Promise
    {
        return call(function () use ($throwable) {
            if ($this->isConnectionInProgress === false) {
                if (isset($this->logger, $throwable)) {
                    $this->logger->error(
                        $throwable->getMessage(),
                        LogHelper::prepareLogContext(
                            __FILE__ . ':' . __LINE__,
                            $throwable
                        )
                    );
                }

                $this->resetState();
                yield $this->emit(RabbitClientDisconnectedEvent::getName());
                asyncCall([$this, 'performNewConnection']);
            }
        });
    }

    /**
     * @return Promise<Client>
     * @psalm-suppress MixedReturnTypeCoercion
     */
    protected function promiseConnectedClient(): Promise
    {
        $deferred = new Deferred();

        $this->pendingConnectionQueue->enqueue($deferred);

        return $deferred->promise();
    }

    /**
     * @return Promise<void>
     */
    protected function performNewConnection(): Promise
    {
        return call(function () {
            $this->isConnectionInProgress = true;

            try {
                if ($this->client->isConnected()) {
                    yield $this->client->disconnect();
                }

                yield $this->client->connect();
            } catch (Throwable $throwable) {
                if (++$this->numberOfAttempts > $this->maxAttempts) {
                    $this->resetState();
                    $this->fail($throwable);

                    return;
                }

                if (isset($this->logger)) {
                    $this->logger->debug(
                        $throwable->getMessage(),
                        LogHelper::prepareLogContext(
                            __FILE__ . ':' . __LINE__,
                            $throwable
                        )
                    );

                    $this->logger->debug(
                        sprintf(
                            'Retrying to connect to the RabbitMQ server. %d attempts',
                            $this->numberOfAttempts
                        ),
                        LogHelper::prepareLogContext(
                            __FILE__ . ':' . __LINE__
                        )
                    );
                }

                if ($this->numberOfAttempts > 5) {
                    $delay = 1500;
                } else {
                    $delay = $this->numberOfAttempts * 300;
                }

                yield new Delayed($delay);

                yield $this->performNewConnection();

                return;
            }

            $this->resetState();
            $this->resolve();

            yield $this->emit(RabbitClientConnectedEvent::getName());
        });
    }

    /**
     * @return void
     */
    protected function resolve(): void
    {
        if (false === $this->pendingConnectionQueue->isEmpty()) {
            while (false === $this->pendingConnectionQueue->isEmpty()) {
                /** @var Deferred $deferred */
                $deferred = $this->pendingConnectionQueue->dequeue();
                $deferred->resolve($this->client);
            }
        }
    }

    /**
     * @param Throwable $throwable
     * @return void
     */
    protected function fail(Throwable $throwable): void
    {
        if (false === $this->pendingConnectionQueue->isEmpty()) {
            while (false === $this->pendingConnectionQueue->isEmpty()) {
                /** @var Deferred $deferred */
                $deferred = $this->pendingConnectionQueue->dequeue();
                $message = sprintf('Connection failed due to error: %s', $throwable->getMessage());
                $deferred->fail(new FailedConnectionException($message, previous: $throwable));
            }
        }
    }

    /**
     * @param string $eventName
     * @return Promise
     * @throws InvalidTypeException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    protected function emit(string $eventName): Promise
    {
        return call(function () use ($eventName) {
            if (isset($this->eventEmitter, $this->eventFactory)) {
                yield $this->eventEmitter->emit($this->eventFactory->create($eventName, new stdClass()));
            }
        });
    }

    /**
     * @return void
     */
    protected function resetState(): void
    {
        $this->channels = [];
        $this->numberOfAttempts = 0;
        $this->isConnectionInProgress = false;
    }
}
