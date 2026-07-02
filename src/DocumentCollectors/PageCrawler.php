<?php

namespace Alnv\GenaiBundle\DocumentCollectors;

use Alnv\GenaiBundle\DataReader\TextDataReader;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
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
        $arrSplitDocuments = DocumentSplitter::splitDocuments($arrDocuments, 800, '.', 60);
        $arrFormattedDocuments = EmbeddingFormatter::formatEmbeddings($arrSplitDocuments);

        $url = $document->getUri()
            ->withFragment('')
            ->withQuery('')
            ->__toString();

        $url = StringUtil::decodeEntities($url);
        $this->insertDocument($arrFormattedDocuments, $arrMeta, $url);

        if (Config::get('gemAINoPdfIndexing')) {
            return;
        }

        $this->addPdfs($strHtml, $arrMeta, $url);
    }

    protected function insertDocument($arrFormattedDocuments, $arrMeta, $url): void
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
            'content' => \serialize($arrContent),
            'pageId' => $arrMeta['pageId'] ?? '',
            'url' => $url,
            'language' => $arrMeta['language'] ?? ''
        ];

        $objDocumentEntity = Database::getInstance()
            ->prepare('SELECT id, url FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($url);

        if (!$objDocumentEntity->numRows) {
            $arrSet['created_at'] = \time();
            Database::getInstance()
                ->prepare('INSERT INTO tl_genai_documents %s')
                ->set($arrSet)
                ->execute();

        } else {
            Database::getInstance()
                ->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                ->limit(1)
                ->set($arrSet)
                ->execute($objDocumentEntity->id);
        }
    }

    protected function addPdfs(string $html, array $meta, string $pageUrl): void
    {
        $crawler = new Crawler($html);
        $rootDir = System::getContainer()->getParameter('kernel.project_dir');

        $parsedPageUrl = \parse_url($pageUrl);
        $domain = \sprintf(
            '%s://%s%s',
            $parsedPageUrl['scheme'] ?? 'https',
            $parsedPageUrl['host'] ?? '',
            isset($parsedPageUrl['port']) ? ':' . $parsedPageUrl['port'] : ''
        );

        $processed = [];

        foreach ($crawler->filter('body a') as $link) {
            $href = \trim($link->getAttribute('href'));

            if ($href === '' || \stripos($href, '.pdf') === false) {
                continue;
            }

            $url = \parse_url($href);
            \parse_str($url['query'] ?? '', $query);

            $file = $query['file'] ?? ($url['path'] ?? '');

            if ($file === '' || isset($processed[$file])) {
                continue;
            }

            $processed[$file] = true;
            $fileModel = FilesModel::findByPath($file);

            if (!$fileModel) {
                continue;
            }

            try {
                $documents = (new FileDataReader($rootDir . '/' . $fileModel->path))->getDocuments();
                $documents = EmbeddingFormatter::formatEmbeddings(
                    DocumentSplitter::splitDocuments($documents, 800, '.', 60)
                );

                $documentMeta = [
                    ...$meta,
                    'pageId' => StringUtil::binToUuid($fileModel->uuid),
                    'sourceName' => $fileModel->name,
                    'title' => $fileModel->name,
                ];

                $this->insertDocument(
                    $documents,
                    $documentMeta,
                    $domain . '/' . $fileModel->path
                );
            } catch (\Throwable $e) {
                System::getContainer()
                    ->get('monolog.logger.contao')
                    ->error(
                        sprintf(
                            'Could not crawl PDF "%s": %s',
                            $fileModel->path,
                            $e->getMessage()
                        ),
                        ['contao' => new ContaoContext(__METHOD__)]
                    );
            }
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
