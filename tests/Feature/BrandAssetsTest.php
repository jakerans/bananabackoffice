<?php

use App\Models\User;

/**
 * Collect every image and icon address a page points at within this site.
 *
 * @return list<string>
 */
function brandAssetPaths(string $html): array
{
    preg_match_all('/<(?:img|link)\b[^>]*\b(?:src|href)="([^"]+\.(?:png|ico|svg|jpg))"/i', $html, $matches);

    return collect($matches[1])
        ->map(fn (string $url) => parse_url($url, PHP_URL_PATH))
        ->unique()
        ->values()
        ->all();
}

test('the sign-in page shows the banana backoffice logo', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('images/backoffice-logo.png', escape: false)
        ->assertSee('alt="Banana Backoffice"', escape: false);
});

test('the application header shows the banana backoffice logo', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('images/backoffice-logo.png', escape: false)
        ->assertSee('alt="Banana Backoffice"', escape: false);
});

test('every logo and icon a page points at exists', function (Closure $visit) {
    $paths = brandAssetPaths($visit()->assertOk()->getContent());

    expect($paths)->toContain('/favicon.ico', '/apple-touch-icon.png', '/images/backoffice-logo.png');

    foreach ($paths as $path) {
        expect(is_file(public_path($path)))->toBeTrue("The page points at [{$path}], which does not exist.");
    }
})->with([
    'sign-in page' => [fn () => test()->get(route('login'))],
    'signed-in home page' => [fn () => test()->actingAs(User::factory()->create())->get(route('dashboard'))],
]);
