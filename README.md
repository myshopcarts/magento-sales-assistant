# MyShopCarts Sales Assistant for Magento 2

An AI shopping assistant for Magento 2 and Adobe Commerce: a storefront chat widget that
helps your shoppers find and buy from **your own catalogue**. It answers only from your
live products and your own content — CMS pages, uploaded documents, and (optionally) your
website — is built to **never invent products, prices or facts**, and can hand a shopper
off to a human when asked.

## What this extension is (and isn't)

This is a **thin client**. It runs entirely inside your Magento store and holds **no AI
logic of its own**: it indexes your catalogue and content into your store's own search
engine, and talks to a **backend service** that does the language work. Every backend
endpoint is a **configuration value**, so the extension is not hardwired to any single
provider — see [Using your own backend](#using-your-own-backend).

It is designed to be used with a **MyShopCarts Sales Assistant subscription**, which
provides the connection details and runs the hosted backend. There are **no credentials
and no backend endpoints baked into this code**, and your secret key is stored encrypted
and used server-side only — the browser only ever receives a short-lived, single-session
token. See [SECURITY.md](SECURITY.md).

## How it works

- **Retrieval is local.** The extension reuses your store's existing search engine
  (OpenSearch / Elasticsearch — every Magento 2.4 store has one) and adds its own product
  and knowledge indices alongside Magento's, under your existing index prefix. Your
  catalogue is never copied off your store; searching happens on your own index. If your
  cluster supports vector search (k-NN) you get hybrid keyword + semantic matching; if not,
  it falls back automatically to keyword-only.
- **Answers are grounded.** A shopper's question is matched against your index; only the
  handful of relevant snippets it finds, plus the question, are sent to the backend to
  compose a reply. The assistant answers only from your data.
- **The browser connects securely.** The storefront widget performs a same-origin token
  exchange for a short-lived token, then streams answers over a WebSocket. Your secret key
  never reaches the browser.
- **Measurable.** The widget emits `chat_open` and `chat_message_sent` events to your own
  analytics — Google Analytics 4 (including Magento's built-in GA4 integration), Google Tag
  Manager, or any listener — so you can track engagement. See [Analytics events](#analytics-events).
- **Hands off to a person when asked.** When a shopper explicitly asks for a human, the
  conversation is handed to your team, and the assistant asks the shopper for an email
  address so you can follow up even if the chat is interrupted. A plain "help me" is
  answered by the assistant itself.
- **One message at a time.** If a shopper sends another message while the assistant is
  still replying, it appears in the chat straight away and is sent as soon as the reply
  finishes, so answers always arrive in order.

## Requirements

- Magento Open Source or Adobe Commerce **2.4.x**
- PHP **8.1, 8.2 or 8.3**
- Your store's OpenSearch / Elasticsearch (the one Magento catalogue search already uses);
  vector (k-NN) search recommended for semantic matching
- A backend for the assistant to talk to — a **MyShopCarts Sales Assistant subscription**,
  or your own compatible backend (see [below](#using-your-own-backend))

## Install

Download the release archive and unzip it into `app/code/Myshopcarts/SalesAssistant` (so
that `app/code/Myshopcarts/SalesAssistant/etc/module.xml` exists), then:

```bash
bin/magento module:enable Myshopcarts_SalesAssistant
bin/magento setup:upgrade
bin/magento setup:di:compile               # production mode only
bin/magento setup:static-content:deploy    # production mode only
bin/magento cache:flush
```

Installing also registers two Magento indexers — **Sales Assistant Catalogue** and **Sales
Assistant Knowledge** (System → Index Management) — and sets them to *Update by Schedule*,
so Magento cron keeps them current automatically. (Magento cron must be running for
scheduled indexing.)

## Configure

Go to **Stores → Configuration → Services → Sales Assistant** and set:

- **Enable widget** — Yes
- **API base URL** — from your subscription (or your own backend)
- **WebSocket URL** — from your subscription (`wss://…/ws`)
- **Merchant secret key** — your `sk_sa_…` key (stored encrypted; server-side only)

Optionally set the assistant's **name**, **welcome message**, **header colour**, a short
**description of what your store sells**, and a **call to action**. The safety guardrails
(answer only from your catalogue/knowledge; never invent) always stay on.

## Index your catalogue and content

The assistant answers from an index it builds on your store. With Magento cron running, the
indexers registered at install build on their next scheduled run; to build them immediately
(or on a self-hosted store):

```bash
bin/magento indexer:reindex myshopcarts_salesassistant_catalogue myshopcarts_salesassistant_knowledge
```

A large catalogue indexes progressively in the background, so the widget can appear before
indexing finishes; prices and stock are read live at answer time, so the assistant never
quotes a stale price.

Choosing **what** the assistant knows — catalogue, CMS pages/blocks, uploaded documents, and
an optional same-origin website crawl — is managed from the admin **Knowledge** page. See
[docs/knowledge-sources.md](docs/knowledge-sources.md) for which content belongs in each
source, and the one rule that matters most: crawl prose, but curate **structured facts**
(pricing tables, plan comparisons) as short single-topic uploads.

## Using your own backend

The extension is backend-agnostic: the **API base URL**, **WebSocket URL** and **secret
key** are all configuration, so you can point it at any backend that implements the same
**client contract**. That contract is defined by the extension's own client code — treat
these files as the source of truth rather than looking for a separate spec:

- **`Model/SalesAssistantClient.php`** — the bearer-authenticated calls the extension makes
  to your *API base URL*: the token exchange, plus the embedding and knowledge-upload calls
  used while indexing.
- **`Controller/Widget/Token.php`** — the same-origin exchange that turns your secret into
  the short-lived token and WebSocket URL handed to the browser.
- **`view/frontend/web/js/widget.js`** — the browser side: how the widget opens the
  WebSocket and exchanges chat messages.

Implement a backend that satisfies those calls and set the three config values to point at
it; nothing else in the extension changes. How a backend actually produces answers is up to
that backend and is outside this extension.

## What leaves your store

- **Stays on your store:** your catalogue, your content, your search index, and your
  encrypted key.
- **Processed to build the index:** your product and page text is sent to your configured
  backend to be turned into the searchable index, which is then stored on your store.
- **Sent when answering a shopper:** only the shopper's question and the specific snippets
  retrieved from your index — enough to write a grounded answer, and no more.

## Analytics events

The widget emits two storefront interaction events for your own analytics: `chat_open`
(a shopper opens the assistant) and `chat_message_sent` (a shopper sends a message). They
fire in the shopper's browser only — nothing is sent anywhere else, and it is a silent
no-op if no analytics is installed or the shopper declines cookies.

What you get depends on how your store's analytics is wired:

| Your analytics setup | Works? | What you do |
|---|---|---|
| **GA4 via gtag** — Magento's built-in Google Analytics (GA4) integration (Stores → Configuration → Sales → Google API), or a standalone gtag.js tag | **Yes, automatically** | Nothing — the events show up in GA4 Realtime |
| **GA4 via Google Tag Manager** | Yes | In GTM, add a *Custom Event* trigger for each event name + a *GA4 Event* tag |
| **Any other analytics** (Matomo, Segment, a custom integration) | Yes | Listen for the DOM events `salesassistant:chat_open` / `salesassistant:chat_message_sent` and forward them |
| **No analytics, or cookies declined** | No-op | — |

**How it's delivered:** each event is pushed to the `dataLayer` as a gtag command (what
reaches GA4 — even with Magento's native integration, which never exposes a global `gtag`),
also as a GTM `{ event: '…' }` custom event, and dispatched as a DOM `CustomEvent`.

**Two things to know:**
- The gtag event is sent to **every Google tag configured on your store** (e.g. GA4 *and*
  Google Ads, if both are set up). GA4 records it; a Google Ads tag ignores a non-conversion
  event. The extension can't scope it to GA4 only, because it doesn't know your Measurement ID.
- If you run **both** a GA4 gtag and a GA4 tag in GTM for the same event, pick one to avoid
  double-counting.

## Release notes

- **1.2.10** — Website crawls keep one entry per page. `/page`, `/page/`,
  `/page?utm_source=…` and `/page#top` are recognised as the same page, so a page that
  changed can no longer leave its old text behind under another spelling of its address.
  Entries from earlier crawls are tidied up the next time the site is crawled.
- **1.2.9** — Recovers by itself from a full rebuild that was stopped part-way (the process
  killed, a server restart or move). Magento pauses an indexer's change tracking during a full
  rebuild and resumes it at the end — a stopped rebuild left it paused, so product and page
  changes silently stopped reaching the assistant, and a half-built index stayed on disk. The
  background job now notices within 5 minutes, resumes change tracking, removes the half-built
  index and keeps the previous index serving; `bin/magento salesassistant:status` shows a
  running, stuck or interrupted rebuild (`--heal` cleans up immediately).
- **1.2.8** — Under *Meaning search for products* the admin now shows your store's own
  estimate: number of products × tokens per product (measured from a sample of your
  catalogue) ≈ total, against your plan's embedding allowance and what's left this month,
  with a verdict (enough now / after renewal / more than your allowance). Also printed by
  `bin/magento salesassistant:status` (`--estimate` re-measures; otherwise cached a day).
- **1.2.7** — New setting **Catalogue search → Meaning search for products**, **off by
  default**. Products are found by keyword search (the assistant first rewrites the
  shopper's question into search terms and filters) and use **no embedding allowance**;
  your knowledge documents and pages always use meaning search. Switch it on to also
  match products by meaning — no reindex needed, products are embedded in the
  background. Upgrading stores move to keyword search for products until you switch it
  on; existing product embeddings are kept for when you do.
- **1.2.6** — Installing or upgrading the extension (or running `bin/magento setup:upgrade`)
  never rebuilds the whole assistant catalogue: Magento's sample-data modules reindex every
  indexer on each `setup:upgrade`, which on a large catalogue would hold the upgrade for
  hours. The live index stays in place, product changes keep flowing in, and a full rebuild
  runs only when you ask for it:
  `bin/magento indexer:reindex myshopcarts_salesassistant_catalogue`.
- **1.2.5** — Indexing no longer changes process priority at all; it is kept light purely by
  pausing between batches (the "Indexing performance" share, default 25%). A lower priority
  would also have slowed whatever else runs in the same process — Magento's own indexers
  in a full reindex or the indexing cron job.
- **1.2.4** — The lower CPU priority applies only to background (command-line and cron)
  indexing, never to a web request: saving a product in the admin with the indexer on
  "Update on Save" no longer leaves that web worker at the lowest priority.
- **1.2.3** — Indexing never slows your store: full rebuilds and the background embedding
  job run at the lowest CPU priority and pause between batches, working at most 25% of the
  time by default (Stores → Configuration → Services → Sales Assistant → Indexing
  performance; 100 turns the pauses off). A large first index takes longer, shoppers
  don't notice it.
- **1.2.2** — Indexing paces itself to a shared rate limit: when the service asks it to wait
  (so shoppers' searches always stay fast), it waits exactly the requested time and carries on —
  a rebuild keeps adding meaning-based search as it goes, and the background job keeps healing
  within the same run, instead of giving up until its next run. `salesassistant:status` shows
  this as "pacing".
- **1.2.1** — Fix: the catalogue index no longer fails on stores that don't have every
  attribute the assistant reads (for example no `brand` or `color` attribute) — such a store
  previously stopped with "Call to a member function getSource() on false".
- **1.2.0** — Indexing never silently loses meaning-based search. When your monthly
  embedding allowance runs out, or the embedding service is briefly unavailable,
  products and content are still indexed (and found by keyword), indexing pauses
  embedding instead of failing, and a new background job (every 5 minutes, in its own
  `salesassistant` cron group, up to ~4 minutes per run) adds the missing vectors as soon as the allowance renews
  or the service recovers — including content indexed this way by earlier versions.
  New command `bin/magento salesassistant:status [--backfill]` shows how many items are
  waiting for a vector, whether embedding is paused, and your remaining allowance.
- **1.1.2** — The chat widget now sends a shopper's messages one at a time: a message typed
  while the assistant is still replying is shown immediately and sent when that reply
  finishes, so replies can no longer arrive out of order or mixed together. A reply that
  never arrives (for example after a dropped connection) no longer holds up the chat.
- **1.1.1** — Documentation: analytics support matrix.
- **1.1.0** — `chat_open` and `chat_message_sent` analytics events.

## Documentation

Full guides — installing, feeding knowledge, customising, and human handoff — are at
**https://docs.myshopcarts.store/magento/extensions/sales-assistant/installing**.

## Security

This extension ships no secrets and no backend endpoints — everything comes from
configuration, and the secret key never reaches the browser. The full posture, and the
automated release gate that enforces it, are in [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © MyShopCarts
