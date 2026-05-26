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

namespace BackOfficeDefaultTwigBundle\DTO\Module;

final readonly class ModuleMetadata
{
    /**
     * @param list<ModuleAuthor> $authors
     * @param list<string>       $languages
     * @param list<string>       $tags
     * @param list<string>       $requiredModules
     */
    public function __construct(
        public string $code,
        public string $title,
        public ?string $chapo,
        public ?string $description,
        public ?string $postscriptum,
        public string $type,
        public string $fullNamespace,
        public string $version,
        public string $theliaVersionMin,
        public ?string $stability,
        public ?string $updateUrl,
        public array $authors,
        public array $languages,
        public array $tags,
        public array $requiredModules,
    ) {
    }
}
