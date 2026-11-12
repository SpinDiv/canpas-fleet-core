<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

use Canpas\Fleet\Plugin;

/**
 * Simple branded HTML email through wp_mail (delivered by FluentSMTP / SES).
 * Never puts ID numbers, documents or passwords in an email.
 */
final class Mail
{
    /** @param list<string> $paragraphs plain text; escaped here */
    public static function send(string $to, string $subject, array $paragraphs, ?array $button = null): bool
    {
        if ($to === '' || !is_email($to)) {
            return false;
        }
        $logo = esc_url(Plugin::url('assets/logo-color.png'));
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:#1B2333">' . nl2br(esc_html($p)) . '</p>';
        }
        if ($button) {
            $body .= '<p style="margin:20px 0"><a href="' . esc_url($button['href']) . '" style="display:inline-block;background:#C2410C;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 18px;border-radius:6px">'
                . esc_html($button['label']) . '</a></p>';
        }
        $html = '<!doctype html><html><body style="margin:0;background:#F3F5F9;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px">'
            . '<tr><td style="padding:24px 28px 8px"><img src="' . $logo . '" alt="Canpas" height="36" style="height:36px;width:auto"></td></tr>'
            . '<tr><td style="padding:8px 28px 20px">' . $body . '</td></tr>'
            . '<tr><td style="padding:14px 28px;border-top:1px solid #E3E7EE;font-size:12px;color:#5B6475">Canpas Fleet &amp; Equipment Portal · This is an automatic message.</td></tr>'
            . '</table></td></tr></table></body></html>';
        $text = implode("\n\n", $paragraphs) . ($button ? "\n\n{$button['label']}: {$button['href']}" : '');
        $alt = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };
        add_action('phpmailer_init', $alt);
        $ok = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $alt);
        return $ok;
    }
}
