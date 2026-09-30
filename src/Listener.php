<?php

declare(strict_types=1);

namespace Reactor;

use Closure;
use InvalidArgumentException;

/**
 * Represents a registered event listener with conditions, a handler, and optional constraints.
 */
class Listener
{
    /** Explicit priority. Null means "inherit from the group, or 0". */
    protected ?int $priority = null;
    protected array $middleware = [];
    protected array $patterns = [];
    protected bool $stopped = false;
    protected bool $ignoreCase = false;

    /**
     * Create a new Listener instance.
     *
     * Conditions: an associative array (AND), a list of such arrays/closures (OR) or a Closure.
     * An empty array means "no constraints": the listener (within its group scope) handles every event.
     *
     * String values are compared strictly and case-sensitively. `{placeholder}` templates are
     * case-sensitive too unless `ignoreCase()` is called; raw regexes (`/.../i`) carry their own flags.
     *
     * @param mixed $conditions
     * @param mixed $handler
     * @param Group|null $group The group this listener belongs to (inherits its scope, middleware, priority)
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly mixed $conditions,
        public readonly mixed $handler,
        protected ?Group $group = null,
    ) {
        if (!$conditions instanceof Closure && !is_array($conditions)) {
            throw new InvalidArgumentException('Conditions must be an array or a Closure.');
        }

        if (!static::isValidHandler($handler)) {
            throw new InvalidArgumentException(
                'Handler must be a callable, class name, "Class@method" string or [class|object, method].'
            );
        }
    }

    /**
     * Set the execution priority of the listener.
     * Higher priority listeners are executed first.
     * An explicit priority always wins over the group's priority.
     *
     * @example $listener->priority(100)
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
     * Assign middleware to this specific listener.
     * Group middleware runs first, then the listener's own.
     *
     * @example $listener->middleware('auth', 'role:admin,moderator', 'throttle:3,100')
     *
     * @param mixed ...$middleware
     * @return self
     */
    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (is_array($item)) {
                $this->middleware = array_merge($this->middleware, $item);
            } else {
                $this->middleware[] = $item;
            }
        }
        return $this;
    }

    /**
     * Add a regular expression constraint for a parameter.
     *
     * @example $listener->where('id', '[0-9]+')
     * @example $listener->where(['id' => '[0-9]+', 'name' => '[a-z]+'])
     *
     * @param string|array $name
     * @param string|null $pattern
     * @return self
     * @throws InvalidArgumentException
     */
    public function where(string|array $name, ?string $pattern = null): self
    {
        if (is_array($name)) {
            foreach ($name as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new InvalidArgumentException('Constraints must be a [name => pattern] map of strings.');
                }
            }

            $this->patterns = array_merge($this->patterns, $name);
            return $this;
        }

        if ($pattern === null) {
            throw new InvalidArgumentException("Pattern for parameter [{$name}] must not be null.");
        }

        $this->patterns[$name] = $pattern;

        return $this;
    }

    /**
     * Constrain specified parameters to strictly contain (ASCII) numbers.
     *
     * @example $listener->whereNumber('id', 'page')
     *
     * @param string ...$names
     * @return self
     */
    public function whereNumber(string ...$names): self
    {
        // Explicit [0-9]: with the /u flag PHP enables UCP and "\d" would also match
        // non-ASCII digits (e.g. Arabic-Indic), which cannot be cast to int.
        return $this->constrain($names, '[0-9]+');
    }

    /**
     * Constrain specified parameters to strictly contain alphabetic characters.
     *
     * @example $listener->whereAlpha('lang', 'sort')
     *
     * @param string ...$names
     * @return self
     */
    public function whereAlpha(string ...$names): self
    {
        return $this->constrain($names, '[a-zA-Z]+');
    }

    /**
     * Constrain specified parameters to strictly contain alphanumeric characters.
     *
     * @example $listener->whereAlphaNumeric('hash', 'token')
     *
     * @param string ...$names
     * @return self
     */
    public function whereAlphaNumeric(string ...$names): self
    {
        return $this->constrain($names, '[a-zA-Z0-9]+');
    }

    /**
     * Add a constraint where the value must be one of the provided list.
     *
     * @example $listener->whereIn('lang', ['ru', 'en', 'es'])
     *
     * @param string $name
     * @param array $values
     * @return self
     */
    public function whereIn(string $name, array $values): self
    {
        if ($values === []) {
            // An empty alternation "(?:)" would match an empty string; nothing must match here.
            $this->patterns[$name] = '(?!)';
            return $this;
        }

        $escaped = array_map(fn($value) => preg_quote((string) $value, '/'), $values);

        $this->patterns[$name] = '(?:' . implode('|', $escaped) . ')';

        return $this;
    }

    /**
     * Make `{placeholder}` templates of this listener case-insensitive.
     * Plain strings stay strict, raw regexes are controlled by their own flags.
     *
     * @example $listener->ignoreCase()
     *
     * @param bool $ignore
     * @return self
     */
    public function ignoreCase(bool $ignore = true): self
    {
        $this->ignoreCase = $ignore;
        return $this;
    }

    /**
     * Stop the chain propagation after this listener,
     * even if its handler returns false.
     *
     * @example $listener->stop()
     *
     * @param bool $stop
     * @return self
     */
    public function stop(bool $stop = true): self
    {
        $this->stopped = $stop;
        return $this;
    }

    /**
     * Get the effective priority: own, else the group's, else 0.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority ?? $this->group?->getPriority() ?? 0;
    }

    /**
     * Get the effective middleware: group chain first, then the listener's own.
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return array_merge($this->group?->getMiddleware() ?? [], $this->middleware);
    }

    /**
     * Get the group condition scopes. Each scope must match (logical AND)
     * in addition to the listener's own conditions.
     *
     * @return array<int, array|Closure>
     */
    public function getScope(): array
    {
        return $this->group?->getConditions() ?? [];
    }

    /**
     * Get the registered regex patterns for constraints.
     *
     * @return array
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Determine if template matching is case-insensitive.
     *
     * @return bool
     */
    public function isIgnoreCase(): bool
    {
        return $this->ignoreCase;
    }

    /**
     * Determine if the propagation chain should be stopped.
     *
     * @return bool
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Cheap structural validation of a handler (classes are resolved lazily at dispatch time).
     *
     * @param mixed $handler
     * @return bool
     */
    protected static function isValidHandler(mixed $handler): bool
    {
        if ($handler instanceof Closure || is_callable($handler)) {
            return true;
        }

        if (is_string($handler)) {
            return $handler !== '';
        }

        return is_array($handler)
            && count($handler) === 2
            && array_is_list($handler)
            && (is_string($handler[0]) || is_object($handler[0]))
            && is_string($handler[1]);
    }

    /**
     * Apply a specific regex constraint to an array of parameter names.
     *
     * @param array $names
     * @param string $pattern
     * @return self
     */
    protected function constrain(array $names, string $pattern): self
    {
        foreach ($names as $name) {
            $this->patterns[$name] = $pattern;
        }
        return $this;
    }
}
