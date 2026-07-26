<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\QuoteRepository;
use Artdon\CommercialCenter\Repositories\QuoteWorkflowRepository;
use Throwable;

final class QuoteWorkflowService
{
    public const STATUSES = [
        'draft', 'pricing', 'pending_approval', 'rejected', 'approved',
        'sent', 'customer_confirmed', 'converted', 'voided',
    ];

    private const TRANSITIONS = [
        'draft' => ['pricing', 'pending_approval', 'voided'],
        'pricing' => ['draft', 'pending_approval', 'voided'],
        'pending_approval' => ['approved', 'rejected', 'voided'],
        'rejected' => ['draft', 'pending_approval', 'voided'],
        'approved' => ['sent', 'converted', 'voided'],
        'sent' => ['customer_confirmed', 'voided'],
        'customer_confirmed' => ['converted', 'voided'],
        'converted' => [],
        'voided' => [],
    ];

    private const PERMISSIONS = [
        'draft' => 'edit',
        'pricing' => 'edit_price',
        'pending_approval' => 'edit',
        'rejected' => 'reject',
        'approved' => 'approve',
        'sent' => 'send',
        'customer_confirmed' => 'send',
        'converted' => 'convert',
        'voided' => 'delete',
    ];

    private const SNAPSHOT_TYPES = [
        'pending_approval' => 'submitted',
        'approved' => 'approved',
        'sent' => 'sent',
        'converted' => 'pre_conversion',
    ];

    private QuoteWorkflowRepository $workflow;
    private QuoteRepository $quotes;
    private QuoteService $quoteService;
    private QuotePermissionService $permissions;

    public function __construct(
        ?QuoteWorkflowRepository $workflow = null,
        ?QuoteRepository $quotes = null,
        ?QuoteService $quoteService = null,
        ?QuotePermissionService $permissions = null
    ) {
        $this->workflow = $workflow ?? new QuoteWorkflowRepository();
        $this->quotes = $quotes ?? new QuoteRepository($this->workflow->connection());
        $this->quoteService = $quoteService ?? new QuoteService($this->quotes);
        $this->permissions = $permissions ?? new QuotePermissionService($this->workflow->connection());
    }

    public function createDraft(array $input, array $actor): array
    {
        $this->permissions->assert($actor, 'create');
        return $this->saveAuthorized($input, $actor, 'create_draft');
    }

    public function editDraft(array $input, array $actor): array
    {
        $this->permissions->assert($actor, 'edit');
        $this->permissions->assert($actor, 'edit_price');
        return $this->saveAuthorized($input, $actor, 'edit_draft');
    }

    public function open(int $quoteId, array $actor): ?array
    {
        $this->permissions->assert($actor, 'view');
        $quote = $this->quotes->find($quoteId);
        if ($quote === null) {
            return null;
        }
        if (!$this->permissions->allows($actor, 'view_cost')) {
            foreach ($quote['items'] as &$item) {
                unset($item['cost_amount']);
            }
            unset($item, $quote['total_cost']);
            if (is_array($quote['version'] ?? null)) {
                unset($quote['version']['cost_snapshot']);
            }
        }
        if (!$this->permissions->allows($actor, 'view_profit')) {
            unset($quote['gross_profit'], $quote['gross_margin']);
        }
        return $quote;
    }

    public function assertAction(array $actor, string $action): void
    {
        $this->permissions->assert($actor, $action);
    }

