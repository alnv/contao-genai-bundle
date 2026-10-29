<?php

namespace Alnv\GenaiBundle\Tools;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;

class RagToolVectorSearch
{
    public function __construct()
    {
    }

    public function getContext(string $question): string
    {
        $results = [];
        $vectors = $this->search($question);

        $documents = $vectors['documents'][0] ?? [];
        $metadata = $vectors['metadatas'][0] ?? [];

        foreach ($documents as $index => $document) {
            $url = $metadata[$index]['URL'] ?? 'Keine URL vorhanden';
            $id = $metadata[$index]['PAGE-ID'] ?? '';
            $title = $metadata[$index]['TITLE'] ?? '';

            $results[] = [
                'url' => $url,
                'content' => \trim($document),
                'id' => $id,
                'title' => $title
            ];
        }

        return \json_encode([
            'question' => $question,
            'results' => $results,
        ], JSON_THROW_ON_ERROR);
    }


    protected function search(string $question): array
    {
        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding(
            Config::get('genAIChromaDBOllamaUrl'),
            Config::get('genAIChromaDBOllamaModel')
        );

        return $objChroma->get($question);
    }
}