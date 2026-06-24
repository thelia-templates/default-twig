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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\Hook\LegacyHookAliases;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Hook\FragmentBag;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\ModuleQuery;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig counterpart for the Smarty `{hookblock}`/`{forhook}`/`{ifhook}` plugins, plus a
 * tolerant `safe_hook` that swallows listener errors when a module ships only Smarty
 * templates during the back-office cohabitation phase.
 *
 *     {% if has_hook('product.tab') %}
 *       <ul>
 *         {% for block in hook_block('product.tab', { product_id: product.id }) %}
 *           <li><a href="{{ block.href }}">{{ block.title }}</a></li>
 *         {% endfor %}
 *       </ul>
 *     {% endif %}
 */
final class HookExtension extends AbstractExtension
{
    private const HOOK_TYPE = TemplateDefinition::BACK_OFFICE;
    private const HOOK_TYPE_PDF = TemplateDefinition::PDF;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly LegacyHookAliases $legacyAliases,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hook_block', $this->renderHookBlock(...)),
            new TwigFunction('has_hook', $this->hasActiveHook(...)),
            new TwigFunction('safe_hook', $this->safeHook(...), ['is_safe' => ['html']]),
            new TwigFunction('hook_cards', $this->hookCards(...), ['is_safe' => ['html']]),
            new TwigFunction('pdf_hook', $this->pdfHook(...), ['is_safe' => ['html']]),
            new TwigFunction('pdf_hook_block', $this->renderPdfHookBlock(...)),
            new TwigFunction('has_pdf_hook', $this->hasActivePdfHook(...)),
        ];
    }

    public function safeHook(string $name, array $parameters = []): string
    {
        return $this->dispatchHookRender($name, $parameters, self::HOOK_TYPE);
    }

    public function pdfHook(string $name, array $parameters = []): string
    {
        return $this->dispatchHookRender($name, $parameters, self::HOOK_TYPE_PDF);
    }

    /**
     * Like {@see safeHook()}, but wraps each contributing module in its own titled card so the
     * stacked output of several modules on the same hook (e.g. the SEO or Modules tab) stays
     * readable. Opt-in: only templates that call `hook_cards` get the framing — `safe_hook` is
     * untouched, so JS/menu/tab hooks keep rendering raw.
     *
     * Accepts one hook name or a list of them: passing several names groups a module spread over
     * adjacent hooks (e.g. a SEO module on both `content.seo.update-form` and `tab-seo.update-form`)
     * into a single card.
     *
     * @param string|list<string> $names
     */
    public function hookCards(string|array $names, array $parameters = []): string
    {
        $event = new HookRenderEvent(\is_array($names) ? ($names[0] ?? '') : $names, $parameters);
        $suffix = $this->moduleSuffix($parameters);

        // Ordered [moduleCode, content] sections; consecutive fragments from the same module are
        // merged so a module exposing several listeners (or hooks) gets a single card.
        $sections = [];

        foreach ((array) $names as $name) {
            foreach ($this->hookNamesFor($name) as $hookName) {
                $eventName = \sprintf('hook.%s.%s', self::HOOK_TYPE, $hookName).$suffix;

                foreach ($this->dispatcher->getListeners($eventName) as $listener) {
                    $before = \count($event->get());

                    try {
                        $listener($event, $eventName, $this->dispatcher);
                    } catch (\Throwable $exception) {
                        $this->logSwallowed('hook', $name, $exception);
                        continue;
                    }

                    $fragment = implode('', \array_slice($event->get(), $before));

                    if ('' === trim($fragment)) {
                        continue;
                    }

                    $code = $this->listenerModuleCode($listener);
                    $last = array_key_last($sections);

                    if (null !== $last && $sections[$last][0] === $code) {
                        $sections[$last][1] .= $fragment;
                    } else {
                        $sections[] = [$code, $fragment];
                    }
                }
            }
        }

        $html = '';
        foreach ($sections as [$code, $content]) {
            $html .= $this->wrapInCard($content, $code);
        }

        return $html;
    }

    private function listenerModuleCode(callable $listener): ?string
    {
        if (\is_array($listener) && ($listener[0] ?? null) instanceof BaseHook) {
            return $listener[0]->getModule()?->getCode();
        }

        return null;
    }

    private function wrapInCard(string $content, ?string $moduleCode): string
    {
        if (null === $moduleCode || '' === $moduleCode) {
            return $content;
        }

        return \sprintf(
            '<div class="card mb-3 bo-hook-card"><div class="card-header py-2 d-flex align-items-center gap-2">'
            .'<i class="bi bi-puzzle text-muted" aria-hidden="true"></i>'
            .'<h3 class="h6 mb-0 text-muted">%s</h3></div>'
            .'<div class="card-body">%s</div></div>',
            htmlspecialchars($moduleCode, ENT_QUOTES, 'UTF-8'),
            $content
        );
    }

    public function renderHookBlock(string $name, array $parameters = []): FragmentBag
    {
        return $this->safelyRenderHookBlock($name, $parameters, self::HOOK_TYPE);
    }

    public function renderPdfHookBlock(string $name, array $parameters = []): FragmentBag
    {
        return $this->safelyRenderHookBlock($name, $parameters, self::HOOK_TYPE_PDF);
    }

    public function hasActiveHook(string $name, array $parameters = []): bool
    {
        return $this->hasListeners($name, $parameters, self::HOOK_TYPE);
    }

    public function hasActivePdfHook(string $name, array $parameters = []): bool
    {
        return $this->hasListeners($name, $parameters, self::HOOK_TYPE_PDF);
    }

    private function hasListeners(string $name, array $parameters, int $type): bool
    {
        $suffix = $this->moduleSuffix($parameters);
        foreach ($this->hookNamesFor($name) as $hookName) {
            if ($this->dispatcher->hasListeners(\sprintf('hook.%s.%s', $type, $hookName).$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function dispatchHookRender(string $name, array $parameters, int $type): string
    {
        $event = new HookRenderEvent($name, $parameters);
        $this->dispatchToListeners($event, $name, $parameters, $type, 'hook');

        return $event->dump();
    }

    private function safelyRenderHookBlock(string $name, array $parameters, int $type): FragmentBag
    {
        return $this->dispatchHookBlock($name, $parameters, $type)->get();
    }

    private function dispatchHookBlock(string $name, array $parameters, int $type): HookRenderBlockEvent
    {
        $event = new HookRenderBlockEvent($name, $parameters);
        $this->dispatchToListeners($event, $name, $parameters, $type, 'hook_block');

        return $event;
    }

    /**
     * Dispatch the hook event to each listener in isolation: a single faulty
     * module (for instance one whose Propel models were never generated) only
     * loses its own fragment instead of wiping out the output already produced
     * by the other modules sharing the hook.
     */
    private function dispatchToListeners(object $event, string $name, array $parameters, int $type, string $kind): void
    {
        $suffix = $this->moduleSuffix($parameters);

        foreach ($this->hookNamesFor($name) as $hookName) {
            $eventName = \sprintf('hook.%s.%s', $type, $hookName).$suffix;

            foreach ($this->dispatcher->getListeners($eventName) as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    return;
                }

                try {
                    $listener($event, $eventName, $this->dispatcher);
                } catch (\Throwable $exception) {
                    $this->logSwallowed($kind, $name, $exception);
                }
            }
        }
    }

    /**
     * The hook name itself, followed by the legacy Smarty names it replaced (cohabitation bridge).
     *
     * @return list<string>
     */
    private function hookNamesFor(string $name): array
    {
        return [$name, ...$this->legacyAliases->legacyNamesFor($name)];
    }

    private function logSwallowed(string $kind, string $name, \Throwable $exception): void
    {
        $message = \sprintf('%s(%s) caught a listener error: %s', $kind, $name, $exception->getMessage());
        $context = ['exception' => $exception];

        $this->debug
            ? $this->logger->error($message, $context)
            : $this->logger->warning($message, $context);
    }

    private function moduleSuffix(array $parameters): string
    {
        $moduleId = (int) ($parameters['module'] ?? 0);
        $moduleCode = (string) ($parameters['modulecode'] ?? '');

        if (0 === $moduleId && '' !== $moduleCode
            && null !== $module = ModuleQuery::create()->findOneByCode($moduleCode)) {
            $moduleId = $module->getId();
        }

        return 0 !== $moduleId ? '.'.$moduleId : '';
    }
}
