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

namespace BackOfficeDefaultTwigBundle\Service\Module;

use BackOfficeDefaultTwigBundle\DTO\Module\ModuleAuthor;
use BackOfficeDefaultTwigBundle\DTO\Module\ModuleMetadata;
use Thelia\Model\Module;
use Thelia\Module\BaseModule;

final readonly class ModuleMetadataReader
{
    private const TYPE_LABELS = [
        BaseModule::CLASSIC_MODULE_TYPE => 'classic',
        BaseModule::DELIVERY_MODULE_TYPE => 'delivery',
        BaseModule::PAYMENT_MODULE_TYPE => 'payment',
    ];

    public function read(Module $module): ?ModuleMetadata
    {
        $xmlPath = $module->getAbsoluteConfigPath().\DIRECTORY_SEPARATOR.'module.xml';
        if (!is_file($xmlPath)) {
            return null;
        }

        $xml = @simplexml_load_file($xmlPath);
        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        return new ModuleMetadata(
            code: (string) $module->getCode(),
            title: (string) $module->getTitle(),
            chapo: $this->nullableString($module->getChapo()),
            description: $this->nullableString($module->getDescription()),
            postscriptum: $this->nullableString($module->getPostscriptum()),
            type: $this->readType((string) $xml->type, (int) $module->getType()),
            fullNamespace: (string) $xml->fullnamespace,
            version: (string) $xml->version,
            theliaVersionMin: (string) $xml->thelia,
            stability: $this->nullableString((string) $xml->stability),
            updateUrl: $this->readUpdateUrl($xml),
            authors: $this->readAuthors($xml),
            languages: $this->readListElements($xml, 'languages', 'language'),
            tags: $this->readListElements($xml, 'tags', 'tag'),
            requiredModules: $this->readListElements($xml, 'required', 'module'),
        );
    }

    private function readType(string $xmlType, int $persistedType): string
    {
        $cleaned = trim($xmlType);
        if ($cleaned !== '') {
            return $cleaned;
        }

        return self::TYPE_LABELS[$persistedType] ?? 'classic';
    }

    private function readUpdateUrl(\SimpleXMLElement $xml): ?string
    {
        if (isset($xml->updateurl)) {
            return $this->nullableString((string) $xml->updateurl);
        }
        if (isset($xml->urlmiseajour)) {
            return $this->nullableString((string) $xml->urlmiseajour);
        }

        return null;
    }

    /**
     * @return list<ModuleAuthor>
     */
    private function readAuthors(\SimpleXMLElement $xml): array
    {
        $authors = [];

        if (isset($xml->authors->author)) {
            foreach ($xml->authors->author as $node) {
                $authors[] = $this->buildAuthor($node);
            }
        }

        if ($authors === [] && isset($xml->author)) {
            $authors[] = $this->buildAuthor($xml->author);
        }

        return $authors;
    }

    private function buildAuthor(\SimpleXMLElement $node): ModuleAuthor
    {
        return new ModuleAuthor(
            name: (string) ($node->name ?? ''),
            company: $this->nullableString((string) ($node->company ?? '')),
            email: $this->nullableString((string) ($node->email ?? '')),
            website: $this->nullableString((string) ($node->website ?? '')),
        );
    }

    /**
     * @return list<string>
     */
    private function readListElements(\SimpleXMLElement $xml, string $container, string $item): array
    {
        if (!isset($xml->{$container}->{$item})) {
            return [];
        }

        $values = [];
        foreach ($xml->{$container}->{$item} as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
