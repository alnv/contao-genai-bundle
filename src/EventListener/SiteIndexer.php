<?php

namespace Alnv\GenaiBundle\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Search\Document;
use Contao\CoreBundle\Search\Indexer\IndexerException;
use Contao\CoreBundle\Search\Indexer\IndexerInterface;
use Contao\Database;
use Alnv\GenaiBundle\DocumentCollectors\PageCrawler;

class SiteIndexer implements IndexerInterface
{

    private ContaoFramework $framework;

    private bool $indexProtected;

    public function __construct(ContaoFramework $framework, bool $indexProtected = false)
    {
        $this->framework = $framework;
        $this->indexProtected = $indexProtected;
    }

    public function index(Document $document): void
    {

        if (200 !== $document->getStatusCode()) {
            $this->throwBecause('HTTP Statuscode is not equal to 200.');
        }

        if ('' === $document->getBody()) {
            $this->throwBecause('Cannot index empty response.');
        }

        try {
            $strTitle = $document->getContentCrawler()->filterXPath('//head/title')->first()->text(null, true);
        } catch (\Exception $e) {
            $strTitle = '';
        }

        try {
            $strLanguage = $document->getContentCrawler()->filterXPath('//html[@lang]')->first()->attr('lang');
        } catch (\Exception $e) {
            $strLanguage = 'en';
        }

        $arrMeta = [
            'title' => $strTitle,
            'language' => $strLanguage,
            'protected' => false,
            'groups' => []
        ];

        if (!isset($arrMeta['pageId']) || 0 === $arrMeta['pageId']) {
            $this->throwBecause('No page ID could be determined.');
        }

        // If search was disabled in the page settings, we do not index
        if (isset($arrMeta['noSearch']) && true === $arrMeta['noSearch']) {
            $this->throwBecause('Was explicitly marked "noSearch" in page settings.');
        }

        // If the front end preview is activated, we do not index
        if (isset($arrMeta['fePreview']) && true === $arrMeta['fePreview']) {
            $this->throwBecause('Indexing when the front end preview is enabled is not possible.');
        }

        // If the page is protected and indexing protecting pages is disabled, we do not index
        if (isset($arrMeta['protected']) && true === $arrMeta['protected'] && !$this->indexProtected) {
            $this->throwBecause('Indexing protected pages is disabled.');
        }

        $this->framework->initialize();

        new PageCrawler($document, $arrMeta);
    }

    public function delete(Document $document): void
    {
        //
    }

    public function clear(): void
    {
        Database::getInstance()->prepare('DELETE FROM tl_genai_documents WHERE pageId!=?')->execute('');
    }

    private function throwBecause(string $message, bool $onlyWarning = true): void
    {

        if ($onlyWarning) {
            throw IndexerException::createAsWarning($message);
        }

        throw new IndexerException($message);
    }
}