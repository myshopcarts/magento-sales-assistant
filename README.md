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

## Documentation

Full guides — installing, feeding knowledge, customising, and human handoff — are at
**https://docs.myshopcarts.store/magento/extensions/sales-assistant/installing**.

## Security

This extension ships no secrets and no backend endpoints — everything comes from
configuration, and the secret key never reaches the browser. The full posture, and the
automated release gate that enforces it, are in [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © MyShopCarts
