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
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PageCrawler - Extracts and indexes content from Contao pages
 * 
 * Processes HTML content from Contao's search indexer, splits it into chunks,
 * and stores it in tl_genai_documents for later embedding.
 */
class PageCrawler
{
    protected Crawler $objCrawler;
    
    /** @var array<string, true> Track processed URLs to avoid duplicates */
    protected static array $processedUrls = [];
    
    /** @var array<string, true> Track processed PDFs per request */
    protected array $processedPdfs = [];

    public function __construct(Document $document, array $arrMeta = [])
    {
        $strHtml = $document->getBody();
        
        // Normalize and validate URL
        $url = $this->normalizeUrl($document->getUri());
        
        if ($url === null) {
            $this->log("Invalid URL, skipping: " . $document->getUri()->__toString(), LogLevel::WARNING);
            return;
        }
        
        // Check for duplicate processing within same request
        if (isset(self::$processedUrls[$url])) {
            return;
        }
        self::$processedUrls[$url] = true;
        
        $strTitle = $arrMeta['title'] ?? '';
        $strContent = $this->parseContent($strHtml);
        
        // Skip empty content
        if (trim($strContent) === '') {
            $this->log("Empty content for page: {$url}", LogLevel::DEBUG);
            return;
        }

        // Process and split documents
        $arrDocuments = (new TextDataReader($strContent, [
            'sourceName' => $strTitle,
            'id' => $arrMeta['pageId'] ?? ''
        ]))->getDocuments();
        
        $arrSplitDocuments = DocumentSplitter::splitDocuments($arrDocuments, 800, '.', 60);
        $arrFormattedDocuments = EmbeddingFormatter::formatEmbeddings($arrSplitDocuments);

        $this->insertDocument($arrFormattedDocuments, $arrMeta, $url);

        // Index linked PDFs unless disabled
        if (!Config::get('gemAINoPdfIndexing')) {
            $this->addPdfs($strHtml, $arrMeta, $url);
        }
    }

