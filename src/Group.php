<?php

declare(strict_types=1);

namespace Reactor;

use Closure;
use InvalidArgumentException;

/**
 * Represents a group of listeners sharing common attributes.
 *
 * Listeners reference their group (and the group references its parent), so every attribute is
 * resolved lazily: changes made to a group after listeners were registered - including changes
 * to a parent group - are reflected in all of its listeners and nested groups.
 */
class Group
{
    protected array $middleware = [];
    protected ?int $priority = null;

    /** @var array<int, array|Closure> Own condition scopes, each one is ANDed with the listener's conditions. */
    protected array $conditions = [];

    /**
     * Create a new listener group instance.
     *
     * @param Dispatcher $dispatcher
     * @param array $attributes Supported keys: middleware, priority, conditions
     * @param Group|null $parent
     * @throws InvalidArgumentException
     */
    public function __construct(
        protected Dispatcher $dispatcher,
        array $attributes = [],
        protected ?Group $parent = null,
    ) {
        if (isset($attributes['middleware'])) {
            $this->middleware = $this->wrap($attributes['middleware']);
        }

        if (isset($attributes['priority'])) {
            $this->priority = (int) $attributes['priority'];
        }

        if (isset($attributes['conditions'])) {
            $this->scope($attributes['conditions']);
        }
    }

    /**
     * Register a new event listener under this group.
     *
     * The group's conditions are ANDed with the listener's own conditions,
     * so a listener can only narrow a group, never widen it. Closure conditions are supported too.
     *
     * @example $group->on(['type' => 'callback_query'], fn() => 'Handled!');
     *
     * @param mixed $conditions
     * @param mixed $handler
     * @return Listener
     */
    public function on(mixed $conditions, mixed $handler): Listener
    {
        return $this->dispatcher->add(new Listener($conditions, $handler, $this));
    }

    /**
     * Add middleware to the group (applies to all its listeners, registered before or after).
     *
     * @example $group->middleware('auth', 'log')
     *
     * @param mixed ...$middleware
     * @return self
     */
    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->middleware = array_merge($this->middleware, $this->wrap($item));
        }

        return $this;
    }

    /**
     * Set the default priority for the group. Listeners with an explicit priority keep their own.
     *
     * @example $group->priority(10)
     *
     * @param int $priority
     * @return self
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Add one more condition scope (array or Closure) to the group.
     *
     * @example $group->scope(['chat.type' => 'private'])
     *
     * @param array|Closure $conditions
     * @return self
     * @throws InvalidArgumentException
     */
    public function scope(array|Closure $conditions): self
    {
        $this->conditions[] = $conditions;

        return $this;
    }

    /**
     * Get the effective middleware: the parent's first, then the group's own.
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return array_merge($this->parent?->getMiddleware() ?? [], $this->middleware);
    }

    /**
     * Get the effective priority: own, else the parent's, else null.
     *
     * @return int|null
     */
    public function getPriority(): ?int
    {
        return $this->priority ?? $this->parent?->getPriority();
    }

    /**
     * Get all condition scopes: the parent's first, then the group's own.
     *
     * @return array<int, array|Closure>
     */
    public function getConditions(): array
    {
        return array_merge($this->parent?->getConditions() ?? [], $this->conditions);
    }

    /**
     * Wrap a value in an array if it isn't one already.
     *
     * @param mixed $value
     * @return array
     */
    protected function wrap(mixed $value): array
    {
        return is_array($value) ? $value : [$value];
    }
}
