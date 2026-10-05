<?php

use App\Support\Contact\PhoneNumber;

test('a phone number typed in different ways normalizes to one matching form', function (string $typed) {
    expect(PhoneNumber::normalize($typed))->toBe('+15552013344');
})->with([
    'digits only' => ['5552013344'],
    'the usual way' => ['(555) 201-3344'],
    'dashes' => ['555-201-3344'],
    'dots' => ['555.201.3344'],
    'spaces around it' => ['  555 201 3344  '],
    'with the country code' => ['1 555 201 3344'],
    'international notation' => ['+1 (555) 201-3344'],
    'with an extension' => ['555-201-3344 ext. 12'],
    'with a short extension' => ['(555) 201-3344 x7'],
]);

test('a number from outside the united states is kept in international form', function () {
    expect(PhoneNumber::normalize('+44 20 7946 0958'))->toBe('+442079460958');
});

test('input that is not a recognizable phone number has no matching form', function (?string $typed) {
    expect(PhoneNumber::normalize($typed))->toBeNull();
})->with([
    'nothing' => [null],
    'blank' => ['   '],
    'too short' => ['201-3344'],
    'too long without a country code' => ['555 201 3344 99'],
    'eleven digits not starting with one' => ['2 555 201 3344'],
    'words' => ['call the office'],
    'international but too short' => ['+44 20'],
]);

test('a recognized number is shown the usual way and anything else as it was typed', function (?string $typed, ?string $expected) {
    expect(PhoneNumber::display($typed, PhoneNumber::normalize($typed)))->toBe($expected);
})->with([
    'plain digits' => ['5552013344', '(555) 201-3344'],
    'with an extension' => ['555-201-3344 ext. 12', '(555) 201-3344 ext. 12'],
    'unrecognized' => ['201-3344 ask for Sam', '201-3344 ask for Sam'],
    'international' => ['+44 20 7946 0958', '+44 20 7946 0958'],
    'nothing' => [null, null],
    'blank' => ['  ', null],
]);