    /**
     * Normalize URL by removing fragments, unnecessary query params
     */
    protected function normalizeUrl($uri): ?string
    {
        try {
            $url = $uri
                ->withFragment('')
                ->withQuery('')
                ->__toString();

            $url = StringUtil::decodeEntities($url);
            
            // Remove trailing slashes for consistency
            $url = rtrim($url, '/');
            
            // Validate URL structure
            $parsed = parse_url($url);
            if (!isset($parsed['host'])) {
                return null;
            }
            
            return $url;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Insert or update document in database
     */
    protected function insertDocument($arrFormattedDocuments, $arrMeta, $url): void
    {
        $arrContent = [];
        foreach ($arrFormattedDocuments as $objDocument) {
            $arrContent[] = [
                'chunkNumber' => $objDocument->chunkNumber,
                'formattedContent' => $objDocument->formattedContent
            ];
        }

        // Calculate content hash for change detection
        $contentHash = hash('xxh3', serialize($arrContent));

        // Determine collection ID (default to first collection)
        $collectionId = $arrMeta['collectionId'] ?? $this->getDefaultCollectionId();

        $arrSet = [
            'tstamp' => time(),
            'updated_at' => time(),
            'pid' => $collectionId,
            'title' => $arrMeta['title'] ?? '',
            'content' => serialize($arrContent),
            'pageId' => $arrMeta['pageId'] ?? '',
            'rootPageId' => $arrMeta['rootPageId'] ?? 0,
            'language' => $arrMeta['language'] ?? '',
            'url' => $url,
            'content_hash' => $contentHash,
            'synced_at' => 0, // Mark for re-embedding
        ];

        $db = Database::getInstance();
        $existing = $db->prepare('SELECT id, content_hash FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($url);

        if (!$existing->numRows) {
            // New document
            $arrSet['created_at'] = time();
            $db->prepare('INSERT INTO tl_genai_documents %s')
                ->set($arrSet)
                ->execute();
        } else {
            // Update only if content changed
            if ($existing->content_hash !== $contentHash) {
                $db->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                    ->limit(1)
                    ->set($arrSet)
                    ->execute($existing->id);
            } else {
                // Content unchanged, just update timestamp
                $db->prepare('UPDATE tl_genai_documents SET tstamp=?, updated_at=? WHERE id=?')
                    ->execute(time(), time(), $existing->id);
            }
        }
    }

    /**
     * Get default collection ID
     */
    protected function getDefaultCollectionId(): int
    {
        static $defaultId = null;
        
        if ($defaultId === null) {
            $collection = Database::getInstance()
                ->execute('SELECT id FROM tl_genai_documents_collections ORDER BY id ASC')
                ->fetchAssoc();
            
            $defaultId = $collection ? (int) $collection['id'] : 1;
        }
        
        return $defaultId;
    }

    /**
     * Extract and index PDFs linked from the page
     */
    protected function addPdfs(string $html, array $meta, string $pageUrl): void
    {
        $crawler = new Crawler($html);
        $rootDir = System::getContainer()->getParameter('kernel.project_dir');

        $parsedPageUrl = parse_url($pageUrl);
        $domain = sprintf(
            '%s://%s%s',
            $parsedPageUrl['scheme'] ?? 'https',
            $parsedPageUrl['host'] ?? '',
            isset($parsedPageUrl['port']) ? ':' . $parsedPageUrl['port'] : ''
        );

        try {
            $links = $crawler->filter('body a[href]');
        } catch (\Throwable $e) {
            return;
        }

        foreach ($links as $link) {
            $href = trim($link->getAttribute('href'));

            // Skip empty or non-PDF links
            if ($href === '' || stripos($href, '.pdf') === false) {
                continue;
            }

            // Skip external PDFs
            if (preg_match('#^https?://#i', $href) && stripos($href, $parsedPageUrl['host']) === false) {
                continue;
            }

            $url = parse_url($href);
            parse_str($url['query'] ?? '', $query);

            $file = $query['file'] ?? ($url['path'] ?? '');
            $file = ltrim($file, '/');

            // Skip if already processed in this request
            if ($file === '' || isset($this->processedPdfs[$file])) {
                continue;
            }
            $this->processedPdfs[$file] = true;

            // Find file in Contao
            $fileModel = FilesModel::findByPath($file);
            if (!$fileModel) {
                continue;
            }

            $fullPath = $rootDir . '/' . $fileModel->path;
            
            // Validate file exists and is readable
            if (!is_file($fullPath) || !is_readable($fullPath)) {
                $this->log("PDF not readable: {$fileModel->path}", LogLevel::WARNING);
                continue;
            }

            // Check file size (skip files > 50MB)
            $fileSize = filesize($fullPath);
            if ($fileSize > 50 * 1024 * 1024) {
                $this->log("PDF too large ({$fileSize} bytes): {$fileModel->path}", LogLevel::INFO);
                continue;
            }

            try {
                $documents = (new FileDataReader($fullPath))->getDocuments();
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
                $this->log(
                    sprintf('Could not index PDF "%s": %s', $fileModel->path, $e->getMessage()),
                    LogLevel::ERROR
                );
            }
        }
    }

    /**
     * Parse HTML content and extract clean text
     */
    public function parseContent(string $strContent): string
    {
        // Normalize whitespace
        $strContent = str_replace(["\n", "\r", "\t", '&#160;', '&nbsp;', '&shy;'], ' ', $strContent);

        // Remove script tags
        $strContent = $this->removeTagContent($strContent, 'script');
        
        // Remove style tags
        $strContent = $this->removeTagContent($strContent, 'style');
        
        // Remove noscript
        $strContent = $this->removeTagContent($strContent, 'noscript');
        
        // Remove nav elements (typically navigation, not content)
        $strContent = $this->removeTagContent($strContent, 'nav');

        // Handle indexer markers (nested support)
        $strContent = $this->processIndexerMarkers($strContent);

        // Extract body content
        $arrMatches = [];
        if (preg_match('/<\/head>/i', $strContent, $arrMatches, PREG_OFFSET_CAPTURE)) {
            $intOffset = strlen($arrMatches[0][0]) + $arrMatches[0][1];
            $strBody = substr($strContent, $intOffset);
        } else {
            $strBody = $strContent;
        }
        
        // Add spaces around block elements for better separation
        $strBody = str_ireplace(
            ['<br', '><', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'],
            [' <br', '> <', '</p> ', '</div> ', '</li> ', '</h1> ', '</h2> ', '</h3> ', '</h4> ', '</h5> ', '</h6> '],
            $strBody
        );

        // Strip remaining HTML tags
        $text = strip_tags($strBody);
        
        // Normalize multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * Remove content between opening and closing tags
     */
    protected function removeTagContent(string $content, string $tag): string
    {
        while (($start = stripos($content, "<{$tag}")) !== false) {
            $end = stripos($content, "</{$tag}>", $start);
            if ($end !== false) {
                $content = substr($content, 0, $start) . substr($content, $end + strlen("</{$tag}>"));
            } else {
                break;
            }
        }
        return $content;
    }

    /**
     * Process indexer::stop/continue markers (with nested support)
     */
    protected function processIndexerMarkers(string $content): string
    {
        while (($start = strpos($content, '<!-- indexer::stop -->')) !== false) {
            $end = strpos($content, '<!-- indexer::continue -->', $start);
            if ($end === false) {
                break;
            }

            // Handle nested markers
            $current = $start;
            while (($nested = strpos($content, '<!-- indexer::stop -->', $current + 22)) !== false && $nested < $end) {
                $newEnd = strpos($content, '<!-- indexer::continue -->', $end + 26);
                if ($newEnd !== false) {
                    $end = $newEnd;
                    $current = $nested;
                } else {
                    break;
                }
            }

            $content = substr($content, 0, $start) . substr($content, $end + 26);
        }

        return $content;
    }

    /**
     * Log message
     */
    protected function log(string $message, string $level = LogLevel::INFO): void
    {
        try {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log($level, "[PageCrawler] {$message}", [
                    'contao' => new ContaoContext(__CLASS__, ContaoContext::GENERAL)
                ]);
        } catch (\Throwable $e) {
            // Logger not available
        }
    }

    /**
     * Clear processed URLs cache (for testing or batch processing)
     */
    public static function clearCache(): void
    {
        self::$processedUrls = [];
    }
}
