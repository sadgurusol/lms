<?php

namespace App\Services\Publishing;

/**
 * Turns a 1-based sibling position into the label the schema asked for.
 *
 * Computed once, at publish, and frozen into the snapshot. If the client
 * derived "Chapter 3" itself, two clients holding different publications — or
 * one mid-sync — would disagree about which chapter a learner is on.
 */
final class Numbering
{
    public static function format(string $style, int $position): string
    {
        return match ($style) {
            'numeric' => (string) $position,
            'roman' => self::roman($position),
            'alpha' => self::alpha($position),
            'none' => '',
            default => (string) $position,
        };
    }

    /**
     * Applies `label_template`, e.g. "Chapter {n}: {title}".
     *
     * With `numbering_style: none` the {n} placeholder resolves to empty, so a
     * template of "{n}. {title}" would render ". Title". Collapse that.
     */
    public static function label(string $template, string $number, string $title): string
    {
        $label = str_replace(['{n}', '{title}'], [$number, $title], $template);

        if ($number === '') {
            $label = preg_replace('/^\s*[.:)\-]?\s*/', '', $label) ?? $label;
        }

        return trim($label);
    }

    private static function roman(int $n): string
    {
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];

        $out = '';
        foreach ($map as $value => $numeral) {
            while ($n >= $value) {
                $out .= $numeral;
                $n -= $value;
            }
        }

        return $out;
    }

    /** 1 => A, 26 => Z, 27 => AA — spreadsheet columns, not modulo. */
    private static function alpha(int $n): string
    {
        $out = '';
        while ($n > 0) {
            $n--;
            $out = chr(65 + ($n % 26)).$out;
            $n = intdiv($n, 26);
        }

        return $out;
    }
}
