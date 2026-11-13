<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

use Canpas\Fleet\Domain\Settings;

/**
 * Canpas's payment accounts (bank, GCash, Maya) from Settings, for quotations, bills, emails and
 * the payment forms. Accounts left empty are not shown. Until online payment is set up, customers
 * pay to these accounts and upload proof; Canpas checks it against the bank or e-wallet.
 */
final class PayInfo
{
    /** @return list<array{0:string,1:string}> label => details, one per account, then the note */
    public static function lines(): array
    {
        $s = Settings::all();
        $out = [];
        $bank = array_filter([(string) $s['pay_bank_name'], (string) $s['pay_bank_branch']]);
        if ((string) $s['pay_bank_account_no'] !== '') {
            $out[] = ['Bank transfer', trim(implode(', ', $bank) . ' · ' . (string) $s['pay_bank_account_name'] . ' · Account no. ' . (string) $s['pay_bank_account_no'], ' ·')];
        }
        if ((string) $s['pay_gcash_no'] !== '') {
            $out[] = ['GCash', trim((string) $s['pay_gcash_name'] . ' · ' . (string) $s['pay_gcash_no'], ' ·')];
        }
        if ((string) $s['pay_maya_no'] !== '') {
            $out[] = ['Maya', trim((string) $s['pay_maya_name'] . ' · ' . (string) $s['pay_maya_no'], ' ·')];
        }
        $extra = trim((string) $s['payment_instructions']);
        if ($extra !== '') {
            $out[] = ['Other', $extra];
        }
        return $out;
    }

    public static function note(?string $ref = null): string
    {
        $note = trim((string) Settings::get('pay_note'));
        return $ref !== null ? str_replace('{ref}', $ref, $note) : str_replace('{ref}', 'your order number', $note);
    }

    /** Plain text block for emails and PDFs. */
    public static function text(?string $ref = null): string
    {
        $rows = self::lines();
        if ($rows === []) {
            return 'Please contact Canpas for payment details.';
        }
        $t = '';
        foreach ($rows as [$k, $v]) {
            $t .= $k . ': ' . $v . "\n";
        }
        return trim($t . self::note($ref));
    }

    /** HTML block for the portal. Escapes everything. */
    public static function html(?string $ref = null): string
    {
        $rows = self::lines();
        if ($rows === []) {
            return '<p class="muted small">Ask Canpas for their bank, GCash or Maya details.</p>';
        }
        $h = '<div class="payinfo"><p class="payinfo-title">How to pay</p><dl class="dl">';
        foreach ($rows as [$k, $v]) {
            $h .= '<dt>' . esc_html($k) . '</dt><dd>' . nl2br(esc_html($v)) . '</dd>';
        }
        $note = self::note($ref);
        return $h . '</dl>' . ($note !== '' ? '<p class="small">' . esc_html($note) . '</p>' : '') . '</div>';
    }
}
