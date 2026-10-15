<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use RuntimeException;

/**
 * 读表格文件：CSV（UTF-8 或 GBK，逗号 / 分号 / 制表符）和 Excel .xlsx（第一个工作表）。
 * 服务器没有 ZipArchive 扩展时，用内置的简单解压读取 .xlsx。只读不写。
 */
final class Spreadsheet
{
    public const MAX_ROWS = 5000;

    /** 测试用：不用 ZipArchive，走内置解压。 */
    public static $withoutZipArchive = false;

    /** @return list<list<string>> */
    public static function read(string $path, string $originalName): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('文件没有上传成功');
        }
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) === 'xlsx' || $head === "PK\x03\x04") {
            return self::xlsx($path);
        }
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) === 'xls') {
            throw new RuntimeException('不支持老格式 .xls，请在 Excel 里另存为 .xlsx 或 .csv');
        }
        return self::csv($path);
    }

    /** @return list<list<string>> */
    public static function csv(string $path): array
    {
        $text = (string) file_get_contents($path);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        } elseif (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'GB18030');
            if (!is_string($converted) || !mb_check_encoding($converted, 'UTF-8')) {
                throw new RuntimeException('文件编码无法识别，请另存为 UTF-8 的 CSV');
            }
            $text = $converted;
        }
        $firstLine = strtok($text, "\n") ?: '';
        $delimiter = ',';
        $best = substr_count($firstLine, ',');
        foreach (["\t", ';'] as $d) {
            if (substr_count($firstLine, $d) > $best) {
                $best = substr_count($firstLine, $d);
                $delimiter = $d;
            }
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                $rows[] = [];
                continue;
            }
            $rows[] = array_map(static function ($v): string {
                return trim((string) $v);
            }, $row);
            if (count($rows) > self::MAX_ROWS + 20) {
                break;
            }
        }
        fclose($handle);
        return $rows;
    }

    /** @return list<list<string>> */
    public static function xlsx(string $path): array
    {
        $files = self::unzip($path, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml'], '#^xl/worksheets/sheet\d+\.xml$#');
        $sheetPath = self::firstSheet($files);
        if ($sheetPath === '' || !isset($files[$sheetPath])) {
            throw new RuntimeException('Excel 文件里没有找到工作表');
        }
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $xml = self::xml($files['xl/sharedStrings.xml']);
            foreach ($xml->si as $si) {
                $shared[] = self::richText($si);
            }
        }
        $xml = self::xml($files[$sheetPath]);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int) $row['r'] - 1;
            if ($rowIndex < 0) {
                $rowIndex = count($rows);
            }
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = $ref !== '' ? self::columnIndex($ref) : count($cells);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $value = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = self::richText($c->is);
                } else {
                    $value = (string) $c->v;
                    if ($type === '' && preg_match('/^-?\d+\.0+$/', $value)) {
                        $value = (string) (int) $value;
                    } elseif ($type === '' && preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $value)) {
                        $value = number_format((float) $value, 0, '.', '');
                    }
                }
                $cells[$col] = trim($value);
            }
            $line = [];
            if ($cells) {
                for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
            }
            while (count($rows) < $rowIndex) {
                $rows[] = [];
            }
            $rows[$rowIndex] = $line;
            if (count($rows) > self::MAX_ROWS + 20) {
                break;
            }
        }
        ksort($rows);
        return array_values($rows);
    }

    /** @param array<string, string> $files */
    private static function firstSheet(array $files): string
    {
        if (isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
            $wb = self::xml($files['xl/workbook.xml']);
            $rels = self::xml($files['xl/_rels/workbook.xml.rels']);
            $first = $wb->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        $sheets = array_values(array_filter(array_keys($files), static function (string $n): bool {
            return (bool) preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n);
        }));
        natsort($sheets);
        return (string) (array_values($sheets)[0] ?? '');
    }

    private static function richText(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            $text = (string) $node->t;
        } else {
            $text = '';
        }
        foreach ($node->r as $r) {
            $text .= (string) $r->t;
        }
        return $text;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = (string) preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException('Excel 文件内容损坏，请另存后重试');
        }
        return $xml;
    }

    /**
     * 取出 zip 里需要的文件。
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private static function unzip(string $path, array $names, string $pattern): array
    {
        $out = [];
        if (class_exists('ZipArchive') && !self::$withoutZipArchive) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Excel 文件打不开，请另存为 .xlsx 后重试');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (in_array($name, $names, true) || preg_match($pattern, $name)) {
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        $out[$name] = $content;
                    }
                }
            }
            $zip->close();
            return $out;
        }
        // 没有 ZipArchive：读中央目录，逐个解压（只支持“存储”和“deflate”两种方式）
        $data = (string) file_get_contents($path);
        $end = strrpos($data, "PK\x05\x06");
        if ($end === false) {
            throw new RuntimeException('Excel 文件打不开，请另存为 .xlsx 后重试');
        }
        $info = unpack('vdisk/vstart/ventries/vtotal/Vsize/Voffset', substr($data, $end + 4, 16));
        $pos = (int) $info['offset'];
        for ($i = 0; $i < (int) $info['total']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack('vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($data, $pos + 10, 36));
            $name = substr($data, $pos + 46, (int) $h['nlen']);
            $pos += 46 + (int) $h['nlen'] + (int) $h['elen'] + (int) $h['clen'];
            if (!in_array($name, $names, true) && !preg_match($pattern, $name)) {
                continue;
            }
            $local = (int) $h['offset'];
            $lh = unpack('vnlen/velen', substr($data, $local + 26, 4));
            $raw = substr($data, $local + 30 + (int) $lh['nlen'] + (int) $lh['elen'], (int) $h['csize']);
            $content = (int) $h['method'] === 8 ? @gzinflate($raw) : ((int) $h['method'] === 0 ? $raw : false);
            if (is_string($content)) {
                $out[$name] = $content;
            }
        }
        return $out;
    }
}
