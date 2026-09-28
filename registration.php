<?php
/**
 * Myshopcarts_SalesAssistant — the storefront AI sales assistant widget.
 *
 * A shopper-facing chat widget that streams answers from OUR backend over a
 * WebSocket, grounded in THIS store's own catalogue (local OpenSearch, added in
 * E5–E7). The store holds ONE secret: the merchant API key (encrypted config).
 * The browser never sees it — a server-side controller exchanges it for a
 * short-lived widget token (doc/sales-assistant-auth.md). Sibling to
 * Myshopcarts_Portal; reuses its client/config/ACL patterns.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Myshopcarts_SalesAssistant', __DIR__);
