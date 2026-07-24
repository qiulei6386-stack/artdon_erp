<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyEmailAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['crm_mails', 'crm_mail_attachments'];
    public function name(): string { return '旧邮件'; }
}
