<?php

namespace pixelwerft\calendarsync\models;

/**
 * A calendar as the provider lists it, before it is chosen for a sync.
 */
class RemoteCalendar
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $color = null,
        public ?string $timezone = null,
        public bool $readOnly = false,
        public bool $primary = false,
    ) {
    }

    /**
     * @return array{id: string, name: string, color: ?string, timezone: ?string, readOnly: bool, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'timezone' => $this->timezone,
            'readOnly' => $this->readOnly,
            'primary' => $this->primary,
        ];
    }
}
