<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\CustomerQuery;

final readonly class CustomerRepository
{
    public function countAll(): int
    {
        return (int) CustomerQuery::create()->count();
    }

    public function countNew(DateRange $range): int
    {
        return (int) CustomerQuery::create()
            ->filterByCreatedAt($range->fromSql(), Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($range->toSql(), Criteria::LESS_EQUAL)
            ->count();
    }
}
