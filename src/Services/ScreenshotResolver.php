<?php

namespace PurpleSpider\VisualBlockPicker\Services;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\View\SSViewer;

/**
 * Resolves a screenshot URL for a block class by filename convention.
 *
 * Drop `HeroBlock.png` into one of the configured screenshot directories and it will be
 * picked up automatically - no code or configuration required. Where two block classes in
 * different namespaces share a short name, the fully qualified name with backslashes
 * replaced by hyphens (`PurpleSpider-MySite-HeroBlock.png`) disambiguates them, and takes
 * precedence over the short-name file.
 */
class ScreenshotResolver
{
    use Injectable;
    use Configurable;

    /**
     * Directories to search for screenshots, relative to BASE_PATH. First match wins.
     *
     * @config
     * @var array
     */
    private static $screenshot_dirs = [];

    /**
     * File extensions to probe for, in order of preference.
     *
     * Named `screenshot_extensions` rather than `extensions` because `extensions` is
     * reserved: ExtensionMiddleware reads it as the class's Extension list.
     *
     * @config
     * @var array
     */
    private static $screenshot_extensions = [];

    /**
     * Map of basename (with extension) => absolute path, built once per request.
     *
     * @var array|null
     */
    protected $index = null;

    /**
     * Get the public URL of the screenshot for the given block class, or null if there
     * isn't one.
     *
     * @param string $class Fully qualified block class name
     * @return string|null
     */
    public function urlForClass(string $class): ?string
    {
        $index = $this->getIndex();

        if (!$index) {
            return null;
        }

        $extensions = (array) static::config()->get('screenshot_extensions');

        foreach ($this->candidateNames($class) as $name) {
            foreach ($extensions as $extension) {
                $key = $name . '.' . $extension;

                if (isset($index[$key])) {
                    return $this->urlForPath($index[$key]);
                }
            }
        }

        return null;
    }

    /**
     * The filename stems to look for, most specific first: the sanitised fully qualified
     * name, then the short class name.
     *
     * The order matters. Checking the short name first would mean a `HeroBlock.png` always
     * won, so `Other-Namespace-HeroBlock.png` could never be reached - and a short-name
     * collision is precisely the situation the fully qualified form exists to resolve.
     *
     * @param string $class
     * @return array
     */
    protected function candidateNames(string $class): array
    {
        $names = [str_replace('\\', '-', $class)];

        $position = strrpos($class, '\\');
        $short = $position === false ? $class : substr($class, $position + 1);

        // Identical for a class in the global namespace.
        if (!in_array($short, $names, true)) {
            $names[] = $short;
        }

        return $names;
    }

    /**
     * Build (and memoise) the basename => absolute path index across every search
     * directory. Earlier directories win.
     *
     * @return array
     */
    protected function getIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        $extensions = (array) static::config()->get('screenshot_extensions');
        $pattern = $extensions ? '*.{' . implode(',', $extensions) . '}' : '*';

        foreach ($this->searchDirectories() as $directory) {
            $absolute = BASE_PATH . DIRECTORY_SEPARATOR . $directory;

            if (!is_dir($absolute)) {
                continue;
            }

            $matches = glob($absolute . DIRECTORY_SEPARATOR . $pattern, GLOB_BRACE) ?: [];

            foreach ($matches as $match) {
                $basename = basename($match);

                // First directory to provide a given filename wins.
                if (!isset($index[$basename])) {
                    $index[$basename] = $match;
                }
            }
        }

        $this->index = $index;

        return $this->index;
    }

    /**
     * The BASE_PATH-relative directories to search: the configured list, followed by a
     * conventional `dist/images/blocks` inside each enabled theme.
     *
     * @return array
     */
    protected function searchDirectories(): array
    {
        $directories = [];

        foreach ((array) static::config()->get('screenshot_dirs') as $directory) {
            $directories[] = trim($directory, '/');
        }

        foreach ($this->themeDirectories() as $directory) {
            $directories[] = $directory;
        }

        return array_values(array_unique($directories));
    }

    /**
     * Conventional screenshot directories inside each configured theme.
     *
     * Reads the SSViewer theme config directly rather than calling SSViewer::get_themes():
     * inside the admin, LeftAndMain swaps the theme cascade to the admin themes, and the
     * `$default` set expands to every module in the manifest.
     *
     * @return array
     */
    protected function themeDirectories(): array
    {
        $directories = [];
        $themes = (array) Config::inst()->get(SSViewer::class, 'themes');

        foreach ($themes as $theme) {
            if (!is_string($theme) || $theme === '' || str_starts_with($theme, '$')) {
                continue;
            }

            $directories[] = 'themes/' . trim($theme, '/') . '/dist/images/blocks';
        }

        return $directories;
    }

    /**
     * Turn an absolute filesystem path into a public URL.
     *
     * @param string $path
     * @return string|null
     */
    protected function urlForPath(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $relative = ltrim(str_replace(BASE_PATH, '', $path), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        // The resource generator resolves against the public folder first, so a path under
        // public/ needs that prefix stripped. Anything else is a private path that expose
        // has symlinked into _resources/, which the generator handles itself.
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, strlen('public/'));
        }

        return ModuleResourceLoader::singleton()->resolveURL($relative) ?: null;
    }
}
