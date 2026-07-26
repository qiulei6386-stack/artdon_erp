<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class CustomQuoteRepository
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function files(int $quoteId): array
    {
        $statement = $this->connection->prepare(
            'SELECT f.*,o.sort_order FROM cc_quote_files f
             LEFT JOIN cc_quote_file_orders o ON o.quote_file_id=f.id
             WHERE f.quote_id=? AND f.status=\'active\' ORDER BY COALESCE(o.sort_order,999999),f.id'
        );
        $statement->execute([$quoteId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function itemFiles(int $quoteId): array
    {
        $statement = $this->connection->prepare(
            'SELECT f.*,o.sort_order,i.id AS quote_item_id,i.sort_order AS item_sort_order FROM cc_quote_item_files f
             INNER JOIN cc_quote_items i ON i.id=f.quote_item_id
             INNER JOIN cc_quote_versions v ON v.id=i.quote_version_id
             INNER JOIN cc_quotes q ON q.id=v.quote_id AND q.current_version=v.version_no
             LEFT JOIN cc_quote_file_orders o ON o.quote_item_file_id=f.id
             WHERE q.id=? AND f.status=\'active\' ORDER BY i.sort_order,COALESCE(o.sort_order,999999),f.id'
        );
        $statement->execute([$quoteId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function copyFilesToCurrentItems(int $quoteId, array $priorFiles): void
    {
        if ($priorFiles === []) {
            return;
        }
        $statement = $this->connection->prepare(
            'SELECT i.id,i.sort_order FROM cc_quote_items i
             INNER JOIN cc_quote_versions v ON v.id=i.quote_version_id
             INNER JOIN cc_quotes q ON q.id=v.quote_id AND q.current_version=v.version_no
             WHERE q.id=?'
        );
        $statement->execute([$quoteId]);
        $current = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $current[(int)$item['sort_order']] = (int)$item['id'];
        }
        foreach ($priorFiles as $file) {
            $newItemId = $current[(int)$file['item_sort_order']] ?? 0;
            if ($newItemId <= 0) {
                continue;
            }
            $this->saveFile($quoteId, $newItemId, (string)$file['file_type'], [
                'name'=>(string)$file['original_name'],'path'=>(string)$file['storage_path'],
                'mime'=>(string)$file['mime_type'],'size'=>(int)$file['file_size'],'hash'=>(string)$file['file_hash'],
            ], (int)($file['uploaded_by_legacy_user_id'] ?? 0));
        }
    }

    public function saveFile(int $quoteId, ?int $itemId, string $type, array $file, int $actorId): int
    {
        $now = date('Y-m-d H:i:s');
        if ($itemId !== null) {
            $statement = $this->connection->prepare(
                'INSERT INTO cc_quote_item_files
                 (quote_item_id,file_type,original_name,storage_path,mime_type,file_size,file_hash,status,
                  uploaded_by_legacy_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,\'active\',?,?,?)'
            );
            $statement->execute([$itemId,$type,$file['name'],$file['path'],$file['mime'],$file['size'],$file['hash'],$actorId ?: null,$now,$now]);
            $id = (int)$this->connection->lastInsertId();
            $this->connection->prepare(
                'INSERT INTO cc_quote_file_orders (quote_item_file_id,sort_order,created_at,updated_at) VALUES (?,0,?,?)'
            )->execute([$id,$now,$now]);
            return $id;
        }
        $version = $this->connection->prepare('SELECT current_version FROM cc_quotes WHERE id=?');
        $version->execute([$quoteId]);
        $versionNo = (int)$version->fetchColumn();
        if ($versionNo <= 0) {
            throw new \RuntimeException('报价不存在。');
        }
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_files
             (quote_id,version_no,file_type,original_name,storage_path,mime_type,file_size,file_hash,status,
              uploaded_by_legacy_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,\'active\',?,?,?)'
        );
        $statement->execute([$quoteId,$versionNo,$type,$file['name'],$file['path'],$file['mime'],$file['size'],$file['hash'],$actorId ?: null,$now,$now]);
        $id = (int)$this->connection->lastInsertId();
        $this->connection->prepare(
            'INSERT INTO cc_quote_file_orders (quote_file_id,sort_order,created_at,updated_at) VALUES (?,0,?,?)'
        )->execute([$id,$now,$now]);
        return $id;
    }

    public function file(int $quoteId, int $fileId, bool $itemFile): ?array
    {
        $sql = $itemFile
            ? 'SELECT f.* FROM cc_quote_item_files f INNER JOIN cc_quote_items i ON i.id=f.quote_item_id
               INNER JOIN cc_quote_versions v ON v.id=i.quote_version_id WHERE f.id=? AND v.quote_id=? LIMIT 1'
            : 'SELECT * FROM cc_quote_files WHERE id=? AND quote_id=? LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute([$fileId,$quoteId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function deleteFile(int $quoteId, int $fileId, bool $itemFile): ?string
    {
        $file = $this->file($quoteId, $fileId, $itemFile);
        if ($file === null || (string)$file['status'] !== 'active') {
            return null;
        }
        $sql = $itemFile
            ? "UPDATE cc_quote_item_files SET status='deleted',updated_at=NOW() WHERE id=?"
            : "UPDATE cc_quote_files SET status='deleted',updated_at=NOW() WHERE id=?";
        $this->connection->prepare($sql)->execute([$fileId]);
        return (string)$file['storage_path'];
    }

    public function reorder(int $quoteId, array $ids, bool $itemFile): void
    {
        foreach (array_values($ids) as $order => $id) {
            $file = $this->file($quoteId, (int)$id, $itemFile);
            if ($file === null) {
                throw new \RuntimeException('附件不属于当前报价。');
            }
            $sql = $itemFile
                ? 'INSERT INTO cc_quote_file_orders (quote_item_file_id,sort_order,created_at,updated_at)
                   VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),updated_at=NOW()'
                : 'INSERT INTO cc_quote_file_orders (quote_file_id,sort_order,created_at,updated_at)
                   VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),updated_at=NOW()';
            $this->connection->prepare($sql)->execute([(int)$id,$order]);
        }
    }

    public function currentItemIds(int $quoteId): array
    {
        $statement = $this->connection->prepare(
            'SELECT i.id FROM cc_quote_items i INNER JOIN cc_quote_versions v ON v.id=i.quote_version_id
             INNER JOIN cc_quotes q ON q.id=v.quote_id AND q.current_version=v.version_no
             WHERE q.id=? ORDER BY i.sort_order,i.id'
        );
        $statement->execute([$quoteId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
