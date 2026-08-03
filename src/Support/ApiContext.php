<?php

namespace ESolution\LaravelAccounting\Support;

use Illuminate\Http\Request;

class ApiContext
{
    public function __construct(
        protected string $action,
        protected ?Request $request = null,
        protected array $payload = [],
        protected array $attributes = []
    ) {}

    public static function fromArray(string $action, array $context): self
    {
        return new self(
            $action,
            $context['request'] ?? null,
            $context['payload'] ?? [],
            collect($context)->except(['request', 'payload'])->all()
        );
    }

    public function action(): string
    {
        return $this->action;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function mergePayload(array $payload): self
    {
        $this->payload = array_merge($this->payload, $payload);

        return $this;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->request?->header($key, $default) ?? $default;
    }

    public function routeParameter(string $key, mixed $default = null): mixed
    {
        return $this->request?->route($key, $default) ?? $default;
    }

    public function user(): mixed
    {
        return $this->request?->user();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function all(): array
    {
        return [
            'request' => $this->request,
            'payload' => $this->payload,
            ...$this->attributes,
        ];
    }
}
