<?php

namespace Alnv\GenaiBundle\DataReader;

use LLPhant\Embeddings\DataReader\DataReader;
use LLPhant\Embeddings\Document;

final class TextDataReader implements DataReader
{

    protected string $strContent;

    protected array $arrMeta = [];

    public function __construct($content, $arrMeta)
    {
        $this->strContent = $content;
        $this->arrMeta = $arrMeta;
    }

    public function getDocuments(): array
    {
        return [$this->getDocument($this->strContent)];
    }

    private function getDocument(string $content): mixed
    {

        $content = \preg_replace('/\s+/', ' ', $content);
        $content = \html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = \str_replace("\u{A0}", " ", $content);

        $objDocument = new Document();

        $objDocument->id = $this->arrMeta['id'] ?? '';
        $objDocument->content = $content;
        $objDocument->sourceType = $this->arrMeta['sourceType'] ?? '';
        $objDocument->sourceName = $this->arrMeta['sourceName'] ?? '';
        $objDocument->hash = \hash('sha256', $content);

        return $objDocument;
    }
}