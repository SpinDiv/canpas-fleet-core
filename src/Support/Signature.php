<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * Drawn signatures from the portal's signature pad (a PNG data URL).
 * A blank or near-blank box is refused. Used for the rental agreement and for
 * receivers signing on the driver's or yard's phone.
 */
final class Signature
{
    /** Validate a signature and return it as a GD image on a white background. */
    public static function image(string $dataUrl): \GdImage
    {
        $fail = static fn() => new DomainError('signature_missing', 'Draw the signature in the box.', ['field' => 'signature']);
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || !function_exists('imagecreatefromstring')) {
            throw $fail();
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 400000) {
            throw $fail();
        }
        $src = @imagecreatefromstring($png);
        if ($src === false) {
            throw $fail();
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 50 || $h < 20 || $w > 2400 || $h > 1200) {
            throw $fail();
        }
        // Count ink: opaque, dark pixels on a coarse grid.
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
            throw $fail();
        }
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagealphablending($out, true);
        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        return $out;
    }

    /** @return array{0:string,1:int,2:int} JPEG bytes, width, height */
    public static function jpeg(string $dataUrl): array
    {
        $im = self::image($dataUrl);
        ob_start();
        imagejpeg($im, null, 88);
        return [(string) ob_get_clean(), imagesx($im), imagesy($im)];
    }

    /** Save a signature as a PNG in the private store; returns the relative path. */
    public static function store(string $dataUrl, string $folder): string
    {
        $im = self::image($dataUrl);
        $folder = trim(preg_replace('/[^a-z0-9\/_-]/', '', strtolower($folder)) ?? '', '/');
        $dir = trailingslashit(CANPAS_FLEET_PRIVATE_DIR) . $folder;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new DomainError('storage_unavailable', 'Files cannot be saved right now. Please contact Canpas.');
        }
        $rel = $folder . '/sig-' . bin2hex(random_bytes(12)) . '.png';
        if (!imagepng($im, trailingslashit(CANPAS_FLEET_PRIVATE_DIR) . $rel)) {
            throw new DomainError('storage_unavailable', 'Files cannot be saved right now. Please contact Canpas.');
        }
        @chmod(trailingslashit(CANPAS_FLEET_PRIVATE_DIR) . $rel, 0600);
        return $rel;
    }
}
