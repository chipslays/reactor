<?php

declare(strict_types=1);

use Reactor\Context;
use Reactor\Group;
use Reactor\Listener;

it('reads attributes from the constructor', function () {
    $g = new Group(makeDispatcher(), [
        'middleware' => ['auth', 'log'],
        'priority' => '7',
        'conditions' => ['chat.type' => 'private'],
    ]);

    expect($g->getMiddleware())->toBe(['auth', 'log']);
    expect($g->getPriority())->toBe(7);
    expect($g->getConditions())->toBe([['chat.type' => 'private']]);
});

it('wraps a single middleware value', function () {
    $g = new Group(makeDispatcher(), ['middleware' => 'auth']);

    expect($g->getMiddleware())->toBe(['auth']);
});

it('has empty defaults', function () {
    $g = new Group(makeDispatcher());

    expect($g->getMiddleware())->toBe([]);
    expect($g->getPriority())->toBeNull();
    expect($g->getConditions())->toBe([]);
});

it('resolves attributes through the parent chain', function () {
    $d = makeDispatcher();
    $parent = new Group($d, ['middleware' => 'p', 'priority' => 5, 'conditions' => ['a' => 1]]);
    $child = new Group($d, ['middleware' => 'c', 'conditions' => ['b' => 2]], $parent);

    expect($child->getMiddleware())->toBe(['p', 'c']);
    expect($child->getPriority())->toBe(5);
    expect($child->getConditions())->toBe([['a' => 1], ['b' => 2]]);
});

it('lets a child override the priority', function () {
    $d = makeDispatcher();
    $parent = new Group($d, ['priority' => 5]);
    $child = new Group($d, ['priority' => 1], $parent);

    expect($child->getPriority())->toBe(1);
    expect($parent->getPriority())->toBe(5);
});

it('reflects later parent changes in children', function () {
    $d = makeDispatcher();
    $parent = new Group($d);
    $child = new Group($d, [], $parent);

    $parent->middleware('late')->priority(9)->scope(['x' => 1]);

    expect($child->getMiddleware())->toBe(['late']);
    expect($child->getPriority())->toBe(9);
    expect($child->getConditions())->toBe([['x' => 1]]);
});

it('flattens array middleware added later', function () {
    $g = (new Group(makeDispatcher()))->middleware(['a', 'b'], 'c');

    expect($g->getMiddleware())->toBe(['a', 'b', 'c']);
});

it('accepts closure scopes', function () {
    $closure = fn() => true;
    $g = (new Group(makeDispatcher()))->scope($closure);

    expect($g->getConditions())->toBe([$closure]);
});

it('registers listeners in the dispatcher and links them to the group', function () {
    $d = makeDispatcher();
    $d->alias('mw', fn(Context $c, Closure $next) => $next($c));
    $g = new Group($d, ['priority' => 3, 'middleware' => 'mw']);

    $l = $g->on(['a' => 1], fn() => 'ok');

    expect($l)->toBeInstanceOf(Listener::class);
    expect($l->getPriority())->toBe(3);
    expect($l->getMiddleware())->toBe(['mw']);
    expect($d->dispatch(['a' => 1]))->toBe('ok');
});

it('propagates group changes to already registered listeners', function () {
    $g = new Group(makeDispatcher());
    $l = $g->on(['a' => 1], fn() => 'ok');

    $g->middleware('late')->priority(4);

    expect($l->getMiddleware())->toBe(['late']);
    expect($l->getPriority())->toBe(4);
});

it('puts group middleware before listener middleware', function () {
    $g = new Group(makeDispatcher(), ['middleware' => 'group']);
    $l = $g->on(['a' => 1], fn() => 1)->middleware('own');

    expect($l->getMiddleware())->toBe(['group', 'own']);
});

it('keeps an explicit listener priority over the group priority', function () {
    $g = new Group(makeDispatcher(), ['priority' => 10]);
    $l = $g->on(['a' => 1], fn() => 1)->priority(2);

    $g->priority(99);

    expect($l->getPriority())->toBe(2);
});
