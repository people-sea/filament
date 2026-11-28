<?php

namespace Filament\Actions\Concerns;

trait HasArguments
{
    /**
     * @var array<string, mixed> | null
     */
    protected ?array $arguments = null;

    /**
     * @param  array<string, mixed> | null  $arguments
     */
    public function arguments(?array $arguments): static
    {
        if ($this->arguments !== $arguments) {
            $this->clearVisibilityCache();
            $this->getRootGroup()?->clearVisibilityCache();
        }

        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function mergeArguments(array $arguments): static
    {
        return $this->arguments([
            ...$this->arguments ?? [],
            ...$arguments,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments ?? [];
    }

    public function resetArguments(): static
    {
        $this->arguments(null);

        return $this;
    }

    public function hasArguments(): bool
    {
        return $this->arguments !== null;
    }
}
