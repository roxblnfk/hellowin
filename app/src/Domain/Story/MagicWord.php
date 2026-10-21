<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * The word that earns the rapier (docs/spec/scenario.md, «Рапира»): «рапира» in any form, with some leeway for typos.
 * Only the server knows it — the client sends what the player typed and learns nothing but the verdict.
 *
 * The input is normalized — lowercase, Cyrillic spelled in Latin letters, nothing but `a-z` — and is right when it
 * starts with {@see STEM} (every case of the noun), or is at most {@see TYPOS} edits from {@see WORD}, or sounds the
 * same by `metaphone`. Shorter than {@see MIN_LENGTH} letters it is never right: `metaphone` hears «rpr» as the word.
 * One typo, not two: at two «пира», «рапа» and «рапорт» would pass, while «rapier» still does by its sound.
 */
final class MagicWord
{
    public const WORD = 'rapira';
    private const STEM = 'rapir';
    private const TYPOS = 1;
    private const MIN_LENGTH = 4;

    /** Cyrillic letters as they sound in Latin ones; the signs vanish. */
    private const LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function matches(string $text): bool
    {
        $word = self::normalize($text);
        if (\strlen($word) < self::MIN_LENGTH) {
            return false;
        }

        return \str_starts_with($word, self::STEM)
            || \levenshtein($word, self::WORD) <= self::TYPOS
            || \metaphone($word) === \metaphone(self::WORD);
    }

    /**
     * @return string Only `a-z`, possibly empty.
     */
    public static function normalize(string $text): string
    {
        $latin = \strtr(\mb_strtolower($text, 'UTF-8'), self::LATIN);

        return (string) \preg_replace('/[^a-z]+/', '', $latin);
    }
}
