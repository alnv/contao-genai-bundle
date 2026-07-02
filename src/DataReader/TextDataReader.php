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
        $content = \mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = \preg_replace('/\s+/', ' ', $content);
        $content = \preg_replace('/ {2,}/', ' ', $content);
        $content = \str_replace('_', ' ', $content);
        $content = \str_replace('-', '', $content);
        $content = \preg_replace('/[•·▪]/u', '', $content);
        $content = \trim($content);

        $objDocument = new Document();

        $objDocument->id = $this->arrMeta['id'] ?? '';
        $objDocument->content = $content;
        $objDocument->sourceType = $this->arrMeta['sourceType'] ?? '';
        $objDocument->sourceName = $this->arrMeta['sourceName'] ?? '';
        $objDocument->hash = \hash('sha256', $content);

        return $objDocument;
    }
}