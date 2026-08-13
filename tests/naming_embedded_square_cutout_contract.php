<?php
/**
 * Contract: embedded square naming models must store cutout as length + width.
 * Round embedded models keep the legacy single opening diameter.
 */

$root = dirname(__DIR__);
$files = array(
    'naming.php' => file_get_contents($root.'/naming.php'),
    'quote_api.php' => file_get_contents($root.'/quote_api.php'),
    'quotation.php' => file_get_contents($root.'/quotation.php'),
    'crm_quote_pdf.php' => file_get_contents($root.'/crm_quote_pdf.php'),
    'crm_quote_excel.php' => file_get_contents($root.'/crm_quote_excel.php'),
    'datasheet_lib.php' => file_get_contents($root.'/datasheet_lib.php'),
    'bom_naming_link_api.php' => file_get_contents($root.'/bom_naming_link_api.php'),
);

function assert_contains_contract(string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\nMissing: {$needle}\n");
        exit(1);
    }
}

$naming = $files['naming.php'];

assert_contains_contract($naming, "'dim_opening_length'=>\"`dim_opening_length` VARCHAR(60) NOT NULL DEFAULT ''\"", 'naming_models must add dim_opening_length');
assert_contains_contract($naming, "'dim_opening_width'=>\"`dim_opening_width` VARCHAR(60) NOT NULL DEFAULT ''\"", 'naming_models must add dim_opening_width');
assert_contains_contract($naming, 'function nm_compose_opening_pair', 'server must compose square cutout pair');
assert_contains_contract($naming, '嵌入方形开孔必须填写“开孔长”和“开孔宽”', 'saving embedded square must require both cutout dimensions');
assert_contains_contract($naming, '<label>开孔长</label><input name="dim_opening_length" id="m_opening_length"', 'modal must show cutout length field');
assert_contains_contract($naming, '<label>开孔宽</label><input name="dim_opening_width" id="m_opening_width"', 'modal must show cutout width field');
assert_contains_contract($naming, "toggleField('openingField', embedded && !isEmbeddedSquare", 'single opening field must be hidden for embedded square');
assert_contains_contract($naming, "toggleField('openingLengthField', embedded && isEmbeddedSquare", 'opening length must show for embedded square');
assert_contains_contract($naming, "toggleField('openingWidthField', embedded && isEmbeddedSquare", 'opening width must show for embedded square');
assert_contains_contract($naming, '开孔 \'.nm_mm_pair($openingLength, $openingWidth)', 'dimension display must render cutout pair');
assert_contains_contract($naming, "'dim_opening_length'=>\$dimOpeningLength", 'save_model must persist opening length');
assert_contains_contract($naming, "'dim_opening_width'=>\$dimOpeningWidth", 'save_model must persist opening width');

assert_contains_contract($files['quote_api.php'], "first_existing_val(\$r,['dim_opening_length'", 'quote API must read opening length');
assert_contains_contract($files['quote_api.php'], "return \$l.'x'.\$w", 'quote API must compose pair cutout');
assert_contains_contract($files['quotation.php'], "p.dim_opening_length", 'quote preview must prefer pair cutout');
assert_contains_contract($files['quotation.php'], "isPair?v.replace", 'quote preview must not prefix Φ for pair cutout');
assert_contains_contract($files['crm_quote_pdf.php'], '$isPair = preg_match', 'PDF cutout formatter must detect pair cutout');
assert_contains_contract($files['crm_quote_excel.php'], '$isPair=preg_match', 'Excel cutout formatter must detect pair cutout');
assert_contains_contract($files['datasheet_lib.php'], 'dim_opening_length', 'datasheet must select and display opening pair');
assert_contains_contract($files['bom_naming_link_api.php'], "'dim_opening_length'", 'BOM naming link must expose opening pair');

echo "OK naming embedded square cutout contract\n";
