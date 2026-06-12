<?php

declare(strict_types=1);

namespace BlogCore\Core;

use PDO;

class QueryBuilder
{
    private PDO $db;
    private string $table;

    private array $selects  = ['*'];
    private array $joins    = [];
    private array $wheres   = [];       // ['sql' => '...', 'bindings' => [...]]
    private ?string $orderByCol = null;
    private string $orderByDir  = 'ASC';
    private ?int $limitVal  = null;
    private ?int $offsetVal = null;

    public function __construct(PDO $db, string $table)
    {
        $this->db    = $db;
        $this->table = $table;
    }

    // -------------------------------------------------------------------------
    // Fluent API
    // -------------------------------------------------------------------------

    public function select(string ...$columns): static
    {
        $this->selects = $columns;
        return $this;
    }

    public function where(string $column, mixed $value, string $op = '='): static
    {
        $this->wheres[] = [
            'sql'      => "{$column} {$op} ?",
            'bindings' => [$value],
        ];
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['sql' => $sql, 'bindings' => $bindings];
        return $this;
    }

    /**
     * INNER JOIN another table.
     * $on example: "post_tag_mapper.post_id = posts.id"
     */
    public function join(string $table, string $on): static
    {
        $this->joins[] = "INNER JOIN {$table} ON {$on}";
        return $this;
    }

    public function leftJoin(string $table, string $on): static
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$on}";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderByCol = $column;
        $this->orderByDir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    public function limit(int $n): static
    {
        $this->limitVal = $n;
        return $this;
    }

    public function offset(int $n): static
    {
        $this->offsetVal = $n;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    public function get(): array
    {
        [$sql, $bindings] = $this->buildSql();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function first(): ?array
    {
        $original = $this->limitVal;
        $this->limitVal = 1;
        $result = $this->get();
        $this->limitVal = $original;
        return $result[0] ?? null;
    }

    public function count(): int
    {
        $bindings = [];
        $sql  = "SELECT COUNT(*) AS cnt FROM {$this->table}";
        $sql .= $this->buildJoinClause();
        $sql .= $this->buildWhereClause($bindings);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function buildSql(): array
    {
        $bindings = [];
        $selects  = implode(', ', $this->selects);
        $sql      = "SELECT {$selects} FROM {$this->table}";
        $sql     .= $this->buildJoinClause();
        $sql     .= $this->buildWhereClause($bindings);

        if ($this->orderByCol !== null) {
            $sql .= " ORDER BY {$this->orderByCol} {$this->orderByDir}";
        }

        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }

        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return [$sql, $bindings];
    }

    private function buildJoinClause(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        return ' ' . implode(' ', $this->joins);
    }

    private function buildWhereClause(array &$bindings): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $where) {
            $parts[]  = $where['sql'];
            $bindings = array_merge($bindings, $where['bindings']);
        }

        return ' WHERE ' . implode(' AND ', $parts);
    }
}
