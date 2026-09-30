<?php

declare(strict_types=1);

use Reactor\Context;
use Reactor\Group;
use Tests\Fixtures\AuthMiddleware;
use Tests\Fixtures\InvokableMiddleware;
use Tests\Fixtures\ThrottleMiddleware;

function wrapWith(string $label): Closure
{
    return fn(Context $c, Closure $next) => "$label(" . $next($c) . ')';
}

it('runs global, group and listener middleware in that order', function () {
    $d = makeDispatcher();
    $d->middleware(wrapWith('global'));
    $d->group(['middleware' => wrapWith('group')], function (Group $g) {
        $g->on(['a' => 1], fn() => 'core')->middleware(wrapWith('own'));
    });

    expect($d->dispatch(['a' => 1]))->toBe('global(group(own(core)))');
});

it('wraps the fallback with global middleware only', function () {
    $d = makeDispatcher();
    $d->middleware(wrapWith('global'));
    $d->on(['a' => 1], fn() => 'core')->middleware(wrapWith('own'));
    $d->fallback(fn() => 'fallback');

    expect($d->dispatch(['a' => 2]))->toBe('global(fallback)');
});

it('lets middleware short-circuit without calling the handler', function () {
    $called = false;
    $d = makeDispatcher();
    $d->on(['a' => 1], function () use (&$called) {
        $called = true;
        return 'core';
    })->middleware(fn(Context $c, Closure $next) => 'denied');

    expect($d->dispatch(['a' => 1]))->toBe('denied');
    expect($called)->toBeFalse();
});

it('continues to the next listener when middleware blocks with false', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'first')->priority(2)->middleware(fn(Context $c, Closure $next) => false);
    $d->on(['a' => 1], fn() => 'second')->priority(1);

    expect($d->dispatch(['a' => 1]))->toBe('second');
});

it('lets middleware modify the context passed down', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn(Context $c) => $c->get('extra'))
        ->middleware(fn(Context $c, Closure $next) => $next(new Context(['a' => 1, 'extra' => 'added'])));

    expect($d->dispatch(['a' => 1]))->toBe('added');
});

it('resolves aliases to closures', function () {
    $d = makeDispatcher();
    $d->alias('wrap', wrapWith('w'));
    $d->on(['a' => 1], fn() => 'core')->middleware('wrap');

    expect($d->dispatch(['a' => 1]))->toBe('w(core)');
});

it('resolves aliases and raw class names to handle() objects', function () {
    $d = makeDispatcher();
    $d->alias('auth', AuthMiddleware::class);
    $d->on(['a' => 1], fn() => 'core')->middleware('auth');
    $d->on(['a' => 2], fn() => 'core')->middleware(AuthMiddleware::class);

    expect($d->dispatch(['a' => 1]))->toBe('auth(user):core');
    expect($d->dispatch(['a' => 2]))->toBe('auth(user):core');
});

it('supports invokable middleware objects', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'core')->middleware(new InvokableMiddleware());

    expect($d->dispatch(['a' => 1]))->toBe('inv:core');
});

it('passes string parameters, trimmed and cast to the declared types', function () {
    $d = makeDispatcher();
    $d->alias('auth', AuthMiddleware::class);
    $d->alias('throttle', ThrottleMiddleware::class);
    $d->on(['a' => 1], fn() => 'core')->middleware('auth:admin', 'throttle: 3 , 100');

    expect($d->dispatch(['a' => 1]))->toBe('auth(admin):throttle(3,100):core');
});

it('ignores an empty parameter list', function () {
    $d = makeDispatcher();
    $d->alias('auth', AuthMiddleware::class);
    $d->on(['a' => 1], fn() => 'core')->middleware('auth:');

    expect($d->dispatch(['a' => 1]))->toBe('auth(user):core');
});

it('casts variadic middleware parameters', function () {
    $d = makeDispatcher();
    $d->alias('roles', fn(Context $c, Closure $next, string ...$roles) => implode('+', $roles) . ':' . $next($c));
    $d->on(['a' => 1], fn() => 'core')->middleware('roles:admin,mod,editor');

    expect($d->dispatch(['a' => 1]))->toBe('admin+mod+editor:core');
});

it('flattens array middleware definitions', function () {
    $d = makeDispatcher();
    $d->middleware([wrapWith('g1'), wrapWith('g2')]);
    $d->on(['a' => 1], fn() => 'core')->middleware([wrapWith('l1'), wrapWith('l2')]);

    expect($d->dispatch(['a' => 1]))->toBe('g1(g2(l1(l2(core))))');
});

it('throws for unknown or invalid middleware', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'core')->middleware('no-such-alias');
    $d->on(['a' => 2], fn() => 'core')->middleware(123);

    expect(fn() => $d->dispatch(['a' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => $d->dispatch(['a' => 2]))->toThrow(InvalidArgumentException::class);
});
