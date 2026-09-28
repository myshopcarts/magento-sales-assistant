<?php
/**
 * Widget block — feeds the storefront template the minimal, non-secret config it
 * needs: whether the assistant is enabled and the same-origin token-exchange URL.
 * The WS URL + token come from the token endpoint at runtime, so nothing secret
 * or environment-specific is rendered into the page.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Myshopcarts\SalesAssistant\Model\Ingest\AttributeCatalog;

class Widget extends Template
{
    public function __construct(
        Context $context,
        private readonly SalesAssistantClient $client,
        private readonly AttributeCatalog $attributeCatalog,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /** Same-origin endpoint the widget calls to mint a short-lived token. */
    public function getTokenUrl(): string
    {
        return $this->getUrl('salesassistant/widget/token');
    }

    /** Same-origin endpoint the widget calls to run local catalogue search. */
    public function getSearchUrl(): string
    {
        return $this->getUrl('salesassistant/widget/search');
    }

    /** Same-origin endpoint the widget calls to run local knowledge search (K4). */
    public function getKnowledgeUrl(): string
    {
        return $this->getUrl('salesassistant/widget/knowledge');
    }

    /** The assistant's name (merchant-configurable); '' if unset — the backend then uses its
     *  default persona. Cosmetic: how the assistant refers to itself if asked. */
    public function getAssistantName(): string
    {
        return trim((string)$this->_scopeConfig->getValue(
            'myshopcarts_salesassistant/api/assistant_name',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
    }

    /** Short business context (what the store offers) injected into the answer prompts so a
     *  service isn't framed as a product catalogue. '' if unset. */
    public function getBusinessContext(): string
    {
        return trim((string)$this->_scopeConfig->getValue(
            'myshopcarts_salesassistant/api/business_context',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
    }

    /** Where to send a shopper who's ready to proceed (e.g. "our plans page:
     *  https://…/plans" or "Contact sales: …"). '' if unset. Injected into the answer prompt. */
    public function getCallToAction(): string
    {
        return trim((string)$this->_scopeConfig->getValue(
            'myshopcarts_salesassistant/api/call_to_action',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
    }

    /** The welcome message shown when a shopper first opens the assistant (merchant-
     *  configurable in Stores → Config → Sales Assistant; a friendly default otherwise). */
    public function getGreeting(): string
    {
        $greeting = trim((string)$this->_scopeConfig->getValue(
            'myshopcarts_salesassistant/api/greeting',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
        // Plain text only — no emoji: a store whose config/DB charset is utf8 (not utf8mb4)
        // mangles 4-byte characters like emoji into "????".
        return $greeting !== ''
            ? $greeting
            : "Hi! I'm your shopping assistant. Ask me about our products or how things "
              . "work here — how can I help?";
    }

    /** Merchant-chosen header-bar colour, as a validated hex string ('' if unset/invalid → the
     *  widget keeps its default dark header). Validated to a #RGB / #RRGGBB literal here so only
     *  a safe colour token is ever written into the widget's inline style (no CSS injection). */
    public function getHeaderColor(): string
    {
        $color = trim((string)$this->_scopeConfig->getValue(
            'myshopcarts_salesassistant/api/header_color',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1 ? $color : '';
    }

    /**
     * The store's filterable keyword-attribute vocabulary (code+label+capped values), so the backend
     * decompose can turn "cotton shirts" / "USB-C cables" into a hard attribute filter for THIS
     * catalogue — not just colour/size. Empty for a store with no extra filterable attributes (then
     * the assistant behaves exactly as before). See doc/sales-assistant-generalized-attributes.md §1b.
     */
    public function getAttributeDescriptor(): array
    {
        try {
            return $this->attributeCatalog->descriptor();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
