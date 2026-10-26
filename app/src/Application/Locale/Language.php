<?php

declare(strict_types=1);

namespace App\Application\Locale;

/**
 * A language the pages speak; the value is the translator locale and the `<html lang>` code.
 */
enum Language: string
{
    case Ru = 'ru';
    case En = 'en';

    public const DEFAULT = self::Ru;

    /**
     * A language from a query parameter or a cookie; anything but a known code is no choice.
     */
    public static function fromInput(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom(\strtolower(\trim($value))) : null;
    }

    /**
     * The first known language of an `Accept-Language` header by q-weight; equal weights keep the header order,
     * `q=0` refuses a language, a region (`en-GB`) counts as its language.
     */
    public static function fromAcceptLanguage(string $header): ?self
    {
        $best = null;
        $bestQ = 0.0;
        foreach (\explode(',', $header) as $part) {
            $params = \explode(';', $part);
            $language = self::tryFrom(\strtolower(\explode('-', \trim($params[0]), 2)[0]));
            if ($language === null) {
                continue;
            }

            $q = 1.0;
            foreach (\array_slice($params, 1) as $param) {
                $pair = \explode('=', $param, 2);
                if (\strtolower(\trim($pair[0])) === 'q' && isset($pair[1]) && \is_numeric(\trim($pair[1]))) {
                    $q = (float) \trim($pair[1]);
                }
            }

            if ($q > $bestQ) {
                $best = $language;
                $bestQ = $q;
            }
        }

        return $best;
    }
}
