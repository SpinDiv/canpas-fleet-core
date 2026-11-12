<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Pdf;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * The rental agreement: versioned text with merge tags, e-signed per order.
 *
 * Signing records the typed name, a drawn signature, the time, a hash of the IP address and the
 * version of the text. A PDF of the signed agreement is saved in the private folder and its
 * SHA-256 is stored on the order, so any later change to the file can be detected.
 * Saving new text creates a new version; orders already signed keep the version they signed.
 */
final class Agreements
{
    public const TAGS = [
        '{{company}}' => 'Customer company name',
        '{{company_address}}' => 'Customer company address',
        '{{order_ref}}' => 'Order number',
        '{{items}}' => 'What is rented (one per line)',
        '{{start_date}}' => 'Rental start date',
        '{{end_date}}' => 'Rental end date',
        '{{days}}' => 'Number of days',
        '{{site}}' => 'Site address, or yard pickup',
        '{{total}}' => 'Order total',
        '{{deposit}}' => 'Deposit',
        '{{signer_name}}' => 'Name of the person signing',
        '{{today}}' => 'Date of signing',
    ];

    /** Draft text until Canpas's lawyer provides the final wording. Bracketed parts must be confirmed. */
    public const DEFAULT_TITLE = 'Truck and Equipment Rental Agreement';

    public const DEFAULT_BODY = <<<'TXT'
DRAFT FOR REVIEW BY CANPAS'S LAWYER. Parts in [brackets] must be confirmed before use with customers.

# 1. Parties
This agreement is between [CANPAS PHILIPPINES — full registered name, address and TIN] ("Canpas") and {{company}}, {{company_address}} ("the Customer"), signed for the Customer by {{signer_name}} on {{today}}.

# 2. What is rented
Order {{order_ref}}:
{{items}}

Rental period: {{start_date}} to {{end_date}} ({{days}} days).
Site: {{site}}

# 3. Charges and deposit
The rental charge for this order is {{total}}, worked out from the Canpas rates in force on the day of booking. The Customer pays a deposit of {{deposit}} before anything leaves the Canpas yard. Extra kilometres, extra days, cleaning, repairs and missing items are charged at the rates shown in the Canpas portal. [Payment terms for the balance: due on return / within ___ days of the statement.]

# 4. Use and care
The Customer uses the trucks and equipment only at the site above, only for their normal purpose and only by trained people. The Customer does not lend, sub-rent or move them to another site without Canpas's written consent. Scaffolding and formwork are erected and dismantled by competent workers following the applicable safety rules [DOLE OSH standards — lawyer to confirm reference].

# 5. Drivers and operators
Trucks rented with a Canpas driver are driven only by that driver. For self-drive trucks and heavy equipment, the Customer's driver or operator must hold a valid licence for the vehicle or machine for the whole rental period. A copy of it is kept by Canpas.

# 6. Loss and damage
The Customer is responsible for the trucks and equipment from delivery (or pickup at the yard) until Canpas checks them back in. Missing items are charged at their replacement cost and damaged items at their repair cost or damage fee, as listed in the Canpas portal at booking. Canpas may deduct these amounts from the deposit. [Insurance, accidents, third-party liability and limits of liability — lawyer to draft.]

# 7. Return
Everything is returned clean and complete by the end date, at the Canpas yard or ready for collection at the site. Each day late is charged at the daily rate. Canpas counts the items on return and the Customer may attend the count.

# 8. Ending the rental early
[Cancellation and early-termination terms — lawyer to draft.]

# 9. Personal data
Canpas collects the IDs and licences of the Customer's representatives only to verify identity and the right to drive or operate, keeps them in a private store, and deletes them after the retention period, in line with the Data Privacy Act of 2012 (Republic Act No. 10173). [Privacy notice reference — lawyer to confirm.]

# 10. Governing law
This agreement is governed by the laws of the Republic of the Philippines. [Venue for disputes — lawyer to confirm.]

# 11. Electronic signature
The Customer agrees that typing their name, drawing their signature and ticking the agreement box in the Canpas portal is their signature on this agreement, in line with the Electronic Commerce Act of 2000 (Republic Act No. 8792).
TXT;

