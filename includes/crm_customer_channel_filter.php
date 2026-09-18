<?php
// Read-only classification. A selected channel is not permission to send.
function crm_customer_filter_channel(string $quick): string
{
    return [
        'has_wechat_personal'=>'wechat', '微信个人'=>'wechat',
        'has_whatsapp_personal'=>'whatsapp', 'WhatsApp个人'=>'whatsapp',
        'has_wechat_group'=>'wechat_group', '微信群'=>'wechat_group',
        'has_whatsapp_group'=>'whatsapp_group', 'WhatsApp群'=>'whatsapp_group',
    ][$quick] ?? '';
}

function crm_customer_channel_condition(string $channel, array &$params): string
{
    if (!in_array($channel, ['wechat','whatsapp','wechat_group','whatsapp_group'], true)) {
        throw new InvalidArgumentException('Unsupported customer channel');
    }
    $params[] = $channel;
    $params[] = $channel;
    // EXISTS preserves one customer per result, regardless of contact/group count.
    return "(EXISTS (SELECT 1 FROM crm_customer_promotion_channels cfc
        WHERE cfc.customer_id=c.id AND cfc.channel_key=?)
        OR EXISTS (SELECT 1 FROM crm_contacts cft
        JOIN crm_contact_promotions cfp ON cfp.contact_id=cft.id
        WHERE cft.customer_id=c.id AND cft.deleted_at IS NULL
        AND cfp.channel=? AND cfp.status='active'))";
}

function crm_customer_channel_summary(string $channel, array $customer, array $contacts, array $groups): array
{
    $labels = ['wechat'=>'微信个人','whatsapp'=>'WhatsApp个人','wechat_group'=>'微信群','whatsapp_group'=>'WhatsApp群'];
    $sources = [];
    if (!empty($customer['channel_selected'])) $sources[] = '客户推广方式';
    $selectedContacts = array_values(array_filter($contacts, static fn($ct) => !empty($ct['channel_selected'])));
    if ($selectedContacts) {
        $names = array_map(static fn($ct) => trim((string)($ct['name'] ?? '')) ?: '未命名联系人', array_slice($selectedContacts, 0, 3));
        $sources[] = '联系人：' . implode('、', $names) . (count($selectedContacts) > 3 ? '等' . count($selectedContacts) . '人' : '');
    }
    $notes = [];
    if (!empty($customer['do_not_contact'])) $notes[] = '客户禁止联系';
    $restricted = array_filter($selectedContacts, static fn($ct) => !empty($ct['is_left']) || !empty($ct['do_not_contact']) || ($channel === 'whatsapp' && !empty($ct['no_whatsapp'])));
    if ($restricted) $notes[] = count($restricted) . '位已设渠道联系人离职或禁联';
    if (str_ends_with($channel, '_group')) {
        if (!$groups) $notes[] = '缺客户群资料';
        else {
            $active = array_filter($groups, static fn($g) => $g['status'] === 'active');
            $paused = count(array_filter($groups, static fn($g) => $g['status'] === 'paused'));
            $invalid = count(array_filter($groups, static fn($g) => $g['status'] === 'invalid'));
            $disabled = count(array_filter($groups, static fn($g) => empty($g['use_for_promotion'])));
            $notes[] = count($groups) . '个群';
            if ($paused) $notes[] = $paused . '个暂停';
            if ($invalid) $notes[] = $invalid . '个失效';
            if ($disabled) $notes[] = $disabled . '个不用于推广';
            if (!array_filter($active, static fn($g) => !empty($g['use_for_promotion']))) $notes[] = '无启用且允许推广的群';
        }
    } else {
        // Main customer channel may use its own or a contact's method. Contact-only
        // settings must not borrow another unrelated contact's number.
        $relevant = !empty($customer['channel_selected']) ? $contacts : $selectedContacts;
        $hasMethod = !empty($customer['channel_selected']) && trim((string)($customer[$channel] ?? '')) !== '';
        foreach ($relevant as $ct) {
            if (trim((string)($ct[$channel] ?? '')) !== '') $hasMethod = true;
        }
        if (!$hasMethod) $notes[] = $channel === 'wechat' ? '缺个人微信号' : '缺个人WhatsApp号码';
        $missing = count(array_filter($selectedContacts, static fn($ct) => trim((string)($ct[$channel] ?? '')) === ''));
        if ($missing && $hasMethod) $notes[] = $missing . '位已设渠道联系人缺号码';
        if (!$notes) $notes[] = '已填联系方式';
    }
    return ['channel'=>$channel, 'label'=>$labels[$channel], 'sources'=>$sources, 'notes'=>$notes];
}

function crm_customer_channel_annotate(PDO $pdo, array $rows, string $channel): array
{
    if (!$rows || $channel === '') return $rows;
    if (!in_array($channel, ['wechat','whatsapp','wechat_group','whatsapp_group'], true)) throw new InvalidArgumentException('Unsupported customer channel');
    // Only enrich the already permission-filtered current page; never query all clients.
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT c.id,c.wechat,c.whatsapp,c.do_not_contact,
        EXISTS(SELECT 1 FROM crm_customer_promotion_channels p WHERE p.customer_id=c.id AND p.channel_key=?) channel_selected
        FROM crm_customers c WHERE c.id IN ($marks)");
    $stmt->execute(array_merge([$channel], $ids));
    $customers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $customers[(int)$c['id']] = $c;
    $stmt = $pdo->prepare("SELECT ct.customer_id,ct.name,ct.wechat,ct.whatsapp,ct.is_left,ct.do_not_contact,ct.no_whatsapp,
        EXISTS(SELECT 1 FROM crm_contact_promotions p WHERE p.contact_id=ct.id AND p.channel=? AND p.status='active') channel_selected
        FROM crm_contacts ct WHERE ct.customer_id IN ($marks) AND ct.deleted_at IS NULL ORDER BY ct.id");
    $stmt->execute(array_merge([$channel], $ids));
    $contacts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ct) $contacts[(int)$ct['customer_id']][] = $ct;
    $groups = [];
    if (str_ends_with($channel, '_group')) {
        $stmt = $pdo->prepare("SELECT customer_id,status,use_for_promotion FROM crm_customer_chat_groups WHERE group_platform=? AND customer_id IN ($marks) AND deleted_at IS NULL");
        $stmt->execute(array_merge([$channel], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) $groups[(int)$g['customer_id']][] = $g;
    }
    foreach ($rows as &$row) {
        $id = (int)$row['id'];
        $row['channel_match'] = crm_customer_channel_summary($channel, $customers[$id] ?? [], $contacts[$id] ?? [], $groups[$id] ?? []);
    }
    unset($row);
    return $rows;
}
