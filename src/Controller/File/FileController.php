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

namespace BackOfficeDefaultTwigBundle\Controller\File;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\File\FileManager;
use Thelia\Core\File\Service\FileDeleteService;
use Thelia\Core\File\Service\FilePositionService;
use Thelia\Core\File\Service\FileProcessorService;
use Thelia\Core\File\Service\FileVisibilityService;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\LangQuery;
use Twig\Environment;

final class FileController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly FileManager $fileManager,
        private readonly FileProcessorService $fileProcessor,
        private readonly FileDeleteService $fileDeleter,
        private readonly FileVisibilityService $fileVisibility,
        private readonly FilePositionService $filePosition,
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly AdminResources $resources,
    ) {
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/list-ajax', name: 'admin.image.list-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageList(string $parentType, int $parentId): Response
    {
        return $this->renderList('image', $parentType, $parentId);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/form-ajax', name: 'admin.image.form-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageForm(string $parentType, int $parentId): Response
    {
        return $this->renderForm('image', $parentType, $parentId);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/save-ajax', name: 'admin.image.save-ajax', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageSave(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handleSave('image', $parentType, $parentId, $request);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/delete', name: 'admin.image.delete', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageDelete(string $parentType, int $imageId): Response
    {
        return $this->handleDelete('image', $parentType, $imageId, TheliaEvents::IMAGE_DELETE);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/toggle', name: 'admin.image.toggle.process', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageToggleVisibility(string $parentType, int $imageId): Response
    {
        return $this->handleToggle('image', $parentType, $imageId, TheliaEvents::IMAGE_TOGGLE_VISIBILITY);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/update-position', name: 'admin.image.update-position', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdatePosition(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handlePosition('image', $parentType, $request, TheliaEvents::IMAGE_UPDATE_POSITION);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/update-title', name: 'admin.image.update-title', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdateTitle(string $parentType, int $imageId, Request $request): Response
    {
        return $this->handleUpdateTitle('image', $parentType, $imageId, $request, TheliaEvents::IMAGE_UPDATE);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/update', name: 'admin.image.update.view', methods: ['GET'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdateView(string $parentType, int $imageId): Response
    {
        return $this->redirectToParent($parentType, 'image', $imageId);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/list-ajax', name: 'admin.document.list-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentList(string $parentType, int $parentId): Response
    {
        return $this->renderList('document', $parentType, $parentId);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/form-ajax', name: 'admin.document.form-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentForm(string $parentType, int $parentId): Response
    {
        return $this->renderForm('document', $parentType, $parentId);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/save-ajax', name: 'admin.document.save-ajax', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentSave(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handleSave('document', $parentType, $parentId, $request);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/delete', name: 'admin.document.delete', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentDelete(string $parentType, int $documentId): Response
    {
        return $this->handleDelete('document', $parentType, $documentId, TheliaEvents::DOCUMENT_DELETE);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/toggle', name: 'admin.document.toggle.process', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentToggleVisibility(string $parentType, int $documentId): Response
    {
        return $this->handleToggle('document', $parentType, $documentId, TheliaEvents::DOCUMENT_TOGGLE_VISIBILITY);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/update-position', name: 'admin.document.update-position', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdatePosition(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handlePosition('document', $parentType, $request, TheliaEvents::DOCUMENT_UPDATE_POSITION);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/update-title', name: 'admin.document.update-title', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdateTitle(string $parentType, int $documentId, Request $request): Response
    {
        return $this->handleUpdateTitle('document', $parentType, $documentId, $request, TheliaEvents::DOCUMENT_UPDATE);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/update', name: 'admin.document.update.view', methods: ['GET'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdateView(string $parentType, int $documentId): Response
    {
        return $this->redirectToParent($parentType, 'document', $documentId);
    }

    private function renderList(string $kind, string $parentType, int $parentId): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::VIEW)) {
            return $denied;
        }

        $items = $this->fetchItems($kind, $parentType, $parentId);
        $canUpdate = $this->access->check($resource, [], AccessManager::UPDATE) === null;
        $canDelete = $this->access->check($resource, [], AccessManager::DELETE) === null;

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_list.html.twig', [
            'kind' => $kind,
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'items' => $items,
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'urls' => $this->urlsFor($kind, $parentType),
        ]));
    }

    private function renderForm(string $kind, string $parentType, int $parentId): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_form.html.twig', [
            'kind' => $kind,
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'urls' => $this->urlsFor($kind, $parentType),
        ]));
    }

    private function handleSave(string $kind, string $parentType, int $parentId, Request $request): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            return new JsonResponse(['error' => $this->translator->trans('No file uploaded.')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->fileProcessor->processFile($this->events, $uploadedFile, $parentId, $parentType, $kind);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleDelete(string $kind, string $parentType, int $fileId, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::DELETE)) {
            return $denied;
        }

        try {
            $this->fileDeleter->deleteFile($this->events, $fileId, $parentType, $kind, $eventName);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleToggle(string $kind, string $parentType, int $fileId, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->fileVisibility->toggleFileVisibility($this->events, $fileId, $parentType, $kind, $eventName);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handlePosition(string $kind, string $parentType, Request $request, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $fileId = (int) $request->request->get('file_id', 0);
        $position = (int) $request->request->get('position', 0);
        if ($fileId === 0 || $position === 0) {
            return new JsonResponse(['error' => $this->translator->trans('Missing file_id or position.')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->filePosition->updateFilePosition($this->events, $parentType, $fileId, $kind, $eventName, $position);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleUpdateTitle(string $kind, string $parentType, int $fileId, Request $request, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $title = trim((string) $request->request->get('title', ''));
        $modelInstance = $this->fileManager->getModelInstance($kind, $parentType);
        $model = $modelInstance->getQueryInstance()->findPk($fileId);
        if ($model === null) {
            return new JsonResponse(['error' => $this->translator->trans('File not found.')], Response::HTTP_NOT_FOUND);
        }

        try {
            $model->setLocale($this->defaultLocale());
            $model->setTitle($title);
            $model->save();
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse($request->headers->get('referer') ?? $this->urls->generate('admin.home'));
    }

    private function redirectToParent(string $parentType, string $kind, int $fileId): Response
    {
        $modelInstance = $this->fileManager->getModelInstance($kind, $parentType);
        $model = $modelInstance->getQueryInstance()->findPk($fileId);
        if ($model === null) {
            return new RedirectResponse($this->urls->generate('admin.home'));
        }

        $parentId = (int) $model->getParentId();
        $parentEditRoute = $this->guessParentEditRoute($parentType);
        if ($parentEditRoute === null) {
            return new RedirectResponse($this->urls->generate('admin.home'));
        }

        return new RedirectResponse($this->urls->generate($parentEditRoute, [$parentType.'_id' => $parentId, 'current_tab' => $kind === 'image' ? 'images' : 'documents']));
    }

    private function guessParentEditRoute(string $parentType): ?string
    {
        return match ($parentType) {
            'product' => 'admin.products.update',
            'category' => 'admin.categories.update',
            'folder' => 'admin.folders.update',
            'content' => 'admin.content.update',
            'brand' => 'admin.brand.update',
            default => null,
        };
    }

    /**
     * @return list<array{id: int, title: string, file: string, visible: bool, position: int, url: string}>
     */
    private function fetchItems(string $kind, string $parentType, int $parentId): array
    {
        $model = $this->fileManager->getModelInstance($kind, $parentType);
        $locale = $this->defaultLocale();

        $query = $model->getQueryInstance();
        $filterMethod = 'filterBy'.ucfirst($parentType).'Id';
        if (method_exists($query, $filterMethod)) {
            $query->{$filterMethod}($parentId);
        }
        $records = $query
            ->orderByPosition()
            ->find();

        $items = [];
        foreach ($records as $record) {
            if (!method_exists($record, 'setLocale')) {
                continue;
            }
            $record->setLocale($locale);
            $file = (string) $record->getFile();
            $items[] = [
                'id' => (int) $record->getId(),
                'title' => (string) $record->getTitle(),
                'file' => $file,
                'visible' => (bool) (method_exists($record, 'getVisible') ? $record->getVisible() : true),
                'position' => (int) (method_exists($record, 'getPosition') ? $record->getPosition() : 0),
                'url' => $this->fileUrl($kind, $parentType, $file),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function urlsFor(string $kind, string $parentType): array
    {
        return $kind === 'image'
            ? [
                'save' => 'admin.image.save-ajax',
                'position' => 'admin.image.update-position',
                'toggle' => 'admin.image.toggle.process',
                'delete' => 'admin.image.delete',
                'update' => 'admin.image.update.view',
                'update_title' => 'admin.image.update-title',
                'list' => 'admin.image.list-ajax',
            ]
            : [
                'save' => 'admin.document.save-ajax',
                'position' => 'admin.document.update-position',
                'toggle' => 'admin.document.toggle.process',
                'delete' => 'admin.document.delete',
                'update' => 'admin.document.update.view',
                'update_title' => 'admin.document.update-title',
                'list' => 'admin.document.list-ajax',
            ];
    }

    private function fileUrl(string $kind, string $parentType, string $file): string
    {
        if ($file === '') {
            return '';
        }

        return '/'.$kind.'/'.$parentType.'/'.$file;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(true);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
