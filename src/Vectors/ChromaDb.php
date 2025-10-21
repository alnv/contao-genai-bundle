<?php

namespace Alnv\GenaiBundle\Vectors;

use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\States;
use Codewithkyrian\ChromaDB\ChromaDB as ChromaDBClient;
use Codewithkyrian\ChromaDB\Embeddings\OllamaEmbeddingFunction;
use Contao\ContentElement;
use Contao\Database;
use Contao\PageModel;
use Contao\StringUtil;

class ChromaDb
{

    protected function parseText($strText): string
    {

        $strText = \preg_replace('#<script(.*?)>(.*?)</script>#is', '', $strText);
        $strText = \strip_tags($strText);
        $strText = \str_replace(["\r", "\n"], ' ', $strText);

        return \preg_replace('/\s+/', ' ', $strText);
    }

    public function getDataFromTable(): array
    {

        $objPstPage = PageModel::findByPk(11);
        $objJobPage = PageModel::findByPk(66);

        $arrDocuments = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $objEntities = Database::getInstance()->prepare('SELECT * FROM cm_pst WHERE tstamp>? AND visible=?')->execute(0, '1');
        while ($objEntities->next()) {

            $strUrl = $objPstPage?->getAbsoluteUrl('/' . $objEntities->area_of_life . '/' . $objEntities->alias);

            $strDocument = $this->parseText($objEntities->name) . ' URL: ' . $strUrl .' ';

            $arrOus = StringUtil::deserialize($objEntities->ou, true);
            foreach ($arrOus as $strOuId) {
                $objOuEntity = Database::getInstance()->prepare('SELECT * FROM cm_organisational_unit WHERE busbb_id=?')->limit(1)->execute($strOuId);

                $strDocument .= $this->parseText($objOuEntity->name) . ' ';
                $strDocument .= 'Adresse: ' . $this->parseText($objOuEntity->city) . ' ' . $this->parseText($objOuEntity->postal) . ' ' . $this->parseText($objOuEntity->street) . ' ' . $this->parseText($objOuEntity->streetNumber) . ' ';
                $strDocument .= 'Kontakt: ' . 'Tel.: ' . $objOuEntity->phone . ' E-Mail: ' . $objOuEntity->email . ' ';
                $strDocument .= 'Öffnungszeiten: ' . $this->parseText($objOuEntity->openingHours) . ' ';

                $arrSynonyms = StringUtil::deserialize($objOuEntity->synonyms, true);
                foreach ($arrSynonyms as $strSynonym) {
                    $strDocument .= $strSynonym . ' ';
                }
            }

            $objElements = Database::getInstance()->prepare('SELECT * FROM tl_content WHERE pid=? AND ptable=? ORDER BY sorting')->execute($objEntities->id, 'cm_pst');
            while ($objElements->next()) {
                $strDocument .= $this->parseText(ContentElement::getContentElement($objElements->id)) . ' ';
            }

            $arrSynonyms = StringUtil::deserialize($objEntities->shorts, true);
            foreach ($arrSynonyms as $strSynonym) {
                $strDocument .= $this->parseText($strSynonym) . ' ';
            }

            $arrDocuments['ids'][] = 'SERVICE-' . $objEntities->id;
            $arrDocuments['documents'][] = $strDocument;

            $arrDocuments['metadatas'][] = [
                'URL' => $strUrl
            ];
        }

        $objEntities = Database::getInstance()->prepare('SELECT * FROM cm_jobs WHERE tstamp>? AND published=?')->execute(0, '1');
        while ($objEntities->next()) {

            $strUrl = $objJobPage?->getAbsoluteUrl( '/' . $objEntities->alias);
            $strDocument = $this->parseText($objEntities->title) . ' URL: ' . $strUrl . ' ';
            $strDocument .= $this->parseText($objEntities->text);

            $arrDocuments['ids'][] = 'JOB-' . $objEntities->id;
            $arrDocuments['documents'][] = $strDocument;

            $arrDocuments['metadatas'][] = [
                'URL' =>  $strUrl
            ];
        }

        $objEntities = Database::getInstance()->prepare('SELECT * FROM cm_tenders WHERE tstamp>? AND published=?')->execute(0, '1');
        while ($objEntities->next()) {

            $strDocument = $this->parseText($objEntities->name) . ' URL: ' . $objEntities->url . ' ';
            $strDocument .= $this->parseText($objEntities->tenderType);

            $arrDocuments['ids'][] = 'TENDER-' . $objEntities->id;
            $arrDocuments['documents'][] = $strDocument;

            $arrDocuments['metadatas'][] = [
                'URL' =>  $objEntities->url
            ];
        }

        $objIndices = Database::getInstance()->prepare('SELECT * FROM tl_indices WHERE state=? AND `language`=? ORDER BY id')->execute(States::ACTIVE, 'de');
        while ($objIndices->next()) {

            if (!\str_contains($objIndices->url, 'karriere')) {
                continue;
            }

            $strText = '';
            foreach (StringUtil::deserialize($objIndices->document, true) as $strField => $varValues) {

                if (!$varValues || !\in_array($strField, ['text', 'h1', 'h2', 'h3'])) {
                    continue;
                }

                $strText .= \implode('. ', $varValues) . ' ';
            }

            $strText = $this->parseText($strText);

            $arrDocuments['ids'][] = 'PAGE-' . $objIndices->id;
            $arrDocuments['documents'][] = $strText;

            $arrDocuments['metadatas'][] = [
                'URL' =>  $objIndices->url
            ];
        }

        return $arrDocuments;
    }

    public function addData(): void
    {

        $arrData = $this->getDataFromTable();

        $objChromaDB = ChromaDBClient::factory()
            ->withHost('http://167.235.148.87')
            ->withPort(8100)
            ->connect();

        $objChromaDB->deleteAllCollections();

        $embeddingFunction = new OllamaEmbeddingFunction('http://167.235.148.87:11434', 'jina/jina-embeddings-v2-base-de');
        $objCollection = $objChromaDB->getOrCreateCollection('potsdam', null, $embeddingFunction);

        $objCollection->add($arrData['ids'], null, $arrData['metadatas'], $arrData['documents']);
    }

    public function get($strQuestion): array
    {

        $strQuestion = $this->parseText($strQuestion);
        $objChromaDB = ChromaDBClient::factory()
            ->withHost('http://167.235.148.87')
            ->withPort(8100)
            ->connect();

        $embeddingFunction = new OllamaEmbeddingFunction('http://167.235.148.87:11434', 'jina/jina-embeddings-v2-base-de');
        $objCollection = $objChromaDB->getOrCreateCollection('potsdam', null, $embeddingFunction);

        $objResults = $objCollection->query(
            queryTexts: [$strQuestion],
            nResults: 1,
            include: ['documents', 'metadatas', 'distances'],
        );

        return $objResults->toArray();
    }
}