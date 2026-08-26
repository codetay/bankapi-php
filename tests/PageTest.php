<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testIteratesOwnItems(): void
    {
        $page = new Page(['a', 'b'], '', static fn (string $c): Page => throw new \LogicException('must not fetch'));
        self::assertSame(['a', 'b'], iterator_to_array($page, false));
    }

    public function testAutoPagingWalksAllPages(): void
    {
        $pages = [
            'c1' => new Page(['c'], 'c2', static fn (string $c): Page => throw new \LogicException('stale fetcher')),
        ];
        $pages['c2'] = new Page(['d'], '', static fn (string $c): Page => throw new \LogicException('last page must not fetch'));

        $fetchCalls = [];
        $first = new Page(['a', 'b'], 'c1', static function (string $cursor) use (&$pages, &$fetchCalls): Page {
            $fetchCalls[] = $cursor;
            // each fetched page reuses this same fetcher via test wiring below
            $next = $pages[$cursor];

            return new Page($next->items, $next->nextCursor, static function (string $c) use (&$pages): Page {
                $n = $pages[$c];

                return new Page($n->items, $n->nextCursor, static fn (string $x): Page => throw new \LogicException('done'));
            });
        });

        self::assertSame(['a', 'b', 'c', 'd'], iterator_to_array($first->autoPaging(), false));
        self::assertSame(['c1'], $fetchCalls);
    }
}
