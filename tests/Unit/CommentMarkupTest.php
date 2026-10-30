<?php

use App\Support\CommentMarkup;

it('turns headings, bullets and numbered items into line formats', function () {
    expect(CommentMarkup::toBlocks("### Fixed:\n- One\n* Two\n1. First\n\nPlain"))->toBe([
        ['text' => 'Fixed:', 'attributes' => []],
        ['text' => "\n", 'attributes' => ['header' => 3]],
        ['text' => 'One', 'attributes' => []],
        ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
        ['text' => 'Two', 'attributes' => []],
        ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
        ['text' => 'First', 'attributes' => []],
        ['text' => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
        ['text' => "\n", 'attributes' => []],
        ['text' => 'Plain', 'attributes' => []],
        ['text' => "\n", 'attributes' => []],
    ]);
});

it('formats bold, code and links inline, leaving dollar amounts alone', function () {
    expect(CommentMarkup::toBlocks('**Res 83437**: $177.00 via `recalc` in [PR](https://github.com/x/pull/1)'))->toBe([
        ['text' => 'Res 83437', 'attributes' => ['bold' => true]],
        ['text' => ': $177.00 via ', 'attributes' => []],
        ['text' => 'recalc', 'attributes' => ['code' => true]],
        ['text' => ' in ', 'attributes' => []],
        ['text' => 'PR', 'attributes' => ['link' => 'https://github.com/x/pull/1']],
        ['text' => "\n", 'attributes' => []],
    ]);
});
