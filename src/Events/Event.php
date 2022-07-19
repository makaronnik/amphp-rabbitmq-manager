<?php

namespace Makaronnik\RabbitManager\Events;

interface Event
{
    /**
     * @return string
     */
    public static function getName(): string;
}
