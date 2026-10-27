<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use VeyroPHP\Database\Query\Builder;

/**
 * A reusable, named set of where conditions.
 *
 *     final class Published implements Scope
 *     {
 *         public function apply(Builder $query): void
 *         {
 *             $query->where('status', 'published')->whereNotNull('published_at');
 *         }
 *     }
 *
 *     $orm->query(Post::class)->apply(new Published())->get();   // local
 *     public static function globalScopes(): array { return [new TenantScope()]; }   // on every query
 *
 * apply() receives a builder for one parenthesised group, so an orWhere() inside a
 * scope never widens the rest of the query. Add conditions only: ordering, limits
 * and selected columns belong to the query itself and are ignored inside a group.
 */
interface Scope
{
    public function apply(Builder $query): void;
}
