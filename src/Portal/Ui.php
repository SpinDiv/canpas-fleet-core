<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

/**
 * Small HTML building blocks shared by every portal screen. Everything passed in as text is escaped here.
 * Forms are submitted by assets/portal.js to the canpas/v1 REST API.
 */
final class Ui
{
    public const LABELS = [
        'percent' => '% of order total', 'fixed' => 'Fixed amount (₱)',
        'portal' => 'Canpas portal sign-in', 'website' => 'Website (theme pages)',
        'available' => 'Available', 'repair' => 'In repair', 'retired' => 'Retired',
        'formwork' => 'Formwork', 'scaffolding' => 'Scaffolding', 'tools' => 'Small tools & accessories', 'heavy' => 'Heavy equipment',
        'pc' => 'Piece', 'set' => 'Set', 'm2' => 'Square metre', 'm' => 'Metre', 'unit' => 'Unit',
        'round_trip' => 'Round trip', 'one_way' => 'One way', '1' => '1 (delivery only)', '2' => '2 (delivery and pickup)',
        'pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'suspended' => 'Suspended',
        'active' => 'Active', 'inactive' => 'Removed',
        'cf_customer_user' => 'Can book and track orders', 'cf_customer_admin' => 'Company admin (can also add people)',
    ];

    private const TONES = [
        'available' => 'good', 'approved' => 'good', 'active' => 'good',
        'pending' => 'warn', 'repair' => 'warn', 'suspended' => 'bad', 'declined' => 'muted', 'retired' => 'muted', 'inactive' => 'muted',
    ];

    public static function e(?string $s): string
    {
        return esc_html((string) $s);
    }

