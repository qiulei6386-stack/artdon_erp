<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Artdon\MaterialCenter\Services\SupplierService;

$db = db();
$service = new SupplierService($db);
$stamp = date('His') . random_int(100, 999);
$supplierId = 0;
$documentId = 0;
$tmp = tempnam(sys_get_temp_dir(), 'mc-supplier-doc-');

try {
    $supplierId = $service->saveSupplier([
        'supplier_code' => 'SDT-' . $stamp,
        'name' => 'Supplier Document Test ' . $stamp,
        'default_currency' => 'CNY',
    ], 1);
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    $documentId = $service->uploadDocument([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
        'name' => 'supplier-test.pdf',
        'tmp_name' => $tmp,
    ], [
        'supplier_id' => $supplierId,
        'document_type' => 'certificate',
    ], 1);
    $document = $service->document($documentId);
    if ($document['sha256'] !== hash_file('sha256', $document['file_path'])
        || $document['mime_type'] !== 'application/pdf'
        || $document['access_level'] !== 'purchasing') {
        throw new RuntimeException('supplier document metadata mismatch');
    }
    echo "Supplier document integration: OK\n";
} finally {
    if ($documentId) {
        $path = $db->query('SELECT file_path FROM mc_supplier_documents WHERE id=' . $documentId)->fetchColumn();
        $db->exec('DELETE FROM mc_supplier_documents WHERE id=' . $documentId);
        $db->exec("DELETE FROM mc_operation_logs WHERE object_type='supplier_document' AND object_id=" . $documentId);
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
    if ($supplierId) {
        $db->exec('DELETE FROM mc_supplier_profiles WHERE supplier_id=' . $supplierId);
        $db->exec('DELETE FROM mc_suppliers WHERE id=' . $supplierId);
        $db->exec("DELETE FROM mc_operation_logs WHERE object_type='supplier' AND object_id=" . $supplierId);
    }
    if (is_file($tmp)) {
        @unlink($tmp);
    }
}
