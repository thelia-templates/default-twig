<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Model\OrderReturnReason;
use Thelia\Model\OrderReturnReasonQuery;

/**
 * Writes the merchant-managed return reasons.
 *
 * The core exposes no event for this reference table - its own admin API
 * persists the resource straight through - so the screen owns the write and
 * keeps it in one place instead of spreading Propel calls over the controller.
 */
final readonly class OrderReturnReasonManager
{
    public function create(string $locale, string $title, string $code, bool $visible): OrderReturnReason
    {
        $reason = new OrderReturnReason();
        $reason
            ->setLocale($locale)
            ->setTitle($title)
            ->setCode($code === '' ? null : $code)
            ->setVisible($visible);
        $reason->setPosition($reason->getNextPosition());
        $reason->save();

        return $reason;
    }

    public function update(
        int $reasonId,
        string $locale,
        string $title,
        string $code,
        bool $visible,
        string $description,
    ): ?OrderReturnReason {
        $reason = OrderReturnReasonQuery::create()->findPk($reasonId);

        if ($reason === null) {
            return null;
        }

        $reason
            ->setLocale($locale)
            ->setTitle($title)
            ->setCode($code === '' ? null : $code)
            ->setVisible($visible)
            ->setDescription($description)
            ->save();

        return $reason;
    }

    /**
     * Deleting a reason never deletes the returns that named it: the foreign key
     * is ON DELETE SET NULL and each return kept a snapshot of the label.
     */
    public function delete(int $reasonId): bool
    {
        $reason = OrderReturnReasonQuery::create()->findPk($reasonId);

        if ($reason === null) {
            return false;
        }

        $reason->delete();

        return true;
    }

    public function updatePosition(int $reasonId, int $mode, int $position): bool
    {
        $reason = OrderReturnReasonQuery::create()->findPk($reasonId);

        if ($reason === null) {
            return false;
        }

        match ($mode) {
            UpdatePositionEvent::POSITION_UP => $reason->movePositionUp(),
            UpdatePositionEvent::POSITION_DOWN => $reason->movePositionDown(),
            default => $reason->changeAbsolutePosition($position),
        };

        return true;
    }
}
