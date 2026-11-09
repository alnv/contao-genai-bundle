<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Tests\Util;

use PHPUnit\Framework\TestCase;

/**
 * Tests for content chunking
 */
class ChunkingTest extends TestCase
{
    /**
     * Test basic chunking with small content
     */
    public function testSmallContentNotChunked(): void
    {
        $content = 'This is a small text.';
        $chunks = $this->chunkContent($content);
        
        $this->assertCount(1, $chunks);
        $this->assertSame(1, $chunks[0]['chunkNumber']);
        $this->assertSame($content, $chunks[0]['formattedContent']);
    }

    /**
     * Test chunking with content larger than chunk size
     */
    public function testLargeContentChunked(): void
    {
        // Create content larger than default chunk size (1000)
        $content = str_repeat('Lorem ipsum dolor sit amet. ', 100);
        $chunks = $this->chunkContent($content, 500, 50);
        
        $this->assertGreaterThan(1, count($chunks));
        
        // Verify chunk numbers are sequential
        foreach ($chunks as $index => $chunk) {
            $this->assertSame($index + 1, $chunk['chunkNumber']);
        }
    }

    /**
     * Test that chunks have overlap
     */
    public function testChunksHaveOverlap(): void
    {
        $content = "First sentence. Second sentence. Third sentence. Fourth sentence. Fifth sentence.";
        $chunks = $this->chunkContent($content, 40, 10);
        
        // With overlap, the end of chunk N should appear at start of chunk N+1
        if (count($chunks) > 1) {
            $endOfFirst = substr($chunks[0]['formattedContent'], -10);
            $startOfSecond = substr($chunks[1]['formattedContent'], 0, 20);
            
            // The overlap should create some repetition
            $this->assertTrue(
                str_contains($startOfSecond, trim($endOfFirst)) || 
                str_contains(trim($endOfFirst), $startOfSecond),
                'Chunks should have overlapping content'
            );
        }
    }

    /**
     * Test chunking preserves content
     */
    public function testChunkingPreservesAllContent(): void
    {
        $words = ['Hello', 'world', 'this', 'is', 'a', 'test', 'of', 'chunking'];
        $content = implode(' ', $words);
        $chunks = $this->chunkContent($content, 20, 5);
        
        // All words should appear in at least one chunk
        $allChunkContent = implode(' ', array_column($chunks, 'formattedContent'));
        foreach ($words as $word) {
            $this->assertStringContainsString($word, $allChunkContent);
        }
    }

    /**
     * Test empty content
     */
    public function testEmptyContent(): void
    {
        $chunks = $this->chunkContent('');
        
        $this->assertCount(1, $chunks);
        $this->assertSame('', $chunks[0]['formattedContent']);
    }

    /**
     * Test whitespace-only content
     */
    public function testWhitespaceContent(): void
    {
        $chunks = $this->chunkContent('   ');
        
        $this->assertCount(1, $chunks);
        $this->assertSame('', $chunks[0]['formattedContent']);
    }

    /**
     * Helper method to chunk content (mirrors SyncVectors::chunkContent)
     */
    private function chunkContent(string $content, int $chunkSize = 1000, int $overlap = 200): array
    {
        $chunks = [];
        $content = trim($content);
        
        if (strlen($content) <= $chunkSize || $content === '') {
            return [
                ['chunkNumber' => 1, 'formattedContent' => $content]
            ];
        }

        $position = 0;
        $chunkNumber = 1;

        while ($position < strlen($content)) {
            $chunk = substr($content, $position, $chunkSize);
            
            // Try to break at sentence or paragraph boundary
            if (strlen($chunk) === $chunkSize && $position + $chunkSize < strlen($content)) {
                $lastBreak = max(
                    strrpos($chunk, ".\n") ?: 0,
                    strrpos($chunk, ". ") ?: 0,
                    strrpos($chunk, "\n\n") ?: 0
                );
                
                if ($lastBreak > $chunkSize * 0.5) {
                    $chunk = substr($chunk, 0, $lastBreak + 1);
                }
            }

            $chunks[] = [
                'chunkNumber' => $chunkNumber++,
                'formattedContent' => trim($chunk)
            ];

            $position += strlen($chunk) - $overlap;
            
            if (strlen($chunk) <= $overlap) {
                break;
            }
        }

        return $chunks;
    }
}
