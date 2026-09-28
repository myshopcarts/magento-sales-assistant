<?php
/**
 * Admin: the Knowledge Documents page (K2b brick 4) — upload + preview-before-embed +
 * manage the store's uploaded knowledge sources.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Myshopcarts_SalesAssistant::knowledge');
        $page->getConfig()->getTitle()->prepend(__('Sales Assistant — Knowledge'));
        return $page;
    }
}
