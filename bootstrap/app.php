<?php

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\RefuseAccountChangesWhileImpersonating;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In this order: decide who the request is served as, then check that person, then guard their sign-in details.
        $middleware->web(append: [
            ApplyImpersonation::class,
            EnsureStaffIsActive::class,
            RefuseAccountChangesWhileImpersonating::class,
        ]);

        // Both run after sign-in is checked and before a route looks up its records
        // or checks a permission, so nothing is ever worked out for the wrong person.
        $middleware->prependToPriorityList(SubstituteBindings::class, ApplyImpersonation::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RefuseAccountChangesWhileImpersonating::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
