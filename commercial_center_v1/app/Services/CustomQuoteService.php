<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\CustomQuoteRepository;
use Artdon\CommercialCenter\Repositories\QuoteRepository;
use Artdon\CommercialCenter\Repositories\QuoteWorkflowRepository;
use Artdon\CommercialCenter\Repositories\StandardQuoteRepository;

final class CustomQuoteService
{
    private CustomQuoteRepository $custom;
    private QuoteRepository $quotes;
    private QuoteWorkflowService $workflow;
    private QuotePermissionService $permissions;
    private StandardQuoteRepository $catalog;
    private QuoteWorkflowRepository $audit;

    public function __construct(?CustomQuoteRepository $custom = null)
    {
        $this->custom = $custom ?? new CustomQuoteRepository();
        $connection = $this->custom->connection();
        $this->quotes = new QuoteRepository($connection);
        $this->permissions = new QuotePermissionService($connection);
        $this->audit = new QuoteWorkflowRepository($connection);
        $this->workflow = new QuoteWorkflowService($this->audit, $this->quotes, new QuoteService($this->quotes), $this->permissions);
        $this->catalog = new StandardQuoteRepository($connection);
    }

    public function bootstrap(int $userId): array
    {
        return [
            'customers' => $this->catalog->customers('', 100),
            'reference_products' => (new ConfigurationEngineService())->catalog($userId)['products'] ?? [],
            'file_types' => ['product_image','reference_image','dimension_drawing','structure_drawing','sketch','material_image','document'],
            'allowed_extensions' => ['jpg','jpeg','png','webp','pdf','xls','xlsx','doc','docx','zip'],
        ];
    }

    public function save(array $payload, array $actor): array
    {
        $existingId = max(0, (int)($payload['id'] ?? 0));
        $priorItemFiles = $existingId > 0 ? $this->custom->itemFiles($existingId) : [];
        $customerId = (int)($payload['customer_id'] ?? 0);
        $customer = $this->catalog->customer($customerId);
        if ($customer === null) {
            throw new \InvalidArgumentException('请选择有效 CRM 客户。');
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            throw new \InvalidArgumentException('定制报价至少需要一项产品。');
        }
        foreach ($items as &$item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('定制报价明细格式无效。');
            }
            $custom = is_array($item['custom_fields'] ?? null) ? $item['custom_fields'] : [];
            foreach (['material','color','dimensions','power','installation','special_process'] as $field) {
                $custom[$field] = trim((string)($custom[$field] ?? ''));
            }
            $custom['target_cost'] = max(0, (float)($custom['target_cost'] ?? 0));
            $custom['estimated_cost'] = max(0, (float)($custom['estimated_cost'] ?? 0));
            $custom['pricing_opinion'] = trim((string)($custom['pricing_opinion'] ?? ''));
            $custom['approval_opinion'] = trim((string)($custom['approval_opinion'] ?? ''));
            $item['custom_fields'] = $custom;
            $item['unit_cost'] = $custom['estimated_cost'];
            $item['product_source'] = !empty($item['reference_product_id']) ? 'standard_reference' : 'manual';
            $item['item_type'] = 'custom_product';
        }
        unset($item);
        $input = [
            'id' => max(0, (int)($payload['id'] ?? 0)),
            'quote_type' => 'custom_product',
            'legacy_customer_id' => $customerId,
            'customer_snapshot' => $customer,
            'currency' => $payload['currency'] ?? 'USD',
            'source_type' => 'manual_custom',
            'source_snapshot' => [
                'project_name' => trim((string)($payload['project_name'] ?? '')),
                'project_type' => trim((string)($payload['project_type'] ?? '')),
                'requirement_summary' => trim((string)($payload['requirement_summary'] ?? '')),
                'crm_opportunity' => trim((string)($payload['crm_opportunity'] ?? '')),
                'crm_project' => trim((string)($payload['crm_project'] ?? '')),
            ],
            'contact_name' => $payload['contact_name'] ?? $customer['contact_name'],
            'contact_phone' => $customer['contact_phone'] ?: $customer['phone'],
            'contact_email' => $customer['contact_email'] ?: $customer['email'],
            'country' => $payload['country'] ?? $customer['country'],
            'currency' => $payload['currency'] ?? 'USD',
            'valid_until' => $payload['valid_until'] ?? null,
            'owner_legacy_user_id' => (int)($actor['id'] ?? 0),
            'owner_name' => $actor['display_name'] ?? $actor['username'] ?? '',
            'payment_terms' => $payload['payment_terms'] ?? '',
            'trade_terms' => $payload['trade_terms'] ?? '',
            'project_ref' => $payload['crm_project'] ?? '',
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'shipping_amount' => $payload['shipping_amount'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'customer_note' => $payload['customer_note'] ?? '',
            'internal_note' => $payload['internal_note'] ?? '',
            'items' => $items,
            'is_test' => !empty($payload['is_test']),
        ];
        $quote = $input['id'] > 0
            ? $this->workflow->editDraft($input, $actor)
            : $this->workflow->createDraft($input, $actor);
        $this->custom->copyFilesToCurrentItems((int)$quote['id'], $priorItemFiles);
        return $this->withFiles($quote);
    }

