<?php

declare(strict_types=1);

use Reactor\Context;
use Reactor\Dispatcher;
use Reactor\Group;
use Reactor\Listener;

it('runs higher priority listeners first and keeps registration order for ties', function () {
    $order = [];
    $d = makeDispatcher();
    $d->on(['a' => 1], function () use (&$order) { $order[] = 'low'; return false; })->priority(1);
    $d->on(['a' => 1], function () use (&$order) { $order[] = 'tie1'; return false; });
    $d->on(['a' => 1], function () use (&$order) { $order[] = 'high'; return false; })->priority(10);
    $d->on(['a' => 1], function () use (&$order) { $order[] = 'tie2'; return false; });

    $d->dispatch(['a' => 1]);

    expect($order)->toBe(['high', 'low', 'tie1', 'tie2']);
});

it('returns the first non-false result and skips the rest', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'first')->priority(2);
    $d->on(['a' => 1], fn() => 'second')->priority(1);

    expect($d->dispatch(['a' => 1]))->toBe('first');
});

it('treats false as "not handled" and continues the chain', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => false)->priority(2);
    $d->on(['a' => 1], fn() => 'second')->priority(1);

    expect($d->dispatch(['a' => 1]))->toBe('second');
});

it('treats null and other falsy values as handled', function () {
    foreach ([null, 0, '', []] as $value) {
        $d = makeDispatcher();
        $d->on(['a' => 1], fn() => $value)->priority(2);
        $d->on(['a' => 1], fn() => 'second')->priority(1);

        expect($d->dispatch(['a' => 1]))->toBe($value);
    }
});

it('stops the chain after a stopped listener even if it returns false', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => false)->priority(2)->stop();
    $d->on(['a' => 1], fn() => 'second')->priority(1);

    expect($d->dispatch(['a' => 1]))->toBeFalse();
});

it('returns false when nothing matches and there is no fallback', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'x');

    expect($d->dispatch(['a' => 2]))->toBeFalse();
});

it('invokes the fallback when nothing matches', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => 'x');
    $d->fallback(fn(Context $c) => 'fb:' . $c->get('a'));

    expect($d->dispatch(['a' => 2]))->toBe('fb:2');
});

it('invokes the fallback when every matching listener declined', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1], fn() => false);
    $d->fallback(fn() => 'fb');

    expect($d->dispatch(['a' => 1]))->toBe('fb');
});

it('skips the fallback when an event was handled or explicitly stopped', function () {
    $handled = makeDispatcher();
    $handled->on(['a' => 1], fn() => 'done');
    $handled->fallback(fn() => 'fb');

    $stopped = makeDispatcher();
    $stopped->on(['a' => 1], fn() => false)->stop();
    $stopped->fallback(fn() => 'fb');

    expect($handled->dispatch(['a' => 1]))->toBe('done');
    expect($stopped->dispatch(['a' => 1]))->toBeFalse();
});

it('accepts any handler format for the fallback', function () {
    $d = makeDispatcher();
    $d->fallback('Tests\Fixtures\Controller');

    expect($d->dispatch(['type' => 'unknown']))->toBe('invoked:unknown');
});

it('throws LogicException when dispatching without context', function () {
    expect(fn() => makeDispatcher()->dispatch())->toThrow(LogicException::class);
});

it('dispatches bound context via run() and the constructor', function () {
    $viaBind = makeDispatcher()->bind(['a' => 1]);
    $viaBind->on(['a' => 1], fn() => 'bound');

    $viaCtor = new Dispatcher(['a' => 1]);
    $viaCtor->on(['a' => 1], fn() => 'ctor');

    expect($viaBind->run())->toBe('bound');
    expect($viaCtor->run())->toBe('ctor');
    expect($viaCtor->context())->toBeInstanceOf(Context::class);
});

it('prefers inline data over the bound context without replacing it', function () {
    $d = new Dispatcher(['a' => 1]);
    $d->on(['a' => 1], fn() => 'one');
    $d->on(['a' => 2], fn() => 'two');

    expect($d->dispatch(['a' => 2]))->toBe('two');
    expect($d->dispatch(new Context(['a' => 2])))->toBe('two');
    expect($d->run())->toBe('one');
});

it('accepts pre-configured listeners via add()', function () {
    $d = makeDispatcher();
    $listener = new Listener(['a' => 1], fn() => 'added');

    expect($d->add($listener))->toBe($listener);
    expect($d->dispatch(['a' => 1]))->toBe('added');
});

