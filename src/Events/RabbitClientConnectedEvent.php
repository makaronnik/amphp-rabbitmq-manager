<?php

namespace Makaronnik\RabbitManager\Events;

final class RabbitClientConnectedEvent implements Event
{
    public const NAME = 'rabbitClientConnected';

    /**
     * @return string
     */
    public static function getName(): string
    {
        return self::NAME;
    }
}
