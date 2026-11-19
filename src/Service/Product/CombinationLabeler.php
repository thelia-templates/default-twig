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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvI18n;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeI18n;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\Lang;
use Thelia\Model\ProductSaleElements;

/**
 * The name of a combination ("Color: Red / Size: M"), written the same way by the
 * Combinations tab and by every screen that lets the merchant pick a combination.
 */
final readonly class CombinationLabeler
{
    public const DEFAULT_LABEL = 'default';

    /**
     * The label of one combination and the position of its attributes, which orders the
     * combinations of a product that are tied on their own position.
     *
     * @param array<int, int> $attributePositions position of each attribute in the template of the product, by attribute id
     *
     * @return array{label: string, order: list<array{int, int}>}
     */
    public function describe(ProductSaleElements $saleElements, string $locale, array $attributePositions = []): array
    {
        $parts = [];
        $order = [];
        foreach ($saleElements->getAttributeCombinations() as $combination) {
            $attribute = $combination->getAttribute();
            $attributeAv = $combination->getAttributeAv();
            if ($attribute === null || $attributeAv === null) {
                continue;
            }
            $parts[] = $this->part($this->titleIn($attribute, $locale), $this->titleIn($attributeAv, $locale));
            $order[] = [
                $attributePositions[(int) $attribute->getId()] ?? (int) $attribute->getPosition(),
                (int) $attributeAv->getPosition(),
            ];
        }
        sort($order);

        return ['label' => $this->join($parts), 'order' => $order];
    }

    /**
     * The labels of many combinations in three queries, whatever their number.
     *
     * @param list<int> $saleElementsIds
     *
     * @return array<int, string> keyed by sale element id
     */
    public function labelsFor(array $saleElementsIds, string $locale): array
    {
        if ([] === $saleElementsIds) {
            return [];
        }

        $combinations = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($saleElementsIds, Criteria::IN)
            ->orderByAttributeId()
            ->orderByAttributeAvId()
            ->find();

        $attributeIds = [];
        $valueIds = [];
        foreach ($combinations as $combination) {
            $attributeIds[] = (int) $combination->getAttributeId();
            $valueIds[] = (int) $combination->getAttributeAvId();
        }

        $attributeTitles = [];
        $valueTitles = [];
        if ([] !== $attributeIds) {
            // One query per table for both languages: the one asked for wins where it has a wording.
            $locales = array_values(array_unique([$this->defaultLocale(), $locale]));
            $attributeTitles = $this->titlesOf(AttributeI18nQuery::create()->filterById($attributeIds, Criteria::IN)->filterByLocale($locales, Criteria::IN)->find(), $locale);
            $valueTitles = $this->titlesOf(AttributeAvI18nQuery::create()->filterById($valueIds, Criteria::IN)->filterByLocale($locales, Criteria::IN)->find(), $locale);
        }

        $partsBySaleElements = [];
        foreach ($combinations as $combination) {
            $partsBySaleElements[(int) $combination->getProductSaleElementsId()][] = $this->part(
                $attributeTitles[(int) $combination->getAttributeId()] ?? '',
                $valueTitles[(int) $combination->getAttributeAvId()] ?? '',
            );
        }

        $labels = [];
        foreach ($saleElementsIds as $saleElementsId) {
            $labels[$saleElementsId] = $this->join($partsBySaleElements[$saleElementsId] ?? []);
        }

        return $labels;
    }

    /**
     * The title in the language asked for, else in the default language of the shop.
     */
    private function titleIn(Attribute|AttributeAv $model, string $locale): string
    {
        $model->setLocale($locale);
        $title = trim((string) $model->getTitle());

        if ('' === $title && $locale !== $this->defaultLocale()) {
            $model->setLocale($this->defaultLocale());
            $title = trim((string) $model->getTitle());
            $model->setLocale($locale);
        }

        return $title;
    }

    /**
     * @param iterable<AttributeI18n|AttributeAvI18n> $translations
     *
     * @return array<int, string> the title of each id in $locale, else in the other language read
     */
    private function titlesOf(iterable $translations, string $locale): array
    {
        $titles = [];
        $preferred = [];
        foreach ($translations as $translation) {
            $title = trim((string) $translation->getTitle());
            if ('' === $title) {
                continue;
            }
            $id = (int) $translation->getId();
            if ($translation->getLocale() === $locale) {
                $preferred[$id] = $title;
            } else {
                $titles[$id] = $title;
            }
        }

        return $preferred + $titles;
    }

    private function defaultLocale(): string
    {
        return Lang::getDefaultLanguage()->getLocale() ?? 'en_US';
    }

    private function part(string $attributeTitle, string $valueTitle): string
    {
        return $attributeTitle.': '.$valueTitle;
    }

    /**
     * @param list<string> $parts
     */
    private function join(array $parts): string
    {
        return [] === $parts ? self::DEFAULT_LABEL : implode(' / ', $parts);
    }
}