    /** @return array<string,mixed> the version customers sign now (created from the draft text if none exists) */
    public static function active(): array
    {
        $v = Db::row('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('agreement_versions') . ' WHERE active = 1 ORDER BY version DESC LIMIT 1');
        if ($v !== null) {
            return $v;
        }
        $n = (int) Db::value('SELECT COALESCE(MAX(version), 0) FROM ' . Db::t('agreement_versions'));
        if ($n > 0) {
            Db::exec('UPDATE ' . Db::t('agreement_versions') . ' SET active = 1 WHERE version = ' . $n);
        } else {
            Db::insert('agreement_versions', ['id' => Uuid::v7(), 'version' => 1, 'title' => self::DEFAULT_TITLE, 'body' => self::DEFAULT_BODY, 'active' => 1, 'created_by' => 0, 'created_at' => Db::now()], ['id']);
        }
        return self::active();
    }

    /** @return list<array<string,mixed>> */
    public static function versions(): array
    {
        self::active();
        return Db::rows('SELECT ' . Db::hex('id') . ', version, title, active, created_by, created_at,
            (SELECT COUNT(*) FROM ' . Db::t('orders') . ' o WHERE o.agreement_version_id = v.id) AS signed
            FROM ' . Db::t('agreement_versions') . ' v ORDER BY version DESC');
    }

    /** Save new text as the next version. Orders already signed keep their version. */
    public static function saveVersion(Actor $actor, string $title, string $body): int
    {
        $actor->require('cf_manage_settings');
        $title = sanitize_text_field(wp_unslash($title));
        $body = trim(str_replace("\r\n", "\n", sanitize_textarea_field(wp_unslash($body))));
        if (mb_strlen($title) < 3) {
            throw new DomainError('invalid_input', 'Enter a title.', ['field' => 'title']);
        }
        if (mb_strlen($body) < 200) {
            throw new DomainError('invalid_input', 'The agreement text looks too short.', ['field' => 'body']);
        }
        return Db::transaction(static function () use ($actor, $title, $body): int {
            Db::exec('SELECT id FROM ' . Db::t('agreement_versions') . ' FOR UPDATE');
            $n = (int) Db::value('SELECT COALESCE(MAX(version), 0) FROM ' . Db::t('agreement_versions')) + 1;
            Db::exec('UPDATE ' . Db::t('agreement_versions') . ' SET active = 0');
            $id = Uuid::v7();
            Db::insert('agreement_versions', ['id' => $id, 'version' => $n, 'title' => mb_substr($title, 0, 160), 'body' => $body, 'active' => 1, 'created_by' => $actor->userId, 'created_at' => Db::now()], ['id']);
            Audit::log(null, $actor->userId, 'agreement.version', 'agreement', $id, ['version' => $n]);
            return $n;
        });
    }

    /** Agreement text for an order with every merge tag filled in (plain text). */
    public static function merged(array $order, array $version, string $signer, ?string $signedAtUtc = null): string
    {
        $company = Db::row(Db::prepare('SELECT name, address FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $order['company_id'])) ?? ['name' => '', 'address' => ''];
        $items = [];
        foreach (Db::rows(Db::prepare('SELECT line_type, description, qty FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) ORDER BY sort', $order['id'])) as $l) {
            $items[] = '- ' . ($l['line_type'] === 'delivery' ? (int) $l['qty'] . ' trip(s): ' : number_format((int) $l['qty']) . ' × ') . $l['description'];
        }
        $when = $signedAtUtc ? new \DateTimeImmutable($signedAtUtc, new \DateTimeZone('UTC')) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = static fn(string $ymd): string => (new \DateTimeImmutable($ymd))->format('j F Y');
        $money = static fn($v): string => '₱' . number_format((float) $v, 2);
        $map = [
            '{{company}}' => (string) $company['name'],
            '{{company_address}}' => (string) ($company['address'] ?? ''),
            '{{order_ref}}' => (string) $order['ref'],
            '{{items}}' => implode("\n", $items),
            '{{start_date}}' => $date((string) $order['start_date']),
            '{{end_date}}' => $date((string) $order['end_date']),
            '{{days}}' => (string) (int) $order['days'],
            '{{site}}' => $order['fulfilment'] === 'pickup' ? 'Picked up and returned at the Canpas yard' : (string) $order['site_address'],
            '{{total}}' => $money($order['total']),
            '{{deposit}}' => $money($order['deposit_amount']),
            '{{signer_name}}' => $signer !== '' ? $signer : '[name of the person signing]',
            '{{today}}' => $when->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j F Y'),
        ];
        return strtr((string) $version['body'], $map);
    }

    /**
     * Customer signs the agreement for an order.
     *
     * @param array<string,mixed> $in signed_name, signature (PNG data URL), agree
     */
    public static function sign(Actor $actor, string $orderId, array $in): void
    {
        $actor->require('cf_sign_agreements');
        $o = Orders::row($orderId);
        Orders::assertCustomerAccess($actor, $o);
        if (!in_array($o['status'], ['requested', 'needs_fix'], true)) {
            throw new DomainError('wrong_status', 'This order can no longer be signed here.');
        }
        if ($o['status'] === 'requested' && $o['hold_expires_at'] < Db::now()) {
            throw new DomainError('hold_expired', 'The hold on this order has ended. Please book again.');
        }
        $name = sanitize_text_field(wp_unslash((string) ($in['signed_name'] ?? '')));
        if (mb_strlen($name) < 3) {
            throw new DomainError('invalid_input', 'Type your full name.', ['field' => 'signed_name']);
        }
        if (empty($in['agree']) || $in['agree'] === '0') {
            throw new DomainError('not_agreed', 'Tick the box to confirm you have read and agree to the agreement.', ['field' => 'agree']);
        }
        [$jpeg, $w, $h] = self::signatureJpeg((string) ($in['signature'] ?? ''));

        $version = self::active();
        $signedAt = Db::now();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : null;
        $pdf = self::pdf($o, $version, $name, $signedAt, $ipHash, $jpeg, $w, $h);
        $rel = 'agreements/' . $o['company_id'] . '/' . $o['ref'] . '-v' . (int) $version['version'] . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $full = trailingslashit(CANPAS_FLEET_PRIVATE_DIR) . $rel;
        if (!is_dir(dirname($full)) && !wp_mkdir_p(dirname($full))) {
            throw new DomainError('storage_unavailable', 'The agreement cannot be saved right now. Please contact Canpas.');
        }
        if (file_put_contents($full, $pdf) === false) {
            throw new DomainError('storage_unavailable', 'The agreement cannot be saved right now. Please contact Canpas.');
        }
        @chmod($full, 0600);
        $sha = hash('sha256', $pdf);
        $old = (string) ($o['agreement_file'] ?? '');
        try {
            Db::transaction(static function () use ($actor, $o, $version, $rel, $sha, $name, $signedAt, $ipHash): void {
                Db::exec(Db::prepare(
                    'UPDATE ' . Db::t('orders') . ' SET agreement_version_id = UNHEX(%s), agreement_file = %s, agreement_sha256 = %s, signed_name = %s, signed_by = %d, signed_at = %s, signed_ip_hash = %s, updated_at = %s WHERE id = UNHEX(%s)',
                    $version['id'], $rel, $sha, mb_substr($name, 0, 120), $actor->userId, $signedAt, $ipHash, Db::now(), $o['id']
                ));
                Audit::log($o['company_id'], $actor->userId, 'agreement.sign', 'order', $o['id'], ['version' => (int) $version['version'], 'sha256' => $sha]);
            });
        } catch (\Throwable $e) {
            @unlink($full);
            throw $e;
        }
        if ($old !== '') {
            \Canpas\Fleet\Support\Files::delete($old);
        }
    }

    /** @return array{path:string,name:string} signed PDF, after a permission check; the view is logged */
    public static function file(Actor $actor, string $orderId): array
    {
        $o = Orders::row($orderId);
        if (!$actor->can('cf_view_documents')) {
            Orders::assertCustomerAccess($actor, $o);
        }
        if (empty($o['agreement_file'])) {
            throw new DomainError('not_found', 'This order has not been signed yet.');
        }
        Documents::logView($actor, 'agreement', $o['id']);
        return ['path' => (string) $o['agreement_file'], 'name' => $o['ref'] . '-agreement.pdf'];
    }

    /** True when the stored PDF still matches the hash recorded at signing. */
    public static function intact(array $order): bool
    {
        $full = trailingslashit(CANPAS_FLEET_PRIVATE_DIR) . ltrim((string) $order['agreement_file'], '/');
        return !empty($order['agreement_sha256']) && is_file($full) && hash_equals((string) $order['agreement_sha256'], (string) hash_file('sha256', $full));
    }

    // ----- internals ---------------------------------------------------------------------------

    /** @return array{0:string,1:int,2:int} JPEG bytes on white, width, height */
    private static function signatureJpeg(string $dataUrl): array
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new DomainError('signature_missing', 'Draw your signature in the box.', ['field' => 'signature']);
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 400000 || !function_exists('imagecreatefromstring')) {
            throw new DomainError('signature_missing', 'Draw your signature in the box.', ['field' => 'signature']);
        }
        $src = @imagecreatefromstring($png);
        if ($src === false) {
            throw new DomainError('signature_missing', 'Draw your signature in the box.', ['field' => 'signature']);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 50 || $h < 20 || $w > 2400 || $h > 1200) {
            imagedestroy($src);
            throw new DomainError('signature_missing', 'Draw your signature in the box.', ['field' => 'signature']);
        }
        // Count ink: opaque, dark pixels on a coarse grid. A blank or near-blank box is refused.
        $ink = 0;
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $c = imagecolorat($src, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $lum = (($c >> 16) & 0xFF) * 0.3 + (($c >> 8) & 0xFF) * 0.59 + ($c & 0xFF) * 0.11;
                if ($a < 90 && $lum < 160) {
                    $ink++;
                }
            }
        }
        if ($ink < 40) {
            imagedestroy($src);
            throw new DomainError('signature_missing', 'Draw your signature in the box.', ['field' => 'signature']);
        }
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagealphablending($out, true);
        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($out, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($out);
        return [$jpeg, $w, $h];
    }

    private static function pdf(array $o, array $version, string $name, string $signedAt, ?string $ipHash, string $jpeg, int $w, int $h): string
    {
        $manila = (new \DateTimeImmutable($signedAt, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'));
        $pdf = new Pdf($version['title'] . ' — ' . $o['ref'], 'Canpas · ' . $o['ref'] . ' · Agreement version ' . (int) $version['version']);
        $pdf->paragraph((string) $version['title'], 17, true, 2);
        $pdf->paragraph('Order ' . $o['ref'] . ' · Version ' . (int) $version['version'], 9, false, 10);
        foreach (preg_split("/\n{2,}/", self::merged($o, $version, $name, $signedAt)) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '# ')) {
                $pdf->heading(substr($block, 2), 11.5);
            } else {
                $pdf->paragraph($block, 9.5);
            }
        }
        $pdf->space(8);
        $pdf->rule();
        $pdf->heading('Signed electronically', 11.5);
        $pdf->jpeg($jpeg, $w, $h, 220.0);
        $pdf->paragraph('Name: ' . $name, 10, true, 2);
        $pdf->paragraph('For: ' . (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id'])), 9.5, false, 2);
        $pdf->paragraph('Date and time: ' . $manila->format('j F Y, g:i:s a') . ' (Philippine time)', 9.5, false, 2);
        $pdf->paragraph('IP reference: ' . ($ipHash ? substr($ipHash, 0, 16) : 'not recorded'), 9.5, false, 2);
        $pdf->paragraph('The signer ticked "I have read and agree to this agreement" before signing.', 9.5, false, 2);
        return $pdf->output();
    }
}
