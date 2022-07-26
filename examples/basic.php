<?php

use Amp\Loop;
use PHPinnacle\Ridge\Client;
use PHPinnacle\Ridge\Channel;
use PHPinnacle\Ridge\Message;
use Makaronnik\RabbitManager\Manager;
use Cspray\Labrador\AsyncEvent\AmpEventEmitter;
use Cspray\Labrador\AsyncEvent\StandardEventFactory;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () {
    $dsn = getenv('RABBIT_EXAMPLE_DSN');

    if (false === \is_string($dsn) || empty($dsn)) {
        echo 'No example dsn! Please set RABBIT_EXAMPLE_DSN environment variable.', \PHP_EOL;

        Loop::stop();
    }

    $manager = new Manager(
        client: Client::create($dsn),
        pendingConnectionQueue: new SplQueue(),
        maxAttempts: 30,
        eventEmitter: new AmpEventEmitter(),
        eventFactory: new StandardEventFactory()
    );

    $channel = yield $manager->getChanel('testChannel');

    try {
        yield $channel->queueDeclare('basic_queue', false, false, false, true);

        for ($i = 0; $i < 10; $i++) {
            yield $channel->publish("test_$i", '', 'basic_queue');
        }

        yield $channel->consume(function (Message $message, Channel $channel) {
            echo $message->content . \PHP_EOL;
            yield $channel->ack($message);
        }, 'basic_queue');

    } catch (Throwable $exception) {
        yield $manager->handleConnectionBreak($exception);
    }

    yield $manager->disconnect('Bye!');
});
