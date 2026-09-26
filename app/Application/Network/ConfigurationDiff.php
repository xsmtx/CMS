<?php

declare(strict_types=1);

namespace App\Application\Network;

/**
 * What changed, as lines an operator reads before agreeing to it.
 *
 * A unified diff written here rather than shelled out to `diff(1)`: the
 * platform runs on whatever PHP is available and a feature that worked on the
 * maintainer's machine and not on a customer's would be worse than none. The
 * algorithm is the ordinary longest-common-subsequence one, bounded — see
 * below.
 *
 * **Bounded on purpose.** LCS is O(n·m) in time and memory, and a device
 * configuration is thousands of lines: two eight-thousand-line configurations
 * would be sixty-four million cells. Above the cap this answers a summary
 * instead of a diff, because a screen that hung for a minute and then printed
 * six thousand lines nobody would read is worse than one that says how many
 * lines moved and offers the two configurations.
 *
 * **Line endings are normalised first**, for the same reason
 * `DeviceConfiguration::fingerprint()` does it: a device that answers `\r\n`
 * over one transport and `\n` over another is the same configuration twice,
 * and a diff that said every line had changed would be a diff nobody could
 * read past.
 */
final readonly class ConfigurationDiff
{
    /**
     * Lines per side, above which a summary replaces the diff.
     *
     * Two thousand is a large device configuration and four million cells is
     * a fraction of a second; the cap exists for the configuration that is
     * ten times that, not for the ordinary one.
     */
    private const int MaxLines = 2_000;

    public static function between(string $before, string $after): string
    {
        $old = self::lines($before);
        $new = self::lines($after);

        if (count($old) > self::MaxLines || count($new) > self::MaxLines) {
            return self::summary($old, $new);
        }

        $common = self::commonSubsequence($old, $new);

        $out = [];
        $i = 0;
        $j = 0;

        foreach ($common as $line) {
            while ($i < count($old) && $old[$i] !== $line) {
                $out[] = '-'.$old[$i++];
            }

            while ($j < count($new) && $new[$j] !== $line) {
                $out[] = '+'.$new[$j++];
            }

            $out[] = ' '.$line;
            $i++;
            $j++;
        }

        while ($i < count($old)) {
            $out[] = '-'.$old[$i++];
        }

        while ($j < count($new)) {
            $out[] = '+'.$new[$j++];
        }

        return implode("\n", $out);
    }

    /** Whether anything at all differs, without building the diff. */
    public static function differ(string $before, string $after): bool
    {
        return self::lines($before) !== self::lines($after);
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    private static function summary(array $old, array $new): string
    {
        $removed = count(array_diff($old, $new));
        $added = count(array_diff($new, $old));

        return sprintf(
            '# %d lines before, %d after: %d removed, %d added. '
            .'Too large to show line by line.',
            count($old),
            count($new),
            $removed,
            $added,
        );
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);

        return explode("\n", rtrim($normalised, "\n"));
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<string>
     */
    private static function commonSubsequence(array $old, array $new): array
    {
        $rows = count($old);
        $columns = count($new);

        /** @var array<int, array<int, int>> $lengths */
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $common = [];
        $i = 0;
        $j = 0;

        while ($i < $rows && $j < $columns) {
            if ($old[$i] === $new[$j]) {
                $common[] = $old[$i];
                $i++;
                $j++;

                continue;
            }

            if ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $common;
    }
}
