<?php

declare(strict_types=1);

namespace Reactor;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * The core event dispatcher that matches incoming data to listeners.
 */
class Dispatcher
{
    /**
     * The bound context data for the current dispatch cycle.
     *
     * @var Context|null
     */
    protected ?Context $context = null;

    /**
     * Registered event listeners.
     *
     * @var Listener[]
     */
    protected array $listeners = [];

    /**
     * Middleware aliases for string-based definitions.
     *
     * @var array<string, mixed>
     */
    protected array $aliases = [];

    /**
     * Global middleware applied to all events.
     *
     * @var array
     */
    protected array $middleware = [];

    /**
     * Stack of active route groups.
     *
     * @var Group[]
     */
    protected array $stack = [];

    /**
     * Custom condition matchers.
     *
     * @var callable[]
     */
    protected array $matchers = [];

    /**
     * Cache of compiled regex patterns.
     *
     * @var array<string, string>
     */
    protected array $compiled = [];

    /**
     * The fallback handler executed if no listener handled the event.
     *
     * @var mixed
     */
    protected mixed $fallback = null;

    /**
     * Class factory (e.g., a DI container) to resolve handlers: fn(string $class): object
     *
     * @var Closure|null
     */
    protected ?Closure $resolver = null;

    /**
     * Shape of a raw regex string: a delimiter (one of / ~ # % @), a body that contains no
     * unescaped delimiter, the closing delimiter and optional PCRE flags.
     */
    protected const REGEX_PATTERN = '~^([/\~#%@])((?:\\\\.|(?!\\1).)+)\\1[imsxuADSUXJn]*$~s';

    /**
     * Create a new Dispatcher instance.
     *
     * @param array|Context|null $context Optional initial context data
     */
    public function __construct(array|Context|null $context = null)
    {
        if ($context !== null) {
            $this->bind($context);
        }
    }

    /**
     * Bind context data to the dispatcher.
     *
     * @example $dispatcher->bind(['type' => 'message', 'text' => 'hello'])
     *
     * @param array|Context $data
     * @return self
     */
    public function bind(array|Context $data): self
    {
        $this->context = $data instanceof Context ? $data : new Context($data);
        return $this;
    }

    /**
     * Retrieve the currently bound context.
     *
     * @return Context|null
     */
    public function context(): ?Context
    {
        return $this->context;
    }

    /**
     * Define how handler and middleware classes are instantiated.
     *
     * By default, it uses `new $class()`, but you can hook up a DI container.
     *
     * @example $dispatcher->resolver(fn(string $class) => $container->get($class))
     *
     * @param callable $resolver
     * @return self
     */
    public function resolver(callable $resolver): self
    {
        $this->resolver = Closure::fromCallable($resolver);
        return $this;
    }

    /**
     * Register a custom pattern matcher.
     *
     * The matcher receives ($pattern, $value, $patterns, &$args).
     * Should return `true` or `false` if a decision is made, or `null` if the pattern is not recognized.
     *
     * @example $dispatcher->matcher(fn($pattern, $value) => $pattern === '*' ? true : null)
     *
     * @param callable $matcher
     * @return self
     */
    public function matcher(callable $matcher): self
    {
        $this->matchers[] = $matcher;
        return $this;
    }

    /**
     * Register an alias for a middleware class or closure.
     *
     * @example $dispatcher->alias('auth', AuthMiddleware::class)
     *
     * @param string $name
     * @param mixed $middleware
     * @return self
     */
    public function alias(string $name, mixed $middleware): self
    {
        $this->aliases[$name] = $middleware;
        return $this;
    }

    /**
     * Register global middleware to wrap the entire dispatch process.
     *
     * @example $dispatcher->middleware('log', GlobalAuthMiddleware::class)
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
     * Register a new event listener.
     *
     * Handler formats:
     *  - Closure or any callable: fn(string $lang) => ...
     *  - [Controller::class, 'method']
     *  - 'Controller@method'
     *  - Controller::class (class with an __invoke method)
     *  - [$object, 'method']
     *
     * @example $dispatcher->on(['type' => 'message'], fn() => 'Handled message!')
     *
     * @param mixed $conditions Conditions to match against the context
     * @param mixed $handler The handler to execute
     * @return Listener
     */
    public function on(mixed $conditions, mixed $handler): Listener
    {
        $group = end($this->stack);

        if ($group instanceof Group) {
            return $group->on($conditions, $handler);
        }

        return $this->add(new Listener($conditions, $handler));
    }