it('applies group conditions as AND and never lets a listener widen them', function () {
    $d = makeDispatcher();
    $d->group(['conditions' => ['chat.type' => 'private']], function (Group $g) {
        $g->on(['text' => 'hi'], fn() => 'hi');
        $g->on(['chat.type' => 'group'], fn() => 'widened');
    });

    expect($d->dispatch(['chat' => ['type' => 'private'], 'text' => 'hi']))->toBe('hi');
    expect($d->dispatch(['chat' => ['type' => 'group'], 'text' => 'hi']))->toBeFalse();
    expect($d->dispatch(['chat' => ['type' => 'group']]))->toBeFalse();
});

it('applies group conditions to closure listeners too', function () {
    $d = makeDispatcher();
    $d->group(['conditions' => ['role' => 'admin']], function (Group $g) {
        $g->on(fn() => true, fn() => 'secret');
    });

    expect($d->dispatch(['role' => 'admin']))->toBe('secret');
    expect($d->dispatch(['role' => 'user']))->toBeFalse();
});

it('supports closure and OR-list group conditions', function () {
    $d = makeDispatcher();
    $d->group(['conditions' => fn(Context $c) => $c->get('n') > 1], function (Group $g) {
        $g->on([], fn() => 'big');
    });
    $d->group(['conditions' => [['k' => 'a'], ['k' => 'b']]], function (Group $g) {
        $g->on(['z' => 1], fn() => 'ab');
    });

    expect($d->dispatch(['n' => 2]))->toBe('big');
    expect($d->dispatch(['n' => 0]))->toBeFalse();
    expect($d->dispatch(['k' => 'b', 'z' => 1]))->toBe('ab');
    expect($d->dispatch(['k' => 'c', 'z' => 1]))->toBeFalse();
});

it('merges captures from group conditions and listener conditions', function () {
    $d = makeDispatcher();
    $d->group(['conditions' => ['a' => 'x {p}']], function (Group $g) {
        $g->on(['b' => 'y {q}'], fn(string $p, string $q) => "$p$q");
    });

    expect($d->dispatch(['a' => 'x 1', 'b' => 'y 2']))->toBe('12');
});

it('nests groups and accumulates attributes', function () {
    $d = makeDispatcher();
    $d->group(['conditions' => ['a' => 1], 'priority' => 5], function () use ($d) {
        $d->group(['conditions' => ['b' => 2]], function () use ($d) {
            $d->on([], fn() => 'nested');
        });
    });

    expect($d->dispatch(['a' => 1, 'b' => 2]))->toBe('nested');
    expect($d->dispatch(['a' => 1, 'b' => 3]))->toBeFalse();
    expect($d->dispatch(['a' => 9, 'b' => 2]))->toBeFalse();
});

it('orders listeners by group priority and explicit priority', function () {
    $d = makeDispatcher();
    $d->on([], fn() => 'plain');
    $d->group(['priority' => 10], function (Group $g) {
        $g->on([], fn() => 'grouped')->priority(-1);
        $g->on([], fn() => 'group-default');
    });

    expect($d->dispatch(['a' => 1]))->toBe('group-default');
});

it('applies group middleware added after registration and to later listeners', function () {
    $d = makeDispatcher();
    $group = $d->group(['priority' => 1]);
    $group->on(['a' => 1], fn() => 'core');
    $group->middleware(fn(Context $c, Closure $next) => 'L:' . $next($c));
    $group->on(['a' => 2], fn() => 'later');

    expect($d->dispatch(['a' => 1]))->toBe('L:core');
    expect($d->dispatch(['a' => 2]))->toBe('L:later');
});

it('supports the callback-only group form and returns the group', function () {
    $d = makeDispatcher();
    $group = $d->group(function (Group $g) {
        $g->on(['a' => 1], fn() => 'ok');
    });

    expect($group)->toBeInstanceOf(Group::class);
    expect($d->dispatch(['a' => 1]))->toBe('ok');
});

it('does not leak group state after the callback throws', function () {
    $d = makeDispatcher();

    try {
        $d->group(['conditions' => ['never' => true]], function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $d->on(['a' => 1], fn() => 'plain');

    expect($d->dispatch(['a' => 1]))->toBe('plain');
});
