<?php

declare(strict_types=1);

use Collectable\Collection;
use Reactor\Context;
use Tests\Fixtures\Controller;
use Tests\Fixtures\NotInvokable;

it('resolves every handler format', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'closure'], fn() => 'closure');
    $d->on(['t' => 'at {id}'], 'Tests\Fixtures\Controller@show');
    $d->on(['t' => 'pair {id}'], [Controller::class, 'show']);
    $d->on(['t' => 'obj {id}'], [new Controller(), 'show']);
    $d->on(['t' => 'invokable'], Controller::class);
    $d->on(['t' => 'static {name}'], 'Tests\Fixtures\Controller::stat');

    expect($d->dispatch(['t' => 'closure', 'type' => 'x']))->toBe('closure');
    expect($d->dispatch(['t' => 'at 5']))->toBe('show:5');
    expect($d->dispatch(['t' => 'pair 6']))->toBe('show:6');
    expect($d->dispatch(['t' => 'obj 7']))->toBe('show:7');
    expect($d->dispatch(['t' => 'invokable', 'type' => 'msg']))->toBe('invoked:msg');
    expect($d->dispatch(['t' => 'static z']))->toBe('stat:z');
});

it('uses the resolver to build handler classes', function () {
    $built = [];
    $d = makeDispatcher();
    $d->resolver(function (string $class) use (&$built) {
        $built[] = $class;
        return new $class();
    });
    $d->on(['t' => 'go {id}'], [Controller::class, 'show']);

    expect($d->dispatch(['t' => 'go 1']))->toBe('show:1');
    expect($built)->toBe([Controller::class]);
});

it('rejects a handler class without __invoke', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'x'], NotInvokable::class);

    expect(fn() => $d->dispatch(['t' => 'x']))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-existing handler method or class', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a'], [Controller::class, 'nope']);
    $d->on(['t' => 'b'], 'Missing\Thing@run');

    expect(fn() => $d->dispatch(['t' => 'a']))->toThrow(InvalidArgumentException::class);
    expect(fn() => $d->dispatch(['t' => 'b']))->toThrow(InvalidArgumentException::class);
});

it('injects parameters by name', function () {
    $d = makeDispatcher();
    $d->on(['t' => '{a} {b}'], fn(string $b, string $a) => "$a-$b");

    expect($d->dispatch(['t' => 'x y']))->toBe('x-y');
});

it('injects positional regex captures in order', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^(\w+)-(\w+)$/'], fn(string $first, string $second) => "$second.$first");

    expect($d->dispatch(['t' => 'a-b']))->toBe('b.a');
});

it('injects the context by its class, parent class or union member', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a'], fn(Context $c) => $c::class);
    $d->on(['t' => 'b'], fn(Collection $c) => $c::class);
    $d->on(['t' => 'c'], fn(int|Context $c) => $c::class);
    $d->on(['t' => 'd'], fn(?Context $c) => $c::class);

    foreach (['a', 'b', 'c', 'd'] as $t) {
        expect($d->dispatch(['t' => $t]))->toBe(Context::class);
    }
});

it('does not consume a positional argument for the context parameter', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'x {id}'], fn(Context $c, string $id) => $id);

    expect($d->dispatch(['t' => 'x 9']))->toBe('9');
});

it('uses defaults and nulls for missing arguments', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a'], fn(string $x = 'def') => $x);
    $d->on(['t' => 'b'], fn(?string $x) => $x ?? 'null');
    $d->on(['t' => 'c'], fn($x) => $x ?? 'untyped-null');

    expect($d->dispatch(['t' => 'a']))->toBe('def');
    expect($d->dispatch(['t' => 'b']))->toBe('null');
    expect($d->dispatch(['t' => 'c']))->toBe('untyped-null');
});

it('throws when a required typed argument cannot be resolved', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a'], fn(string $x) => $x);

    expect(fn() => $d->dispatch(['t' => 'a']))->toThrow(InvalidArgumentException::class);
});

it('ignores variadic parameters', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a {x}'], fn(string $x, string ...$rest) => $x . count($rest));

    expect($d->dispatch(['t' => 'a q']))->toBe('q0');
});

it('casts strings to scalar types', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'int {v}'], fn(int $v) => gettype($v));
    $d->on(['t' => 'float {v}'], fn(float $v) => gettype($v));
    $d->on(['t' => 'bool {v}'], fn(bool $v) => $v ? 'yes' : 'no');
    $d->on(['t' => 'str {v}'], fn(string $v) => gettype($v));

    expect($d->dispatch(['t' => 'int 42']))->toBe('integer');
    expect($d->dispatch(['t' => 'int -7']))->toBe('integer');
    expect($d->dispatch(['t' => 'float 1.5']))->toBe('double');
    expect($d->dispatch(['t' => 'bool true']))->toBe('yes');
    expect($d->dispatch(['t' => 'bool 0']))->toBe('no');
    expect($d->dispatch(['t' => 'str 42']))->toBe('string');
});

it('accepts leading zeros for int parameters', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'n {v}'], fn(int $v) => $v);

    expect($d->dispatch(['t' => 'n 007']))->toBe(7);
    expect($d->dispatch(['t' => 'n 000']))->toBe(0);
});

it('casts into union types with a sensible priority', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'u {v}'], fn(int|float $v) => gettype($v));
    $d->on(['t' => 's {v}'], fn(string|int $v) => gettype($v));

    expect($d->dispatch(['t' => 'u 3']))->toBe('integer');
    expect($d->dispatch(['t' => 'u 3.5']))->toBe('double');
    expect($d->dispatch(['t' => 's 3']))->toBe('string');
});

it('never silently truncates or overflows integers', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'big {v}'], fn(string|bool $v) => gettype($v));
    $d->on(['t' => 'strict {v}'], fn(int $v) => $v);

    expect($d->dispatch(['t' => 'big 99999999999999999999']))->toBe('string');
    expect(fn() => $d->dispatch(['t' => 'strict 99999999999999999999']))->toThrow(TypeError::class);
    expect(fn() => $d->dispatch(['t' => 'strict 1.5']))->toThrow(TypeError::class);
});
