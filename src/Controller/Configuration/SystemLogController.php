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

use BackOfficeDefaultTwigBundle\Form\Configuration\SystemLogConfigurationType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Twig\Environment;

#[Route('/admin/configuration/system-logs', name: 'admin.configuration.system-logs.')]
final class SystemLogController
{
    private const TEMPLATE = '@BackOfficeDefaultTwig/configuration/system-logs/index.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdminFormValidator $validator,
        private readonly AdminLogger $adminLogger,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function view(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::SYSTEM_LOG, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::TEMPLATE, $this->buildContext($request, $this->buildForm())));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::SYSTEM_LOG, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->buildForm();

        try {
            $validated = $this->validator->validate($form);
            $this->persist($validated, $request);

            $this->adminLogger->log(
                AdminResources::SYSTEM_LOG,
                AccessManager::UPDATE,
                'System logging configuration modified.',
            );

            return new RedirectResponse($this->urls->generate('admin.configuration.system-logs.default'));
        } catch (\Throwable) {
            return new Response(
                $this->twig->render(self::TEMPLATE, $this->buildContext($request, $form)),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    private function persist(FormInterface $validated, Request $request): void
    {
        $data = $validated->getData() ?? [];

        ConfigQuery::write(Tlog::VAR_LEVEL, (string) ($data['level'] ?? Tlog::DEFAULT_LEVEL));
        ConfigQuery::write(Tlog::VAR_PREFIXE, (string) ($data['format'] ?? Tlog::DEFAUT_PREFIXE));
        ConfigQuery::write(Tlog::VAR_SHOW_REDIRECT, (string) ($data['show_redirections'] ?? Tlog::DEFAUT_SHOW_REDIRECT));
        ConfigQuery::write(Tlog::VAR_FILES, (string) ($data['files'] ?? ''));
        ConfigQuery::write(Tlog::VAR_IP, (string) ($data['ip_addresses'] ?? ''));

        $destinations = (array) $request->request->all('destinations');
        $configs = (array) $request->request->all('config');
        $active = [];

        foreach ($destinations as $classname => $payload) {
            if (\is_array($payload) && isset($payload['active'])) {
                $active[] = (string) ($payload['classname'] ?? $classname);
            }

            if (isset($configs[$classname]) && \is_array($configs[$classname])) {
                foreach ($configs[$classname] as $var => $value) {
                    ConfigQuery::write((string) $var, (string) $value, true, true);
                }
            }
        }

        ConfigQuery::write(Tlog::VAR_DESTINATIONS, implode(';', $active));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Request $request, FormInterface $form): array
    {
        $destinations = $this->loadDestinations();
        $active = explode(';', (string) ConfigQuery::read(Tlog::VAR_DESTINATIONS, Tlog::DEFAUT_DESTINATIONS));

        return [
            'form' => $form->createView(),
            'ip_address' => (string) $request->getClientIp(),
            'destinations' => $destinations,
            'active_destinations' => $active,
        ];
    }

    private function buildForm(): FormInterface
    {
        return $this->formFactory->createNamed('thelia_system_log_configuration', SystemLogConfigurationType::class, [
            'level' => ConfigQuery::read(Tlog::VAR_LEVEL, Tlog::DEFAULT_LEVEL),
            'format' => ConfigQuery::read(Tlog::VAR_PREFIXE, Tlog::DEFAUT_PREFIXE),
            'show_redirections' => ConfigQuery::read(Tlog::VAR_SHOW_REDIRECT, Tlog::DEFAUT_SHOW_REDIRECT),
            'files' => ConfigQuery::read(Tlog::VAR_FILES, Tlog::DEFAUT_FILES),
            'ip_addresses' => ConfigQuery::read(Tlog::VAR_IP, Tlog::DEFAUT_IP),
        ]);
    }

    /**
     * @return list<array{classname: string, fqcn: string, title: string, description: string, configs: list<array{name: string, title: string, label: string, type: int, value: string}>}>
     */
    private function loadDestinations(): array
    {
        $destinations = [];
        $seen = [];
        foreach (Tlog::getInstance()->getDestinationsDirectories() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $classname = $entry->getBasename('.php');
                if (isset($seen[$classname])) {
                    continue;
                }

                $fqcn = 'Thelia\\Log\\Destination\\'.$classname;
                if (!class_exists($fqcn)) {
                    continue;
                }

                $seen[$classname] = true;
                $destination = new $fqcn();
                $destinations[] = [
                    'classname' => $classname,
                    'fqcn' => $fqcn,
                    'title' => (string) $destination->getTitle(),
                    'description' => (string) $destination->getDescription(),
                    'configs' => array_map(
                        static fn (object $config): array => [
                            'name' => (string) $config->getName(),
                            'title' => (string) $config->getTitle(),
                            'label' => (string) $config->getLabel(),
                            'type' => (int) $config->getType(),
                            'value' => (string) $config->getValue(),
                        ],
                        $destination->getConfigs(),
                    ),
                ];
            }
        }

        return $destinations;
    }
}
