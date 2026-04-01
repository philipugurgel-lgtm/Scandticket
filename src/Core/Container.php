<?php
declare(strict_types=1);

namespace ScandTicket\Core;

use Closure;
use RuntimeException;

final class Container
{
    private static ?self $instance = null;
    private array $bindings = [];
    private array $singletons = [];
    private array $resolved = [];
    private array $providers = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, Closure|string $concrete): void
    {
        $this->singletons[$abstract] = $concrete;
    }

    public function make(string $abstract): mixed
    {
        if (isset($this->resolved[$abstract])) {
            return $this->resolved[$abstract];
        }

        if (isset($this->singletons[$abstract])) {
            $concrete = $this->singletons[$abstract];
            $obj = $concrete instanceof Closure ? $concrete($this) : new $concrete();
            $this->resolved[$abstract] = $obj;
            return $obj;
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            return $concrete instanceof Closure ? $concrete($this) : new $concrete();
        }

        if (class_exists($abstract)) {
            // Only auto-resolve classes that need no constructor arguments.
            // Classes with required parameters must be explicitly registered to
            // avoid silently constructing objects with uninitialised dependencies.
            $ctor = (new \ReflectionClass($abstract))->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return new $abstract();
            }
            throw new RuntimeException(
                "Service [{$abstract}] is not registered in the container and cannot be " .
                'auto-resolved because its constructor requires parameters. ' .
                'Register it via bind() or singleton() in a ServiceProvider.'
            );
        }

        throw new RuntimeException("Service [{$abstract}] not found in container.");
    }

    public function registerProviders(array $providers): void
    {
        foreach ($providers as $providerClass) {
            $provider = new $providerClass($this);
            $provider->register();
            $this->providers[] = $provider;
        }
    }

    public function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
    }
}