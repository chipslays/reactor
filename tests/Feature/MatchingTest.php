<?php

declare(strict_types=1);

use Reactor\Context;

it('compares plain values strictly', function () {
    $d = makeDispatcher();
    $d->on(['type' => 'message', 'n' => 1], fn() => 'hit');

    expect($d->dispatch(['type' => 'message', 'n' => 1]))->toBe('hit');
    expect($d->dispatch(['type' => 'Message', 'n' => 1]))->toBeFalse();
    expect($d->dispatch(['type' => 'message', 'n' => '1']))->toBeFalse();
    expect($d->dispatch(['type' => 'message']))->toBeFalse();
});

it('matches nested dot paths and null for absent keys', function () {
    $d = makeDispatcher();
    $d->on(['chat.type' => 'private', 'edited' => null], fn() => 'hit');

    expect($d->dispatch(['chat' => ['type' => 'private']]))->toBe('hit');
    expect($d->dispatch(['chat' => ['type' => 'private'], 'edited' => true]))->toBeFalse();
});

it('requires every condition in an associative array (AND)', function () {
    $d = makeDispatcher();
    $d->on(['a' => 1, 'b' => 2], fn() => 'hit');

    expect($d->dispatch(['a' => 1, 'b' => 2]))->toBe('hit');
    expect($d->dispatch(['a' => 1, 'b' => 3]))->toBeFalse();
});

it('treats a list of groups as OR and supports closures inside it', function () {
    $d = makeDispatcher();
    $d->on([['a' => 1], fn(Context $c) => $c->get('b') === 2], fn() => 'hit');

    expect($d->dispatch(['a' => 1]))->toBe('hit');
    expect($d->dispatch(['b' => 2]))->toBe('hit');
    expect($d->dispatch(['a' => 3, 'b' => 3]))->toBeFalse();
});

it('supports closure conditions', function () {
    $d = makeDispatcher();
    $d->on(fn(Context $c) => $c->get('n') > 5, fn() => 'big');

    expect($d->dispatch(['n' => 6]))->toBe('big');
    expect($d->dispatch(['n' => 1]))->toBeFalse();
});

it('treats empty conditions as match-all', function () {
    $d = makeDispatcher();
    $d->on([], fn() => 'all');

    expect($d->dispatch(['anything' => true]))->toBe('all');
    expect($d->dispatch([]))->toBe('all');
});

it('captures positional groups from a regex', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^n(\d+)-(\d+)$/'], fn(int $a, int $b) => $a + $b);

    expect($d->dispatch(['t' => 'n5-6']))->toBe(11);
    expect($d->dispatch(['t' => 'x5-6']))->toBeFalse();
});

it('captures named groups from a regex and ignores unnamed ones', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^(?<cmd>[a-z]+)(\d)$/'], fn(string $cmd) => $cmd);

    expect($d->dispatch(['t' => 'go7']))->toBe('go');
});

it('passes null for unmatched optional regex groups', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^a(?<x>b)?$/'], fn(?string $x) => $x ?? 'none');

    expect($d->dispatch(['t' => 'a']))->toBe('none');
    expect($d->dispatch(['t' => 'ab']))->toBe('b');
});

it('keeps regex flags under the caller control', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^hello$/i'], fn() => 'hit');

    expect($d->dispatch(['t' => 'HeLLo']))->toBe('hit');
});

it('does not match regex against non-scalar values', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/.*/'], fn() => 'hit');

    expect($d->dispatch(['t' => ['x']]))->toBeFalse();
    expect($d->dispatch(['t' => true]))->toBeFalse();
});

it('throws on a broken regex written by the developer', function () {
    $d = makeDispatcher();
    $d->on(['t' => '~([~'], fn() => 'x');

    expect(fn() => $d->dispatch(['t' => 'a']))->toThrow(InvalidArgumentException::class);
});

it('extracts required and optional template placeholders', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/cmd {a} {b?}'], fn(string $a, ?string $b = null) => $a . '|' . ($b ?? '-'));

    expect($d->dispatch(['t' => '/cmd x']))->toBe('x|-');
    expect($d->dispatch(['t' => '/cmd x y']))->toBe('x|y');
    expect($d->dispatch(['t' => '/cmd']))->toBeFalse();
    expect($d->dispatch(['t' => '/cmd x y z']))->toBeFalse();
});

it('supports an optional placeholder without a leading space', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'page{n?}'], fn(?string $n = null) => $n ?? 'none');

    expect($d->dispatch(['t' => 'page']))->toBe('none');
    expect($d->dispatch(['t' => 'page3']))->toBe('3');
});

it('treats template literals as plain text, not regex', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'a.b ${x}'], fn(string $x) => $x);

    expect($d->dispatch(['t' => 'a.b $1']))->toBe('1');
    expect($d->dispatch(['t' => 'axb $1']))->toBeFalse();
});

it('captures numeric context values with templates', function () {
    $d = makeDispatcher();
    $d->on(['user.id' => '{id}'], fn(string $id) => "u$id");

    expect($d->dispatch(['user' => ['id' => 123]]))->toBe('u123');
});

