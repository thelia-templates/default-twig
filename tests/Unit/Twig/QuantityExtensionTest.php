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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Twig;

use BackOfficeDefaultTwigBundle\Twig\QuantityExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** `format_quantity` writes a quantity in the admin's language, with its decimals and unit. */
final class QuantityExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityFormatter::class)) {
            self::markTestSkipped('The installed core predates the quantity formatter.');
        }
    }

    public function testAQuantitySoldByMeasureReadsWithItsUnitInTheAdminsLanguage(): void
    {
        self::assertSame('1,25 kg', $this->render('fr_FR', '{{ 1.25|format_quantity(2, "kg") }}'));
        self::assertSame('1.25 kg', $this->render('en_US', '{{ 1.25|format_quantity(2, "kg") }}'));
    }

    public function testAQuantitySoldByThePieceReadsAsAWholeNumberWithoutUnit(): void
    {
        self::assertSame('6', $this->render('fr_FR', '{{ 6.0|format_quantity(0, null) }}'));
        self::assertSame('6', $this->render('fr_FR', '{{ 6|format_quantity }}'));
    }

    public function testAnUnknownUnitIsLeftOut(): void
    {
        self::assertSame('2,5', $this->render('fr_FR', '{{ 2.5|format_quantity(1, "oz") }}'));
    }

    private function render(string $locale, string $template): string
    {
        $request = new Request();
        $request->setLocale($locale);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $twig = new Environment(new ArrayLoader(['quantity' => $template]));
        $twig->addExtension(new QuantityExtension(new QuantityFormatter(), $requestStack));

        return $twig->render('quantity');
    }
}
