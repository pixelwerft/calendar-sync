<?php

namespace pixelwerft\calendarsync\web\assets\widgets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles and the script of the dashboard widgets.
 */
class WidgetsAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['widgets.css'];
        $this->js = ['widgets.js'];

        parent::init();
    }
}
