<?php
/**
 * HtmlToMarkdown — HTML → markdown for the structure-first chunker (§5), using a REAL DOM
 * parser (ext-dom / libxml), not regex. Regex parsing was the source of two production bugs:
 * `strip_tags` removed <style> TAGS but left their CSS as text (PageBuilder CSS leaked into the
 * knowledge index), and a "grab the biggest <main>" regex dropped sibling content. A DOM parser
 * removes a <style>/<script> node AND its contents, and a proper tree walk keeps the whole
 * chosen region — so laid-out content (e.g. plan cards) survives.
 *
 * Two entry points, both preserved from the old API:
 *   - convert(): trusted CMS fragment → markdown. Strips any UN-rendered Magento directives
 *     ({{block}}/{{widget}}/{{media}} left as literal text) first, then DOM-renders the body.
 *   - convertPage(): a full fetched web PAGE (K3 URL source) → {markdown,title}. Drops
 *     non-content + page chrome, prefers the <main>/<article> content region, lifts <title>.
 *
 * Maps h1–h6 → ATX headings (the chunker splits on these), <li> → "- ", table rows → "a | b",
 * and block boundaries → blank lines. Best-effort: the merchant previews/edits before embedding.
 * PDFs/DOCX go through the backend /extract endpoint (K2b) instead.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

class HtmlToMarkdown
{
    /** Non-content subtrees: removed node AND contents (this is what kills the CSS leak). */
    private const DROP_TAGS = ['script', 'style', 'noscript', 'svg', 'head', 'template', 'iframe', 'object'];
    /** Page chrome: removed for a full PAGE only (a trusted CMS fragment has no chrome).
     * NOTE: <form> is deliberately NOT here — storefronts wrap real content in forms (the
     * plan/checkout cards are `<form action="/portal/checkout/start">`), and dropping forms is
     * what hid the plan cards from the index. Only structural page chrome is removed. */
    private const CHROME_TAGS = ['nav', 'header', 'footer', 'aside'];
    /** Block-level tags: their boundaries become paragraph breaks. */
    private const BLOCK_TAGS = [
        'p', 'div', 'section', 'article', 'main', 'ul', 'ol', 'table', 'thead', 'tbody',
        'blockquote', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'address', 'hr', 'pre',
    ];

    /**
     * Convert a full web PAGE to markdown + title.
     * @return array{markdown:string,title:string}
     */
    public function convertPage(string $html): array
    {
        if (trim($html) === '') {
            return ['markdown' => '', 'title' => ''];
        }
        $doc = $this->load($html);
        $title = $this->title($doc);
        $this->strip($doc, array_merge(self::DROP_TAGS, self::CHROME_TAGS));
        $root = $this->contentRoot($doc);
        return ['markdown' => $this->tidy($this->render($root)), 'title' => $title];
    }

    /** Convert a trusted CMS fragment to markdown. */
    public function convert(string $html): string
    {
        // Un-rendered Magento directives ({{block ...}}, {{widget ...}}, {{media ...}}) are
        // literal text here, not content — drop them. (Dynamic block output that must be
        // indexed should be RENDERED by the source before reaching this converter.)
        $html = preg_replace('/\{\{[^}]*\}\}/s', ' ', $html) ?? $html;
        if (trim($html) === '') {
            return '';
        }
        $doc = $this->load($html);
        $this->strip($doc, self::DROP_TAGS);
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        return $body ? $this->tidy($this->render($body)) : '';
    }

    // ---- DOM helpers -------------------------------------------------------

    private function load(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8: without this libxml assumes ISO-8859-1 and mangles multibyte text.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    private function title(\DOMDocument $doc): string
    {
        $t = $doc->getElementsByTagName('title')->item(0);
        return $t ? trim((string)preg_replace('/\s+/', ' ', $t->textContent)) : '';
    }

    /** Remove every node of the given tag names, contents included. */
    private function strip(\DOMDocument $doc, array $tags): void
    {
        foreach ($tags as $tag) {
            // Snapshot first — removing while iterating a live NodeList skips siblings.
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * The region to extract: the largest <main>/<article> if the page marks one (strips the
     * storefront chrome around it), else the <body>. We keep the WHOLE subtree of the chosen
     * region — unlike the old regex, sibling sections inside it (e.g. a card grid) are kept.
     */
    private function contentRoot(\DOMDocument $doc): \DOMNode
    {
        $best = null;
        $bestLen = 0;
        foreach (['main', 'article'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $len = strlen($node->textContent);
                if ($len > $bestLen) {
                    $best = $node;
                    $bestLen = $len;
                }
            }
        }
        return $best ?? $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
    }

    // ---- render: DOM subtree → markdown ------------------------------------

    private function render(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child);
        }
        return $out;
    }

    private function renderNode(\DOMNode $n): string
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            // Collapse internal whitespace; structural newlines come from block handling.
            return (string)preg_replace('/\s+/', ' ', (string)$n->nodeValue);
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        $tag = strtolower($n->nodeName);
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $txt = trim($this->render($n));
            return $txt !== '' ? "\n\n" . str_repeat('#', (int)$m[1]) . ' ' . $txt . "\n\n" : '';
        }
        switch ($tag) {
            case 'br':
                return "\n";
            case 'li':
                $txt = trim($this->render($n));
                return $txt !== '' ? "\n- " . $txt : '';
            case 'tr':
                // Join the row's cells with " | "; the cell handler adds the separator.
                return "\n" . trim((string)preg_replace('/\s*\|\s*$/', '', $this->render($n)));
            case 'td':
            case 'th':
                return trim($this->render($n)) . ' | ';
            default:
                if (in_array($tag, self::BLOCK_TAGS, true)) {
                    return "\n" . $this->render($n) . "\n";
                }
                // Inline (span, a, strong, em, b, i, small, label, button, …): pass through.
                return $this->render($n);
        }
    }

    private function tidy(string $s): string
    {
        // Double-encoded CMS markup (&amp;lt;h2&amp;gt;) surfaces as literal entities — decode.
        if (preg_match('/&(lt|gt|amp|quot|#\d+);/', $s)) {
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $s = (string)preg_replace('/[ \t]+/', ' ', $s);        // collapse spaces
        $s = (string)preg_replace('/ *\n */', "\n", $s);        // trim line-edge spaces
        $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);      // collapse blank runs
        return trim($s);
    }
}
