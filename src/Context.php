<?php

declare(strict_types=1);

namespace Reactor;

use Collectable\Collection;

/**
 * A wrapper around incoming event data.
 *
 * Extends a powerful collection by adding event-specific helpers.
 *
 * @example $context = new Context(['message' => ['type' => 'text']]);
 */
class Context extends Collection
{
    /**
     * Quick check if a value at the specified dot-notation path equals the expected one.
     *
     * @example $context->is('message.type', 'text')
     * @example $context->is('user.id', 123)
     *
     * @param string $path Dot-notation path (e.g., 'message.type')
     * @param mixed $value Expected value
     * @return bool
     */
    public function is(string $path, mixed $value): bool
    {
        return $this->get($path) === $value;
    }

    /**
     * Check if the value at the specified path is strictly true.
     *
     * @example $context->isTrue('message.is_bot')
     *
     * @param string $path Dot-notation path
     * @return bool
     */
    public function isTrue(string $path): bool
    {
        return $this->get($path) === true;
    }

    /**
     * Check if the value at the specified path is strictly false.
     *
     * @example $context->isFalse('message.is_bot')
     *
     * @param string $path Dot-notation path
     * @return bool
     */
    public function isFalse(string $path): bool
    {
        return $this->get($path) === false;
    }
}
