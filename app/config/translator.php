<?php

declare(strict_types=1);

use App\Application\Locale\Language;

/**
 * The page translator: templates mark text as `[[Текст]]`, the Russian text is the key, `app/locale/en/messages.php`
 * holds the English. A key missing from a catalogue renders as is.
 *
 * @link https://spiral.dev/docs/advanced-i18n
 */
return [
    'locale' => Language::DEFAULT->value,
    'fallbackLocale' => Language::DEFAULT->value,
    'directory' => directory('locale'),
    'directories' => [],
    // Off: in debug mode the translator would write every missing key into the runtime cache
    'autoRegister' => false,
    'loaders' => [
        'php' => \Symfony\Component\Translation\Loader\PhpFileLoader::class,
    ],
    'dumpers' => [
        'php' => \Symfony\Component\Translation\Dumper\PhpFileDumper::class,
    ],
    // Every bundle, `view-{namespace}-{view}` of the Stempler `[[...]]` included, lands in one domain
    'domains' => [
        'messages' => ['*'],
    ],
];
