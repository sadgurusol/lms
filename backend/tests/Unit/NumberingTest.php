<?php

use App\Services\Publishing\Numbering;

it('formats numeric positions', function () {
    expect(Numbering::format('numeric', 1))->toBe('1')
        ->and(Numbering::format('numeric', 42))->toBe('42');
});

it('formats roman numerals', function () {
    expect(Numbering::format('roman', 1))->toBe('I')
        ->and(Numbering::format('roman', 4))->toBe('IV')
        ->and(Numbering::format('roman', 9))->toBe('IX')
        ->and(Numbering::format('roman', 14))->toBe('XIV')
        ->and(Numbering::format('roman', 40))->toBe('XL')
        ->and(Numbering::format('roman', 1990))->toBe('MCMXC');
});

/** Spreadsheet columns, not modulo: 26 is Z and 27 is AA, never "[". */
it('formats alphabetic positions past Z', function () {
    expect(Numbering::format('alpha', 1))->toBe('A')
        ->and(Numbering::format('alpha', 26))->toBe('Z')
        ->and(Numbering::format('alpha', 27))->toBe('AA')
        ->and(Numbering::format('alpha', 52))->toBe('AZ')
        ->and(Numbering::format('alpha', 53))->toBe('BA');
});

it('yields no number when numbering is off', function () {
    expect(Numbering::format('none', 3))->toBe('');
});

it('applies the label template', function () {
    expect(Numbering::label('Chapter {n}: {title}', '3', 'Tenses'))->toBe('Chapter 3: Tenses')
        ->and(Numbering::label('Part {n}', 'IV', 'ignored'))->toBe('Part IV')
        ->and(Numbering::label('{n}. {title}', '2', 'Simple Past'))->toBe('2. Simple Past');
});

/**
 * A template of "{n}. {title}" with numbering off would otherwise render
 * ". Simple Past".
 */
it('collapses the separator when there is no number', function () {
    expect(Numbering::label('{n}. {title}', '', 'Simple Past'))->toBe('Simple Past')
        ->and(Numbering::label('{n}: {title}', '', 'Simple Past'))->toBe('Simple Past')
        ->and(Numbering::label('{n} - {title}', '', 'Simple Past'))->toBe('Simple Past');
});
