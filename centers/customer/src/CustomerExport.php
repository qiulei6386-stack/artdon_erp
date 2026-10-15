<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use PDO;

/**
 * 导出客户（customer.export）：按列表当前的标签和筛选，导出自己看得到的客户为 CSV（UTF-8，Excel 可直接打开）。
 * 导出的文件可以直接再导入（表头与导入模板一致）。每次导出写客户日志和敏感审计。
 */
final class CustomerExport
{
    public const MAX_ROWS = 20000;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        if (!Permission::allows($operator, 'customer.export')) {
            throw new PermissionException('没有导出客户的权限（customer.export）');
        }
    }

    /** @return list<string> */
    public static function headers(): array
    {
        return ['编号', '客户名称', '英文名称', '国家', '城市', '地址', '网站', '邮箱', '电话', 'WhatsApp', '微信', '客户代码', '等级', '阶段', '来源', '推广方式', '分组',
            '负责人', '协助负责人', '备注', '联系人1姓名', '联系人1职位', '联系人1邮箱', '联系人1电话', '联系人1WhatsApp', '联系人2姓名', '联系人2职位', '联系人2邮箱', '联系人2电话', '联系人2WhatsApp',
            '联系人3姓名', '联系人3职位', '联系人3邮箱', '联系人3电话', '联系人3WhatsApp', '联系人数', '最近跟进', '新建时间', '更新时间'];
    }

    /**
     * 生成导出的所有行（表头在第一行）。
     *
     * @return list<list<string>>
     */
    public function rows(string $tab, array $filters): array
    {
        $service = new CustomerService($this->operator, $this->pdo);
        $out = [self::headers()];
        $page = 1;
        $count = 0;
        do {
            $result = $service->list($tab, $filters, $page);
            if ($result['total'] > self::MAX_ROWS) {
                throw new \RuntimeException('一次最多导出 ' . self::MAX_ROWS . ' 个客户，当前筛选有 ' . $result['total'] . ' 个，请加筛选条件');
            }
            $ids = array_map('intval', array_column($result['rows'], 'id'));
            $extra = $this->extra($ids);
            foreach ($result['rows'] as $r) {
                $id = (int) $r['id'];
                $e = $extra[$id] ?? ['sources' => [], 'channels' => [], 'groups' => [], 'contacts' => [], 'remark' => '', 'website' => '', 'address' => '', 'phone' => '', 'whatsapp' => '', 'wechat' => ''];
                $line = [
                    (string) $id, (string) $r['customer_name'], (string) ($r['customer_name_en'] ?? ''), Country::label((string) $r['country']), (string) ($r['city'] ?? ''), $e['address'],
                    $e['website'], (string) ($r['email'] ?? ''), $e['phone'], $e['whatsapp'], $e['wechat'], (string) ($r['customer_code'] ?? ''),
                    $r['level'] ? Dictionary::label('customer_level', (string) $r['level']) : '', $r['lifecycle_key'] ? Dictionary::label('customer_lifecycle', (string) $r['lifecycle_key']) : '',
                    implode('、', array_map(static function (string $k): string {
                        return Dictionary::label('customer_source', $k);
                    }, $e['sources'])),
                    implode('、', array_map(static function (string $k): string {
                        return Dictionary::label('promotion_channel', $k);
                    }, $e['channels'])),
                    implode('、', $e['groups']), (string) $r['primary_owner'], implode('、', array_column($r['assistants'], 'name')), $e['remark'],
                ];
                for ($n = 0; $n < 3; $n++) {
                    $c = $e['contacts'][$n] ?? [];
                    foreach (['name', 'position', 'email', 'phone', 'whatsapp'] as $f) {
                        $line[] = (string) ($c[$f] ?? '');
                    }
                }
                $line[] = (string) (int) $r['contact_count'];
                $line[] = substr((string) ($r['last_followup'] ?? ''), 0, 16);
                $line[] = substr((string) $r['created_at'], 0, 16);
                $line[] = substr((string) $r['updated_at'], 0, 16);
                $out[] = $line;
                $count++;
            }
            $page++;
        } while ($page <= $result['pages']);
        CustomerLog::write('customer_export', 0, '导出客户', '共 ' . $count . ' 个（' . (CustomerService::TABS[$tab] ?? $tab) . '）', null, ['count' => $count, 'tab' => $tab, 'filters' => array_filter($filters)], $this->operator, 'export', date('YmdHis'));
        return $out;
    }

    /** 输出 CSV 文件（带 UTF-8 BOM，Excel 打开不乱码）。 */
    public static function send(array $rows, string $filename): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'safeCell'], $row), ',', '"', '\\');
        }
        fclose($out);
    }

    /** 以 = + - @ 开头的内容前加单引号，防止在 Excel 里被当成公式执行。 */
    public static function safeCell($value): string
    {
        $value = (string) $value;
        return $value !== '' && strpos('=+-@', $value[0]) !== false && !preg_match('/^[+-]?\d[\d\s().-]*$/', $value) ? "'" . $value : $value;
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function extra(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $stmt = $this->pdo->prepare('SELECT id, remark, website, address, phone, whatsapp, wechat FROM crm_customers WHERE id IN (' . $marks . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['sources' => [], 'channels' => [], 'groups' => [], 'contacts' => [], 'remark' => (string) ($r['remark'] ?? ''), 'website' => (string) ($r['website'] ?? ''),
                'address' => (string) ($r['address'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''), 'whatsapp' => (string) ($r['whatsapp'] ?? ''), 'wechat' => (string) ($r['wechat'] ?? '')];
        }
        foreach ([['crm_customer_source_tags', 'source_key', 'sources'], ['crm_customer_promotion_channels', 'channel_key', 'channels']] as [$table, $col, $key]) {
            $stmt = $this->pdo->prepare("SELECT customer_id, {$col} FROM {$table} WHERE customer_id IN ({$marks}) ORDER BY id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$cid, $v]) {
                $out[(int) $cid][$key][] = (string) $v;
            }
        }
        $stmt = $this->pdo->prepare("SELECT r.customer_id, g.group_name FROM crm_customer_group_relations r JOIN crm_customer_groups g ON g.id = r.group_id AND g.deleted_at IS NULL WHERE r.customer_id IN ({$marks}) ORDER BY g.sort_order, g.id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$cid, $v]) {
            $out[(int) $cid]['groups'][] = (string) $v;
        }
        $stmt = $this->pdo->prepare("SELECT customer_id, name, position, email, phone, whatsapp FROM crm_contacts WHERE customer_id IN ({$marks}) AND deleted_at IS NULL ORDER BY is_primary DESC, is_left ASC, id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[(int) $c['customer_id']]['contacts'][] = $c;
        }
        return $out;
    }
}
