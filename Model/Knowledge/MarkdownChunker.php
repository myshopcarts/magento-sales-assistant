<?php
/**
 * MarkdownChunker — structure-first chunking (knowledge-slice §5). Splits markdown
 * on ATX headings into sections in document order; each chunk is **prefixed with its
 * heading path** so it stands alone; oversized sections split into paragraph children
 * that share the parent section text (small-to-big: index the child, return the
 * parent for context). Size is a guardrail, not the driver.
 *
 * K2a handles markdown / HTML-as-markdown / plain text (no heavy parser). PDF/DOCX go
 * through the backend `/extract` endpoint (K2b) which emits the same chunk shape.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

class MarkdownChunker
{
    private const CHILD_CAP = 1600; // ~400 tokens (≈chars/4); split larger sections
    private const SEP = ' > ';

    /**
     * @param array{title?:string,source_id:string,url?:string,updated_at?:string,content_hash?:string} $meta
     * @return array<int,array<string,mixed>> chunk docs for KnowledgeIndexer
     */
    public function chunk(string $markdown, array $meta): array
    {
        $title = trim((string)($meta['title'] ?? ''));
        $hash = (string)($meta['content_hash'] ?? sha1($markdown));
        $updated = (string)($meta['updated_at'] ?? gmdate('c'));
        $url = (string)($meta['url'] ?? '');
        $sourceId = (string)$meta['source_id'];

        $out = [];
        $sec = 0;
        foreach ($this->splitSections($markdown) as $s) {
            $body = trim($s['body']);
            if ($body === '') {
                continue;
            }
            $sec++;
            $path = $this->headingPath($title, $s['path']);
            $sectionId = $sourceId . '#s' . $sec;
            $pieces = $this->splitBySize($body, self::CHILD_CAP);
            $multi = count($pieces) > 1;
            $parentText = $multi ? ($path . "\n\n" . $body) : '';
            foreach ($pieces as $i => $piece) {
                $out[] = [
                    'id' => $sectionId . ($multi ? '-' . $i : ''),
                    'source_type' => 'document',
                    'source_id' => $sourceId,
                    'title' => $title,
                    'url' => $url,
                    'updated_at' => $updated,
                    'content_hash' => $hash,
                    'chunk_type' => 'section',
                    'heading_path' => $path,
                    // heading-prefixed, self-contained (like the product identity header)
                    'text' => $path . "\n\n" . $piece,
                    'parent_id' => $multi ? $sectionId : '',
                    'parent_text' => $parentText,
                    'version_scope' => 'all', // merchant docs: version-independent by default
                ];
            }
        }
        return $out;
    }

    /** @return array<int,array{path:array<int,string>,body:string}> sections in order. */
    private function splitSections(string $md): array
    {
        $lines = preg_split('/\r?\n/', $md) ?: [];
        $stack = [];
        $sections = [];
        $cur = ['path' => [], 'body' => ''];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*\S)\s*$/', $line, $m)) {
                if (trim($cur['body']) !== '') {
                    $sections[] = $cur;
                }
                $level = strlen($m[1]);
                $stack = array_slice($stack, 0, $level - 1);
                $stack[$level - 1] = trim($m[2]);
                $cur = ['path' => array_values($stack), 'body' => ''];
            } else {
                $cur['body'] .= $line . "\n";
            }
        }
        if (trim($cur['body']) !== '') {
            $sections[] = $cur;
        }
        return $sections;
    }

    private function headingPath(string $title, array $path): string
    {
        $parts = $title !== '' ? array_merge([$title], $path) : $path;
        // Drop consecutive duplicates — a doc's H1 usually equals its title, so
        // "Backups + [Backups, Retention]" becomes "Backups > Retention".
        $dedup = [];
        foreach ($parts as $p) {
            if ($p !== '' && (empty($dedup) || end($dedup) !== $p)) {
                $dedup[] = $p;
            }
        }
        return implode(self::SEP, $dedup);
    }

    /** Split a body into <= $cap-char pieces at paragraph boundaries. */
    private function splitBySize(string $body, int $cap): array
    {
        if (strlen($body) <= $cap) {
            return [$body];
        }
        $pieces = [];
        $buf = '';
        foreach (preg_split('/\n\s*\n/', $body) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if ($buf !== '' && strlen($buf) + strlen($para) + 2 > $cap) {
                $pieces[] = $buf;
                $buf = '';
            }
            $buf = $buf === '' ? $para : $buf . "\n\n" . $para;
            while (strlen($buf) > $cap) { // single oversized paragraph: hard-split
                $pieces[] = substr($buf, 0, $cap);
                $buf = (string)substr($buf, $cap);
            }
        }
        if (trim($buf) !== '') {
            $pieces[] = $buf;
        }
        return $pieces;
    }
}
