<?php

namespace PurpleSpider\VisualBlockPicker\Tests;

use PurpleSpider\VisualBlockPicker\Services\ScreenshotResolver;
use PurpleSpider\VisualBlockPicker\Tests\Fixture\TestBlockAlpha;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class ScreenshotResolverTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * BASE_PATH-relative scratch directory for fixture images.
     */
    private const TEST_DIR = 'silverstripe-cache/visual-block-picker-tests';

    private const SECOND_DIR = 'silverstripe-cache/visual-block-picker-tests-2';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TEST_DIR, self::SECOND_DIR] as $dir) {
            $absolute = BASE_PATH . '/' . $dir;

            if (!is_dir($absolute)) {
                mkdir($absolute, 0o777, true);
            }
        }

        Config::modify()->set(ScreenshotResolver::class, 'screenshot_dirs', [self::TEST_DIR]);
        Config::modify()->set(
            ScreenshotResolver::class,
            'screenshot_extensions',
            ['webp', 'png', 'jpg']
        );
    }

    protected function tearDown(): void
    {
        foreach ([self::TEST_DIR, self::SECOND_DIR] as $dir) {
            foreach (glob(BASE_PATH . '/' . $dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir(BASE_PATH . '/' . $dir);
        }

        parent::tearDown();
    }

    private function writeFixture(string $filename, string $dir = self::TEST_DIR): void
    {
        file_put_contents(BASE_PATH . '/' . $dir . '/' . $filename, 'not-really-an-image');
    }

    /**
     * A fresh instance each time - the index is memoised per instance.
     */
    private function resolver(): ScreenshotResolver
    {
        return ScreenshotResolver::create();
    }

    public function testReturnsNullWhenNoScreenshotExists(): void
    {
        $this->assertNull($this->resolver()->urlForClass(TestBlockAlpha::class));
    }

    public function testFindsScreenshotByShortClassName(): void
    {
        $this->writeFixture('TestBlockAlpha.png');

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertNotNull($url);
        $this->assertStringContainsString('TestBlockAlpha.png', $url);
    }

    public function testFindsScreenshotByHyphenatedFullyQualifiedName(): void
    {
        $this->writeFixture('PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.png');

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertNotNull($url);
        $this->assertStringContainsString(
            'PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.png',
            $url
        );
    }

    /**
     * The whole point of supporting the fully qualified filename is disambiguating two
     * classes that share a short name, so it has to win when both files are present.
     */
    public function testFullyQualifiedNameTakesPrecedenceOverShortName(): void
    {
        $this->writeFixture('TestBlockAlpha.png');
        $this->writeFixture('PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.png');

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertStringContainsString(
            'PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.png',
            $url
        );
    }

    /**
     * Extension preference applies within a filename, so a more specific filename beats a
     * more preferred extension.
     */
    public function testExtensionPreferenceIsAppliedWithinAName(): void
    {
        $this->writeFixture('TestBlockAlpha.png');
        $this->writeFixture('TestBlockAlpha.webp');

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertStringContainsString('TestBlockAlpha.webp', $url);
    }

    public function testFullyQualifiedNameWinsEvenWithLaterExtension(): void
    {
        $this->writeFixture('TestBlockAlpha.webp');
        $this->writeFixture('PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.jpg');

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertStringContainsString(
            'PurpleSpider-VisualBlockPicker-Tests-Fixture-TestBlockAlpha.jpg',
            $url
        );
    }

    public function testUnconfiguredExtensionIsIgnored(): void
    {
        $this->writeFixture('TestBlockAlpha.gif');

        $this->assertNull($this->resolver()->urlForClass(TestBlockAlpha::class));
    }

    public function testFirstConfiguredDirectoryWins(): void
    {
        Config::modify()->set(
            ScreenshotResolver::class,
            'screenshot_dirs',
            [self::TEST_DIR, self::SECOND_DIR]
        );

        $this->writeFixture('TestBlockAlpha.png', self::TEST_DIR);
        $this->writeFixture('TestBlockAlpha.png', self::SECOND_DIR);

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertStringContainsString(self::TEST_DIR, $url);
        $this->assertStringNotContainsString(self::SECOND_DIR, $url);
    }

    public function testLaterDirectoryUsedWhenFirstHasNoMatch(): void
    {
        Config::modify()->set(
            ScreenshotResolver::class,
            'screenshot_dirs',
            [self::TEST_DIR, self::SECOND_DIR]
        );

        $this->writeFixture('TestBlockAlpha.png', self::SECOND_DIR);

        $url = $this->resolver()->urlForClass(TestBlockAlpha::class);

        $this->assertStringContainsString(self::SECOND_DIR, $url);
    }
}
