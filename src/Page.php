<?php

declare(strict_types=1);

namespace BankApi;

/**
 * One page of a cursor-paginated list. Exhausted when nextCursor is empty.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate
{
    /**
     * @param list<T>                 $items
     * @param \Closure(string): self<T> $fetch fetches the page at a cursor
     */
    public function __construct(
        public readonly array $items,
        public readonly string $nextCursor,
        private readonly \Closure $fetch,
    ) {
    }

    /** @return \Traversable<int, T> */
    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    /**
     * Iterate every item across all remaining pages, fetching lazily.
     *
     * @return \Generator<int, T>
     */
    public function autoPaging(): \Generator
    {
        $page = $this;
        while (true) {
            yield from $page->items;
            if ($page->nextCursor === '') {
                return;
            }
            $page = ($page->fetch)($page->nextCursor);
        }
    }
}
