<?php

namespace pixelwerft\calendarsync\db;

/**
 * The names of the plugin's tables.
 */
abstract class Table
{
    public const CONNECTIONS = '{{%calendarsync_connections}}';
    public const CALENDARS = '{{%calendarsync_calendars}}';
    public const EVENTS = '{{%calendarsync_events}}';
}
