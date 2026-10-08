<?php

declare(strict_types=1);

namespace Anubit\Filefix\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * AJAX route ajax_filefix_thumbnail: one thumbnail per request, used with <img loading="lazy">
 * in the duplicate and oversized reports. Processing thumbnails inline made the report pages
 * wait for every (often very large) original image.
 */
class ThumbnailController
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function thumbnailAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            return new Response('php://temp', 403);
        }
        try {
            $file = $this->resourceFactory->getFileObject((int)($request->getQueryParams()['file'] ?? 0));
            if (!$file->isImage()) {
                return new Response('php://temp', 404);
            }
            $url = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['maxWidth' => 64, 'maxHeight' => 64])->getPublicUrl();
        } catch (\Exception) {
            return new Response('php://temp', 404);
        }
        return $url ? new RedirectResponse($url, 302) : new Response('php://temp', 404);
    }
}
