<?php

namespace Alnv\GenaiBundle\DocumentCollectors;

use Alnv\GenaiBundle\DataReader\TextDataReader;
use Contao\CoreBundle\Search\Document;
use Contao\Database;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\System;
use LLPhant\Embeddings\DataReader\FileDataReader;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\EmbeddingFormatter\EmbeddingFormatter;
use Symfony\Component\DomCrawler\Crawler;

class PageCrawler
{
    protected Crawler $objCrawler;

    public function __construct(Document $document, array $arrMeta = [])
    {

        $strHtml = $document->getBody();
        $strTitle = $arrMeta['title'] ?? '';
        $strContent = $this->parseContent($strHtml);

        $arrDocuments = (new TextDataReader($strContent, ['sourceName' => $strTitle, 'id' => $arrMeta['pageId'] ?? '']))->getDocuments();
        $arrSplitDocuments = DocumentSplitter::splitDocuments($arrDocuments, 775, '.', 25);
        $arrFormattedDocuments = EmbeddingFormatter::formatEmbeddings($arrSplitDocuments);

        $strUrl = $document->getUri()->__toString();
        $strUrl = StringUtil::decodeEntities($strUrl);

        $this->insertDocument($arrFormattedDocuments, $arrMeta, $strUrl);

        $this->addPdfs($strHtml, $arrMeta, $strUrl);
    }

    protected function insertDocument($arrFormattedDocuments, $arrMeta, $strUrl): void
    {

        $arrContent = [];
        foreach ($arrFormattedDocuments as $objDocument) {
            $arrContent[] = [
                'chunkNumber' => $objDocument->chunkNumber,
                'formattedContent' => $objDocument->formattedContent
            ];
        }

        $arrSet = [
            'tstamp' => \time(),
            'updated_at' => \time(),
            'pid' => 1, // todo
            'title' => $arrMeta['title'] ?? '',
            // 'description' => '',
            'content' => \serialize($arrContent),
            'pageId' => $arrMeta['pageId'] ?? '',
            'url' => $strUrl,
            'language' => $arrMeta['language'] ?? ''
        ];

        $objDocumentEntity = Database::getInstance()->prepare('SELECT id, url FROM tl_genai_documents WHERE url=?')->limit(1)->execute($strUrl);
        if (!$objDocumentEntity->numRows) {
            $arrSet['created_at'] = \time();
            Database::getInstance()->prepare('INSERT INTO tl_genai_documents %s')->set($arrSet)->execute();
        } else {
            Database::getInstance()->prepare('UPDATE tl_genai_documents %s WHERE id=?')->limit(1)->set($arrSet)->execute($objDocumentEntity->id);
        }
    }

    protected function addPdfs($strHtml, $arrMeta, $strPageUrl): void
    {

        $this->objCrawler = new Crawler($strHtml);
        $objLinks = $this->objCrawler->filter("body a");
        $strRootDir = System::getContainer()->getParameter('kernel.project_dir');

        $arrDomain = \parse_url($strPageUrl);
        $strDomain = ($arrDomain['scheme'] ?? '')
            . '://'
            . ($arrDomain['host'] ?? '')
            . (isset($arrDomain['port']) && $arrDomain['port'] ? ':' . $arrDomain['port'] : '');

        foreach ($objLinks as $objLink) {

            $strHref = $objLink->getAttribute('href');
            if (!$strHref || !\str_contains($strHref, '.pdf')) {
                continue;
            }

            $arrUrlFragments = \parse_url($strHref, PHP_URL_QUERY);
            \parse_str($arrUrlFragments, $arrQueries);

            $strFile = $arrQueries['file'] ?? ($arrUrlFragments['path'] ?? '');

            $objFile = FilesModel::findByPath($strFile);
            if (!$objFile) {
                continue;
            }

            $strFilePath = $strRootDir . '/' . $objFile->path;
            $objReader = new FileDataReader($strFilePath);
            $arrDocuments = $objReader->getDocuments();

            $arrSplitDocuments = DocumentSplitter::splitDocuments($arrDocuments, 800);
            $arrFormattedDocuments = EmbeddingFormatter::formatEmbeddings($arrSplitDocuments);

            $strUrl = $strDomain . '/' . $objFile->path;

            $this->insertDocument($arrFormattedDocuments, $arrMeta, $strUrl);
        }
    }

    public function parseContent($strContent): string
    {

        $strContent = \str_replace(["\n", "\r", "\t", '&#160;', '&nbsp;', '&shy;'], [' ', ' ', ' ', ' ', ' ', ''], $strContent);

        while (($intStart = \strpos($strContent, '<script')) !== false) {
            if (($intEnd = \strpos($strContent, '</script>', $intStart)) !== false) {
                $strContent = \substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
            } else {
                break;
            }
        }

        while (($intStart = \strpos($strContent, '<style')) !== false) {
            if (($intEnd = \strpos($strContent, '</style>', $intStart)) !== false) {
                $strContent = \substr($strContent, 0, $intStart) . \substr($strContent, $intEnd + 8);
            } else {
                break;
            }
        }

        while (($intStart = \strpos($strContent, '<!-- indexer::stop -->')) !== false) {
            if (($intEnd = \strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false) {
                $intCurrent = $intStart;

                while (($intNested = \strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd) {
                    if (($intNewEnd = \strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false) {
                        $intEnd = $intNewEnd;
                        $intCurrent = $intNested;
                    } else {
                        break;
                    }
                }

                $strContent = \substr($strContent, 0, $intStart) . \substr($strContent, $intEnd + 26);
            } else {
                break;
            }
        }

        $arrMatches = [];
        \preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE);
        $intOffset = \strlen($arrMatches[0][0]) + $arrMatches[0][1];

        $strBody = \substr($strContent, $intOffset);
        $strBody = \str_ireplace(['<br', '><'], [' <br', '> <'], $strBody);

        return \strip_tags($strBody);
    }
}
