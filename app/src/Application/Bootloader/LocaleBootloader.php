<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Bootloader\I18nBootloader;
use Spiral\Bootloader\Views\TranslatedCacheBootloader;
use Spiral\Translator\Catalogue\CacheInterface;
use Spiral\Translator\Catalogue\NullCache;

/**
 * The translator for the `[[...]]` of the templates; the view cache varies by its locale.
 */
final class LocaleBootloader extends Bootloader
{
    protected const DEPENDENCIES = [
        I18nBootloader::class,
        TranslatedCacheBootloader::class,
    ];

    /**
     * Catalogues are read from `app/locale` once per worker and kept in its memory. Not the framework's file cache in
     * `runtime/cache/i18n.*`: it would outlive a catalogue edit, while a new worker always reads the fresh files.
     */
    protected const SINGLETONS = [
        CacheInterface::class => NullCache::class,
    ];
}