it('is case-sensitive by default and case-insensitive with ignoreCase()', function () {
    $strict = makeDispatcher();
    $strict->on(['t' => '/id {id}'], fn() => 'hit');

    $loose = makeDispatcher();
    $loose->on(['t' => '/id {id}'], fn() => 'hit')->ignoreCase();

    expect($strict->dispatch(['t' => '/ID 1']))->toBeFalse();
    expect($loose->dispatch(['t' => '/ID 1']))->toBe('hit');
});

it('keeps plain strings strict even with ignoreCase()', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'start'], fn() => 'hit')->ignoreCase();

    expect($d->dispatch(['t' => 'START']))->toBeFalse();
});

it('applies where() constraints to placeholders', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'item {id}'], fn(string $id) => $id)->whereNumber('id');

    expect($d->dispatch(['t' => 'item 42']))->toBe('42');
    expect($d->dispatch(['t' => 'item abc']))->toBeFalse();
});

it('rejects non-ASCII digits in whereNumber()', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'item {id}'], fn(string $id) => $id)->whereNumber('id');

    expect($d->dispatch(['t' => "item \u{0663}"]))->toBeFalse();
});

it('applies whereIn(), including slashes and an empty list', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'lang {l}'], fn(string $l) => $l)->whereIn('l', ['ru', 'a/b']);

    expect($d->dispatch(['t' => 'lang ru']))->toBe('ru');
    expect($d->dispatch(['t' => 'lang a/b']))->toBe('a/b');
    expect($d->dispatch(['t' => 'lang en']))->toBeFalse();

    $e = makeDispatcher();
    $e->on(['t' => 'lang {l}'], fn(string $l) => $l)->whereIn('l', []);

    expect($e->dispatch(['t' => 'lang ru']))->toBeFalse();
});

it('allows a slash inside a where() pattern', function () {
    $d = makeDispatcher();
    $d->on(['t' => 'go {p}'], fn(string $p) => $p)->where('p', '[a-z]+/[a-z]+');

    expect($d->dispatch(['t' => 'go ab/cd']))->toBe('ab/cd');
});

it('throws for a template with duplicated placeholder names', function () {
    $d = makeDispatcher();
    $d->on(['t' => '{x} {x}'], fn() => 'x');

    expect(fn() => $d->dispatch(['t' => 'a b']))->toThrow(InvalidArgumentException::class);
});

it('merges captures from several conditions', function () {
    $d = makeDispatcher();
    $d->on(['a' => 'x {p}', 'b' => 'y {q}'], fn(string $p, string $q) => "$p$q");

    expect($d->dispatch(['a' => 'x 1', 'b' => 'y 2']))->toBe('12');
});

it('lets custom matchers decide or defer', function () {
    $d = makeDispatcher();
    $d->matcher(fn($pattern, $value) => $pattern === '*' ? $value !== null : null);
    $d->matcher(fn($pattern, $value) => $pattern === '!' ? false : null);
    $d->on(['a' => '*'], fn() => 'any');
    $d->on(['b' => '!'], fn() => 'never');
    $d->on(['c' => 'plain'], fn() => 'plain');

    expect($d->dispatch(['a' => 'whatever']))->toBe('any');
    expect($d->dispatch(['b' => '!']))->toBeFalse();
    expect($d->dispatch(['c' => 'plain']))->toBe('plain');
});

it('recognizes regexes with the delimiters / ~ # % @', function () {
    $d = makeDispatcher();
    $d->on(['t' => '~^a(\d+)$~'], fn(int $n) => "tilde$n");
    $d->on(['t' => '#^b(\d+)$#'], fn(int $n) => "hash$n");
    $d->on(['t' => '%^c(\d+)$%'], fn(int $n) => "pct$n");
    $d->on(['t' => '@^d(\d+)$@'], fn(int $n) => "at$n");
    $d->on(['t' => '~^E$~i'], fn() => 'flag');

    expect($d->dispatch(['t' => 'a1']))->toBe('tilde1');
    expect($d->dispatch(['t' => 'b2']))->toBe('hash2');
    expect($d->dispatch(['t' => 'c3']))->toBe('pct3');
    expect($d->dispatch(['t' => 'd4']))->toBe('at4');
    expect($d->dispatch(['t' => 'e']))->toBe('flag');
});

it('allows an escaped delimiter inside a regex body', function () {
    $d = makeDispatcher();
    $d->on(['t' => '/^a\/b$/'], fn() => 'slash');
    $d->on(['t' => '~^x\~y$~'], fn() => 'tilde');

    expect($d->dispatch(['t' => 'a/b']))->toBe('slash');
    expect($d->dispatch(['t' => 'x~y']))->toBe('tilde');
});

it('compares strings that only look like regexes literally', function () {
    $d = makeDispatcher();
    $d->on(['t' => '///'], fn() => 'slashes');
    $d->on(['t' => '/a/b/'], fn() => 'path');
    $d->on(['t' => '##'], fn() => 'hashes');

    expect($d->dispatch(['t' => '///']))->toBe('slashes');
    expect($d->dispatch(['t' => '/a/b/']))->toBe('path');
    expect($d->dispatch(['t' => '##']))->toBe('hashes');
    expect($d->dispatch(['t' => 'x']))->toBeFalse();
});
