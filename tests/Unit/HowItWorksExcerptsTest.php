<?php

declare(strict_types=1);

namespace Tests\Unit;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The code on the «How it works» page is quoted from the sources: a changed method fails here, not silently on the page.
 * An excerpt is a `<figure class="code">` whose caption starts with the quoted file; `# …` and `// …` lines mark cuts.
 */
#[Test]
final class HowItWorksExcerptsTest
{
    private const ROOT = __DIR__ . '/../..';
    private const CUT = ['# …', '// …'];

    #[DataSet(['how-it-works.dark.php'], 'ru')]
    #[DataSet(['how-it-works.en.dark.php'], 'en')]
    public function everyExcerptLineIsInItsSourceInOrder(string $view): void
    {
        $excerpts = $this->excerpts($view);
        Assert::true(\count($excerpts) >= 13, "$view: the excerpts are not found, has the markup changed?");

        foreach ($excerpts as [$path, $code]) {
            $source = self::ROOT . '/' . $path;
            Assert::true(\is_file($source), "$view quotes $path, which does not exist");

            $lines = \array_map(\trim(...), \file($source, \FILE_IGNORE_NEW_LINES));
            $at = -1;
            foreach (\explode("\n", $code) as $line) {
                $line = \trim($line);
                if ($line === '' || \in_array($line, self::CUT, true)) {
                    continue;
                }

                $found = \array_search($line, \array_slice($lines, $at + 1, preserve_keys: true), true);
                Assert::true($found !== false, "$view quotes from $path a line that is not there (or out of order): $line");
                $at = $found;
            }
        }
    }

    /**
     * @return list<array{string, string}> The quoted file and the decoded code.
     */
    private function excerpts(string $view): array
    {
        \preg_match_all(
            '~<figure class="code">\s*<figcaption><code>([^<]+)</code>.*?</figcaption>\s*<pre><code>(.*?)</code></pre>~s',
            \file_get_contents(self::ROOT . '/app/views/' . $view),
            $matches,
            \PREG_SET_ORDER,
        );

        return \array_map(
            static fn(array $match): array => [$match[1], \html_entity_decode($match[2], \ENT_QUOTES | \ENT_HTML5)],
            $matches,
        );
    }
}
