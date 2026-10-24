<?php

namespace App\Http\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Shared pagination and sorting conventions for list endpoints:
 *
 *   ?page=2&per_page=50&sort=-created_at
 *
 * Sorting only accepts allow-listed columns, so user input never reaches an
 * ORDER BY clause unchecked.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sortColumn,
        public readonly string $sortDirection,
    ) {}

    /**
     * @param  list<string>  $sortable
     */
    public static function fromRequest(Request $request, array $sortable, string $defaultSort): self
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'max:64'],
        ]);

        $sort = $validated['sort'] ?? $defaultSort;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $sortable, true)) {
            throw ValidationException::withMessages([
                'sort' => ['Sort must be one of: '.implode(', ', $sortable).' (prefix with - for descending).'],
            ]);
        }

        return new self(
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            $column,
            $direction,
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, callable(Builder<TModel>, string): void>  $customOrder
     *                                                                               per-column ordering for columns whose natural order is not
     *                                                                               lexical (e.g. severity by rank); receives the query and direction
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query, array $customOrder = []): LengthAwarePaginator
    {
        if (isset($customOrder[$this->sortColumn])) {
            $customOrder[$this->sortColumn]($query, $this->sortDirection);
        } else {
            $query->orderBy($query->getModel()->qualifyColumn($this->sortColumn), $this->sortDirection);
        }

        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName(), $this->sortDirection) // stable ordering
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }
}