    /**
     * Add a pre-configured listener instance to the dispatcher.
     *
     * @param Listener $listener
     * @return Listener
     */
    public function add(Listener $listener): Listener
    {
        $this->listeners[] = $listener;
        return $listener;
    }

    /**
     * Create a route group with shared attributes (middleware, priority, conditions).
     *
     * @example
     * $dispatcher->group(['middleware' => 'auth'], function (Group $group) {
     *     $group->on(['action' => 'delete'], fn() => 'Deleted');
     * });
     *
     * @param array|callable $attributes Attributes array or the callback if no attributes are needed
     * @param callable|null $callback
     * @return Group
     */
    public function group(array|callable $attributes, ?callable $callback = null): Group
    {
        if (!is_array($attributes)) {
            $callback = $attributes;
            $attributes = [];
        }

        $parent = end($this->stack) ?: null;
        $group = new Group($this, $attributes, $parent);

        if ($callback !== null) {
            $this->stack[] = $group;

            try {
                $callback($group);
            } finally {
                array_pop($this->stack);
            }
        }

        return $group;
    }

    /**
     * Set a fallback handler to execute if no listener handled the event
     * (nothing matched, or every matching listener returned `false`).
     *
     * @example $dispatcher->fallback(fn() => 'Command not found.')
     *
     * @param mixed $handler
     * @return self
     */
    public function fallback(mixed $handler): self
    {
        $this->fallback = $handler;
        return $this;
    }

    /**
     * Dispatch an event through the middleware and listeners.
     *
     * Global middleware is executed once per event, wrapping the entire process:
     * both listener searching and the fallback.
     *
     * The first listener that handles the event completes the chain and returns the result.
     * Returning `false` means "not handled": the chain continues to the next listener,
     * unless `stop()` has been called on it.
     * If no listener handled the event (none matched, or every matching one returned `false`),
     * the fallback is invoked; without a fallback the result is `false`.
     *
     * @example $result = $dispatcher->dispatch(['type' => 'callback_query'])
     *
     * @param array|Context|null $data Optional inline context data
     * @return mixed
     */
    public function dispatch(array|Context|null $data = null): mixed
    {
        $context = match (true) {
            $data instanceof Context => $data,
            is_array($data)          => new Context($data),
            default                  => $this->context,
        };

        if ($context === null) {
            throw new LogicException('Cannot dispatch without context data.');
        }

        return $this->pipe($context, $this->middleware, fn(Context $ctx) => $this->route($ctx));
    }

    /**
     * Alias for dispatch().
     *
     * @return mixed
     */
    public function run(): mixed
    {
        return $this->dispatch();
    }

    /**
     * Internal method to route the context to the correct listener.
     *
     * @param Context $context
     * @return mixed
     */
    protected function route(Context $context): mixed
    {
        foreach ($this->sorted() as $listener) {
            $args = [];

            if (!$this->accepts($listener, $context, $args)) {
                continue;
            }

            $response = $this->pipe(
                $context,
                $listener->getMiddleware(),
                fn(Context $ctx) => $this->invoke($this->resolve($listener->handler), $ctx, $args),
            );

            if ($response !== false || $listener->isStopped()) {
                return $response;
            }
        }

        if ($this->fallback !== null) {
            return $this->invoke($this->resolve($this->fallback), $context, []);
        }

        return false;
    }

    /**
     * Sort listeners by priority in descending order.
     *
     * @return Listener[]
     */
    protected function sorted(): array
    {
        $listeners = $this->listeners;
        usort($listeners, fn(Listener $a, Listener $b) => $b->getPriority() <=> $a->getPriority());
        return $listeners;
    }

    /**
     * Check that the listener's group scopes AND its own conditions all match the context.
     * Captured arguments from every part are merged (later named values override earlier ones).
     *
     * @param Listener $listener
     * @param Context $context
     * @param array $args
     * @return bool
     */
    protected function accepts(Listener $listener, Context $context, array &$args): bool
    {
        $patterns = $listener->getPatterns();
        $ignoreCase = $listener->isIgnoreCase();
        $collected = [];

        foreach ([...$listener->getScope(), $listener->conditions] as $conditions) {
            $found = [];

            if (!$this->matches($conditions, $context, $patterns, $found, $ignoreCase)) {
                return false;
            }

            $collected = array_merge($collected, $found);
        }

        $args = $collected;

        return true;
    }

