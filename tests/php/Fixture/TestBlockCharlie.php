<?php

namespace PurpleSpider\VisualBlockPicker\Tests\Fixture;

use DNADesign\Elemental\Models\BaseElement;

/**
 * Deliberately declares no class_description, to cover the "block without a description"
 * path through the payload builder.
 */
class TestBlockCharlie extends BaseElement
{
    private static $table_name = 'VisualBlockPickerTestBlockCharlie';

    private static $singular_name = 'Charlie Block';

    private static $icon = 'font-icon-block-content';

    public function getType()
    {
        return 'Charlie';
    }
}
