<h1 align="center">
  Reactor 🎯
</h1>

<p align="center">
  A high-performance, context-driven event routing engine.
<p>

<p align="center">
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-%5E8.4-8892BF.svg" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/tests-passing-brightgreen.svg" alt="Tests">
</p>

Designed specifically for applications that ingest semi-structured payload streams—such as Telegram/Discord/Slack bots, incoming webhook pipelines, WebSocket microservices, and event-driven backends—it replaces brittle `if/else` and `switch` statements with an expressive, Laravel-inspired routing syntax over arbitrary associative arrays and JSON payloads.

With Reactor, you define declarative routes with pattern matching, dot-notation lookups, regex constraints, onion-layer middleware pipelines, hierarchical nested groups, and automatic reflection-based dependency injection.

---

## Table of Contents

* [Use Cases and Practical Applications](#use-cases-and-practical-applications)
* [Installation and Requirements](#installation-and-requirements)
* [Core Architecture](#core-architecture)
* [Context Deep Dive](#context-deep-dive)
  * [Instantiation and Dot-Notation Access](#instantiation-and-dot-notation-access)
  * [Context Comparison Helpers](#context-comparison-helpers)
* [Condition Matching Engine](#condition-matching-engine)
  * [1. Strict Equality and Dot-Paths (AND Logic)](#1-strict-equality-and-dot-paths-and-logic)
  * [2. Multiple Alternatives (OR Logic)](#2-multiple-alternatives-or-logic)
  * [3. Closure Conditions](#3-closure-conditions)
  * [4. Match-All Catch Routes](#4-match-all-catch-routes)
  * [5. Absent Keys and Null Matching](#5-absent-keys-and-null-matching)
* [Route Templates and Placeholders](#route-templates-and-placeholders)
  * [Required Placeholders](#required-placeholders)
  * [Optional Placeholders](#optional-placeholders)
  * [Numeric Context Capture](#numeric-context-capture)
  * [Case Sensitivity and ignoreCase()](#case-sensitivity-and-ignorecase)
  * [Placeholder Uniqueness Constraint](#placeholder-uniqueness-constraint)
* [Parameter Constraints (where)](#parameter-constraints-where)
  * [Built-In Constraint Shortcuts](#built-in-constraint-shortcuts)
  * [Custom Regular Expressions and Slashes](#custom-regular-expressions-and-slashes)
  * [Enum Whitelists with whereIn](#enum-whitelists-with-wherein)
* [Raw Regular Expressions (PCRE)](#raw-regular-expressions-pcre)
  * [Supported Delimiters and Flags](#supported-delimiters-and-flags)
  * [Escaped Delimiters Inside Patterns](#escaped-delimiters-inside-patterns)
  * [Named vs Positional Captures](#named-vs-positional-captures)
* [Custom Pattern Matchers](#custom-pattern-matchers)
* [Handler Formats and Dependency Injection](#handler-formats-and-dependency-injection)
  * [Supported Handler Types](#supported-handler-types)
  * [Automatic Parameter Resolution](#automatic-parameter-resolution)
  * [Type Casting and Overflow Protection](#type-casting-and-overflow-protection)
  * [PSR-11 and Container Resolution](#psr-11-and-container-resolution)
* [Middleware Pipeline](#middleware-pipeline)
  * [Pipeline Flow](#pipeline-flow)
  * [Middleware Formats](#middleware-formats)
  * [Aliases and Parameter Injection](#aliases-and-parameter-injection)
  * [Variadic Middleware Parameters](#variadic-middleware-parameters)
  * [Global, Group, and Route Middleware](#global-group-and-route-middleware)
  * [Mutating Context Across Layers](#mutating-context-across-layers)
* [Hierarchical Route Groups](#hierarchical-route-groups)
  * [Group Attributes and Callable-Only Form](#group-attributes-and-callable-only-form)
  * [Nested Groups and Attribute Inheritance](#nested-groups-and-attribute-inheritance)
  * [Adding Conditions via scope()](#adding-conditions-via-scope)
  * [Lazy and Retroactive Attribute Propagation](#lazy-and-retroactive-attribute-propagation)
  * [Exception Safety in Groups](#exception-safety-in-groups)
* [Execution Flow and Propagation Control](#execution-flow-and-propagation-control)
  * [Priority Ordering](#priority-ordering)
  * [Understanding Return Values: false, null, true, void](#understanding-return-values-false-null-true-void)
  * [Halting Propagation with stop()](#halting-propagation-with-stop)
  * [Fallback Handler](#fallback-handler)
* [Dispatch Modes: dispatch() vs run()](#dispatch-modes-dispatch-vs-run)
  * [1. Direct Payload Dispatching](#1-direct-payload-dispatching)
  * [2. Pre-Bound Context with run()](#2-pre-bound-context-with-run)
  * [3. Inspecting Context with context()](#3-inspecting-context-with-context)
  * [4. Direct Listener Registration with add()](#4-direct-listener-registration-with-add)
* [Complete API Reference](#complete-api-reference)
  * [Reactor\Context](#reactorcontext)
  * [Reactor\Dispatcher](#reactordispatcher)
  * [Reactor\Listener](#reactorlistener)
  * [Reactor\Group](#reactorgroup)
* [Running Tests](#running-tests)
* [License](#license)

---

## Use Cases and Practical Applications

* **Telegram, Discord & Slack Bots:** Cleanly route incoming messages, button callbacks (`callback_query.data`), inline queries, and multi-step conversation states based on nested JSON keys without nested `if/switch` blocks.
* **Webhook Ingestion Gateways:** Ingest webhooks from Stripe, GitHub, Shopify, or PayPal, routing events directly to specialized controllers based on payload fields like `event.type`, `action`, or `status`.
* **Event-Driven Architectures & CQRS:** Dispatch internal domain events and async message-bus envelopes (RabbitMQ, Redis Streams, Kafka) by inspecting envelope metadata and payload shape.
* **JSON-RPC & Microservice Routers:** Route internal RPC requests based on body fields (`method`, `params.action`, `version`) rather than relying on HTTP URIs.
* **State Machine & Workflow Routing:** Inspect context state flags and execute transition handlers when matching specific state configurations.

---

## Installation and Requirements

* **PHP:** `8.4` or higher
* **Dependencies:** [collectable/collection](https://github.com/chipslays/collectable)

Install via Composer:

```bash
composer require reactor/reactor
```

---

## Core Architecture

Reactor is built around four decoupled, highly cohesive classes:

1. **`Reactor\Context`**: An immutable-friendly data container wrapping incoming event data. Extends `Collectable\Collection` and adds strict comparison helpers.
2. **`Reactor\Dispatcher`**: The engine and orchestrator. Stores registered listeners, manages global middleware, compiles route templates, coordinates class resolution, and executes the dispatch pipeline.
3. **`Reactor\Listener`**: An individual route definition encapsulating its conditions, target handler, parameter constraints, priority, and assigned middleware.
4. **`Reactor\Group`**: A grouping container providing shared attributes (conditions, middleware, priority) to registered listeners. Supports infinite nesting and resolves attributes lazily.

---

## Context Deep Dive

The `Context` object encapsulates the raw event payload. Because it inherits from [`Collectable\Collection`](https://github.com/chipslays/collectable), it includes a comprehensive set of array manipulation and lookup methods.

### Instantiation and Dot-Notation Access

```php
use Reactor\Context;

$ctx = new Context([
    'update_id' => 881293,
    'message' => [
        'id'   => 4001,
        'from' => [
            'id'       => 101,
            'username' => 'alex_dev',
            'is_bot'   => false,
        ],
        'chat' => [
            'type'  => 'private',
            'title' => null,
        ],
    ],
    'meta' => [
        'tags' => ['vip', 'tester'],
    ],
]);

// 1. Dot-notation lookups
$username = $ctx->get('message.from.username');         // "alex_dev"
$missing  = $ctx->get('message.from.first_name', 'N/A'); // "N/A"

// 2. Existence verification
$exists = $ctx->has('message.from.id'); // true
$absent = $ctx->has('message.location'); // false

// 3. Slicing and extraction
$data = $ctx->only(['update_id', 'message.from.id']);
// ['update_id' => 881293, 'message.from.id' => 101]

$filtered = $ctx->except(['meta']);

// 4. Access all raw data
$rawArray = $ctx->all();
```

### Context Comparison Helpers

`Context` provides dedicated methods for strict type comparisons:

```php
// is(string $path, mixed $value): bool (Strict === check)
$ctx->is('message.chat.type', 'private'); // true
$ctx->is('message.from.id', '101');       // false (int 101 !== string '101')

// isTrue(string $path): bool (Strict === true check)
$ctx->isTrue('message.from.is_bot');      // false
$ctx->isTrue('non_existing_key');         // false

// isFalse(string $path): bool (Strict === false check)
$ctx->isFalse('message.from.is_bot');     // true
$ctx->isFalse('message.chat.title');      // false (null is not false)
```

---

## Condition Matching Engine

The Dispatcher checks incoming events against conditions attached to each listener. A condition can be an associative array, a list of arrays (disjunction), a Closure, or a template.

### 1. Strict Equality and Dot-Paths (AND Logic)

When you pass an associative array, **every** path must match the expected value (logical `AND`):

```php
$dispatcher->on([
    'type'              => 'message',
    'message.chat.type' => 'supergroup',
    'user.is_admin'     => true,
], function () {
    return 'Admin message received in supergroup';
});
```

### 2. Multiple Alternatives (OR Logic)

When you provide an indexed array of condition sets, the listener matches if **any one** of the sets matches (logical `OR`):

```php
$dispatcher->on([
    ['command' => '/start'],
    ['command' => '/help'],
    ['action'  => 'show_welcome_screen'],
], function () {
    return 'Displaying navigation menu';
});
```

### 3. Closure Conditions

For dynamic conditions that cannot be expressed as static values, pass a Closure returning a boolean. The Closure receives the active `Context`:

```php
$dispatcher->on(
    fn(Context $ctx) => $ctx->is('type', 'transaction') && (float) $ctx->get('amount', 0) > 10000.0,
    function (Context $ctx) {
        return "🚨 Large transaction flagged: " . $ctx->get('amount');
    }
);
```

Closures can also be mixed directly inside OR condition lists:

```php
$dispatcher->on([
    ['type' => 'immediate_action'],
    fn(Context $ctx) => $ctx->get('user.reputation', 0) > 100,
], fn() => 'Access granted');
```

### 4. Match-All Catch Routes

Passing an empty array `[]` applies no constraints. The listener matches every event reaching it:

```php
$dispatcher->on([], function (Context $ctx) {
    return 'Default processor for any unconstrained event';
});
```

### 5. Absent Keys and Null Matching

If a dot-path does not exist in the context, `Context::get()` returns `null`. Therefore:

```php
$dispatcher->on(['message.edit_date' => null], function () {
    return 'This update was never edited (key is either absent or explicitly null).';
});
```

---

## Route Templates and Placeholders

Route templates extract parameters directly from string values in the context using `{placeholder}` notation. By default, a placeholder matches any continuous sequence of non-whitespace characters (`\S+`).

### Required Placeholders

```php
$dispatcher->on(['text' => 'order {item_id} {quantity}'], function (string $item_id, int $quantity) {
    return "Ordering {$quantity} unit(s) of item {$item_id}";
});

$dispatcher->dispatch(['text' => 'order SKU-884 5']);
// Output: Ordering 5 unit(s) of item SKU-884
```

### Optional Placeholders

Append a question mark `{param?}` to make a placeholder optional.

* **With space:** `"cmd {arg?}"` automatically treats the leading space as optional. It matches `"cmd"` as well as `"cmd test"`.
* **Without space:** `"page{num?}"` matches `"page"` as well as `"page12"`.

```php
$dispatcher->on(['text' => 'list {page?}'], function (int $page = 1) {
    return "Viewing catalog page #{$page}";
});

$dispatcher->dispatch(['text' => 'list']);    // Viewing catalog page #1
$dispatcher->dispatch(['text' => 'list 4']);  // Viewing catalog page #4
```

### Numeric Context Capture

Templates also work on numeric context values (integers or floats). If your incoming JSON has an integer ID, a placeholder template will match and capture it cleanly:

```php
$dispatcher->on(['user.id' => '{id}'], function (int $id) {
    return "Captured user ID: {$id}";
});

$dispatcher->dispatch(['user' => ['id' => 9912]]);
// Output: Captured user ID: 9912
```

### Case Sensitivity and ignoreCase()

By default, template literals are case-sensitive. You can enable case-insensitive matching by calling `->ignoreCase()` on the listener:

```php
$dispatcher->on(['text' => 'find {query}'], function (string $query) {
    return "Searching for: {$query}";
})->ignoreCase();

$dispatcher->dispatch(['text' => 'FIND php']); // Matches!
$dispatcher->dispatch(['text' => 'find PHP']); // Matches!
```

> **Note:** `ignoreCase()` affects `{placeholder}` templates. Plain string equality remains strict, and raw regexes use their own PCRE flags.

### Placeholder Uniqueness Constraint

Placeholder names inside a single template string must be unique. Defining duplicates (e.g. `{x} {x}`) is invalid and throws an `InvalidArgumentException`:

```php
// Throws InvalidArgumentException on dispatch:
$dispatcher->on(['cmd' => 'compare {id} {id}'], fn() => '...');
```

---

## Parameter Constraints (where)

You can constrain placeholders using regular expressions. If a placeholder fails its constraint, the route match fails and the dispatcher proceeds to subsequent listeners.

### Built-In Constraint Shortcuts

| Method | Pattern Applied | Description |
|---|---|---|
| `whereNumber(...$params)` | `[0-9]+` | Strictly ASCII digits (avoids non-ASCII Unicode digits) |
| `whereAlpha(...$params)` | `[a-zA-Z]+` | Strictly English alphabetic characters |
| `whereAlphaNumeric(...$params)` | `[a-zA-Z0-9]+` | Letters and digits only |
| `whereIn($param, array $values)` | `(?:val1\|val2)` | Must match one of the exact string values |
| `where(string\|array $name, ?string $pattern = null)` | Custom regex | Custom pattern for one or more parameters |

```php
$dispatcher->on(['cmd' => 'user {id} {tag} {code}'], $handler)
    ->whereNumber('id')              // id must be 0-9
    ->whereAlpha('tag')              // tag must be a-z/A-Z
    ->whereAlphaNumeric('code');     // code must be alphanumeric
```

### Custom Regular Expressions and Slashes

Pass custom regex strings without enclosing delimiters. Reactor automatically handles internal slashes so they never prematurely terminate compiled patterns:

```php
$dispatcher->on(['path' => 'file/{filepath}'], $handler)
    ->where('filepath', '[a-zA-Z0-9_]+/[a-zA-Z0-9_]+'); // Safely matches "folder/file"
```

You can also pass an associative array:

```php
$listener->where([
    'uuid' => '[0-9a-fA-F-]{36}',
    'lang' => '[a-z]{2}',
]);
```

### Enum Whitelists with whereIn

`whereIn()` automatically escapes all values and builds an alternation regex:

```php
$dispatcher->on(['text' => 'locale {lang}'], function (string $lang) {
    return "Locale set to {$lang}";
})->whereIn('lang', ['en', 'ru', 'es', 'pt-br', 'custom/locale']);
```

> **Edge case protection:** If an empty array `whereIn('param', [])` is passed, Reactor compiles it to `(?!)`, which is guaranteed to never match any input.

---

## Raw Regular Expressions (PCRE)

When you need complete control over pattern matching, pass a raw regular expression directly as the condition value.

### Supported Delimiters and Flags

Reactor automatically recognizes raw regexes enclosed in any of these five standard delimiters: `/`, `~`, `#`, `%`, or `@`:

```php
// 1. Standard slash delimiter with case-insensitive modifier
$dispatcher->on(['command' => '/^\/stats$/i'], fn() => 'Displaying stats');

// 2. Tilde delimiter (ideal when pattern contains slashes)
$dispatcher->on(['url' => '~^/api/v1/users/(?P<id>\d+)$~'], function (int $id) {
    return "API User #{$id}";
});

// 3. Hash delimiter
$dispatcher->on(['hash' => '#^[a-f0-9]{32}$#'], fn() => 'Valid MD5 hash');

// 4. Percent and At delimiters
$dispatcher->on(['token' => '%^[A-Z0-9]{16}$%'], fn() => 'Token matched');
$dispatcher->on(['slug'  => '@^[a-z\-]+$@'], fn() => 'Slug matched');
```

### Escaped Delimiters Inside Patterns

If the pattern body contains an escaped instance of its chosen delimiter, Reactor parses it correctly without confusing it with the closing delimiter:

```php
$dispatcher->on(['url'  => '/^a\/b$/'], fn() => 'Matched slash delimiter');
$dispatcher->on(['tag'  => '~^x\~y$~'], fn() => 'Matched tilde delimiter');
```

Strings that look like malformed delimiters (e.g. `///` or `##`) are treated as literal strings, preventing unwanted regex compilation errors.

### Named vs Positional Captures

* **Named Captures:** If named capture groups (`(?P<name>...)` or `(?<name>...)`) are present, they are extracted and mapped to handler parameters matching their names. Unnamed captures in the same expression are ignored.
* **Positional Captures:** If **only** unnamed capture groups are present, they are injected into handler parameters by position (`$1`, `$2`, etc.).
* **Optional Captures:** If an optional capture group does not match (e.g. `(?P<ext>\.[a-z]+)?`), `null` is injected.

```php
// Positional capture injection
$dispatcher->on(['tag' => '/^#([a-zA-Z]+)-([0-9]+)$/'], function (string $category, int $id) {
    return "Tag: {$category}, ID: {$id}";
});

// Named capture injection (order in signature does not matter)
$dispatcher->on(['ref' => '/^ref_(?P<campaign>\w+)_(?P<affiliate>\d+)$/'], function (int $affiliate, string $campaign) {
    return "Affiliate {$affiliate} on campaign {$campaign}";
});
```

---

## Custom Pattern Matchers

You can extend Reactor's matching engine by registering custom matchers with `$dispatcher->matcher()`.

The matcher callback receives `($pattern, $value, $patterns, &$args)`:
* Return `true`: Condition is satisfied. Any key-value pairs written into `&$args` are injected as handler arguments.
* Return `false`: Condition failed. Listener is rejected.
* Return `null`: Pattern is not handled by this matcher; Reactor passes it to subsequent matchers or the default engine.

```php
// 1. Add support for "starts_with:" prefix
$dispatcher->matcher(function ($pattern, $value, $patterns, &$args) {
    if (is_string($pattern) && str_starts_with($pattern, 'starts_with:')) {
        $prefix = substr($pattern, 12);
        if (is_string($value) && str_starts_with($value, $prefix)) {
            $args['matched_prefix'] = $prefix;
            $args['matched_string'] = $value;
            return true;
        }
        return false;
    }
    return null; // Defer to regular engine
});

// 2. Add support for "*" wildcards
$dispatcher->matcher(function ($pattern, $value) {
    if ($pattern === '*') {
        return $value !== null;
    }
    return null;
});

// Usage
$dispatcher->on(['text' => 'starts_with:/admin_'], function (string $matched_string) {
    return "Admin command detected: {$matched_string}";
});
```

---

## Handler Formats and Dependency Injection

### Supported Handler Types

Reactor supports all modern PHP callable formats:

```php
// 1. Closures
$dispatcher->on(['action' => 'ping'], fn() => 'pong');

// 2. Class and method tuple
$dispatcher->on(['cmd' => 'user {id}'], [UserController::class, 'show']);

// 3. Laravel-style string syntax
$dispatcher->on(['cmd' => 'user {id}'], 'UserController@show');

// 4. Invokable class (must implement __invoke)
$dispatcher->on(['action' => 'process'], ProcessOrderHandler::class);

// 5. Existing object instance and method
$dispatcher->on(['action' => 'sync'], [$syncService, 'run']);

// 6. Static class method
$dispatcher->on(['action' => 'version'], [SystemInfo::class, 'version']);
```

### Automatic Parameter Resolution

Handler parameters are resolved via PHP Reflection:

1. **`Context` Injection:** Any parameter type-hinted as `Context`, its parent [`Collectable\Collection`](https://github.com/chipslays/collectable), or a child class receives the current `Context` instance. It can be placed at any position in the parameter list.
2. **Named Argument Mapping:** Placeholders and named regex groups are matched directly to parameter names (e.g. `{username}` binds to `$username`).
3. **Positional Arguments:** If no named parameters match, captured parameters are mapped by position.
4. **Default Values & Nullability:** Missing arguments fall back to their PHP default value. If untyped or nullable without a default, `null` is injected.
5. **Variadic Arguments in Handlers:** Variadic arguments (`...$rest`) are ignored during injection without raising parameter-resolution exceptions.

```php
$dispatcher->on(['cmd' => 'greet {name} {greeting?}'], function (Context $ctx, string $name, string $greeting = 'Hello') {
    $senderId = $ctx->get('from.id');
    return "{$greeting}, {$name}! (From sender #{$senderId})";
});
```

### Type Casting and Overflow Protection

Extracted strings are automatically cast to scalar types matching your parameter type-hints:

* **`int`**: Numerical strings are cast to integers. Leading zeros are safely handled (e.g., `"007"` becomes `7`, `"000"` becomes `0`).
* **Integer Overflow Protection:** If a number exceeds `PHP_INT_MAX`, Reactor will not silently corrupt or truncate the number; it safely passes the string or throws a `TypeError` if strict typing is required.
* **`float`**: Floating-point strings are converted to `float`.
* **`bool`**: String values `"true"`, `"1"` become `true`; `"false"`, `"0"` become `false`.
* **Union Types:** E.g. `int|float` resolves to the most accurate scalar representation. If `string` or `mixed` is part of the union (e.g. `string|int`), the value remains a `string` to prevent unwanted mutation.

### PSR-11 and Container Resolution

By default, handler and middleware classes are created via `new $class()`. You can wire up your PSR-11 dependency injection container (PHP-DI, Laravel Container, Symfony DI) using `resolver()`:

```php
use Psr\Container\ContainerInterface;

/** @var ContainerInterface $container */
$container = $myApp->getContainer();

// Teach the dispatcher how to resolve classes using your container:
$dispatcher->resolver(fn(string $class) => $container->get($class));
```

Now any class registered by name (`UserController@show`, `[OrderController::class, 'cancel']`, or middleware classes) will be resolved through your DI container with full autowiring.

---

## Middleware Pipeline

Reactor implements an onion-architecture middleware pipeline. Middleware can inspect the payload, mutate the `Context`, verify permissions, rate-limit, or abort execution.

### Pipeline Flow

```
Incoming Context
       │
       ▼
┌──────────────────────────────────────────────┐
│ Global Middleware                            │
│   ┌────────────────────────────────────────┐ │
│   │ Group Middleware (Parent -> Child)     │ │
│   │   ┌──────────────────────────────────┐ │ │
│   │   │ Listener Middleware              │ │ │
│   │   │   ┌────────────────────────────┐ │ │ │
│   │   │   │ Target Handler             │ │ │ │
│   │   │   └────────────────────────────┘ │ │ │
│   │   └──────────────────────────────────┘ │ │
│   └────────────────────────────────────────┘ │
└──────────────────────────────────────────────┘
```

### Middleware Formats

Middleware must satisfy the signature `function (Context $ctx, Closure $next, ...$args): mixed`.

#### 1. Closures
```php
$dispatcher->middleware(function (Context $ctx, Closure $next) {
    if ($ctx->get('blocked') === true) {
        return 'Access blocked'; // Terminate without calling $next
    }
    return $next($ctx);
});
```

#### 2. Class with `handle()` Method
```php
class ThrottleMiddleware
{
    public function handle(Context $ctx, Closure $next, int $limit = 5): mixed
    {
        $userId = (string) $ctx->get('user.id', 'guest');
        if (RateLimiter::isExceeded($userId, $limit)) {
            return "Too many requests. Limit: {$limit}";
        }
        return $next($ctx);
    }
}
```

#### 3. Invokable Class
```php
class VerifySignature
{
    public function __invoke(Context $ctx, Closure $next): mixed
    {
        if (!$ctx->has('signature')) {
            return false; // Treat as unhandled, advance to next listener
        }
        return $next($ctx);
    }
}
```

### Aliases and Parameter Injection

Register aliases using `$dispatcher->alias()`. Parameters can be passed after a colon `:` separated by commas `,`:

```php
$dispatcher->alias('role', function (Context $ctx, Closure $next, string ...$roles) {
    if (!in_array($ctx->get('user.role'), $roles, true)) {
        return "Denied: Required role [" . implode(',', $roles) . "]";
    }
    return $next($ctx);
});

$dispatcher->alias('throttle', ThrottleMiddleware::class);

// Usage on listener:
$dispatcher->on(['cmd' => '/admin'], fn() => 'Admin Panel')
    ->middleware('role:admin,superadmin', 'throttle:10');
```

Parameters passed in string notation are automatically cast to the types declared in the middleware signature using Reflection.

### Variadic Middleware Parameters

Unlike route handlers, middleware methods natively support variadic parameter arguments (`string ...$roles`). The types of the variadic parameter are used to coerce each separated string argument:

```php
$dispatcher->alias('permissions', function (Context $ctx, Closure $next, int ...$permissionIds) {
    // $permissionIds is automatically an array of integers: [1, 5, 12]
    return $next($ctx);
});

$dispatcher->on(['cmd' => 'edit'], fn() => 'ok')
    ->middleware('permissions:1,5,12');
```

### Global, Group, and Route Middleware

```php
// 1. Global: Wraps ALL dispatched events, including the Fallback handler
$dispatcher->middleware(GlobalLogger::class);

// 2. Group: Wraps all listeners registered inside this group
$dispatcher->group(['middleware' => 'auth'], function ($group) {
    $group->on(['cmd' => 'me'], fn() => 'Profile');
});

// 3. Listener-specific:
$dispatcher->on(['cmd' => 'wipe'], fn() => 'Wiped')
    ->middleware('role:superadmin');
```

### Mutating Context Across Layers

Middleware can modify data or create a new `Context` instance and pass it down the pipeline:

```php
$dispatcher->middleware(function (Context $ctx, Closure $next) {
    $user = UserRepository::find($ctx->get('user_id'));

    // Pass updated context down the pipeline
    return $next(new Context([
        ...$ctx->all(),
        'user' => $user,
    ]));
});
```

---

## Hierarchical Route Groups

Route groups bundle shared conditions, middleware, and base priority settings across multiple listeners.

### Group Attributes and Callable-Only Form

You can create groups with an attribute array or using the callable-only shorthand:

```php
// 1. With attribute array:
$dispatcher->group([
    'conditions' => ['chat.type' => 'supergroup'],
    'middleware' => ['auth', 'rate_limit:100'],
    'priority'   => 20,
], function (\Reactor\Group $group) {
    $group->on(['text' => '/rules'], fn() => 'Group Rules');
});

// 2. Shorthand (callable only without attributes):
$group = $dispatcher->group(function (\Reactor\Group $group) {
    $group->on(['action' => 'ping'], fn() => 'pong');
});
```

### Nested Groups and Attribute Inheritance

Groups can be nested indefinitely. Attributes accumulate down the hierarchy:

```php
$dispatcher->group(['conditions' => ['module' => 'billing']], function ($billing) {

    // Module is billing AND action is invoice
    $billing->on(['action' => 'invoice'], fn() => 'Invoices');

    // Nested group: Module is billing AND role is accountant
    $billing->group(['middleware' => 'role:accountant'], function ($accountant) {
        $accountant->on(['action' => 'refund'], fn() => 'Process Refund');
    });
});
```

* **Conditions:** Parent and child conditions are combined with logical `AND`. A nested route can only narrow its parent scope, never widen it.
* **Middleware:** Parent group middleware executes before child group middleware.
* **Priority:** Nested listeners inherit their parent group's priority unless explicitly overridden.

### Adding Conditions via scope()

You can fluently add additional condition scopes to a group at any time using `$group->scope()`:

```php
$group = $dispatcher->group(['priority' => 10]);

// Add array condition scope
$group->scope(['chat.type' => 'private']);

// Add closure condition scope
$group->scope(fn(Context $ctx) => $ctx->has('user.id'));
```

### Lazy and Retroactive Attribute Propagation

Groups maintain live references to registered listeners. Adding middleware or adjusting priorities on a group *after* defining routes updates all registered listeners retroactively:

```php
$group = $dispatcher->group(['priority' => 10]);
$group->on(['action' => 'audit'], fn() => 'Audit log');

// Middleware added after listener declaration is still executed!
$group->middleware(SecurityCheckMiddleware::class);
```

### Exception Safety in Groups

If an exception is thrown inside a group callback, Reactor safely unwinds its internal group stack via `try...finally`. This guarantees that subsequent routes registered outside the group do not accidentally inherit the failed group's attributes:

```php
try {
    $dispatcher->group(['conditions' => ['broken' => true]], function () {
        throw new RuntimeException('Config error');
    });
} catch (RuntimeException) {
    // Stack is cleanly unwound
}

// Registered at root level, NOT inside the broken group:
$dispatcher->on(['cmd' => 'health'], fn() => 'Healthy');
```

---

## Execution Flow and Propagation Control

### Priority Ordering

Listeners are evaluated in descending order of priority (e.g. `100` runs before `10`, which runs before `0`). Listeners with equal priorities execute in the order they were registered.

```php
// Run urgent maintenance check first
$dispatcher->on([], fn() => 'System undergoing maintenance')
    ->priority(1000);

// Default priority (0)
$dispatcher->on(['cmd' => 'ping'], fn() => 'pong');

// Negative priority (runs last)
$dispatcher->on([], fn() => 'Default catch-all')
    ->priority(-100);
```

### Understanding Return Values: false, null, true, void

In Reactor, return values control whether the event is considered **handled**:

> **The Golden Rule:**
> * **`return false`** means: *"The event is NOT handled — pass control to the next matching listener."*
> * **Any other value (`true`, `null`, `0`, `""`, `[]`, objects, or NO return statement at all)** means: *"The event is SUCCESSFULLY handled — stop the chain and return this result."*

#### Do you need to return anything in your handlers?
**No!** If your handler just performs an action (e.g., sends a message or writes to a database) without an explicit `return` statement:

```php
$dispatcher->on(['cmd' => '/start'], function (Context $ctx) {
    Telegram::sendMessage($ctx->get('chat.id'), 'Welcome!');
    // No return statement -> PHP returns null automatically
});
```

Because PHP implicitly returns `null` for `void` functions, the Dispatcher treats `null` as success, completes the dispatch cycle, and prevents fallback from executing.

#### Using `return false` for Pass-Through Listeners

```php
// 1. Analytics Listener (priority 50)
$dispatcher->on(['type' => 'order'], function (Context $ctx) {
    Metrics::increment('orders');
    return false; // Tells dispatcher: "I logged this, keep looking for the real handler!"
})->priority(50);

// 2. Real Business Handler (priority 10)
$dispatcher->on(['type' => 'order'], function (Context $ctx) {
    return OrderService::create($ctx->all());
})->priority(10);
```

### Halting Propagation with stop()

If you want a listener to reject an event or return `false`, but **prohibit** any further listeners or fallback from running, call `->stop()` on the listener:

```php
$dispatcher->on(['user.is_banned' => true], function () {
    // User is banned: return false, but stop all further execution
    return false;
})->stop();

// This listener will NEVER execute if user.is_banned is true:
$dispatcher->on(['user.is_banned' => true], fn() => 'Secret Admin Data');
```

### Fallback Handler

If no listeners match the event (or every matching listener returns `false` without `stop()`), the fallback handler is executed:

```php
$dispatcher->fallback(function (Context $ctx) {
    return "No listener handled update ID #" . $ctx->get('update_id', 'unknown');
});
```

* The fallback handler supports all callable formats (`Closure`, `Controller@method`, invokable classes).
* Global middleware wraps the fallback handler. Group and listener-level middleware do not.

---

## Dispatch Modes: dispatch() vs run()

### 1. Direct Payload Dispatching

Pass context data directly to `dispatch()`:

```php
$dispatcher = new Dispatcher();
$dispatcher->on(['type' => 'alert'], fn() => 'Alert acknowledged');

$result = $dispatcher->dispatch($_POST);
```

### 2. Pre-Bound Context with run()

Bind the context during instantiation or bootstrap, and execute via `run()`:

```php
$dispatcher = new Dispatcher($_POST); // Context bound in constructor

$dispatcher->on(['type' => 'alert'], fn() => 'Alert acknowledged');

$result = $dispatcher->run();
```

> **Inline Override:** If you pass data to `dispatch($inlineData)` on a dispatcher that already has bound context, the inline data is processed for that single call without overwriting the stored bound context.

### 3. Inspecting Context with context()

You can retrieve the currently bound `Context` instance using `$dispatcher->context()`:

```php
$dispatcher = new Dispatcher(['app' => 'v1']);
$currentContext = $dispatcher->context(); // Instance of Reactor\Context
```

### 4. Direct Listener Registration with add()

You can instantiate `Listener` objects independently and register them via `$dispatcher->add()`:

```php
$listener = new \Reactor\Listener(['cmd' => 'status'], fn() => 'Online');
$listener->priority(10);

$dispatcher->add($listener);
```

---

## Complete API Reference

### Reactor\Context
Extends [`Collectable\Collection`](https://github.com/chipslays/collectable).

| Method | Return Type | Description |
|---|---|---|
| `is(string $path, mixed $value)` | `bool` | Strict equality check (`$ctx->get($path) === $value`). |
| `isTrue(string $path)` | `bool` | Checks if path is strictly `true` (`=== true`). |
| `isFalse(string $path)` | `bool` | Checks if path is strictly `false` (`=== false`). |
| `...` | `...` | See more methods here -> [`Collectable\Collection`](https://github.com/chipslays/collectable) |

### Reactor\Dispatcher

| Method | Return Type | Description |
|---|---|---|
| `__construct(array\|Context\|null $context = null)` | `void` | Creates dispatcher and optionally binds context. |
| `bind(array\|Context $data)` | `self` | Binds default context data. |
| `context()` | `?Context` | Returns the currently bound context instance. |
| `resolver(callable $resolver)` | `self` | Registers custom DI resolver `fn(string $class): object`. |
| `matcher(callable $matcher)` | `self` | Registers custom pattern matcher `fn($pat, $val, $pats, &$args): ?bool`. |
| `alias(string $name, mixed $middleware)` | `self` | Maps an alias string to a middleware class or closure. |
| `middleware(mixed ...$middleware)` | `self` | Registers global middleware layers. |
| `on(mixed $conditions, mixed $handler)` | `Listener` | Registers a listener (or forwards to active group). |
| `add(Listener $listener)` | `Listener` | Adds an instantiated `Listener` object directly. |
| `group(array\|callable $attributes, ?callable $callback = null)` | `Group` | Creates an attribute group and executes callback. |
| `fallback(mixed $handler)` | `self` | Sets fallback handler for unhandled events. |
| `dispatch(array\|Context\|null $data = null)` | `mixed` | Runs the pipeline against the given context. |
| `run()` | `mixed` | Alias for `dispatch()` using bound context. |

### Reactor\Listener

| Method | Return Type | Description |
|---|---|---|
| `priority(int $priority)` | `self` | Sets listener priority (higher numbers execute first). |
| `middleware(mixed ...$middleware)` | `self` | Attaches middleware to this specific listener. |
| `where(string\|array $name, ?string $pattern = null)` | `self` | Adds regex constraint(s) to placeholder(s). |
| `whereNumber(string ...$names)` | `self` | Constrains placeholders to `[0-9]+` (strictly ASCII). |
| `whereAlpha(string ...$names)` | `self` | Constrains placeholders to `[a-zA-Z]+`. |
| `whereAlphaNumeric(string ...$names)` | `self` | Constrains placeholders to `[a-zA-Z0-9]+`. |
| `whereIn(string $name, array $values)` | `self` | Constrains placeholder to list of exact values. |
| `ignoreCase(bool $ignore = true)` | `self` | Toggles case-insensitive matching for templates. |
| `stop(bool $stop = true)` | `self` | Prevents further listeners from running even if `false` is returned. |
| `getPriority()` | `int` | Returns effective priority (listener or group default). |
| `getMiddleware()` | `array` | Returns merged middleware chain (group + own). |
| `getScope()` | `array` | Returns parent group condition scopes. |
| `getPatterns()` | `array` | Returns registered parameter constraint patterns. |
| `isIgnoreCase()` | `bool` | Checks whether template matching is case-insensitive. |
| `isStopped()` | `bool` | Checks whether propagation is stopped. |

### Reactor\Group

| Method | Return Type | Description |
|---|---|---|
| `on(mixed $conditions, mixed $handler)` | `Listener` | Registers a listener bound to this group's scope. |
| `middleware(mixed ...$middleware)` | `self` | Appends middleware to all listeners in this group. |
| `priority(int $priority)` | `self` | Sets default priority for listeners in this group. |
| `scope(array\|Closure $conditions)` | `self` | Adds a condition scope to the group (ANDed with listener rules). |
| `getMiddleware()` | `array` | Returns effective middleware (parents + own). |
| `getPriority()` | `?int` | Returns effective priority. |
| `getConditions()` | `array` | Returns all condition scopes (parents + own). |

---

## Running Tests

Reactor is thoroughly tested with [Pest PHP](https://pestphp.com). Run the test suite via Composer:

```bash
composer test
```

---

## License

Reactor Dispatcher is open-sourced software licensed under the [MIT License](LICENSE).
