<?php

namespace PurpleSpider\VisualBlockPicker\Extensions;

use PurpleSpider\VisualBlockPicker\Services\BlockPickerConfigProvider;
use SilverStripe\Core\Extension;

/**
 * Adds the `visualBlockPicker` key to the CMS client config.
 *
 * This can't decorate elemental's own `elementTypes` payload: the updateClientConfig hook
 * fires inside LeftAndMain::getClientConfig(), which ElementalAreaController calls *before*
 * it appends `elementTypes`. So we ship a sibling key that the JS joins on the class name.
 *
 * @extends Extension<\DNADesign\Elemental\Controllers\ElementalAreaController>
 */
class ElementalAreaControllerExtension extends Extension
{
    /**
     * @param array $clientConfig
     * @return void
     */
    public function updateClientConfig(&$clientConfig)
    {
        $clientConfig['visualBlockPicker'] = BlockPickerConfigProvider::singleton()->getPayload();
    }
}
