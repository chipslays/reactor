<?php

declare(strict_types=1);

use Reactor\Context;

function sampleContext(): Context
{
    return new Context([
        'message' => ['type' => 'text', 'is_bot' => false, 'is_admin' => true],
        'user' => ['id' => 123],
        'flag' => 1,
    ]);
}

it('compares values by dot path strictly', function () {
    $c = sampleContext();

    expect($c->is('message.type', 'text'))->toBeTrue();
    expect($c->is('message.type', 'photo'))->toBeFalse();
    expect($c->is('user.id', 123))->toBeTrue();
    expect($c->is('user.id', '123'))->toBeFalse();
});

it('does not match a missing path', function () {
    expect(sampleContext()->is('missing.path', 'x'))->toBeFalse();
});

it('checks for strict true', function () {
    $c = sampleContext();

    expect($c->isTrue('message.is_admin'))->toBeTrue();
    expect($c->isTrue('message.is_bot'))->toBeFalse();
    expect($c->isTrue('flag'))->toBeFalse();
    expect($c->isTrue('missing'))->toBeFalse();
});

it('checks for strict false', function () {
    $c = sampleContext();

    expect($c->isFalse('message.is_bot'))->toBeTrue();
    expect($c->isFalse('message.is_admin'))->toBeFalse();
    expect($c->isFalse('missing'))->toBeFalse();
});
