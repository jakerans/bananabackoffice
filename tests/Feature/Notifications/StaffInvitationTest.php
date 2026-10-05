<?php

use App\Models\User;
use App\Notifications\StaffInvitation;

test('the invitation email links to the accept invitation screen', function () {
    $staff = User::factory()->invited()->make(['email' => 'sam@example.com']);

    $content = (string) (new StaffInvitation('the-token'))->toMail($staff)->render();

    expect($content)
        ->toContain('Set your password')
        ->toContain('invitation/the-token')
        ->toContain('expires in 72 hours');
});

test('the invitation email escapes the staff member\'s name', function () {
    $staff = User::factory()->invited()->make(['name' => "O'Brien <script>alert('xss')</script>"]);

    $content = (string) (new StaffInvitation('the-token'))->toMail($staff)->render();

    expect($content)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>");
});