    /**
     * Check if a set of conditions matches the given context.
     *
     * Accepted forms: a Closure, an associative array (all must match), or a list of
     * arrays/Closures (any may match). An empty array has no constraints and always matches.
     *
     * @param mixed $conditions
     * @param Context $context
     * @param array $patterns
     * @param array $args
     * @param bool $ignoreCase Case-insensitive {placeholder} templates (raw regexes use their own flags)
     * @return bool
     */
    protected function matches(mixed $conditions, Context $context, array $patterns, array &$args, bool $ignoreCase = false): bool
    {
        if ($conditions instanceof Closure) {
            return (bool) $conditions($context);
        }

        if (!is_array($conditions)) {
            return false;
        }

        // No conditions - nothing to check: the listener (within its group scope) handles every event
        if ($conditions === []) {
            return true;
        }

        if (!array_is_list($conditions)) {
            return $this->matchGroup($conditions, $context, $patterns, $args, $ignoreCase);
        }

        // List of groups: any match is sufficient (logical OR)
        foreach ($conditions as $group) {
            $found = [];

            $matched = match (true) {
                $group instanceof Closure => (bool) $group($context),
                is_array($group)          => $this->matchGroup($group, $context, $patterns, $found, $ignoreCase),
                default                   => false,
            };

            if ($matched) {
                $args = $found;
                return true;
            }
        }

        return false;
    }

    /**
     * Match a specific group of conditions against the context.
     *
     * @param array $group
     * @param Context $context
     * @param array $patterns
     * @param array $args
     * @param bool $ignoreCase
     * @return bool
     */
    protected function matchGroup(array $group, Context $context, array $patterns, array &$args, bool $ignoreCase = false): bool
    {
        foreach ($group as $path => $pattern) {
            if (!$this->compare($pattern, $context->get((string) $path), $patterns, $args, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare a pattern against a context value, extracting arguments if necessary.
     *
     * Pattern kinds for string patterns:
     *  - `/regex/flags` - a raw regular expression. Delimiters: / ~ # % @ (the same character opens and
     *    closes it, an unescaped delimiter inside the body is not allowed, flags are optional).
     *    To compare a literal string of such a shape exactly, use a Closure condition.
     *  - `text {name} {opt?}` - a template with placeholders (case-sensitive unless Listener::ignoreCase()).
     *  - anything else - strict equality.
     * Non-string patterns are compared strictly (===).
     *
     * @param mixed $pattern
     * @param mixed $value
     * @param array $patterns
     * @param array $args
     * @param bool $ignoreCase
     * @return bool
     */
    protected function compare(mixed $pattern, mixed $value, array $patterns, array &$args, bool $ignoreCase = false): bool
    {
        foreach ($this->matchers as $matcher) {
            $result = $matcher($pattern, $value, $patterns, $args);

            if (is_bool($result)) {
                return $result;
            }
        }

        if (!is_string($pattern)) {
            return $pattern === $value;
        }

        $isRegex = preg_match(static::REGEX_PATTERN, $pattern) === 1;
        $isTemplate = !$isRegex && str_contains($pattern, '{') && str_contains($pattern, '}');

        if (!$isRegex && !$isTemplate) {
            return $pattern === $value;
        }

        // Numeric context values (e.g. JSON ids) can be captured by regex/template patterns.
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return false;
        }

        return $this->capture($isRegex ? $pattern : $this->compile($pattern, $patterns, $ignoreCase), $value, $args);
    }

    /**
     * Capture arguments using a regular expression.
     *
     * @param string $regex
     * @param string $value
     * @param array $args
     * @return bool
     */
    protected function capture(string $regex, string $value, array &$args): bool
    {
        // A scoped no-op handler instead of "@": it also hides the warning from test runners
        // and framework error handlers (which may convert warnings into exceptions).
        set_error_handler(static fn(): bool => true);

        try {
            $result = preg_match($regex, $value, $matches, PREG_UNMATCHED_AS_NULL);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            // A compile error means a broken pattern written by the developer: don't hide it.
            // Runtime failures (backtrack limit, invalid UTF-8 in user input) are just "no match".
            if (preg_last_error() === PREG_INTERNAL_ERROR) {
                throw new InvalidArgumentException("Invalid regular expression [{$regex}].");
            }

            return false;
        }

        if ($result !== 1) {
            return false;
        }

        $named = false;
        foreach ($matches as $key => $_) {
            if (is_string($key)) {
                $named = true;
                break;
            }
        }

        foreach ($matches as $key => $match) {
            if (is_string($key)) {
                $args[$key] = $match;
            } elseif (!$named && $key > 0) {
                $args[] = $match;
            }
        }

        return true;
    }

    /**
     * Compile a template string containing placeholders into a regex pattern.
     * Results are cached for performance.
     *
     * @param string $pattern
     * @param array $patterns
     * @return string
     */
    protected function compile(string $pattern, array $patterns, bool $ignoreCase = false): string
    {
        // serialize() never fails, unlike json_encode() on invalid UTF-8 (which would collide cache keys)
        $key = ($ignoreCase ? 'i' : 's') . "\0" . $pattern . "\0" . serialize($patterns);

        return $this->compiled[$key] ??= $this->build($pattern, $patterns, $ignoreCase);
    }

    /**
     * Build the regular expression for a string pattern.
     *
     * @param string $pattern
     * @param array $patterns
     * @return string
     */
    protected function build(string $pattern, array $patterns, bool $ignoreCase = false): string
    {
        $regex = preg_quote($pattern, '/');
        // Escape unescaped "/" in user-supplied sub-patterns, otherwise they terminate the regex delimiter
        $sub = fn(string $name): string => isset($patterns[$name])
            ? preg_replace('~(?<!\\\\)/~', '\\/', (string) $patterns[$name])
            : '\S+';

        // " {name?}" - optional parameter along with the space before it
        $regex = preg_replace_callback(
            '/\s+\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\?\\\\\}/',
            fn($m) => '(?:\s+(?P<' . $m[1] . '>' . $sub($m[1]) . '))?',
            $regex,
        );

        // "{name?}" - optional parameter
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\?\\\\\}/',
            fn($m) => '(?:(?P<' . $m[1] . '>' . $sub($m[1]) . '))?',
            $regex,
        );

        // "{name}" - required parameter
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            fn($m) => '(?P<' . $m[1] . '>' . $sub($m[1]) . ')',
            $regex,
        );

