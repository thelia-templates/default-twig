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

use BackOfficeDefaultTwigBundle\DTO\Configuration\SystemInformation;
use BackOfficeDefaultTwigBundle\DTO\Configuration\SystemInformationItem;
use BackOfficeDefaultTwigBundle\DTO\Configuration\SystemInformationSection;
use Propel\Runtime\Propel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\TheliaKernel;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;

/**
 * Collects read-only technical facts about the running Thelia installation from
 * heterogeneous, side-effecting sources (ini_get, the filesystem and the Propel
 * connection). Kept out of the controller and the template so the exact same
 * snapshot feeds both the on-screen tables and the copied plain-text summary.
 */
final readonly class SystemInformationProvider
{
    /**
     * Expected PHP extensions, each checked at runtime with extension_loaded().
     * Consolidates the installer list (Thelia\Core\Install\CheckPermission::$extensions,
     * a protected property duplicated here rather than read dynamically) with the
     * ext-* requires of the root composer.json (ext-simplexml, ext-dom) and of
     * core/composer.json (ext-curl, ext-intl, ext-json, ext-pdo).
     * Keep in sync with CheckPermission.
     *
     * @var list<string>
     */
    private const EXPECTED_EXTENSIONS = [
        'curl', 'dom', 'fileinfo', 'gd', 'intl', 'json',
        'openssl', 'pdo', 'pdo_mysql', 'simplexml', 'zip',
    ];

    private const LOG_LEVEL_NAMES = [
        Tlog::DEBUG => 'DEBUG',
        Tlog::INFO => 'INFO',
        Tlog::NOTICE => 'NOTICE',
        Tlog::WARNING => 'WARNING',
        Tlog::ERROR => 'ERROR',
        Tlog::CRITICAL => 'CRITICAL',
        Tlog::ALERT => 'ALERT',
        Tlog::EMERGENCY => 'EMERGENCY',
    ];

    private const CACHE_FILES_SCAN_LIMIT = 50000;

    public function __construct(
        private TemplateHelperInterface $templateHelper,
        #[Autowire('%kernel.cache_dir%')]
        private string $cacheDir,
        #[Autowire('%kernel.logs_dir%')]
        private string $logDir,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function collect(): SystemInformation
    {
        return new SystemInformation(
            [
                $this->theliaSection(),
                $this->phpSection(),
                $this->phpExtensionsSection(),
                $this->symfonySection(),
                $this->databaseSection(),
                $this->cacheAndLogsSection(),
            ],
            new \DateTimeImmutable(),
        );
    }

    private function theliaSection(): SystemInformationSection
    {
        return new SystemInformationSection(SystemInformationSection::KEY_THELIA, 'Thelia', [
            new SystemInformationItem('Version', TheliaKernel::THELIA_VERSION),
            new SystemInformationItem('Environment', $this->environment),
            new SystemInformationItem('Debug mode', $this->yesNo($this->debug)),
            new SystemInformationItem('Active back-office template', $this->safe(fn (): string => $this->templateHelper->getActiveAdminTemplate()->getName())),
            new SystemInformationItem('Active front-office template', $this->safe(fn (): string => $this->templateHelper->getActiveFrontTemplate()->getName())),
            new SystemInformationItem('Project root', $this->projectRoot()),
        ]);
    }

    private function phpSection(): SystemInformationSection
    {
        $opcache = \extension_loaded('Zend OPcache') && (bool) \ini_get('opcache.enable');

        return new SystemInformationSection(SystemInformationSection::KEY_PHP, 'PHP', [
            new SystemInformationItem('Version', \PHP_VERSION),
            new SystemInformationItem('Server API', \PHP_SAPI),
            $this->iniItem('memory_limit'),
            $this->iniItem('post_max_size'),
            $this->iniItem('upload_max_filesize'),
            $this->iniItem('max_execution_time'),
            new SystemInformationItem('date.timezone', \ini_get('date.timezone') ?: date_default_timezone_get()),
            new SystemInformationItem(
                'OPcache',
                $this->yesNo($opcache),
                $opcache ? SystemInformationItem::STATUS_OK : SystemInformationItem::STATUS_WARNING,
            ),
        ]);
    }

    private function phpExtensionsSection(): SystemInformationSection
    {
        $items = [];
        foreach (self::EXPECTED_EXTENSIONS as $extension) {
            $items[] = new SystemInformationItem(
                $extension,
                $extension,
                \extension_loaded($extension) ? SystemInformationItem::STATUS_OK : SystemInformationItem::STATUS_DANGER,
            );
        }

        return new SystemInformationSection(SystemInformationSection::KEY_PHP_EXTENSIONS, 'PHP extensions', $items);
    }

    private function symfonySection(): SystemInformationSection
    {
        return new SystemInformationSection(SystemInformationSection::KEY_SYMFONY, 'Symfony', [
            new SystemInformationItem('Version', Kernel::VERSION),
            new SystemInformationItem('End of maintenance', Kernel::END_OF_MAINTENANCE),
            new SystemInformationItem('End of life', Kernel::END_OF_LIFE),
        ]);
    }

    private function databaseSection(): SystemInformationSection
    {
        try {
            $connection = Propel::getConnection('TheliaMain');

            $charset = $connection->query('SELECT @@character_set_database, @@collation_database')->fetch(\PDO::FETCH_NUM);

            $items = [
                new SystemInformationItem('Server version', (string) $connection->query('SELECT VERSION()')->fetchColumn()),
                new SystemInformationItem('Database name', (string) $connection->query('SELECT DATABASE()')->fetchColumn()),
                new SystemInformationItem('Driver', (string) $connection->getAttribute(\PDO::ATTR_DRIVER_NAME)),
                new SystemInformationItem('Character set', (string) ($charset[0] ?? '')),
                new SystemInformationItem('Collation', (string) ($charset[1] ?? '')),
                new SystemInformationItem('sql_mode', (string) $connection->query('SELECT @@SESSION.sql_mode')->fetchColumn()),
            ];
        } catch (\Throwable) {
            $items = array_map(
                static fn (string $label): SystemInformationItem => new SystemInformationItem($label, 'Unavailable', SystemInformationItem::STATUS_DANGER),
                ['Server version', 'Database name', 'Driver', 'Character set', 'Collation', 'sql_mode'],
            );
        }

        return new SystemInformationSection(SystemInformationSection::KEY_DATABASE, 'Database', $items);
    }

    private function cacheAndLogsSection(): SystemInformationSection
    {
        $cacheWritable = is_writable($this->cacheDir);
        $logWritable = is_writable($this->logDir);

        return new SystemInformationSection(SystemInformationSection::KEY_CACHE_LOGS, 'Cache and logs', [
            $this->directoryItem('Cache directory', $this->cacheDir, $cacheWritable),
            new SystemInformationItem('Cache size', $this->cacheSize($this->cacheDir)),
            $this->directoryItem('Log directory', $this->logDir, $logWritable),
            new SystemInformationItem('Thelia log level', $this->logLevelName()),
        ]);
    }

    private function directoryItem(string $label, string $directory, bool $writable): SystemInformationItem
    {
        return new SystemInformationItem(
            $label,
            $this->relativePath($directory),
            $writable ? SystemInformationItem::STATUS_OK : SystemInformationItem::STATUS_DANGER,
            $writable ? 'Writable' : 'Not writable',
        );
    }

    private function iniItem(string $directive): SystemInformationItem
    {
        $value = \ini_get($directive);

        return new SystemInformationItem($directive, false === $value || '' === $value ? 'Unavailable' : $value);
    }

    private function projectRoot(): string
    {
        return \defined('THELIA_ROOT') ? \THELIA_ROOT : $this->projectDir;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim($this->projectRoot(), '/\\');
        $real = realpath($path) ?: $path;

        if (str_starts_with($real, $base)) {
            return ltrim(substr($real, \strlen($base)), '/\\') ?: '.';
        }

        return $real;
    }

    private function cacheSize(string $directory): string
    {
        if (!is_dir($directory)) {
            return 'Unavailable';
        }

        $bytes = 0;
        $files = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (++$files > self::CACHE_FILES_SCAN_LIMIT) {
                    return 'Not computed';
                }
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return 'Unavailable';
        }

        return $this->humanBytes($bytes);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }

    private function logLevelName(): string
    {
        $level = (int) ConfigQuery::read(Tlog::VAR_LEVEL, (string) Tlog::DEFAULT_LEVEL);

        if (\PHP_INT_MAX === $level) {
            return 'MUET';
        }

        return self::LOG_LEVEL_NAMES[$level] ?? (string) $level;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * @param callable(): string $collector
     */
    private function safe(callable $collector): string
    {
        try {
            return $collector();
        } catch (\Throwable) {
            return 'Unavailable';
        }
    }
}
