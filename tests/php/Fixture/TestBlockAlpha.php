<?php

namespace PurpleSpider\VisualBlockPicker\Tests\Fixture;

use DNADesign\Elemental\Models\BaseElement;

class TestBlockAlpha extends BaseElement
{
    private static $table_name = 'VisualBlockPickerTestBlockAlpha';

    private static $singular_name = 'Alpha Block';

    private static $class_description = 'The first test block.';

    private static $icon = 'font-icon-block-content';

    public function getType()
    {
        return 'Alpha';
    }
}
