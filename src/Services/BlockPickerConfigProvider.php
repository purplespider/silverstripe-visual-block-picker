<?php

namespace PurpleSpider\VisualBlockPicker\Services;

use DNADesign\Elemental\Models\BaseElement;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;

/**
 * Builds the `visualBlockPicker` client config payload: for every block class, its
 * screenshot URL, description and group title, plus the ordered list of group titles.
 *
 * The payload is cached, because LeftAndMain::getCombinedClientConfig() instantiates every
 * LeftAndMain subclass and calls getClientConfig() on each one for every CMS page load.
 */
class BlockPickerConfigProvider implements Flushable
{
    use Injectable;
    use Configurable;

    /**
     * Ordered list of groups, each `['title' => 'Media', 'blocks' => ['My\Block', ...]]`.
     *
     * @config
     * @var array
     */
    private static $groups = [];

    /**
     * Heading for blocks that aren't listed in any group. Set to an empty string to render
     * ungrouped blocks without a heading.
     *
     * @config
     * @var string
     */
    private static $ungrouped_title = 'Other';

    /**
     * Block classes to omit from the picker entirely.
     *
     * @config
     * @var array
     */
    private static $exclude_classes = [];

    /**
     * Show each block's elemental icon next to its name on the card.
     *
     * Off by default: the screenshot already identifies the block, so the icon is mostly
     * redundant next to the title. This does not affect the icon-only tile shown for blocks
     * that have no screenshot - that one is the fallback and is always rendered.
     *
     * @config
     * @var bool
     */
    private static $show_title_icons = false;

    /**
     * Rebuild the payload on ?flush.
     *
     * Caches built through CacheFactory aren't cleared automatically, so adding a screenshot
     * or changing a group would otherwise have no visible effect.
     *
     * @return void
     */
    public static function flush()
    {
        static::singleton()->getCache()->clear();
    }

    /**
     * @return array
     */
    public function getPayload(): array
    {
        $cache = $this->getCache();
        $key = $this->cacheKey();
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->buildPayload();
        $cache->set($key, $payload);

        return $payload;
    }

    /**
     * Cache key for the built payload.
     *
     * The payload embeds descriptions resolved through _t(), and LeftAndMain::init() sets
     * the i18n locale from the logged-in member, so the CMS locale varies per user. Keying
     * on the locale stops whichever user warms the cache first from freezing their language
     * in for everyone else.
     *
     * @return string
     */
    protected function cacheKey(): string
    {
        // PSR-16 reserves {}()/\@: in keys; locales don't use them, but don't rely on that.
        $locale = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) i18n::get_locale());

        return 'payload_' . $locale;
    }

    /**
     * @return array
     */
    protected function buildPayload(): array
    {
        $resolver = ScreenshotResolver::singleton();
        $groupTitles = [];
        $groupForClass = [];
        $orderForClass = [];
        $position = 0;

        foreach ((array) static::config()->get('groups') as $group) {
            $title = $group['title'] ?? null;

            if (!$title) {
                continue;
            }

            if (!in_array($title, $groupTitles, true)) {
                $groupTitles[] = $title;
            }

            foreach ((array) ($group['blocks'] ?? []) as $class) {
                // First group to claim a class wins.
                if (!isset($groupForClass[$class])) {
                    $groupForClass[$class] = $title;
                    // Cards render in the order they're listed here, so the most-reached-for
                    // block in a group can lead it. Sorting happens per group in the JS, so a
                    // single running counter is enough.
                    $orderForClass[$class] = $position++;
                }
            }
        }

        // The fallback heading only earns its place when there's something to fall back
        // *from*. With no groups configured every block is ungrouped, so the heading would
        // just be a label over the entire list - drop it and render one flat grid.
        $ungroupedTitle = $groupTitles
            ? (string) static::config()->get('ungrouped_title')
            : '';

        $excluded = (array) static::config()->get('exclude_classes');
        $hasUngrouped = false;
        $blocks = [];

        foreach ($this->blockClasses() as $class) {
            if (in_array($class, $excluded, true)) {
                continue;
            }

            $group = $groupForClass[$class] ?? $ungroupedTitle;

            if (!isset($groupForClass[$class])) {
                $hasUngrouped = true;
            }

            $blocks[$class] = [
                'screenshot' => $resolver->urlForClass($class),
                'description' => $this->descriptionFor($class),
                'group' => $group,
                'order' => $orderForClass[$class] ?? null,
            ];
        }

        if ($hasUngrouped && $ungroupedTitle !== '' && !in_array($ungroupedTitle, $groupTitles, true)) {
            $groupTitles[] = $ungroupedTitle;
        }

        return [
            'blocks' => $blocks,
            'groups' => $groupTitles,
            'ungroupedTitle' => $ungroupedTitle,
            'showTitleIcons' => (bool) static::config()->get('show_title_icons'),
        ];
    }

    /**
     * Every concrete block class, excluding BaseElement itself.
     *
     * ElementTypeRegistry::generate() would be the obvious source, but its result is never
     * memoised, so each call re-runs a full class scan and a getCMSFields() per class.
     *
     * @return array
     */
    protected function blockClasses(): array
    {
        // subclassesFor() returns an array keyed by lowercased class name, so take values.
        $classes = array_values(ClassInfo::subclassesFor(BaseElement::class, false));

        return array_values(array_filter($classes, function ($class) {
            return !(new ReflectionClass($class))->isAbstract();
        }));
    }

    /**
     * The block's description, or null if it doesn't declare one.
     *
     * i18n_classDescription() reads `class_description` without inheritance, so blocks that
     * don't set one return null rather than inheriting BaseElement's own description.
     *
     * @param string $class
     * @return string|null
     */
    protected function descriptionFor(string $class): ?string
    {
        $description = singleton($class)->i18n_classDescription();

        return $description ? (string) $description : null;
    }

    /**
     * @return CacheInterface
     */
    protected function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.visualBlockPicker');
    }
}
