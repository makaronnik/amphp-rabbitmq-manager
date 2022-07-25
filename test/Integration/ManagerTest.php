<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */

namespace Makaronnik\RabbitManager\Test\Integration;

use Mockery;
use SplQueue;
use stdClass;
use Generator;
use Amp\Success;
use DG\BypassFinals;
use ReflectionClass;
use RuntimeException;
use Mockery\MockInterface;
use PHPinnacle\Ridge\Client;
use Psr\Log\LoggerInterface;
use PHPinnacle\Ridge\Channel;
use Amp\PHPUnit\AsyncTestCase;
use Makaronnik\RabbitManager\Manager;
use Cspray\Labrador\AsyncEvent\AmpEventEmitter;
use PHPinnacle\Ridge\Exception\ClientException;
use Cspray\Labrador\AsyncEvent\StandardEventFactory;
use Makaronnik\RabbitManager\Events\RabbitClientConnectedEvent;
use Makaronnik\RabbitManager\Events\RabbitClientDisconnectedEvent;
use Makaronnik\RabbitManager\Exceptions\FailedConnectionException;

use function Amp\call;

class ManagerTest extends AsyncTestCase
{
    protected const TEST_CHANEL_NAME = 'testChanel';

    protected static MockInterface $client;
    protected static MockInterface $channel;
    protected static MockInterface $logger;
    protected static MockInterface $eventEmitter;
    protected static MockInterface $eventFactory;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        BypassFinals::enable();

