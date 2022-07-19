<?php

namespace Makaronnik\RabbitManager\Events;

final class RabbitClientDisconnectedEvent implements Event
{
    public const NAME = 'rabbitClientDisconnected';

    /**
     * @return string
     */
    public static function getName(): string
    {
        return self::NAME;
    }
}
