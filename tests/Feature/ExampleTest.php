<?php

test('the home page sends visitors to the dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
