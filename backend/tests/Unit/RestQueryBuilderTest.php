<?php

namespace Tests\Unit;

use App\Query\RestQueryBuilder;
use Tests\TestCase;

class RestQueryBuilderTest extends TestCase
{
    public function test_eq_filter(): void
    {
        $qb = RestQueryBuilder::fromArray(['status' => 'eq.paid']);
        $this->assertCount(1, $qb->wheres);
        $this->assertStringContainsString('status = ?', $qb->wheres[0]['sql']);
        $this->assertSame(['paid'], $qb->wheres[0]['bindings']);
    }

    public function test_neq_filter(): void
    {
        $qb = RestQueryBuilder::fromArray(['status' => 'neq.pending']);
        $this->assertStringContainsString('status <> ?', $qb->wheres[0]['sql']);
    }

    public function test_comparison_filters(): void
    {
        $this->assertStringContainsString('amount > ?', RestQueryBuilder::fromArray(['amount' => 'gt.100'])->wheres[0]['sql']);
        $this->assertStringContainsString('amount >= ?', RestQueryBuilder::fromArray(['amount' => 'gte.100'])->wheres[0]['sql']);
        $this->assertStringContainsString('amount < ?', RestQueryBuilder::fromArray(['amount' => 'lt.100'])->wheres[0]['sql']);
        $this->assertStringContainsString('amount <= ?', RestQueryBuilder::fromArray(['amount' => 'lte.100'])->wheres[0]['sql']);
    }

    public function test_like_converts_wildcards(): void
    {
        $qb = RestQueryBuilder::fromArray(['name' => 'like.*john*']);
        $this->assertSame(['%john%'], $qb->wheres[0]['bindings']);
    }

    public function test_in_filter(): void
    {
        $qb = RestQueryBuilder::fromArray(['status' => 'in.paid,pending']);
        $this->assertCount(1, $qb->ins);
        $this->assertSame(['paid', 'pending'], $qb->ins[0]['values']);
    }

    public function test_is_null_filter(): void
    {
        $qb = RestQueryBuilder::fromArray(['notes' => 'is.null']);
        $this->assertTrue($qb->nulls[0]['null']);
    }

    public function test_or_group_is_parsed(): void
    {
        $qb = RestQueryBuilder::fromArray(['or' => '(a.eq.1,b.eq.2)']);
        $this->assertCount(1, $qb->orGroups);
        $this->assertSame('a', $qb->orGroups[0]['conditions'][0][0]);
        $this->assertSame('1', $qb->orGroups[0]['conditions'][0][2]);
    }

    public function test_select_limit_offset_and_count(): void
    {
        $qb = RestQueryBuilder::fromArray([
            'select' => 'id,name',
            'limit' => '10',
            'offset' => '5',
            'count' => 'exact',
        ]);
        $this->assertSame(['id', 'name'], $qb->columns);
        $this->assertSame(10, $qb->limit);
        $this->assertSame(5, $qb->offset);
        $this->assertTrue($qb->count);
    }
}
