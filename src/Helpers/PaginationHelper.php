<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

use BlogCore\Core\QueryBuilder;

class PaginationHelper
{
    /**
     * Run a paginated query and return a result envelope.
     *
     * @param QueryBuilder $query   A fully-configured QueryBuilder (wheres, joins, etc.)
     *                              Do NOT call limit/offset before passing in.
     * @param int          $page    Current page number (1-based).
     * @param int          $perPage Items per page.
     *
     * @return array{
     *   items:       array,
     *   total:       int,
     *   perPage:     int,
     *   currentPage: int,
     *   lastPage:    int,
     *   hasPrev:     bool,
     *   hasNext:     bool,
     *   prevPage:    int,
     *   nextPage:    int,
     * }
     */
    public static function paginate(QueryBuilder $query, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $total   = $query->count();
        $offset  = ($page - 1) * $perPage;
        $lastPage = max(1, (int)ceil($total / $perPage));

        $items = $query->limit($perPage)->offset($offset)->get();

        return [
            'items'       => $items,
            'total'       => $total,
            'perPage'     => $perPage,
            'currentPage' => $page,
            'lastPage'    => $lastPage,
            'hasPrev'     => $page > 1,
            'hasNext'     => $page < $lastPage,
            'prevPage'    => max(1, $page - 1),
            'nextPage'    => min($lastPage, $page + 1),
        ];
    }

    /**
     * Read the current page number from the query string (?page=N).
     */
    public static function currentPage(): int
    {
        return max(1, (int)($_GET['page'] ?? 1));
    }
}
