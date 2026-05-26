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

use Michelf\MarkdownExtra;
use Thelia\Model\Module;

final readonly class ModuleDocumentationReader
{
    private const CANDIDATE_FILES = [
        'Readme.md',
        'README.md',
        'readme.md',
        'Resources/doc/index.md',
        'Resources/doc/README.md',
    ];

    public function read(Module $module): ?string
    {
        $sourcePath = $this->locateMarkdown($module->getAbsoluteBaseDir());
        if ($sourcePath === null) {
            return null;
        }

        $markdown = @file_get_contents($sourcePath);
        if ($markdown === false || trim($markdown) === '') {
            return null;
        }

        return MarkdownExtra::defaultTransform($markdown);
    }

    private function locateMarkdown(string $baseDir): ?string
    {
        foreach (self::CANDIDATE_FILES as $candidate) {
            $path = $baseDir.DIRECTORY_SEPARATOR.$candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
