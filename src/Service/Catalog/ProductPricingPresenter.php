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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\CurrencyQuery;

/**
 * Renders the price, sale price and stock cells of the catalog list: untaxed on
 * top, taxed below, and a stock badge that turns red when nothing is left.
 */
final class ProductPricingPresenter
{
    private ?string $symbol = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function price(ProductPricingSnapshot $snapshot): string
    {
        if ($snapshot->price <= 0.0) {
            return '<span class="text-muted">-</span>';
        }

        return $this->amountPair($snapshot->price, $snapshot->taxedPrice);
    }

    public function promoPrice(ProductPricingSnapshot $snapshot): string
    {
        if ($snapshot->promoPrice <= 0.0) {
            return '<span class="text-muted">-</span>';
        }

        $pair = $this->amountPair($snapshot->promoPrice, $snapshot->taxedPromoPrice);
        if (!$snapshot->onSale) {
            // Value is set but the sale is off: show it muted so the list does not
            // suggest customers are being charged that amount.
            return \sprintf(
                '<span class="text-muted" data-bs-toggle="tooltip" title="%s">%s</span>',
                htmlspecialchars($this->translator->trans('Sale is not active on this product')),
                $pair,
            );
        }

        return $pair;
    }

    public function stock(ProductPricingSnapshot $snapshot): string
    {
        $quantity = $this->formatQuantity($snapshot->quantity);
        $class = $snapshot->quantity <= 0.0 ? 'text-bg-danger-subtle text-danger-emphasis' : 'text-bg-light';

        $tooltip = $snapshot->saleElementCount > 1
            ? $this->translator->trans('Total stock over %count% combinations', ['%count%' => $snapshot->saleElementCount])
            : '';

        return \sprintf(
            '<span class="badge %s bo-product-stock"%s>%s</span>',
            $class,
            $tooltip !== '' ? ' data-bs-toggle="tooltip" title="'.htmlspecialchars($tooltip).'"' : '',
            htmlspecialchars($quantity),
        );
    }

    private function amountPair(float $untaxed, float $taxed): string
    {
        return \sprintf(
            '<div class="bo-product-price"><div class="fw-semibold">%s</div><div class="text-muted small">%s</div></div>',
            htmlspecialchars($this->format($untaxed).' '.$this->translator->trans('excl. tax')),
            htmlspecialchars($this->format($taxed).' '.$this->translator->trans('incl. tax')),
        );
    }

    private function format(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' '.$this->currencySymbol();
    }

    private function formatQuantity(float $quantity): string
    {
        return $quantity === floor($quantity)
            ? number_format($quantity, 0, ',', ' ')
            : number_format($quantity, 2, ',', ' ');
    }

    private function currencySymbol(): string
    {
        if ($this->symbol !== null) {
            return $this->symbol;
        }

        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? CurrencyQuery::create()->findOne();

        return $this->symbol = $currency !== null ? (string) $currency->getSymbol() : '';
    }
}
