<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$php = file_get_contents($root . '/crm_customer.php');
if ($js === false || $php === false) {
    throw new RuntimeException('CRM lead pool contact persistence sources are not readable');
}

$requiredJs = [
    'contactHasMeaningfulData: function',
    'contactFallbackName: function',
    '联系人至少填写姓名、邮箱、电话、WhatsApp、微信或 LinkedIn 任一项。',
    "contact.name = this.contactFallbackName(contact);",
    "contact.name = CustomerModule.contactFallbackName(contact);",
    "if (!CustomerModule.contactHasMeaningfulData(item)) return;",
    "if (!contact || !CustomerModule.contactHasMeaningfulData(contact)) return;",
    "this.entryContacts = (Array.isArray(payload.contacts) ? payload.contacts : []).map(this.normalizeContactForEditor.bind(this));",
    "payload.contacts_json = JSON.stringify(contacts);",
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Lead pool contact persistence JS marker missing: {$marker}");
    }
}

$requiredPhp = [
    'function crm_contact_has_meaningful_data',
    'function crm_contact_fallback_name',
    'function crm_contact_normalize_minimum',
    '$contact = crm_contact_normalize_minimum($contact);',
    'if (!crm_contact_has_meaningful_data($contact)) continue;',
    '$input = crm_contact_normalize_minimum($input);',
    '联系人至少填写姓名、邮箱、电话、WhatsApp、微信或 LinkedIn 任一项。',
    'if ((!isset($input[\'contacts\']) || !is_array($input[\'contacts\'])) && !empty($input[\'contacts_json\']))',
    '$contacts = crm_customer_initial_contacts($input);',
    '$input[\'raw_email\'] = trim((string)$primaryContact[\'email\']);',
    '$input[\'entry_mode\'] = \'force\';',
];
foreach ($requiredPhp as $marker) {
    if (!str_contains($php, $marker)) {
        throw new RuntimeException("Lead pool contact persistence PHP marker missing: {$marker}");
    }
}

echo "CRM lead pool contact persistence contract passed\n";
