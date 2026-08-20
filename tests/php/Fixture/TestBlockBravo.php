<?php

namespace PurpleSpider\VisualBlockPicker\Tests\Fixture;

use DNADesign\Elemental\Models\BaseElement;

class TestBlockBravo extends BaseElement
{
    private static $table_name = 'VisualBlockPickerTestBlockBravo';

    private static $singular_name = 'Bravo Block';

    private static $class_description = 'The second test block.';

    private static $icon = 'font-icon-block-content';

    public function getType()
    {
        return 'Bravo';
    }
}
