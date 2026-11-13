<?php

namespace pixelwerft\calendarsync\services;

use craft\events\RegisterComponentTypesEvent;
use pixelwerft\calendarsync\providers\CalDav;
use pixelwerft\calendarsync\providers\Google;
use pixelwerft\calendarsync\providers\IcsFeed;
use pixelwerft\calendarsync\providers\Microsoft;
use pixelwerft\calendarsync\providers\ProviderInterface;
use yii\base\Component;

/**
 * The calendar services a connection can be made to.
 */
class Providers extends Component
{
    /**
     * @event RegisterComponentTypesEvent Add the class of another provider to `$event->types`.
     */
    public const EVENT_REGISTER_PROVIDERS = 'registerProviders';

    /** @var array<string, ProviderInterface>|null */
    private ?array $providers = null;

    /**
     * @return array<string, ProviderInterface> By handle
     */
    public function all(): array
    {
        if ($this->providers === null) {
            $event = new RegisterComponentTypesEvent([
                'types' => [Google::class, Microsoft::class, CalDav::class, IcsFeed::class],
            ]);
            $this->trigger(self::EVENT_REGISTER_PROVIDERS, $event);

            $this->providers = [];
            foreach ($event->types as $class) {
                if (is_subclass_of($class, ProviderInterface::class)) {
                    $this->providers[$class::handle()] = new $class();
                }
            }
        }

        return $this->providers;
    }

    public function get(string $handle): ?ProviderInterface
    {
        return $this->all()[$handle] ?? null;
    }
}
