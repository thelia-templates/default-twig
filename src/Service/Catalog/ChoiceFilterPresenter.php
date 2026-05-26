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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\Filters\Type\CheckboxType;
use Thelia\Api\Bridge\Propel\Filter\CustomFilters\FilterService;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ChoiceFilterOtherQuery;
use Thelia\Model\ChoiceFilterQuery;

final readonly class ChoiceFilterPresenter
{
    public function __construct(
        private FilterService $filterService,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    public function forCategory(int $categoryId, string $locale): array
    {
        $category = CategoryQuery::create()->filterById($categoryId)->findOne();
        if ($category === null) {
            return $this->emptyContext();
        }

        $templateId = null;
        $resolvedCategoryId = null;
        $choiceFilters = ChoiceFilterQuery::findChoiceFilterByCategory($category, $templateId, $resolvedCategoryId);

        $messages = [];
        $enabled = false;
        $features = new ObjectCollection();
        $attributes = new ObjectCollection();
        $others = new ObjectCollection();

        if ($templateId === null) {
            $choiceFilters = new ObjectCollection();
            $messages[] = $this->translator->trans('This category uses no filter configuration.');
        } else {
            $features = ChoiceFilterQuery::findFeaturesByTemplateId($templateId, [$locale]);
            $attributes = ChoiceFilterQuery::findAttributesByTemplateId($templateId, [$locale]);
            $others = ChoiceFilterOtherQuery::findOther();

            if ($resolvedCategoryId === null) {
                $messages[] = $this->translator->trans(
                    'This category uses the template configuration %templateId.',
                    ['%templateId' => $templateId],
                );
            } elseif ($resolvedCategoryId === (int) $category->getId()) {
                $enabled = true;
                $messages[] = $this->translator->trans('This category uses its own filter configuration.');
            } else {
                $messages[] = $this->translator->trans(
                    'This category uses the filter configuration of category %categoryId.',
                    ['%categoryId' => $resolvedCategoryId],
                );
            }
        }

        $filters = $this->buildFilters($choiceFilters, $features, $attributes, $others);
        $filters = $this->annotateDisplayType($filters, $categoryId, 'filterByCategoryId');

        return [
            'filters' => $filters,
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => $enabled,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    public function forTemplate(int $templateId, string $locale): array
    {
        $features = ChoiceFilterQuery::findFeaturesByTemplateId($templateId, [$locale]);
        $attributes = ChoiceFilterQuery::findAttributesByTemplateId($templateId, [$locale]);
        $others = ChoiceFilterOtherQuery::findOther([$locale]);
        $choiceFilters = ChoiceFilterQuery::create()
            ->filterByTemplateId($templateId)
            ->orderByPosition()
            ->find();

        $filters = $this->buildFilters($choiceFilters, $features, $attributes, $others);
        $filters = $this->annotateDisplayType($filters, $templateId, 'filterByTemplateId');

        return [
            'filters' => $filters,
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => \count($choiceFilters) > 0,
            'messages' => [],
        ];
    }

    /**
     * @return array{filters: list<array<string, mixed>>, filter_types: list<string>, enabled: bool, messages: list<string>}
     */
    private function emptyContext(): array
    {
        return [
            'filters' => [],
            'filter_types' => $this->filterService->getFilterTypes(),
            'enabled' => false,
            'messages' => [],
        ];
    }

    /**
     * @param iterable<mixed>      $choiceFilters
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $features
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $attributes
     * @param iterable<array-key, array<string, mixed>>|ObjectCollection $others
     *
     * @return list<array<string, mixed>>
     */
    private function buildFilters(iterable $choiceFilters, iterable $features, iterable $attributes, iterable $others): array
    {
        $features = $features instanceof ObjectCollection ? $features->toArray() : (array) $features;
        $attributes = $attributes instanceof ObjectCollection ? $attributes->toArray() : (array) $attributes;
        $others = $others instanceof ObjectCollection ? $others->toArray() : (array) $others;

        $features = array_map(static fn ($row) => $row + ['Type' => 'feature', 'Visible' => 1], $features);
        $attributes = array_map(static fn ($row) => $row + ['Type' => 'attribute', 'Visible' => 1], $attributes);

        $merged = [];
        $choiceList = $choiceFilters instanceof ObjectCollection ? iterator_to_array($choiceFilters) : (array) $choiceFilters;

        if ($choiceList !== []) {
            foreach ($choiceList as $choiceFilter) {
                if (($attributeId = $choiceFilter->getAttributeId()) !== null) {
                    foreach ($attributes as $key => $attribute) {
                        if ((int) $attribute['Id'] === (int) $attributeId) {
                            $attribute['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                            $merged[] = $attribute;
                            unset($attributes[$key]);
                        }
                    }
                } elseif (($featureId = $choiceFilter->getFeatureId()) !== null) {
                    foreach ($features as $key => $feature) {
                        if ((int) $feature['Id'] === (int) $featureId) {
                            $feature['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                            $merged[] = $feature;
                            unset($features[$key]);
                        }
                    }
                } else {
                    $other = $choiceFilter->getChoiceFilterOther();
                    $type = $other?->getType();
                    if ($type !== null) {
                        foreach ($others as $key => $row) {
                            if (($row['Type'] ?? null) === $type) {
                                $row['Visible'] = $choiceFilter->getVisible() ? 1 : 0;
                                $merged[] = $row;
                                unset($others[$key]);
                            }
                        }
                    }
                }
            }
        }

        $merged = [...$merged, ...$attributes, ...$features, ...$others];
        $position = 1;

        return array_map(static function (array $row) use (&$position): array {
            $row['Position'] ??= $position;
            ++$position;

            return $row;
        }, $merged);
    }

    /**
     * @param list<array<string, mixed>> $filters
     *
     * @return list<array<string, mixed>>
     */
    private function annotateDisplayType(array $filters, int $scopeId, string $scopeFilter): array
    {
        foreach ($filters as $index => $filter) {
            $type = (string) ($filter['Type'] ?? '');
            $filterByType = 'filterBy'.ucfirst($type).'Id';
            $query = ChoiceFilterQuery::create();
            $query->{$scopeFilter}($scopeId);
            if ($type === 'category' || !method_exists($query, $filterByType)) {
                $query->useChoiceFilterOtherQuery()->filterByType($type)->endUse();
            }
            if ($type !== 'category' && method_exists($query, $filterByType)) {
                $query->{$filterByType}((int) ($filter['Id'] ?? 0), Criteria::EQUAL);
            }
            $persisted = $query->findOne();
            $filters[$index]['DisplayType'] = $persisted?->getType() ?? CheckboxType::getName();
        }

        return $filters;
    }
}