    public function transition(
        int $quoteId,
        string $target,
        array $actor,
        string $reason = '',
        array $request = []
    ): array {
        if (!in_array($target, self::STATUSES, true)) {
            throw new \InvalidArgumentException('未知报价状态。');
        }
        $this->permissions->assert($actor, self::PERMISSIONS[$target]);
        if (in_array($target, ['rejected', 'voided'], true) && trim($reason) === '') {
            throw new \InvalidArgumentException('驳回或作废必须填写原因。');
        }

        $connection = $this->workflow->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $locked = $this->workflow->lock($quoteId);
            $from = (string)$locked['status'];
            if (!in_array($target, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("报价状态不能从 {$from} 变更为 {$target}。");
            }
            $before = $this->quotes->find($quoteId) ?? throw new \RuntimeException('报价不存在。');
            $beforeHash = null;
            if (isset(self::SNAPSHOT_TYPES[$target])) {
                $this->workflow->cloneCurrentVersion($quoteId, $this->actorId($actor));
                $snapshot = $this->workflow->createSnapshot(
                    $quoteId,
                    self::SNAPSHOT_TYPES[$target],
                    $this->actorId($actor)
                );
                $beforeHash = $snapshot['hash'];
            }
            $current = $this->quotes->find($quoteId) ?? throw new \RuntimeException('报价版本不存在。');
            $versionId = (int)$current['version']['id'];
            $this->workflow->updateStatus($quoteId, $versionId, $target);
            $after = $this->quotes->find($quoteId) ?? throw new \RuntimeException('状态更新后报价不存在。');
            $afterSnapshot = $this->workflow->createSnapshot(
                $quoteId,
                'state_' . $target,
                $this->actorId($actor)
            );
            $this->workflow->stateHistory($quoteId, $versionId, $from, $target, $reason, $actor);
            if (in_array($target, ['pending_approval', 'approved', 'rejected'], true)) {
                $this->workflow->approval(
                    $quoteId,
                    $versionId,
                    $target,
                    $target,
                    $reason,
                    $actor,
                    $beforeHash,
                    $afterSnapshot['hash']
                );
            }
            $this->workflow->audit(
                $after,
                'transition_' . $target,
                $reason,
                $actor,
                $before,
                $after,
                $request
            );
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $after;
        } catch (Throwable $error) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public function reviseApproved(
        int $quoteId,
        array $input,
        array $actor,
        string $reason,
        array $request = []
    ): array {
        $this->permissions->assert($actor, 'edit');
        $this->permissions->assert($actor, 'edit_price');
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('修改已审核报价必须填写原因。');
        }
        $connection = $this->workflow->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $locked = $this->workflow->lock($quoteId);
            if ((string)$locked['status'] !== 'approved') {
                throw new \RuntimeException('只有已审核报价可以走修订流程。');
            }
            $before = $this->quotes->find($quoteId) ?? throw new \RuntimeException('报价不存在。');
            $snapshot = $this->workflow->createSnapshot(
                $quoteId,
                'pre_revision',
                $this->actorId($actor)
            );
            $versionId = (int)$before['version']['id'];
            $this->workflow->updateQuoteStatus($quoteId, 'draft');
            $input['id'] = $quoteId;
            $saved = $this->quoteService->saveDraft($input, $this->actorId($actor));
            $newVersionId = (int)$saved['version']['id'];
            $this->workflow->stateHistory($quoteId, $newVersionId, 'approved', 'draft', $reason, $actor);
            $this->workflow->audit(
                $saved,
                'revise_approved',
                $reason,
                $actor,
                ['approved_snapshot_hash' => $snapshot['hash'], 'quote' => $before],
                $saved,
                $request
            );
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $saved;
        } catch (Throwable $error) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public function history(int $quoteId, array $actor): array
    {
        $this->permissions->assert($actor, 'view');
        return $this->workflow->history($quoteId);
    }

    private function saveAuthorized(array $input, array $actor, string $action): array
    {
        $connection = $this->workflow->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $before = null;
            $quoteId = (int)($input['id'] ?? 0);
            if ($quoteId > 0) {
                $before = $this->quotes->find($quoteId);
            }
            $saved = $this->quoteService->saveDraft($input, $this->actorId($actor));
            $this->workflow->audit(
                $saved,
                $action,
                (string)($input['change_reason'] ?? ''),
                $actor,
                $before,
                $saved,
                is_array($input['request_context'] ?? null) ? $input['request_context'] : []
            );
            if ($ownsTransaction) {
                $connection->commit();
            }
            return $saved;
        } catch (Throwable $error) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private function actorId(array $actor): int
    {
        return max(0, (int)($actor['id'] ?? $actor['user_id'] ?? 0));
    }
}
