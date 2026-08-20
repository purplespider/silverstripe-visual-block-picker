<?php

namespace PurpleSpider\VisualBlockPicker\Tests;

use PurpleSpider\VisualBlockPicker\Services\BlockPickerConfigProvider;
use PurpleSpider\VisualBlockPicker\Tests\Fixture\TestBlockAlpha;
use PurpleSpider\VisualBlockPicker\Tests\Fixture\TestBlockBravo;
use PurpleSpider\VisualBlockPicker\Tests\Fixture\TestBlockCharlie;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

class BlockPickerConfigProviderTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        TestBlockAlpha::class,
        TestBlockBravo::class,
        TestBlockCharlie::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The payload cache outlives the process, so start every test from empty.
        BlockPickerConfigProvider::flush();

        Config::modify()->set(BlockPickerConfigProvider::class, 'groups', []);
        Config::modify()->set(BlockPickerConfigProvider::class, 'exclude_classes', []);
        Config::modify()->set(BlockPickerConfigProvider::class, 'ungrouped_title', 'Other');
        Config::modify()->set(BlockPickerConfigProvider::class, 'show_title_icons', false);

        // Set fixture descriptions explicitly rather than relying on the private statics
        // being picked up: whether the class manifest scans tests/ depends on how it was
        // last built, which would make these assertions depend on ambient state.
        Config::modify()->set(TestBlockAlpha::class, 'class_description', 'The first test block.');
        Config::modify()->set(TestBlockBravo::class, 'class_description', 'The second test block.');
        Config::modify()->set(TestBlockCharlie::class, 'class_description', null);
    }

    protected function tearDown(): void
    {
        BlockPickerConfigProvider::flush();
        parent::tearDown();
    }

    /**
     * The payload is cached, so drop it before every read or config changes won't be seen.
     */
    private function payload(): array
    {
        BlockPickerConfigProvider::flush();

        return BlockPickerConfigProvider::singleton()->getPayload();
    }

    private function configureGroups(array $groups): void
    {
        Config::modify()->set(BlockPickerConfigProvider::class, 'groups', $groups);
    }

    public function testEveryBlockClassAppearsInThePayload(): void
    {
        $blocks = $this->payload()['blocks'];

        $this->assertArrayHasKey(TestBlockAlpha::class, $blocks);
        $this->assertArrayHasKey(TestBlockBravo::class, $blocks);
        $this->assertArrayHasKey(TestBlockCharlie::class, $blocks);
    }

    public function testBaseElementItselfIsNotOffered(): void
    {
        $this->assertArrayNotHasKey(
            \DNADesign\Elemental\Models\BaseElement::class,
            $this->payload()['blocks']
        );
    }

    public function testDescriptionsComeFromClassDescription(): void
    {
        $blocks = $this->payload()['blocks'];

        $this->assertSame('The first test block.', $blocks[TestBlockAlpha::class]['description']);
    }

    public function testBlockWithoutADescriptionGetsNull(): void
    {
        $blocks = $this->payload()['blocks'];

        $this->assertNull($blocks[TestBlockCharlie::class]['description']);
    }

    public function testBlocksAreAssignedToTheirConfiguredGroup(): void
    {
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
            ['title' => 'Bravo group', 'blocks' => [TestBlockBravo::class]],
        ]);

        $blocks = $this->payload()['blocks'];

        $this->assertSame('Alpha group', $blocks[TestBlockAlpha::class]['group']);
        $this->assertSame('Bravo group', $blocks[TestBlockBravo::class]['group']);
    }

    public function testGroupsAreListedInConfiguredOrder(): void
    {
        $this->configureGroups([
            ['title' => 'Second', 'blocks' => [TestBlockBravo::class]],
            ['title' => 'First', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $groups = $this->payload()['groups'];

        $this->assertSame(['Second', 'First'], array_slice($groups, 0, 2));
    }

    public function testUnlistedBlocksFallIntoTheUngroupedGroup(): void
    {
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $payload = $this->payload();

        $this->assertSame('Other', $payload['blocks'][TestBlockBravo::class]['group']);
        $this->assertContains('Other', $payload['groups']);
    }

    public function testUngroupedGroupIsLastInTheList(): void
    {
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $groups = $this->payload()['groups'];

        $this->assertSame('Other', end($groups));
    }

    /**
     * With nothing grouped there is nothing for the fallback heading to distinguish, so the
     * picker should render one flat grid with no heading at all.
     */
    public function testUngroupedTitleIsSuppressedWhenNoGroupsAreConfigured(): void
    {
        $payload = $this->payload();

        $this->assertSame('', $payload['ungroupedTitle']);
        $this->assertSame([], $payload['groups']);
        $this->assertSame('', $payload['blocks'][TestBlockAlpha::class]['group']);
    }

    public function testUngroupedTitleAppliesOnceAnyGroupExists(): void
    {
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $this->assertSame('Other', $this->payload()['ungroupedTitle']);
    }

    public function testEmptyUngroupedTitleDropsTheHeadingButKeepsGroups(): void
    {
        Config::modify()->set(BlockPickerConfigProvider::class, 'ungrouped_title', '');
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $payload = $this->payload();

        $this->assertSame(['Alpha group'], $payload['groups']);
        $this->assertSame('', $payload['blocks'][TestBlockBravo::class]['group']);
    }

    public function testConfiguredBlockOrderIsRecorded(): void
    {
        $this->configureGroups([
            [
                'title' => 'Alpha group',
                'blocks' => [TestBlockBravo::class, TestBlockAlpha::class],
            ],
        ]);

        $blocks = $this->payload()['blocks'];

        $this->assertLessThan(
            $blocks[TestBlockAlpha::class]['order'],
            $blocks[TestBlockBravo::class]['order'],
            'Bravo is listed first, so it should sort before Alpha'
        );
    }

    public function testReversingConfigReversesOrder(): void
    {
        $this->configureGroups([
            [
                'title' => 'Alpha group',
                'blocks' => [TestBlockAlpha::class, TestBlockBravo::class],
            ],
        ]);

        $blocks = $this->payload()['blocks'];

        $this->assertLessThan(
            $blocks[TestBlockBravo::class]['order'],
            $blocks[TestBlockAlpha::class]['order']
        );
    }

    public function testUnlistedBlocksHaveNoOrder(): void
    {
        $this->configureGroups([
            ['title' => 'Alpha group', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $this->assertNull($this->payload()['blocks'][TestBlockBravo::class]['order']);
    }

    public function testFirstGroupToClaimABlockWins(): void
    {
        $this->configureGroups([
            ['title' => 'First', 'blocks' => [TestBlockAlpha::class]],
            ['title' => 'Second', 'blocks' => [TestBlockAlpha::class]],
        ]);

        $this->assertSame('First', $this->payload()['blocks'][TestBlockAlpha::class]['group']);
    }

    public function testExcludedClassesAreOmitted(): void
    {
        Config::modify()->set(
            BlockPickerConfigProvider::class,
            'exclude_classes',
            [TestBlockBravo::class]
        );

        $blocks = $this->payload()['blocks'];

        $this->assertArrayHasKey(TestBlockAlpha::class, $blocks);
        $this->assertArrayNotHasKey(TestBlockBravo::class, $blocks);
    }

    public function testGroupsWithoutATitleAreSkipped(): void
    {
        $this->configureGroups([
            ['blocks' => [TestBlockAlpha::class]],
            ['title' => 'Real', 'blocks' => [TestBlockBravo::class]],
        ]);

        $payload = $this->payload();

        $this->assertSame(['Real', 'Other'], $payload['groups']);
        $this->assertSame('Other', $payload['blocks'][TestBlockAlpha::class]['group']);
    }

    public function testTitleIconsAreOffByDefault(): void
    {
        $this->assertFalse($this->payload()['showTitleIcons']);
    }

    public function testTitleIconsCanBeEnabled(): void
    {
        Config::modify()->set(BlockPickerConfigProvider::class, 'show_title_icons', true);

        $this->assertTrue($this->payload()['showTitleIcons']);
    }

    public function testPayloadIsCachedBetweenCalls(): void
    {
        BlockPickerConfigProvider::flush();

        $first = BlockPickerConfigProvider::singleton()->getPayload();

        // Changing config without flushing should not be picked up.
        Config::modify()->set(BlockPickerConfigProvider::class, 'show_title_icons', true);

        $this->assertSame($first, BlockPickerConfigProvider::singleton()->getPayload());
        $this->assertTrue($this->payload()['showTitleIcons']);
    }

    /**
     * Descriptions resolve through _t(), and LeftAndMain sets the locale per CMS user, so
     * one locale must not be able to serve its payload to another. Proven by changing config
     * without flushing: the original locale keeps its cached copy, a different locale misses
     * the cache and rebuilds.
     */
    public function testCacheIsKeyedByLocale(): void
    {
        $original = i18n::get_locale();

        try {
            i18n::set_locale('en_US');
            BlockPickerConfigProvider::flush();
            $warmed = BlockPickerConfigProvider::singleton()->getPayload();
            $this->assertFalse($warmed['showTitleIcons']);

            Config::modify()->set(BlockPickerConfigProvider::class, 'show_title_icons', true);

            // Same locale, still cached.
            $this->assertFalse(
                BlockPickerConfigProvider::singleton()->getPayload()['showTitleIcons']
            );

            // Different locale, so a different key - rebuilds and sees the new config.
            i18n::set_locale('de_DE');
            $this->assertTrue(
                BlockPickerConfigProvider::singleton()->getPayload()['showTitleIcons']
            );
        } finally {
            i18n::set_locale($original);
        }
    }
}
