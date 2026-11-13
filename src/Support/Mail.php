<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Plugin;

/**
 * Branded HTML email through wp_mail (delivered by FluentSMTP / SES), with a record of every send.
 * Navy header with the Canpas logo, optional title and summary box, a button, and the company
 * details in the footer. Replies go to the office email; an optional records copy is BCC'd.
 * Never puts ID numbers, documents or passwords in an email.
 */
final class Mail
{
    private const NAVY = '#141D30';
    private const ORANGE = '#C2410C';
    private const INK = '#1B2333';
    private const MUTED = '#5B6475';

    /**
     * @param list<string> $paragraphs plain text; escaped here
     * @param array{label:string, href:string}|null $button
     * @param array{title?:string, summary?:list<array{0:string,1:string}>, order_id?:?string, company_id?:?string, kind?:string} $opts
     */
    public static function send(string $to, string $subject, array $paragraphs, ?array $button = null, array $opts = []): bool
    {
        $ok = false;
        if ($to !== '' && is_email($to)) {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $office = (string) Settings::get('company_email');
            if ($office !== '' && is_email($office)) {
                $headers[] = 'Reply-To: ' . $office;
            }
            $bcc = (string) Settings::get('records_bcc');
            if ($bcc !== '' && is_email($bcc) && strcasecmp($bcc, $to) !== 0) {
                $headers[] = 'Bcc: ' . $bcc;
            }
            $text = ($opts['title'] ?? '') !== '' ? $opts['title'] . "\n\n" : '';
            foreach ($opts['summary'] ?? [] as [$k, $v]) {
                $text .= "{$k}: {$v}\n";
            }
            $text .= ($opts['summary'] ?? []) ? "\n" : '';
            $text .= implode("\n\n", $paragraphs) . ($button ? "\n\n{$button['label']}: {$button['href']}" : '') . "\n\n" . self::footerText();
            $alt = static function ($phpmailer) use ($text): void {
                $phpmailer->AltBody = $text;
            };
            add_action('phpmailer_init', $alt);
            $ok = (bool) wp_mail($to, $subject, self::html($paragraphs, $button, $opts), $headers);
            remove_action('phpmailer_init', $alt);
        }
        self::log($to, $subject, $ok, $opts);
        return $ok;
    }

    /** @param list<string> $paragraphs */
    private static function html(array $paragraphs, ?array $button, array $opts): string
    {
        $logo = esc_url(Plugin::url('assets/logo-white.png'));
        $body = '';
        if (($opts['title'] ?? '') !== '') {
            $body .= '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:' . self::INK . ';font-weight:700">' . esc_html($opts['title']) . '</h1>';
        }
        if (!empty($opts['summary'])) {
            $rows = '';
            foreach ($opts['summary'] as [$k, $v]) {
                $rows .= '<tr><td style="padding:6px 12px;font-size:13px;color:' . self::MUTED . ';white-space:nowrap;vertical-align:top">' . esc_html($k) . '</td>'
                    . '<td style="padding:6px 12px;font-size:13px;color:' . self::INK . ';font-weight:600">' . nl2br(esc_html((string) $v)) . '</td></tr>';
            }
            $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#F5F7FA;border:1px solid #E3E7EE;border-radius:6px">' . $rows . '</table>';
        }
        foreach ($paragraphs as $p) {
            $body .= '<p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:' . self::INK . '">' . nl2br(esc_html($p)) . '</p>';
        }
        if ($button) {
            $body .= '<p style="margin:22px 0 6px"><a href="' . esc_url($button['href']) . '" style="display:inline-block;background:' . self::ORANGE . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:6px">'
                . esc_html($button['label']) . '</a></p>';
        }
        $s = Settings::all();
        $footer = '<b style="color:' . self::INK . '">' . esc_html((string) $s['company_legal_name']) . '</b><br>'
            . nl2br(esc_html((string) $s['company_address'])) . '<br>'
            . esc_html(trim((string) $s['company_phone'] . ' · ' . (string) $s['company_email'], ' ·')) . '<br>'
            . ($s['company_tin'] !== '' ? 'TIN ' . esc_html((string) $s['company_tin']) . '<br>' : '')
            . '<span style="color:#8A93A3">Replies to this email go to the Canpas office.</span>';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#F3F5F9;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #E3E7EE">'
            . '<tr><td style="background:' . self::NAVY . ';padding:20px 28px"><img src="' . $logo . '" alt="Canpas" height="32" style="height:32px;width:auto;display:block;border:0"></td></tr>'
            . '<tr><td style="height:4px;background:' . self::ORANGE . ';font-size:0;line-height:0">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px 28px 18px">' . $body . '</td></tr>'
            . '<tr><td style="padding:18px 28px 22px;border-top:1px solid #E3E7EE;font-size:12px;line-height:1.6;color:' . self::MUTED . '">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function footerText(): string
    {
        $s = Settings::all();
        return trim((string) $s['company_legal_name'] . "\n" . (string) $s['company_address'] . "\n" . (string) $s['company_phone'] . ' · ' . (string) $s['company_email']
            . ($s['company_tin'] !== '' ? "\nTIN " . (string) $s['company_tin'] : ''));
    }

    /** Record the send. Never throws: a logging problem must not undo the business change. */
    private static function log(string $to, string $subject, bool $ok, array $opts): void
    {
        try {
            $hex = static fn($v): ?string => is_string($v) && preg_match('/^[0-9a-f]{32}$/', $v) ? $v : null;
            Db::insert('email_log', [
                'id' => Uuid::v7(), 'order_id' => $hex($opts['order_id'] ?? null), 'company_id' => $hex($opts['company_id'] ?? null),
                'to_email' => mb_substr($to, 0, 190), 'subject' => mb_substr($subject, 0, 255),
                'kind' => mb_substr((string) ($opts['kind'] ?? 'general'), 0, 32), 'sent_ok' => $ok ? 1 : 0, 'created_at' => Db::now(),
            ], ['id', 'order_id', 'company_id']);
        } catch (\Throwable $e) {
            error_log('Canpas mail log failed: ' . $e->getMessage());
        }
    }

    /** @return list<array{to_email:string, subject:string, kind:string, sent_ok:string, created_at:string}> */
    public static function forOrder(string $orderId): array
    {
        return Db::rows(Db::prepare('SELECT to_email, subject, kind, sent_ok, created_at FROM ' . Db::t('email_log') . ' WHERE order_id = UNHEX(%s) ORDER BY created_at', $orderId));
    }
}
