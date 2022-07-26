[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)
[![Unit Testing & Code Lint](https://github.com/makaronnik/amphp-rabbitmq-manager/actions/workflows/main.yml/badge.svg)](https://github.com/makaronnik/amphp-rabbitmq-manager/actions/workflows/main.yml)
![Latest Release](https://img.shields.io/github/v/release/makaronnik/amphp-rabbitmq-manager)
[![License](http://poser.pugx.org/makaronnik/amphp-rabbitmq-manager/license)](https://packagist.org/packages/makaronnik/amphp-rabbitmq-manager)

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://vshymanskyy.github.io/StandWithUkraine/)

# amphp-rabbitmq-manager
PHP (8.1) Async Manager for RabbitMQ connection and channels, which is a wrapper over [PHPinnacle Ridge](https://github.com/phpinnacle/ridge) library, based on [Amp](https://amphp.org/)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require makaronnik/amphp-rabbitmq-manager
```

## Requirements
- PHP 8.1+


## What is this manager used for?
1. To get the connected client (PHPinnacle\Ridge\Client). In case of unsuccessful connection (an exception occurs), it attempts to reconnect (the number of attempts is configured in the manager's constructor). When AsyncEvent is configured, an event is emitted that a connection has been made.
2. To get the active channel (PHPinnacle\Ridge\Channel) by its name. Before that, the manager verifies the connection and, if necessary, performs the connection process.
3. To handle an exceptional disconnect. With a logger configured and an exception passed, a log entry is made. When AsyncEvent is configured, an event is emitted that the connection is lost.

## Basic Usage
```php
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
```

## Use case
If you run your application in a containerized environment (eg Docker Compose), in most cases your application will start much faster than the RabbitMQ container is ready to go. This will throw an exception in your application when trying to create a connected client. You will need to catch this exception yourself and attempt to reconnect at a certain interval and number of attempts.

You can use the [dockerize](https://github.com/jwilder/dockerize) utility to run your application after the RabbitMQ server is ready to respond to requests:
```bach
CMD dockerize -wait tcp://rabbitmq:5672 -timeout 30s php app.php
```

But, some rebbit images, like [bitnami/rabbitmq](https://hub.docker.com/r/bitnami/rabbitmq), do a pre-configuration run first, and then restart the server in production mode. This behavior will trick your dockerize into giving it a response in the first stage of startup, then your application will start up and get an exception when trying to connect to RabbitMQ as the server will go into a restart.

In this case, it would be wise to use dockerize to get the first response from the server, and amphp-rabbitmq-manager to successfully get a connection after your application has started.

## Versioning
`makaronnik/amphp-rabbitmq-manager` follows the [semver](http://semver.org/) semantic versioning specification.

## Security
If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License
The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
