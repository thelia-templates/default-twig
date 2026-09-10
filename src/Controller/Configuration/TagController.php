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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Tagging\Service\TagService;
use Thelia\Model\Tag;
use Thelia\Model\TagQuery;
use Twig\Environment;

/**
 * The tag vocabulary of the shop: what an administrator may put on a customer.
 *
 * Its own admin resource rather than the customer one: a profile allowed to
 * rename or delete a tag reaches every customer carrying it, which is not the
 * same reach as editing one customer.
 */
#[Route('/admin/configuration/tags', name: 'admin.configuration.tags.')]
final class TagController
{
    private const RESOURCE = AdminResources::TAG;
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/list.html.twig';

    /**
     * The same shape the API resource validates a colour against.
     *
     * Re-checked here on the way out, not only on the way in: this value ends up
     * inside a style attribute, and a row written by an import, a module or a
     * hand-run SQL statement never passed the API validator.
     */
    private const COLOR_CODE_SHAPE = '/^#[0-9A-Fa-f]{6}$/';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly TagService $tags,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, ['id', 'label'], 'label');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;

        $query = TagQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            default => $query->orderByLabel($criteria),
        };

        // One grouped query for the whole table rather than a count per row: the
        // screen shows every tag, so a per-row count would be one query per line.
        $customerCounts = $this->tags->countCustomersByTag();

        $rows = [];
        foreach ($query->find() as $tag) {
            \assert($tag instanceof Tag);
            $rows[] = [
                'id' => (int) $tag->getId(),
                'label' => (string) $tag->getLabel(),
                'color_code' => (string) $tag->getColorCode(),
                'color_swatch' => $this->colorSwatch((string) $tag->getColorCode()),
                'customer_count' => $customerCounts[$tag->getId()] ?? 0,
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    /**
     * A coloured square, or nothing at all when the stored value is not a colour.
     */
    private function colorSwatch(string $colorCode): string
    {
        if (preg_match(self::COLOR_CODE_SHAPE, $colorCode) !== 1) {
            return '';
        }

        return \sprintf(
            '<span class="d-inline-block rounded border" style="width:1rem;height:1rem;background-color:%s" aria-hidden="true"></span>',
            $colorCode,
        );
    }
}
