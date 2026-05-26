<?php

namespace Anon\Core\Pagination;

use IteratorAggregate;
use Traversable;

class Paginator implements IteratorAggregate
{
    /**
     * @param iterable<int|string, mixed> $items
     */
    public function __construct(
        protected iterable $items,
        protected int $total,
        protected int $page = 1,
        protected int $perPage = 15,
        protected ?string $path = null
    ) {
        $this->page = max(1, $this->page);
        $this->perPage = max(1, $this->perPage);
        $this->total = max(0, $this->total);
    }

    /**
     * @param iterable<int|string, mixed> $items
     */
    public static function make(iterable $items, int $total, int $page = 1, int $perPage = 15, ?string $path = null): self
    {
        return new self($items, $total, $page, $perPage, $path);
    }

    /**
     * @return array<int, mixed>
     */
    public function items(): array
    {
        return is_array($this->items) ? array_values($this->items) : iterator_to_array($this->items, false);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): ?int
    {
        if ($this->total === 0) {
            return null;
        }

        return (($this->page - 1) * $this->perPage) + 1;
    }

    public function to(): ?int
    {
        if ($this->total === 0) {
            return null;
        }

        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * @return array<string, int|null>
     */
    public function meta(): array
    {
        return [
            'current_page' => $this->page(),
            'per_page' => $this->perPage(),
            'total' => $this->total(),
            'last_page' => $this->lastPage(),
            'from' => $this->from(),
            'to' => $this->to(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function links(): array
    {
        return [
            'first' => $this->url(1),
            'last' => $this->url($this->lastPage()),
            'prev' => $this->page > 1 ? $this->url($this->page - 1) : null,
            'next' => $this->page < $this->lastPage() ? $this->url($this->page + 1) : null,
        ];
    }

    public function getIterator(): Traversable
    {
        yield from $this->items();
    }

    protected function url(int $page): ?string
    {
        if ($this->path === null || $this->path === '') {
            return null;
        }

        $separator = str_contains($this->path, '?') ? '&' : '?';

        return $this->path . $separator . 'page=' . $page . '&per_page=' . $this->perPage;
    }
}