    public function open(int $quoteId, array $actor): array
    {
        $quote = $this->workflow->open($quoteId, $actor) ?? throw new \RuntimeException('报价不存在。');
        if ((string)$quote['quote_type'] !== 'custom_product') {
            throw new \RuntimeException('当前报价不是定制品报价。');
        }
        return $this->withFiles($quote);
    }

    public function submit(int $quoteId, array $actor): array
    {
        return $this->withFiles($this->workflow->transition($quoteId, 'pending_approval', $actor, '定制品报价提交审核'));
    }

    public function approve(int $quoteId, array $actor, string $reason): array
    {
        return $this->withFiles($this->workflow->transition($quoteId, 'approved', $actor, $reason));
    }

    public function handoff(int $quoteId, string $type, array $actor): array
    {
        $this->permissions->assert($actor, 'convert');
        if (!in_array($type, ['project','order'], true)) {
            throw new \InvalidArgumentException('转化类型无效。');
        }
        $quote = $this->open($quoteId, $actor);
        if (!in_array((string)$quote['status'], ['approved','sent','customer_confirmed'], true)) {
            throw new \RuntimeException('报价审核通过后才能转项目或订单。');
        }
        $json = json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $statement = $this->custom->connection()->prepare(
            'INSERT INTO cc_quote_handoffs
             (quote_id,handoff_type,snapshot_json,snapshot_hash,status,created_by_legacy_user_id,created_by_name,created_at)
             VALUES (?,?,?,?,\'created\',?,?,NOW())
             ON DUPLICATE KEY UPDATE snapshot_json=VALUES(snapshot_json),snapshot_hash=VALUES(snapshot_hash)'
        );
        $statement->execute([$quoteId,$type,$json,hash('sha256',$json),(int)($actor['id'] ?? 0) ?: null,$actor['display_name'] ?? $actor['username'] ?? null]);
        $this->audit->audit($quote, 'custom_handoff_' . $type, '定制品报价转' . ($type === 'project' ? '项目' : '订单'), $actor, [], ['handoff_type'=>$type], []);
        return ['id'=>(int)$this->custom->connection()->lastInsertId(),'type'=>$type,'status'=>'created'];
    }

    public function upload(int $quoteId, ?int $itemId, string $type, array $upload, array $actor): array
    {
        $this->permissions->assert($actor, 'edit');
        $quote = $this->open($quoteId, $actor);
        if ((string)$quote['status'] !== 'draft') {
            throw new \RuntimeException('只有草稿可以上传附件。');
        }
        if ($itemId !== null && !in_array($itemId, $this->custom->currentItemIds($quoteId), true)) {
            throw new \RuntimeException('报价明细不存在。');
        }
        $saved = $this->storeUpload($quoteId, $upload);
        $id = $this->custom->saveFile($quoteId, $itemId, $type, $saved, (int)($actor['id'] ?? 0));
        return ['id'=>$id,'item_file'=>$itemId !== null] + $saved;
    }

    public function deleteFile(int $quoteId, int $fileId, bool $itemFile, array $actor): void
    {
        $this->permissions->assert($actor, 'edit');
        $path = $this->custom->deleteFile($quoteId, $fileId, $itemFile);
        if ($path === null) {
            throw new \RuntimeException('附件不存在或已删除。');
        }
    }

    public function reorderFiles(int $quoteId, array $ids, bool $itemFile, array $actor): void
    {
        $this->permissions->assert($actor, 'edit');
        $this->custom->reorder($quoteId, $ids, $itemFile);
    }

    private function withFiles(array $quote): array
    {
        $quote['files'] = $this->custom->files((int)$quote['id']);
        $quote['item_files'] = $this->custom->itemFiles((int)$quote['id']);
        return $quote;
    }

    private function storeUpload(int $quoteId, array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException('上传文件无效。');
        }
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('单个文件必须小于 20MB。');
        }
        $name = basename((string)($upload['name'] ?? 'file'));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','pdf','xls','xlsx','doc','docx','zip'];
        if (!in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException('不支持该文件格式。');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']) ?: 'application/octet-stream';
        $directory = dirname(__DIR__, 2) . '/uploads/custom_quotes/' . $quoteId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('附件目录创建失败。');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file((string)$upload['tmp_name'], $target)) {
            throw new \RuntimeException('附件保存失败。');
        }
        return [
            'name'=>$name,'path'=>'uploads/custom_quotes/' . $quoteId . '/' . $filename,
            'mime'=>$mime,'size'=>$size,'hash'=>hash_file('sha256',$target),
        ];
    }
}
