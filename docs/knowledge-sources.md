# Feeding the Sales Assistant: knowledge sources

The assistant only answers from *your store's own data*. That data reaches it through four
sources — and the single most important rule is that **structured facts need a different
source than prose.** Get this right and the assistant is accurate and complete; get it wrong
and it can only answer half a question.

## The four sources

1. **Product catalogue — automatic.** Your products (name, price, stock, attributes) are
   indexed from Magento and searched live. Nothing to set up; just keep your catalogue current.
2. **CMS pages & blocks — automatic, on by default.** Your active CMS pages and static blocks
   (FAQ, shipping, returns, About…) are auto-indexed as knowledge. Toggle under **Stores →
   Configuration → Services → Sales Assistant → Knowledge indexing**. Magento's system pages
   (`no-route`, `enable-cookies`) are always skipped.
3. **File upload.** Upload documents (PDF, Word, Markdown, HTML, text) for anything that isn't a
   product or a CMS page. You preview the extracted text before it's added.
4. **Website crawl.** Discover and ingest the pages of a website **you own**.

## What to put where

| Content | Best source |
|---|---|
| Products, prices, stock | Catalogue (automatic) |
| Policies, FAQ, shipping/returns, About, guides (prose) | CMS pages/blocks, or upload |
| A downloadable guide/manual (PDF/Word) | Upload |
| Prose article/documentation pages on a site you own | Crawl |
| **Structured facts — pricing tables, plan/tier comparisons, spec sheets, size charts** | **A curated upload** (see below) |

## What crawling is fine for — and what it isn't

Crawling is great for **text/prose pages you own**: articles, guides, documentation, policy
pages. It fetches each page, extracts the readable content, and indexes it.

Crawling is **not** reliable for **structured or laid-out data** — pricing tables, plan/tier
comparison cards, spec sheets, size charts. Laid-out content gets split across separate chunks
that don't retrieve together, so the assistant can surface *one* row but not reliably list *all*
of them or compare them. **For structured facts, don't crawl the page — write a short curated
document instead** (next section).

Two more crawl rules:
- **Only crawl sites you own or have permission to crawl.** You confirm this before every crawl.
- **Don't double-index.** If you crawl your *own* store's site, turn **off** CMS auto-indexing
  — otherwise the same pages get indexed twice (once as CMS, once as a crawled URL), which adds
  noise and lets the wrong copy win in search.

## Curated documents for structured facts (the important one)

For anything the assistant must state **completely and exactly** — your plans/tiers, a pricing
table, a size chart, a comparison — write a **short, self-contained document** and upload it:

- **One topic per document, kept concise.** A structured reference under ~1,500 characters stays
  in a *single* retrievable chunk, so the assistant always gets the whole thing at once. (Longer
  content is split into pieces that may not co-retrieve — the exact problem crawling has.)
- **List the key facts plainly**, one item per line, with the exact figures.
- **Add a one-line "which one for whom"** if you want the assistant to *recommend*, not just list.

Example — a plans reference (one uploaded document titled "Our plans"):

```
# Our plans
We offer three plans. All include [shared features]. 30-day money-back guarantee.
- Starter — €X/month. [key specs]. Best for small/new stores.
- Growth  — €Y/month. [key specs]. Best for growing stores with moderate traffic.
- Pro     — €Z/month. [key specs]. Best for high-traffic stores / large catalogues.
Choosing by size: Starter for light traffic; Growth for moderate; Pro for heavy. You can
change plans at any time.
```

With that one document, the assistant can list every plan with correct prices *and* recommend
one for a described workload — reliably, because it all arrives in one chunk. Exact figures are
reproduced verbatim; it won't invent or mangle them.

## Keeping knowledge clean

- **The document list is the source of truth** — everything indexed appears there. Remove items
  individually (tick the checkboxes → **Delete selected**) or wipe everything with **Clear
  knowledge base** (this clears only the assistant's own index, nothing else in your store).
- **Recent crawls** is just history — prune it with its own **Delete selected**; it doesn't
  affect indexed content.
- After adding or removing content, a **reindex** (or the automatic schedule, which needs
  Magento cron running) refreshes what the assistant knows.

## Rule of thumb

> **Prose → crawl or CMS. Structured facts → one short curated document. Products → the
> catalogue.** If the assistant must answer "list them all" or "which one for me," it's a
> structured fact — curate it, don't scrape it.
