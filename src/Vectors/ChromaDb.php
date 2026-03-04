<?php

namespace Alnv\GenaiBundle\Vectors;

use Codewithkyrian\ChromaDB\ChromaDB as ChromaDBClient;
use Codewithkyrian\ChromaDB\Client;
use Codewithkyrian\ChromaDB\Embeddings\EmbeddingFunction;
use Codewithkyrian\ChromaDB\Embeddings\OllamaEmbeddingFunction;
use Codewithkyrian\ChromaDB\Embeddings\OpenAIEmbeddingFunction;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use Psr\Log\LogLevel;

class ChromaDb
{

    protected string $strCollectionName;

    protected string $strUrl;

    protected int $intPort;

    protected EmbeddingFunction $objEmbeddingFunction;

    protected Client $objChromaDB;

    public function __construct($arrConfig = [])
    {

        // todo dyn per db
        $this->strUrl = Config::get('genAIChromaDBUrl') ?: '';
        $this->intPort = Config::get('genAIChromaDBPort') ?: null;
        $this->strCollectionName = Config::get('genAIChromaDBCollectionName') ?: '';

        $this->objChromaDB = ChromaDBClient::factory()
            ->withHost($this->strUrl)
            ->withPort($this->intPort)
            ->connect();
    }

    public function setOllamaEmbedding($strBaseUrl, $strModel): void
    {
        $this->objEmbeddingFunction = new OllamaEmbeddingFunction($strBaseUrl, $strModel);
    }

    public function setOpenAIEmbedding($strApiToken, $strOrganization, $strModel): void
    {
        $this->objEmbeddingFunction = new OpenAIEmbeddingFunction($strApiToken, $strOrganization, $strModel);
    }

    public function get($strQuestion): array
    {

        $objChromaDB = ChromaDBClient::factory()
            ->withHost($this->strUrl)
            ->withPort($this->intPort)
            ->connect();

        $objCollection = $objChromaDB->getOrCreateCollection($this->strCollectionName, null, $this->objEmbeddingFunction);

        $objResults = $objCollection->query(
            queryTexts: [$strQuestion],
            nResults: 10,
            include: ['documents', 'metadatas', 'distances'],
        );

        return $objResults->toArray();
    }

    protected function parse($objDocuments, &$arrData): void
    {

        while ($objDocuments->next()) {

            $arrContents = StringUtil::deserialize($objDocuments->content, true);

            foreach ($arrContents as $arrContent) {

                if (!($arrContent['chunkNumber'] ?? '') || !($arrContent['formattedContent'] ?? '')) {
                    continue;
                }

                $arrData['ids'][] = 'ID-' . $objDocuments->id . '-' . $arrContent['chunkNumber'];
                $arrData['documents'][] = $arrContent['formattedContent'] ?? '';
                $arrData['metadatas'][] = [
                    'URL' => $objDocuments->url,
                    'LANGUAGE' => $objDocuments->language,
                    'PAGE-ID' => $objDocuments->pageId ?: 0,
                    'TITLE' => $objDocuments->title
                ];
            }
        }
    }

    public function sync(): void
    {

        $arrData = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $objDocuments = Database::getInstance()->prepare('SELECT * FROM tl_genai_documents WHERE synced_at>0 AND tstamp>0 AND `language`=? ORDER BY title')->limit(10)->execute('de');
        if (!$objDocuments->numRows) {
            return;
        }

        $this->parse($objDocuments, $arrData);

        if (empty($arrData['ids'])) {
            return;
        }

        $objCollection = $this->objChromaDB->getOrCreateCollection($this->strCollectionName, null, $this->objEmbeddingFunction);
        $objCollection->update($arrData['ids'], null, $arrData['metadatas'], $arrData['documents']);

        $this->flag($objDocuments);
    }

    public function add(): void
    {

        $arrData = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $objDocuments = Database::getInstance()->prepare('SELECT * FROM tl_genai_documents WHERE synced_at=0 AND tstamp>0 AND `language`=? ORDER BY title')->limit(10)->execute('de');
        if (!$objDocuments->numRows) {
            return;
        }

        $this->parse($objDocuments, $arrData);

        if (empty($arrData['ids'])) {
            return;
        }

        try {
            $objCollection = $this->objChromaDB->getOrCreateCollection($this->strCollectionName, null, $this->objEmbeddingFunction);
            $objCollection->add($arrData['ids'], null, $arrData['metadatas'], $arrData['documents']);
        } catch (\Exception $objError) {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log(LogLevel::ERROR, 'Error by document with ID: ' . $objDocuments->id . ': ' . $objError->getMessage(), ['contao' => new ContaoContext(__CLASS__ . '::' . __FUNCTION__)]);
        }

        $this->flag($objDocuments);
    }

    protected function flag($objDocuments): void
    {

        $objDocuments->reset();

        while ($objDocuments->next()) {

            Database::getInstance()->prepare('UPDATE tl_genai_documents %s WHERE id=?')->set(['tstamp' => \time(), 'synced_at' => \time()])->limit(1)->execute($objDocuments->id);
        }
    }

    public function deleteAll(): void
    {
        $this->objChromaDB->deleteCollection($this->strCollectionName);
    }

    public function count(): int
    {
        $objCollection = $this->objChromaDB->getOrCreateCollection($this->strCollectionName, null, $this->objEmbeddingFunction);

        return $objCollection->count();
    }
}