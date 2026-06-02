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

namespace BackOfficeDefaultTwigBundle\Service\Configuration;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class EmailTemplateFileLister
{
    public function __construct(
        private KernelInterface $kernel,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @return array{layouts: list<string>, html_templates: list<string>, text_templates: list<string>}
     */
    public function listForActiveTheme(string $activeTheme = 'default'): array
    {
        $directory = $this->kernel->getProjectDir().'/templates/email/'.$activeTheme;
        if (!$this->filesystem->exists($directory)) {
            return ['layouts' => [], 'html_templates' => [], 'text_templates' => []];
        }

        $layouts = [];
        $htmlTemplates = [];
        $textTemplates = [];

        $finder = (new Finder())->files()->in($directory)->depth(0);
        foreach ($finder as $file) {
            $name = $file->getFilename();
            if (str_contains($name, '-layout.')) {
                $layouts[] = $name;
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (\in_array($extension, ['html', 'htm', 'twig'], true)) {
                $htmlTemplates[] = $name;
                continue;
            }
            if (\in_array($extension, ['txt', 'text'], true)) {
                $textTemplates[] = $name;
            }
        }

        sort($layouts);
        sort($htmlTemplates);
        sort($textTemplates);

        return [
            'layouts' => $layouts,
            'html_templates' => $htmlTemplates,
            'text_templates' => $textTemplates,
        ];
    }
}
