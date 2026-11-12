<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * A small A4 PDF writer for signed agreements: headings, wrapped paragraphs and one JPEG image,
 * with the built-in Helvetica fonts (no font files, no libraries).
 * Text is converted to Windows-1252; the peso sign is written as "PHP".
 */
final class Pdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 56.0;

    /** Helvetica widths (1/1000 em) for ASCII 32..126. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** @var list<string> content stream per page */
    private array $pages = [];
    private float $y = 0;
    /** @var list<array{w:int,h:int,data:string}> */
    private array $images = [];

    public function __construct(private string $title = '', private string $footer = '')
    {
        $this->newPage();
    }

    public function heading(string $text, float $size = 14): void
    {
        $this->space($size * 0.6);
        $this->paragraph($text, $size, true);
        $this->space(2);
    }

    public function paragraph(string $text, float $size = 10, bool $bold = false, float $after = 6): void
    {
        $lead = $size * 1.42;
        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $lines = $this->wrap($this->encode($raw), $size, $bold, self::W - 2 * self::M);
            foreach ($lines as $line) {
                $this->ensure($lead);
                $this->y -= $lead;
                if ($line !== '') {
                    $this->pages[count($this->pages) - 1] .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, self::M, $this->y, $this->escape($line));
                }
            }
        }
        $this->space($after);
    }

    public function space(float $pt): void
    {
        $this->y -= $pt;
    }

    /** Thin horizontal rule across the text width. */
    public function rule(): void
    {
        $this->ensure(10);
        $this->y -= 6;
        $this->pages[count($this->pages) - 1] .= sprintf("0.8 G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G\n", self::M, $this->y, self::W - self::M, $this->y);
        $this->y -= 6;
    }

    /** Place a JPEG (e.g. a signature) at the current position, $widthPt wide. */
    public function jpeg(string $jpeg, int $pxW, int $pxH, float $widthPt): void
    {
        $h = $widthPt * $pxH / max(1, $pxW);
        $this->ensure($h + 4);
        $this->images[] = ['w' => $pxW, 'h' => $pxH, 'data' => $jpeg];
        $n = count($this->images);
        $this->y -= $h;
        $this->pages[count($this->pages) - 1] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $widthPt, $h, self::M, $this->y, $n);
        $this->y -= 4;
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $xobj = '';
        foreach ($this->images as $i => $img) {
            $objects[$next] = ['<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($img['data']) . ' >>', $img['data']];
            $xobj .= '/Im' . ($i + 1) . ' ' . $next . ' 0 R ';
            $next++;
        }
        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj !== '' ? ' /XObject << ' . $xobj . '>>' : '') . ' >>';
        $kids = [];
        $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $foot = sprintf("BT /F1 8 Tf %.2F 30 Td (%s) Tj ET\n", self::M, $this->escape($this->encode($this->footer)));
            $pageNo = $this->encode('Page ' . ($i + 1) . ' of ' . $total);
            $foot .= sprintf("BT /F1 8 Tf %.2F 30 Td (%s) Tj ET\n", self::W - self::M - $this->width($pageNo, 8, false), $this->escape($pageNo));
            $stream = $content . $foot;
            $objects[$next] = ['<< /Length ' . strlen($stream) . ' >>', $stream];
            $objects[$next + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources ' . $resources . ' /Contents ' . $next . ' 0 R >>';
            $kids[] = ($next + 1) . ' 0 R';
            $next += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $info = $next;
        $objects[$info] = '<< /Title (' . $this->escape($this->encode($this->title)) . ') /Producer (Canpas Fleet) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $obj) {
            $offsets[$n] = strlen($out);
            $out .= $n . " 0 obj\n" . (is_array($obj) ? $obj[0] . "\nstream\n" . $obj[1] . "\nendstream" : $obj) . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objects));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
        }
        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    // ----- internals ---------------------------------------------------------------------------

    private function newPage(): void
    {
        $this->pages[] = '';
        $this->y = self::H - self::M;
    }

    private function ensure(float $height): void
    {
        if ($this->y - $height < self::M + 14) {
            $this->newPage();
        }
    }

    /** UTF-8 to Windows-1252 bytes. */
    private function encode(string $s): string
    {
        $s = str_replace(['₱', "\t"], ['PHP ', '    '], $s);
        $out = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) : false;
        return $out === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    private function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '']);
    }

    private function width(string $s, float $size, bool $bold): float
    {
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $w += ($c >= 32 && $c <= 126) ? self::WIDTHS[$c - 32] : 556;
        }
        return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
    }

    /** @return list<string> */
    private function wrap(string $text, float $size, bool $bold, float $max): array
    {
        if (trim($text) === '') {
            return [''];
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->width($try, $size, $bold) <= $max) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            // A single word longer than the line is broken by characters.
            while ($this->width($word, $size, $bold) > $max) {
                $cut = strlen($word);
                while ($cut > 1 && $this->width(substr($word, 0, $cut), $size, $bold) > $max) {
                    $cut--;
                }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        $lines[] = $line;
        return $lines;
    }
}
