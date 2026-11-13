<?php

namespace pixelwerft\calendarsync\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles and the form script of the Calendar Sync pages.
 */
class CalendarSyncAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['calendar-sync.css'];
        $this->js = ['calendar-sync.js'];

        parent::init();
    }
}
