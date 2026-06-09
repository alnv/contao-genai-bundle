<?php

namespace Alnv\GenaiBundle\Vectors;

use Alnv\GenaiBundle\Embeddings\OllamaEmbeddings;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use HelgeSverre\Chromadb\Chromadb as ChromaDbClient;
use HelgeSverre\Chromadb\Embeddings\EmbeddingFunction;
use Psr\Log\LogLevel;

// https://github.com/helgeSverre/chromadb
class ChromaDb
{
    protected string $collectionName;
    protected string $collectionId;
    protected string $url;
    protected int $port;
    protected EmbeddingFunction $embeddingFunction;
    protected ChromaDBClient $chromaDB;

    public function __construct($arrConfig = [])
    {
        // todo dyn per db
        $this->url = Config::get('genAIChromaDBUrl') ?: '';
        $this->port = Config::get('genAIChromaDBPort') ?: null;
        $this->collectionName = Config::get('genAIChromaDBCollectionName') ?: '';

        $this->chromaDB = new ChromaDbClient(
            token: null,
            host: $this->url,
            port: $this->port
        );

        $response = $this->chromaDB->collections()->create($this->collectionName, true);
        $this->collectionId = $response->json('id');
    }

    public function setOllamaEmbedding($strBaseUrl, $strModel): void
    {
        $this->embeddingFunction = new OllamaEmbeddings(
            model: $strModel,
            baseUrl: $strBaseUrl,
            timeout: 120
        );
    }

    public function setOpenAIEmbedding($strApiToken, $strOrganization, $strModel): void
    {
        // todo
    }

    public function get(string $query): array
    {
        $results = $this->chromaDB->items()->queryWithText(
            collectionId: $this->collectionId,
            queryText: $query,
            embeddingFunction: $this->embeddingFunction,
            nResults: 5,
            include: ['documents', 'metadatas', 'distances']
        );

        return $results->array();
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
        $data = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $objDocuments = Database::getInstance()
            ->prepare('SELECT * FROM tl_genai_documents WHERE synced_at>0 AND tstamp>0 AND `language`=? ORDER BY RAND()')
            ->limit(1)
            ->execute('de');

        if (!$objDocuments->numRows) {
            return;
        }

        $this->parse($objDocuments, $data);

        if (empty($data['ids'])) {
            return;
        }

        try {
            // $objCollection = $this->chromaDB->getOrCreateCollection($this->strCollectionName, null, $this->objEmbeddingFunction);
            // $objCollection->update($data['ids'], null, $data['metadatas'], $data['documents']);
            // $this->flag($objDocuments);
        } catch (\Exception $error) {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log(LogLevel::ERROR, 'Document with ID: ' . $objDocuments->id . ': ' . $error->getMessage(), ['contao' => new ContaoContext(__CLASS__ . '::' . __FUNCTION__)]);
        }
    }

    public function add(): void
    {
        $data = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $objDocuments = Database::getInstance()
            ->prepare('SELECT * FROM tl_genai_documents WHERE synced_at=0 AND tstamp>0 AND `language`=? ORDER BY RAND()')
            ->limit(1)
            ->execute('de');

        if (!$objDocuments->numRows) {
            return;
        }

        $this->parse($objDocuments, $data);
        if (empty($data['ids'])) {
            if ($objDocuments->numRows) {
                $this->flag($objDocuments);
            }
            return;
        }

        try {
            $this->chromaDB
                ->items()
                ->addWithEmbeddings(
                    collectionId: $this->collectionId,
                    documents: $data['documents'],
                    embeddingFunction: $this->embeddingFunction,
                    ids: $data['ids'],
                    metadatas: $data['metadatas']
                );

            $this->flag($objDocuments);
        } catch (\Exception $error) {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log(LogLevel::ERROR, 'Document with ID: ' . $objDocuments->id . ': ' . $error->getMessage(), ['contao' => new ContaoContext(__CLASS__ . '::' . __FUNCTION__)]);
        }
    }

    protected function flag($objDocuments): void
    {
        $objDocuments->reset();
        while ($objDocuments->next()) {
            Database::getInstance()
                ->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                ->set(['tstamp' => \time(), 'synced_at' => \time()])->limit(1)
                ->execute($objDocuments->id);
        }
    }

    public function deleteAll(): void
    {
        Database::getInstance()
            ->prepare('UPDATE tl_genai_documents %s')
            ->set([
                'tstamp' => \time(),
                'synced_at' => 0
            ])
            ->execute();

        $this->chromaDB->items()->delete($this->collectionName);
    }

    public function count(): int
    {
        return $this->chromaDB->items()->count(collectionId: $this->collectionId);
    }
}