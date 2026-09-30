<?php

declare(strict_types=1);

use Reactor\Listener;
use Tests\Fixtures\Controller;

function listener(mixed $conditions = ['a' => 1], mixed $handler = null): Listener
{
    return new Listener($conditions, $handler ?? fn() => 'ok');
}

it('accepts every supported handler format', function () {
    $handlers = [
        fn() => 1,
        'Controller@show',
        Controller::class,
        [Controller::class, 'show'],
        [new Controller(), 'show'],
        'strtoupper',
    ];

    foreach ($handlers as $handler) {
        expect(new Listener(['a' => 1], $handler))->toBeInstanceOf(Listener::class);
    }
});

it('rejects malformed handlers', function () {
    foreach ([123, '', ['only-one'], [1, 'method'], ['Class', 5], null, 1.5] as $handler) {
        expect(fn() => new Listener(['a' => 1], $handler))->toThrow(InvalidArgumentException::class);
    }
});

it('accepts array and closure conditions', function () {
    expect(new Listener([], fn() => 1))->toBeInstanceOf(Listener::class);
    expect(new Listener(fn() => true, fn() => 1))->toBeInstanceOf(Listener::class);
});

it('rejects conditions of a wrong type', function () {
    foreach (['text', 5, null, true] as $conditions) {
        expect(fn() => new Listener($conditions, fn() => 1))->toThrow(InvalidArgumentException::class);
    }
});

it('has sane defaults', function () {
    $l = listener();

    expect($l->getPriority())->toBe(0);
    expect($l->getMiddleware())->toBe([]);
    expect($l->getPatterns())->toBe([]);
    expect($l->getScope())->toBe([]);
    expect($l->isStopped())->toBeFalse();
    expect($l->isIgnoreCase())->toBeFalse();
});

it('sets priority fluently', function () {
    $l = listener();

    expect($l->priority(10))->toBe($l);
    expect($l->getPriority())->toBe(10);
    expect($l->priority(-5)->getPriority())->toBe(-5);
});

it('collects middleware from items and arrays in order', function () {
    $l = listener()->middleware('a', ['b', 'c'])->middleware('d');

    expect($l->getMiddleware())->toBe(['a', 'b', 'c', 'd']);
});

it('stores constraints via where()', function () {
    $l = listener()->where('id', '[0-9]+')->where(['name' => '[a-z]+', 'x' => '.+']);

    expect($l->getPatterns())->toBe(['id' => '[0-9]+', 'name' => '[a-z]+', 'x' => '.+']);
});

it('rejects invalid where() arguments', function () {
    expect(fn() => listener()->where('id'))->toThrow(InvalidArgumentException::class);
    expect(fn() => listener()->where(['id' => null]))->toThrow(InvalidArgumentException::class);
    expect(fn() => listener()->where([0 => '[0-9]+']))->toThrow(InvalidArgumentException::class);
});

it('provides ascii-only shortcut constraints', function () {
    $l = listener()->whereNumber('id', 'page')->whereAlpha('lang')->whereAlphaNumeric('hash');

    expect($l->getPatterns())->toBe([
        'id' => '[0-9]+',
        'page' => '[0-9]+',
        'lang' => '[a-zA-Z]+',
        'hash' => '[a-zA-Z0-9]+',
    ]);
});

it('builds an escaped alternation for whereIn()', function () {
    $l = listener()->whereIn('lang', ['ru', 'e.n', 'a/b']);

    expect($l->getPatterns()['lang'])->toBe('(?:ru|e\.n|a\/b)');
});

it('builds a never-matching pattern for an empty whereIn()', function () {
    expect(listener()->whereIn('lang', [])->getPatterns()['lang'])->toBe('(?!)');
});

it('toggles stop and ignoreCase', function () {
    $l = listener();

    expect($l->stop()->isStopped())->toBeTrue();
    expect($l->stop(false)->isStopped())->toBeFalse();
    expect($l->ignoreCase()->isIgnoreCase())->toBeTrue();
    expect($l->ignoreCase(false)->isIgnoreCase())->toBeFalse();
});