        self::$client = Mockery::mock(Client::class);
        self::$logger = Mockery::mock(LoggerInterface::class);
        self::$eventEmitter = Mockery::mock(AmpEventEmitter::class);
        self::$eventFactory = Mockery::mock(StandardEventFactory::class, []);

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (false === isset(self::$channel)) {
            self::$channel = Mockery::mock(Channel::class);
        }
    }

    /**
     * @return Generator
     */
    public function testGetNewChanel(): Generator
    {
        $client = clone self::$client;

        $client->expects('channel')
            ->once()
            ->andReturn(call(fn () => self::$channel));

        $manager = Mockery::mock(Manager::class, [$client, new SplQueue()])->makePartial();

        $manager->expects('getConnectedClient')
            ->once()
            ->andReturn(call(fn () => $client));

        /** @var Manager $manager */
        $this->assertSame(self::$channel, yield $manager->getChanel(self::TEST_CHANEL_NAME));

        return $manager;
    }

    /**
     * @depends testGetNewChanel
     *
     * @param Manager $manager
     *
     * @return Generator
     */
    public function testGetExistingChanel(Manager $manager): Generator
    {
        $this->assertSame(self::$channel, yield $manager->getChanel(self::TEST_CHANEL_NAME));
    }

    /**
     * @return Generator
     */
    public function testGetConnectedClientPreviouslyUnconnectedWithSuccess(): Generator
    {
        $client = self::$client;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $client->makePartial()
            ->expects('connect')
            ->once()
            ->andReturn(new Success());

        $eventEmitter->makePartial()
            ->expects('emit')
            ->once()
            ->passthru();

        $eventFactory->makePartial()
            ->expects('create')
            ->once()
            ->passthru();

        /**
         * @var Client $client
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), eventEmitter: $eventEmitter, eventFactory: $eventFactory);

        $this->assertFalse($client->isConnected());
        $this->assertInstanceOf(Client::class, yield $manager->getConnectedClient());
    }

    /**
     * @return Generator
     */
    public function testGetConnectedClientPreviouslyUnconnectedWithException(): Generator
    {
        $client = self::$client;
        $logger = self::$logger;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $this->expectException(FailedConnectionException::class);

        $client->makePartial()
            ->expects('connect')
            ->twice()
            ->andThrows(ClientException::class);

        $logger->makePartial()
            ->expects('debug')
            ->twice();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->never();

        $eventFactory->makePartial()
            ->expects('create')
            ->never();

        /**
         * @var Client $client
         * @var LoggerInterface $logger
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), 1, $logger, $eventEmitter, $eventFactory);

        $this->assertFalse($client->isConnected());

        yield $manager->getConnectedClient();
    }

    /**
     * @return Generator
     */
    public function testGetConnectedClientPreviouslyConnectedWithSuccess(): Generator
    {
        $client = self::$client;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $client->expects('isConnected')
            ->once()
            ->andReturn(true);

        $eventEmitter->makePartial()
            ->expects('emit')
            ->never();

        $eventFactory->makePartial()
            ->expects('create')
            ->never();

        /**
         * @var Client $client
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), eventEmitter: $eventEmitter, eventFactory: $eventFactory);

        $this->assertInstanceOf(Client::class, yield $manager->getConnectedClient());
    }

    /**
     * @return Generator
     */
    public function testGetConnectedClientPreviouslyConnectedWithBrokenStatusAndSuccess(): Generator
    {
        $client = self::$client;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $client->makePartial()
            ->expects('isConnected')
            ->atMost()
            ->twice()
            ->andReturn(false, true);

        $client->expects('connect')
            ->once()
            ->andReturn(new Success());

        $client->expects('disconnect')
            ->once()
            ->passthru();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->once()
            ->passthru();

        $eventFactory->makePartial()
            ->expects('create')
            ->once()
            ->with(RabbitClientConnectedEvent::getName(), anInstanceOf(stdClass::class))
            ->passthru();

        /**
         * @var Client $client
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), eventEmitter: $eventEmitter, eventFactory: $eventFactory);

        $this->assertInstanceOf(Client::class, yield $manager->getConnectedClient());
    }

    /**
     * @return Generator
     */
    public function testDisconnectPreviouslyConnectedClientWithSuccess(): Generator
    {
        $client = self::$client;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $client->makePartial()
            ->expects('isConnected')
            ->twice()
            ->andReturn(true, false);

        $client->expects('disconnect')
            ->once()
            ->passthru();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->once()
            ->passthru();

        $eventFactory->makePartial()
            ->expects('create')
            ->once()
            ->with(RabbitClientDisconnectedEvent::getName(), anInstanceOf(stdClass::class))
            ->passthru();

        /**
         * @var Client $client
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), eventEmitter: $eventEmitter, eventFactory: $eventFactory);

        yield $manager->disconnect();

        $this->assertFalse($client->isConnected());
    }

    /**
     * @return Generator
     */
    public function testDisconnectPreviouslyUnconnectedClientWithSuccess(): Generator
    {
        $client = self::$client;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $client->makePartial()
            ->expects('isConnected')
            ->twice()
            ->andReturn(false);

        $client->expects('disconnect')
            ->never();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->never();

        $eventFactory->makePartial()
            ->expects('create')
            ->never();

        /**
         * @var Client $client
         * @var AmpEventEmitter $eventEmitter
         * @var StandardEventFactory $eventFactory
         */
        $manager = new Manager($client, new SplQueue(), eventEmitter: $eventEmitter, eventFactory: $eventFactory);

        yield $manager->disconnect();

        $this->assertFalse($client->isConnected());
    }

    /**
     * @return Generator
     */
    public function testHandleConnectionBreakIfConnectionNotInProgressWithThrowable(): Generator
    {
        $client = self::$client;
        $logger = self::$logger;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $logger->makePartial()
            ->expects('error')
            ->once();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->once()
            ->passthru();

        $eventFactory->makePartial()
            ->expects('create')
            ->once()
            ->with(RabbitClientDisconnectedEvent::getName(), anInstanceOf(stdClass::class))
            ->passthru();


        /**
         * @var Manager&Mockery\Mock $manager
         */
        $manager = Mockery::spy(Manager::class, [
            $client,
            new SplQueue(),
            1,
            $logger,
            $eventEmitter,
            $eventFactory
        ])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $reflectedManager = new ReflectionClass(Manager::class);
        $isConnectionInProgressProperty = $reflectedManager->getProperty('isConnectionInProgress');
        $isConnectionInProgress = $isConnectionInProgressProperty->getValue($manager);

        $this->assertFalse($isConnectionInProgress);

        yield $manager->handleConnectionBreak(new RuntimeException('Some error'));

        $manager->shouldHaveReceived('resetState');
    }

    /**
     * @return Generator
     */
    public function testHandleConnectionBreakIfConnectionNotInProgressWithoutThrowable(): Generator
    {
        $client = self::$client;
        $logger = self::$logger;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $logger->makePartial()
            ->expects('error')
            ->never();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->once()
            ->passthru();

        $eventFactory->makePartial()
            ->expects('create')
            ->once()
            ->with(RabbitClientDisconnectedEvent::getName(), anInstanceOf(stdClass::class))
            ->passthru();


        /**
         * @var Manager&Mockery\Mock $manager
         */
        $manager = Mockery::spy(Manager::class, [
            $client,
            new SplQueue(),
            1,
            $logger,
            $eventEmitter,
            $eventFactory
        ])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $reflectedManager = new ReflectionClass(Manager::class);
        $isConnectionInProgressProperty = $reflectedManager->getProperty('isConnectionInProgress');
        $isConnectionInProgress = $isConnectionInProgressProperty->getValue($manager);

        $this->assertFalse($isConnectionInProgress);

        yield $manager->handleConnectionBreak();

        $manager->shouldHaveReceived('resetState');
    }

    /**
     * @return Generator
     */
    public function testHandleConnectionBreakIfConnectionInProgressWithThrowable(): Generator
    {
        $client = self::$client;
        $logger = self::$logger;
        $eventEmitter = self::$eventEmitter;
        $eventFactory = self::$eventFactory;

        $logger->makePartial()
            ->expects('error')
            ->never();

        $eventEmitter->makePartial()
            ->expects('emit')
            ->never();

        $eventFactory->makePartial()
            ->expects('create')
            ->never();


        /**
         * @var Manager&Mockery\Mock $manager
         */
        $manager = Mockery::spy(Manager::class, [
            $client,
            new SplQueue(),
            1,
            $logger,
            $eventEmitter,
            $eventFactory
        ])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $reflectedManager = new ReflectionClass(Manager::class);
        $isConnectionInProgressProperty = $reflectedManager->getProperty('isConnectionInProgress');
        $isConnectionInProgressProperty->setValue($manager, true);
        $isConnectionInProgress = $isConnectionInProgressProperty->getValue($manager);

        $this->assertTrue($isConnectionInProgress);

        yield $manager->handleConnectionBreak(new RuntimeException('Some error'));

        $manager->shouldNotReceive('resetState');
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
