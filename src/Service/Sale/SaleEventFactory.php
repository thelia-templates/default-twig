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

namespace BackOfficeDefaultTwigBundle\Service\Sale;

use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\Sale\SaleCreateEvent;
use Thelia\Core\Event\Sale\SaleUpdateEvent;
use Thelia\Model\Sale;

final readonly class SaleEventFactory
{
    /**
     * @param array<string, mixed> $formData
     */
    public function createEvent(array $formData, string $fallbackLocale): SaleCreateEvent
    {
        return (new SaleCreateEvent())
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setSaleLabel((string) ($formData['label'] ?? ''));
    }

    /**
     * @param array<string, mixed> $formData
     */
    public function updateEvent(int $saleId, array $formData, Request $request, string $fallbackLocale): SaleUpdateEvent
    {
        $event = new SaleUpdateEvent($saleId);
        $event
            ->setStartDate($this->stringOrNull($formData['start_date'] ?? null))
            ->setEndDate($this->stringOrNull($formData['end_date'] ?? null))
            ->setActive((bool) ($formData['active'] ?? false))
            ->setDisplayInitialPrice((bool) ($formData['display_initial_price'] ?? false))
            ->setPriceOffsetType((int) ($formData['price_offset_type'] ?? Sale::OFFSET_TYPE_PERCENTAGE))
            ->setPriceOffsets($this->priceOffsets($request))
            ->setProducts($this->products($request))
            ->setProductAttributes([])
            ->setLocale((string) ($formData['locale'] ?? $fallbackLocale))
            ->setTitle((string) ($formData['title'] ?? ''))
            ->setSaleLabel((string) ($formData['label'] ?? ''))
            ->setChapo((string) ($formData['chapo'] ?? ''))
            ->setDescription((string) ($formData['description'] ?? ''))
            ->setPostscriptum((string) ($formData['postscriptum'] ?? ''));

        return $event;
    }

    /**
     * @return array<int, float>
     */
    private function priceOffsets(Request $request): array
    {
        $offsets = [];
        foreach ((array) $request->request->all('price_offset') as $currencyId => $offset) {
            $offsets[(int) $currencyId] = (float) $offset;
        }

        return $offsets;
    }

    /**
     * @return list<int>
     */
    private function products(Request $request): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->request->all('products'))));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $cast = trim((string) $value);

        return $cast === '' ? null : $cast;
    }
}
