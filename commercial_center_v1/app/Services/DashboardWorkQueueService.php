<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

final class DashboardWorkQueueService
{
    private const BUSINESS_TERMS = [
        '报价', 'quote', '工程', '评估', '发布', 'publish', 'pdf', '邮件', '客户确认',
        '订单', 'order', '包装', 'pack', '单证', 'invoice', '佣金', '收款', '出货', 'shipment',
    ];

    public function normalize(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $haystack = mb_strtolower(implode(' ', [
                $row['title'] ?? '', $row['project'] ?? '', $row['linked_system'] ?? '', $row['linked_title'] ?? '',
            ]));
            if (!$this->isCommercial($haystack)) {
                continue;
            }
            $due = $this->dueLabel((string)($row['due_at'] ?? ''));
            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'number' => (string)($row['task_no'] ?? ''),
                'title' => (string)($row['title'] ?? '商务任务'),
                'summary' => (string)(($row['project'] ?? '') ?: ($row['linked_title'] ?? '')),
                'source' => $this->sourceLabel((string)($row['linked_system'] ?? '')),
                'stage' => $this->stageLabel($haystack),
                'owner' => (string)(($row['assignee_name'] ?? '') ?: '未指定'),
                'due_label' => $due['label'],
                'overdue' => $due['overdue'],
                'status' => $this->statusLabel((string)($row['status'] ?? '')),
                'priority' => $this->priorityLabel((string)($row['priority'] ?? '')),
                'action' => '查看任务',
                'target' => '../dispatch_next.php',
            ];
            if (count($result) >= 8) {
                break;
            }
        }
        return $result;
    }

    private function isCommercial(string $value): bool
    {
        foreach (self::BUSINESS_TERMS as $term) {
            if (str_contains($value, $term)) {
                return true;
            }
        }
        return false;
    }

    private function stageLabel(string $value): string
    {
        $map = ['工程' => '工程评估', '评估' => '工程评估', '发布' => '渠道发布', '包装' => '包装资料',
            '单证' => '单证处理', '出货' => '出货跟进', '订单' => '订单跟进', 'order' => '订单跟进',
            '邮件' => '报价发送', '佣金' => '佣金确认', '收款' => '收款跟进'];
        foreach ($map as $term => $label) {
            if (str_contains($value, $term)) {
                return $label;
            }
        }
        return '报价处理';
    }

    private function sourceLabel(string $source): string
    {
        $source = mb_strtolower($source);
        return str_contains($source, 'crm') ? 'CRM' : (str_contains($source, 'order') ? '广州订单' : '广州报价');
    }

    private function statusLabel(string $status): string
    {
        return ['pending' => '待处理', 'todo' => '待处理', 'doing' => '处理中', 'in_progress' => '处理中',
            'blocked' => '受阻', 'done' => '已完成', 'cancelled' => '已取消'][$status] ?? '待处理';
    }

    private function priorityLabel(string $priority): string
    {
        return ['urgent' => '紧急', 'important' => '重要', 'high' => '高', 'normal' => '普通', 'low' => '低'][$priority] ?? '普通';
    }

    private function dueLabel(string $dueAt): array
    {
        if ($dueAt === '') {
            return ['label' => '未设置截止', 'overdue' => false];
        }
        $today = new \DateTimeImmutable('today');
        $due = (new \DateTimeImmutable($dueAt))->setTime(0, 0);
        $days = (int)$today->diff($due)->format('%r%a');
        if ($days < 0) {
            return ['label' => '已逾期 ' . abs($days) . ' 天', 'overdue' => true];
        }
        if ($days === 0) {
            return ['label' => '今天截止', 'overdue' => false];
        }
        return ['label' => '剩余 ' . $days . ' 天', 'overdue' => false];
    }
}