        return '/^' . $regex . '$/' . ($ignoreCase ? 'i' : '') . 'uD';
    }

    /**
     * Send context through a pipeline of middleware to a destination closure.
     *
     * @param Context $context
     * @param array $middleware
     * @param Closure $destination
     * @return mixed
     */
    protected function pipe(Context $context, array $middleware, Closure $destination): mixed
    {
        $chain = array_reduce(
            array_reverse($middleware),
            fn(Closure $next, mixed $layer) => fn(Context $ctx) => $this->call($layer, $ctx, $next),
            $destination,
        );

        return $chain($context);
    }

    /**
     * Execute a single middleware layer.
     *
     * @param mixed $middleware
     * @param Context $context
     * @param Closure $next
     * @return mixed
     */
    protected function call(mixed $middleware, Context $context, Closure $next): mixed
    {
        $args = [];

        if (is_string($middleware)) {
            [$name, $rest] = array_pad(explode(':', $middleware, 2), 2, null);

            if ($rest !== null && $rest !== '') {
                $args = array_map('trim', explode(',', $rest));
            }

            $middleware = $this->aliases[$name] ?? $name;

            if (is_string($middleware)) {
                if (!class_exists($middleware)) {
                    throw new InvalidArgumentException("Middleware alias or class [{$name}] not found.");
                }

                $middleware = $this->make($middleware);
            }
        }

        if (is_object($middleware) && method_exists($middleware, 'handle')) {
            $target = Closure::fromCallable([$middleware, 'handle']);
        } elseif (is_callable($middleware)) {
            $target = Closure::fromCallable($middleware);
        } else {
            throw new InvalidArgumentException('Invalid middleware provided.');
        }

        return $target($context, $next, ...$this->coerce($args, $target));
    }

    /**
     * Coerce parameters from a string "name:a,b" to middleware parameter types
     * (after $context and $next parameters).
     * For variadic parameters, the type of the last parameter is used.
     *
     * @param array $args
     * @param Closure $target
     * @return array
     */
    protected function coerce(array $args, Closure $target): array
    {
        if ($args === []) {
            return $args;
        }

        $params = array_slice((new ReflectionFunction($target))->getParameters(), 2);
        $last = $params ? end($params) : null;

        foreach ($args as $i => $arg) {
            $param = $params[$i] ?? ($last?->isVariadic() ? $last : null);
            $args[$i] = $param ? $this->cast($arg, $param->getType()) : $arg;
        }

        return $args;
    }

    /**
     * Convert any supported handler format into a Closure.
     *
     * @param mixed $handler
     * @return Closure
     */
    protected function resolve(mixed $handler): Closure
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        // 'Controller@method'
        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }

        if (is_array($handler) && count($handler) === 2) {
            // [$object, 'method'] or static [Class::class, 'method']
            if (is_callable($handler)) {
                return Closure::fromCallable($handler);
            }

            // [Controller::class, 'method'] - needs an instance
            [$target, $method] = $handler;

            if (is_string($target)) {
                $callable = [$this->make($target), $method];

                if (is_callable($callable)) {
                    return Closure::fromCallable($callable);
                }
            }

            throw new InvalidArgumentException('Handler method is not callable.');
        }

        // Controller::class with __invoke method
        if (is_string($handler) && class_exists($handler)) {
            $object = $this->make($handler);

            if (!method_exists($object, '__invoke')) {
                throw new InvalidArgumentException("Handler class [{$handler}] must have __invoke() method.");
            }

            return Closure::fromCallable($object);
        }

        if (is_callable($handler)) {
            return Closure::fromCallable($handler);
        }

        throw new InvalidArgumentException('Invalid handler provided.');
    }

    /**
     * Instantiate a class via the resolver or directly.
     *
     * @param string $class
     * @return object
     */
    protected function make(string $class): object
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Class [{$class}] not found.");
        }

        return $this->resolver !== null ? ($this->resolver)($class) : new $class();
    }

    /**
     * Invoke the handler, injecting the Context and extracted arguments using Reflection.
     *
     * @param Closure $handler
     * @param Context $context
     * @param array $args
     * @return mixed
     */
    protected function invoke(Closure $handler, Context $context, array $args): mixed
    {
        $values = [];
        $position = 0; // counter only for parameters not related to Context

        foreach ((new ReflectionFunction($handler))->getParameters() as $param) {
            if ($param->isVariadic()) {
                break;
            }

            $type = $param->getType();

            if ($this->wantsContext($type, $context)) {
                $values[] = $context;
                continue;
            }

            $value = $args[$param->getName()] ?? $args[$position] ?? null;
            $position++;

            if ($value !== null) {
                $values[] = $this->cast($value, $type);
            } elseif ($param->isDefaultValueAvailable()) {
                $values[] = $param->getDefaultValue();
            } elseif ($type === null || $type->allowsNull()) {
                $values[] = null;
            } else {
                throw new InvalidArgumentException("Cannot resolve handler parameter \${$param->getName()}.");
            }
        }

        return $handler(...$values);
    }

    /**
     * Determine whether a parameter type accepts the Context (named class type or a member of a union).
     *
     * @param ReflectionType|null $type
     * @param Context $context
     * @return bool
     */
    protected function wantsContext(?ReflectionType $type, Context $context): bool
    {
        foreach ($this->namedTypes($type) as $named) {
            if (!$named->isBuiltin() && is_a($context, $named->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten a type into its named members (a union yields each of its named types).
     *
     * @param ReflectionType|null $type
     * @return ReflectionNamedType[]
     */
    protected function namedTypes(?ReflectionType $type): array
    {
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default                              => [],
        };

        return array_values(array_filter($types, fn($t) => $t instanceof ReflectionNamedType));
    }

    /**
     * Cast string values to integer, float or boolean when the type hint requires it.
     *
     * Values that cannot be cast safely (e.g. integer overflow) are left untouched.
     * Union types are supported; if "string" (or "mixed") is allowed the value stays a string.
     *
     * @param mixed $value
     * @param ReflectionType|null $type
     * @return mixed
     */
    protected function cast(mixed $value, ?ReflectionType $type): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $names = [];

        foreach ($this->namedTypes($type) as $named) {
            if ($named->isBuiltin()) {
                $names[] = $named->getName();
            }
        }

        if ($names === [] || in_array('string', $names, true) || in_array('mixed', $names, true)) {
            return $value;
        }

        if (in_array('int', $names, true) && preg_match('/^([+-]?)0*([0-9]+)$/D', $value, $m) === 1) {
            // Leading zeros are allowed ("007"); overflow yields false and keeps the original string.
            $int = filter_var(($m[1] === '-' ? '-' : '') . $m[2], FILTER_VALIDATE_INT);

            if ($int !== false) {
                return $int;
            }
        }

        if (in_array('float', $names, true) && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array('bool', $names, true)) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($bool !== null) {
                return $bool;
            }
        }

        return $value;
    }
}