    public static function label(string $value): string
    {
        return self::LABELS[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    public static function pill(string $status, ?string $text = null): string
    {
        $tone = self::TONES[$status] ?? 'info';
        return '<span class="pill ' . $tone . '">' . self::e($text ?? self::label($status)) . '</span>';
    }

    public static function money(mixed $v): string
    {
        return '₱' . number_format((float) $v, 2);
    }

    public static function date(?string $ymd): string
    {
        if (!$ymd) {
            return '—';
        }
        $ts = strtotime($ymd);
        return $ts ? wp_date('j M Y', $ts) : '—';
    }

    /** UTC DATETIME from the database, shown in Manila time. */
    public static function when(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j M Y, g:i a');
    }

    public static function card(string $title, string $inner, string $actions = '', string $class = ''): string
    {
        $head = $title !== '' || $actions !== ''
            ? '<div class="card-head"><h2>' . self::e($title) . '</h2>' . $actions . '</div>'
            : '';
        return '<section class="card ' . esc_attr($class) . '">' . $head . $inner . '</section>';
    }

    /**
     * A data table. Cells are raw HTML: escape values with Ui::e() before passing them.
     *
     * @param list<string> $headers  prefix a header with '>' to right-align its column
     * @param list<list<string>> $rows
     */
    public static function table(array $headers, array $rows, string $empty = 'Nothing here yet.'): string
    {
        if ($rows === []) {
            return '<p class="empty">' . self::e($empty) . '</p>';
        }
        $right = [];
        $h = '';
        foreach ($headers as $i => $head) {
            $right[$i] = str_starts_with($head, '>');
            $h .= '<th scope="col"' . ($right[$i] ? ' class="r"' : '') . '>' . self::e(ltrim($head, '>')) . '</th>';
        }
        $b = '';
        foreach ($rows as $row) {
            $b .= '<tr>';
            foreach ($row as $i => $cell) {
                $b .= '<td' . (!empty($right[$i]) ? ' class="r num"' : '') . '>' . $cell . '</td>';
            }
            $b .= '</tr>';
        }
        return '<div class="table-wrap"><table><thead><tr>' . $h . '</tr></thead><tbody>' . $b . '</tbody></table></div>';
    }

    /** KPI tile. */
    public static function kpi(string $label, string $value, string $note = '', string $tone = ''): string
    {
        return '<div class="card kpi"><span class="kpi-label">' . self::e($label) . '</span><span class="kpi-value num">' . self::e($value) . '</span>'
            . ($note !== '' ? '<span class="kpi-note ' . esc_attr($tone) . '">' . self::e($note) . '</span>' : '') . '</div>';
    }

    /** Tabs as links (?tab=...). @param array<string,string> $tabs key => label */
    public static function tabs(array $tabs, string $active, string $param = 'tab'): string
    {
        $out = '<div class="tabs-bar"><button type="button" class="tabs-arrow prev" data-tabs-scroll="-1" aria-label="Scroll tabs left" tabindex="-1">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>'
            . '<nav class="tabs" aria-label="Sections">';
        foreach ($tabs as $key => $label) {
            $url = esc_url(add_query_arg([$param => $key, 'edit' => false]));
            // "Label (3)" shows the number as a small count badge.
            $text = preg_match('/^(.*) \((\d+)\)$/s', $label, $m)
                ? self::e($m[1]) . '<span class="tab-count">' . self::e($m[2]) . '</span>'
                : self::e($label);
            $out .= '<a href="' . $url . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . $text . '</a>';
        }
        return $out . '</nav><button type="button" class="tabs-arrow next" data-tabs-scroll="1" aria-label="Scroll tabs right" tabindex="-1">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button></div>';
    }

    /**
     * A form that posts to the REST API.
     *
     * @param array<string, array{0:string,1:string,2:bool,3:?float,4:?float}> $fields  name => [label, type, required, min, max]
     * @param array<string, mixed> $values current values (edit) or defaults
     * @param array<string, array<string,string>> $options select options for 'id' fields: name => [value => label]
     */
    public static function form(string $endpoint, array $fields, array $values = [], array $options = [], string $submit = 'Save', bool $disabled = false, string $after = 'reload'): string
    {
        $out = '<form class="form" data-endpoint="' . esc_attr($endpoint) . '" data-after="' . esc_attr($after) . '" novalidate><div class="form-grid">';
        foreach ($fields as $name => [$label, $type, $required]) {
            $out .= self::field($name, $label, $type, $required, $values[$name] ?? null, $options[$name] ?? [], $disabled);
        }
        $out .= '</div><p class="form-error" role="alert" hidden></p>';
        if (!$disabled) {
            $out .= '<div class="form-actions"><button class="btn primary" type="submit">' . self::e($submit) . '</button></div>';
        }
        return $out . '</form>';
    }

    /** @param array<string,string> $options */
    public static function field(string $name, string $label, string $type, bool $required, mixed $value, array $options = [], bool $disabled = false): string
    {
        $id = 'f-' . $name;
        $req = $required ? ' required' : '';
        $dis = $disabled ? ' disabled' : '';
        $v = $value === null ? '' : (string) $value;
        $lab = '<label for="' . $id . '">' . self::e($label) . ($required ? '' : ' <span class="opt">(optional)</span>') . '</label>';

        if ($type === 'bool') {
            $checked = $value === null || (int) $value === 1 ? ' checked' : '';
            return '<div class="field check"><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . $checked . $dis . '> ' . self::e($label) . '</label></div>';
        }
        if ($type === 'textarea') {
            return '<div class="field wide">' . $lab . '<textarea id="' . $id . '" name="' . esc_attr($name) . '" rows="4"' . $req . $dis . '>' . esc_textarea($v) . '</textarea></div>';
        }
        if (str_starts_with($type, 'enum:') || $type === 'id') {
            $choices = $type === 'id' ? $options : array_combine(explode('|', substr($type, 5)), array_map([self::class, 'label'], explode('|', substr($type, 5))));
            $opts = $type === 'id' ? '<option value="">Choose…</option>' : '';
            foreach ($choices as $val => $text) {
                $opts .= '<option value="' . esc_attr((string) $val) . '"' . ((string) $val === $v ? ' selected' : '') . '>' . self::e($text) . '</option>';
            }
            return '<div class="field">' . $lab . '<select id="' . $id . '" name="' . esc_attr($name) . '"' . $req . $dis . '>' . $opts . '</select></div>';
        }
        $attrs = match ($type) {
            'money' => ' type="text" inputmode="decimal" class="num"',
            'decimal', 'float' => ' type="text" inputmode="decimal" class="num"',
            'int' => ' type="text" inputmode="numeric" class="num"',
            'date' => ' type="date"',
            'email' => ' type="email" autocomplete="email"',
            'plate' => ' type="text" class="mono" autocapitalize="characters"',
            'password' => ' type="password" autocomplete="new-password"',
            'password-current' => ' type="password" autocomplete="current-password"',
            default => ' type="text"',
        };
        if ($type === 'money' && $v !== '') {
            $v = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        }
        return '<div class="field">' . $lab . '<input id="' . $id . '" name="' . esc_attr($name) . '" value="' . esc_attr($v) . '"' . $attrs . $req . $dis . '></div>';
    }

    /** A button that posts to the REST API, optionally asking for a reason or confirmation first. */
    public static function action(string $label, string $endpoint, string $style = '', string $confirm = '', string $prompt = ''): string
    {
        return '<button type="button" class="btn ' . esc_attr($style) . '" data-endpoint="' . esc_attr($endpoint) . '"'
            . ($confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '')
            . ($prompt !== '' ? ' data-prompt="' . esc_attr($prompt) . '"' : '') . '>' . self::e($label) . '</button>';
    }
}
