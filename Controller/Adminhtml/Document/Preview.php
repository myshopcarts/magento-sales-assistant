<?php
/**
 * Admin: preview an uploaded knowledge document BEFORE it is embedded (K2b brick 4).
 * Uploads the file to the central store via the presigned rail, extracts it to markdown
 * on the backend, and returns that markdown for the merchant to review/edit. Nothing is
 * embedded or indexed here — that only happens on Save. This is the quality gate.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SalesAssistantClient $client
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            if (!$this->client->isEnabled()) {
                throw new \RuntimeException('The assistant is not configured.');
            }
            $file = $this->getRequest()->getFiles('file');
            if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new \RuntimeException('No file uploaded.');
            }
            $name = (string)($file['name'] ?? 'document');
            $type = (string)($file['type'] ?? 'application/octet-stream');
            $bytes = (string)file_get_contents($file['tmp_name']);
            if ($bytes === '') {
                throw new \RuntimeException('The uploaded file is empty.');
            }
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new \RuntimeException('The file is too large.');
            }

            // presign -> upload the durable raw source -> extract to markdown.
            $presign = $this->client->presignUpload($name, $type, strlen($bytes));
            $this->client->putPresigned((string)$presign['upload_url'], $bytes, $type);
            // Pass the original filename so source_id is stable (re-upload replaces, no dupe).
            $extract = $this->client->extract((string)$presign['s3_key'], $name);

            return $result->setData([
                'success' => true,
                's3_key' => $presign['s3_key'],
                'source_id' => $extract['source_id'] ?? '',
                'content_hash' => $extract['content_hash'] ?? '',
                'markdown' => $extract['markdown'] ?? '',
                'warning' => $extract['warning'] ?? '',
                'chunk_count' => (int)($extract['count'] ?? 0),
                'title' => $this->titleFromFilename($name),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function titleFromFilename(string $name): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $name);
        return trim(str_replace(['_', '-'], ' ', (string)$base));
    }
}
