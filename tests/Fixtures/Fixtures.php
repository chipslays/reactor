<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Reactor\Context;

class Controller
{
    public function show(int $id): string
    {
        return "show:$id";
    }

    public function __invoke(Context $ctx): string
    {
        return 'invoked:' . $ctx->get('type');
    }

    public static function stat(string $name): string
    {
        return "stat:$name";
    }
}

class NotInvokable
{
    public function run(): string
    {
        return 'run';
    }
}

class AuthMiddleware
{
    public function handle(Context $ctx, Closure $next, string $role = 'user'): mixed
    {
        return "auth($role):" . $next($ctx);
    }
}

class ThrottleMiddleware
{
    public function handle(Context $ctx, Closure $next, int $max, int $seconds): mixed
    {
        return "throttle($max,$seconds):" . $next($ctx);
    }
}

class InvokableMiddleware
{
    public function __invoke(Context $ctx, Closure $next): mixed
    {
        return 'inv:' . $next($ctx);
    }
}
