<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Plugin;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Page shell for the portal: navy sidebar, white top bar, light content area, Work Sans.
 * Rendered outside the theme so the portal looks and behaves the same whatever theme the site uses.
 * Design tokens follow the UI standard in the blueprint (14px body, 4px spacing grid).
 */
final class Layout
{
    public static function esc(string $s): string
    {
        return esc_html($s);
    }

    /** Full portal page: sidebar menu for the actor's role, top bar, then $body inside <main>. */
    public static function page(Actor $actor, string $active, string $title, string $body, string $crumb = '', string $actions = ''): void
    {
        $user = wp_get_current_user();
        $name = self::esc($user->display_name);
        $role = self::esc(str_replace('Canpas: ', '', Roles::LABELS[$actor->role] ?? ''));
        $initials = self::esc(self::initials($user->display_name));
        $logout = esc_url(wp_logout_url(Router::url()));
        $logo = esc_url(Plugin::url('assets/logo-white.png'));
        $sub = $actor->isStaff() ? 'Fleet &amp; Equipment' : 'Customer portal';

        $nav = '';
        foreach (Nav::menu($actor) as $group) {
            $nav .= '<div class="nav-group"><p class="nav-label">' . self::esc($group['label']) . '</p>';
            foreach ($group['items'] as $item) {
                $current = $item['slug'] === $active ? ' aria-current="page"' : '';
                $nav .= '<a class="nav-item" href="' . esc_url(Router::url($item['slug'])) . '"' . $current . '>'
                    . Nav::icon($item['icon']) . '<span>' . self::esc($item['label']) . '</span></a>';
            }
            $nav .= '</div>';
        }

        $crumbHtml = $crumb !== '' ? '<p class="crumb">' . self::esc($crumb) . '</p>' : '';
        $titleE = self::esc($title);
        $heading = $actions !== ''
            ? '<div class="head-row"><div>' . $crumbHtml . '<h1>' . $titleE . '</h1></div><div class="row-actions">' . $actions . '</div></div>'
            : $crumbHtml . '<h1>' . $titleE . '</h1>';
        $search = Nav::icon('search', 16);
        $out = Nav::icon('logout');
        $home = esc_url(Router::url());
        $burger = '<svg class="ic-open" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>'
            . '<svg class="ic-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';

        // Yard checkers and drivers get a phone tab bar instead of the slide-out menu.
        $tabs = Nav::tabs($actor);
        $shellClass = $tabs ? 'shell has-tabs' : 'shell';
        $mbarEnd = $tabs ? '' : '<a class="icon-btn" href="' . $logout . '" aria-label="Log out">' . $out . '</a>'
            . '<button type="button" class="icon-btn" data-menu-toggle aria-controls="side-nav" aria-expanded="false" aria-label="Menu">' . $burger . '</button>';
        $scrim = $tabs ? '' : '<div class="nav-scrim" data-menu-close aria-hidden="true"></div>';
        $tabbar = '';
        if ($tabs) {
            $tabbar = '<nav class="tabbar" aria-label="Main">';
            foreach ($tabs as $t) {
                $current = $t['slug'] === $active ? ' aria-current="page"' : '';
                $tabbar .= '<a href="' . esc_url(Router::url($t['slug'])) . '"' . $current . '>' . Nav::icon($t['icon'], 22)
                    . '<span>' . self::esc($t['label']) . '</span></a>';
            }
            $tabbar .= '</nav>';
        }

        $chrome = <<<HTML
<a class="skip" href="#main">Skip to main content</a>
<div class="{$shellClass}">
  <div class="mbar">
    <a class="mbar-logo" href="{$home}"><img src="{$logo}" alt="Canpas" width="80" height="24"></a>
    <span class="spacer"></span>
    <span class="avatar" title="{$name} · {$role}" aria-hidden="true">{$initials}</span>
    {$mbarEnd}
  </div>
  {$scrim}
  <nav class="side" id="side-nav" aria-label="Main menu">
    <div class="mwho"><span class="avatar" aria-hidden="true">{$initials}</span><span class="who-text"><b>{$name}</b><small>{$role}</small></span></div>
    <div class="brand"><img src="{$logo}" alt="Canpas" width="106" height="32"><span>{$sub}</span></div>
    {$nav}
    <a class="nav-item logout" href="{$logout}">{$out}<span>Log out</span></a>
  </nav>
  <div class="body">
    <header class="top">
      <label class="search">{$search}<span class="sr">Search</span><input type="search" placeholder="Search order no., plate, serial or company" disabled></label>
      <div class="spacer"></div>
      <div class="who"><span class="avatar" aria-hidden="true">{$initials}</span><span class="who-text"><b>{$name}</b><small>{$role}</small></span></div>
    </header>
    <main id="main">
      {$heading}
      {$body}
    </main>
  </div>
  {$tabbar}
</div>
HTML;
        self::bare($title, $chrome);
    }

    /** A centred message card for errors, pending accounts and the like. */
    public static function message(string $title, string $text, int $status = 200, ?array $action = null): void
    {
        status_header($status);
        $logo = esc_url(Plugin::url('assets/logo-color.png'));
        $btn = $action ? '<p><a class="btn primary" href="' . esc_url($action['href']) . '">' . self::esc($action['label']) . '</a></p>' : '';
        self::bare($title, '<main id="main" class="center"><section class="card note"><img src="' . $logo . '" alt="Canpas" width="140" height="42">'
            . '<h1>' . self::esc($title) . '</h1><p>' . self::esc($text) . '</p>' . $btn
            . '<p class="small"><a href="' . esc_url(wp_logout_url(Router::url())) . '">Sign out</a></p></section></main>');
    }

    /** Document shell only: head, tokens, fonts. */
    public static function bare(string $title, string $body, string $css = ''): void
    {
        $titleE = self::esc($title);
        $base = self::css();
        $icon = esc_url(Plugin::url('assets/logo-color.png'));
        $cfg = wp_json_encode([
            'rest' => esc_url_raw(rest_url('canpas/v1/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
        $js = esc_url(Plugin::url('assets/portal.js') . '?v=' . CANPAS_FLEET_VERSION);
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#141D30">
<title>{$titleE} | Canpas Fleet</title>
<link rel="icon" href="{$icon}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<style>{$base}{$css}</style>
</head>
<body>
{$body}
<script>window.CF={$cfg};</script>
<script src="{$js}" defer></script>
</body>
</html>
HTML;
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';
        return strtoupper(mb_substr($first, 0, 1) . mb_substr((string) $last, 0, 1));
    }

    public static function css(): string
    {
        return <<<'CSS'
:root{
  --navy:#141D30; --navy-ink:#C9D2E3; --navy-muted:#8F9BB2;
  --paper:#F3F5F9; --surface:#FFFFFF; --ink:#1B2333; --ink-2:#3A4458; --muted:#5B6475; --line:#E3E7EE; --line-2:#CBD2DE;
  --accent:#C2410C; --link:#1D4ED8;
  --good-bg:#DCF3E6; --good:#166534; --warn-bg:#FDECD3; --warn:#9A3412; --bad-bg:#FDE2E2; --bad:#991B1B; --info-bg:#DCE7FB; --info:#1E40AF;
  --r:8px; --shadow:0 1px 2px rgba(20,29,48,.06),0 4px 14px rgba(20,29,48,.05);
}
*{box-sizing:border-box}
[hidden]{display:none!important}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--paper);color:var(--ink);font-family:"Work Sans",system-ui,sans-serif;font-size:14px;line-height:1.5}
a{color:var(--link);text-decoration:none}a:hover{text-decoration:underline}
:focus-visible{outline:2px solid var(--link);outline-offset:2px}
.num{font-variant-numeric:tabular-nums}
.mono{font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:12px}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.skip{position:absolute;left:-999px;top:0;background:var(--surface);padding:12px 16px;z-index:20}.skip:focus{left:16px}
.shell{display:flex;flex-wrap:wrap;min-height:100vh}
.side{flex:0 0 240px;background:var(--navy);color:var(--navy-ink);display:flex;flex-direction:column;gap:2px;padding:20px 12px}
.brand{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:4px 12px 20px}
.brand img{height:32px;width:auto}
.brand span{font-size:11px;color:var(--navy-muted);letter-spacing:.08em;text-transform:uppercase}
.nav-group{display:flex;flex-direction:column;gap:2px;padding-bottom:14px}
.nav-label{margin:0;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--navy-muted);padding:8px 12px 6px}
.nav-item{display:flex;align-items:center;gap:10px;height:36px;padding:0 12px;border-radius:6px;font-size:13px;font-weight:500;color:var(--navy-ink);text-decoration:none}
.nav-item:hover{background:rgba(255,255,255,.06);text-decoration:none}
.nav-item[aria-current=page]{background:rgba(255,255,255,.10);color:#fff}
.nav-item.logout{margin-top:auto}
.body{flex:999 1 560px;min-width:0;display:flex;flex-direction:column}
.top{height:64px;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:16px;padding:0 32px}
.search{flex:0 1 420px;height:38px;display:flex;align-items:center;gap:8px;padding:0 12px;border-radius:19px;background:var(--paper);color:var(--muted)}
.search input{flex:1;min-width:0;border:0;background:transparent;font:inherit;font-size:13px;color:var(--ink)}
.spacer{flex:1}
.who{display:flex;align-items:center;gap:10px}
.avatar{width:36px;height:36px;border-radius:18px;background:#DCE3EE;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center}
.who-text{display:flex;flex-direction:column;line-height:1.3}.who-text b{font-size:13px;font-weight:600}.who-text small{font-size:12px;color:var(--muted)}
main{padding:24px 32px 40px;max-width:1400px;width:100%}
.crumb{margin:0 0 4px;font-size:12px;color:var(--muted)}
h1{margin:0 0 20px;font-size:22px;font-weight:600;line-height:1.25}
h2{margin:0 0 12px;font-size:16px;font-weight:600;line-height:1.3}
p{margin:0 0 12px}
.card{background:var(--surface);border-radius:var(--r);box-shadow:var(--shadow);padding:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.pill{display:inline-block;font-size:12px;font-weight:500;padding:3px 8px;border-radius:10px}
.pill.good{background:var(--good-bg);color:var(--good)}.pill.warn{background:var(--warn-bg);color:var(--warn)}.pill.info{background:var(--info-bg);color:var(--info)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 14px;border-radius:6px;border:1px solid var(--line-2);background:var(--surface);color:var(--ink);font:inherit;font-size:13px;font-weight:500;text-decoration:none;cursor:pointer}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
.btn:hover{text-decoration:none}
.list{list-style:none;margin:0;padding:0}.list li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--line)}.list li:first-child{border-top:0}
.muted{color:var(--muted)}.small{font-size:12px}
.center{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.note{max-width:440px;display:flex;flex-direction:column;gap:4px}.note img{height:42px;width:auto;margin-bottom:12px;align-self:flex-start}
main>.card+.card,main>.grid+.card,main>.card+.grid,main>.tabs-bar+.card,main>.grid+.grid{margin-top:16px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.card-head h2{margin:0}
.kpi{display:flex;flex-direction:column;gap:4px;padding:16px}.kpi-label{font-size:12px;font-weight:500;color:var(--muted);letter-spacing:.02em}
.kpi-value{font-size:24px;font-weight:600;line-height:1.15}.kpi-note{font-size:12px;color:var(--muted)}.kpi-note.warn{color:var(--warn)}.kpi-note.bad{color:var(--bad)}
.grid.k4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.pill.bad{background:var(--bad-bg);color:var(--bad)}.pill.muted{background:#EEF1F5;color:var(--ink-2)}
.tabs-bar{position:relative;display:flex;align-items:stretch;border-bottom:1px solid var(--line);margin-bottom:16px}
.tabs{flex:1;min-width:0;display:flex;gap:4px;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;scroll-behavior:smooth;scrollbar-width:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}
.tabs::-webkit-scrollbar{display:none}
.tabs-bar.can-right .tabs{-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent)}
.tabs-bar.can-left .tabs{-webkit-mask-image:linear-gradient(to left,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to left,#000 calc(100% - 40px),transparent)}
.tabs-bar.can-left.can-right .tabs{-webkit-mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent)}
.tabs-arrow{display:none;flex:none;width:32px;align-items:center;justify-content:center;border:0;background:transparent;color:var(--ink-2);cursor:pointer;border-radius:6px}
.tabs-arrow:hover{background:#EEF1F5}
.tabs-bar.can-left .prev,.tabs-bar.can-right .next{display:flex}
.tabs a{flex:none;white-space:nowrap;scroll-snap-align:start;display:flex;align-items:center;padding:8px 12px 10px;font-size:13px;font-weight:500;color:var(--muted);border-bottom:2px solid transparent;text-decoration:none}
.tab-count{display:inline-block;min-width:20px;margin-left:6px;padding:0 6px;border-radius:10px;background:#EEF1F5;color:var(--muted);font-size:11px;font-weight:600;line-height:18px;text-align:center;font-variant-numeric:tabular-nums}
.tabs a[aria-current=page] .tab-count{background:var(--accent);color:#fff}
@media (hover:none){.tabs-arrow{display:none!important}}
.tabs a[aria-current=page]{color:var(--ink);border-bottom-color:var(--accent)}
.table-wrap{overflow-x:auto;margin:0 -20px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{font-size:12px;font-weight:500;color:var(--muted);text-align:left;padding:10px 20px;border-bottom:1px solid var(--line);white-space:nowrap;letter-spacing:.02em}
td{padding:10px 20px;border-bottom:1px solid #EEF1F5;vertical-align:middle}
tr:last-child td{border-bottom:0}
th.r,td.r{text-align:right;white-space:nowrap}
td .btn{height:30px;padding:0 10px;font-size:12px}
.row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.empty{color:var(--muted);margin:4px 0}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px 16px}
.field{display:flex;flex-direction:column;gap:6px}.field.wide{grid-column:1/-1}
.field label{font-size:12px;font-weight:500;color:var(--ink-2);letter-spacing:.02em}.opt{font-weight:400;color:var(--muted)}
.field input,.field select,.field textarea{height:38px;padding:0 10px;border:1px solid var(--line-2);border-radius:6px;font:inherit;font-size:14px;color:var(--ink);background:#fff;width:100%}
.field textarea{height:auto;padding:8px 10px}
.field input[aria-invalid],.field select[aria-invalid]{border-color:var(--bad);box-shadow:0 0 0 1px var(--bad)}
.field input:disabled,.field select:disabled{background:#F7F8FB;color:var(--ink-2)}
.field.check{justify-content:flex-end}.field.check label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:400;color:var(--ink);height:38px}
.form-actions{margin-top:16px;display:flex;gap:8px}
.form-error{margin:12px 0 0;padding:10px 12px;border-radius:6px;background:var(--bad-bg);color:var(--bad);font-size:13px}
.success{padding:16px;border-radius:8px;background:var(--good-bg);color:var(--good);font-weight:500}
.btn.danger{color:var(--bad);border-color:#F3C3C3}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;padding:12px 18px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.2);font-size:14px;z-index:50;max-width:calc(100% - 32px)}
.dl{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;margin:0}.dl dt{color:var(--muted);font-size:13px}.dl dd{margin:0}
.split{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}.split>*{flex:1 1 360px;min-width:0}
.stack{display:flex;flex-direction:column;gap:16px}
main>.grid+.split,main>.split+.split,main>.split+.card{margin-top:16px}
.alert-row{display:flex;gap:12px;padding:12px;border-radius:8px;background:#F5F7FA;color:var(--ink);text-decoration:none;margin-bottom:8px}.alert-row:hover{text-decoration:none;background:#EEF1F5}
.alert-row .dot{width:8px;height:8px;border-radius:4px;background:#64748B;margin-top:6px;flex:none}
.alert-row.warn{background:#FFF8EB}.alert-row.warn .dot{background:#D97706}.alert-row.bad{background:#FEF3F2}.alert-row.bad .dot{background:#DC2626}
details.adder{padding:16px 20px}details.adder summary{list-style:none;cursor:pointer;display:inline-block}details.adder summary::-webkit-details-marker{display:none}
details.adder[open] summary .btn{background:#fff;color:var(--ink);border-color:var(--line-2)}
main>details+.card,main>.tabs-bar+details,main>details+details{margin-top:16px}main>.card+.card{margin-top:16px}
.quote-box{margin-top:16px;padding:16px;border:1px solid var(--line);border-radius:8px;background:#FBFCFE}
.quote-box .table-wrap{margin:0}.quote-box th,.quote-box td{padding-left:8px;padding-right:8px}
.totals{display:grid;grid-template-columns:1fr auto;gap:6px 16px;margin:12px 0 8px;padding-top:12px;border-top:1px solid var(--line)}
.totals dt{color:var(--ink-2)}.totals dd{margin:0;text-align:right;font-weight:600}.totals dt:first-child,.totals dd:nth-child(2){font-size:16px}
h3.cat{margin:20px 0 4px;font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.items .table-wrap{margin:0}.items th,.items td{padding-left:8px;padding-right:8px}
input.qty{width:96px;height:36px;padding:0 10px;border:1px solid var(--line-2);border-radius:6px;font:inherit;text-align:right}
.field small{font-size:12px}
.form-actions button[disabled]{opacity:.5;cursor:not-allowed}
.head-row{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:20px}.head-row h1{margin:0}
.mbar,.mwho,.nav-scrim,.tabbar{display:none}
.quote-line{border:1px solid var(--line);border-radius:8px;padding:12px 16px 4px;margin:0 0 12px}.quote-line legend{padding:0 6px;font-size:13px}
.rate-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px 12px}
.totals .save{color:#15803D;font-weight:600}s.muted,s{text-decoration-thickness:1px}
.quote-ask small{display:block;margin-top:4px}
.payinfo{background:#F5F7FA;border:1px solid var(--line);border-radius:8px;padding:12px 16px;margin:0 0 16px}.payinfo-title{margin:0 0 8px;font-weight:600;font-size:13px}.payinfo .dl{margin:0 0 6px}
.rates{display:flex;flex-wrap:wrap;gap:0 12px}.rates .rate{white-space:nowrap}.list li>.pill{align-self:flex-start;flex:none}
@media (max-width:820px){
  .shell{align-content:flex-start}.body{min-height:calc(100vh - 56px);min-height:calc(100dvh - 56px)}
  .mbar{display:flex;flex:1 1 100%;position:sticky;top:0;z-index:60;height:56px;align-items:center;gap:4px;padding:0 4px 0 16px;background:var(--navy);color:#fff}
  .mbar-logo{display:flex;align-items:center}.mbar-logo img{height:24px;width:auto}
  .mbar .avatar{width:32px;height:32px;font-size:12px;margin-right:4px;color:var(--navy)}.mwho .avatar{color:var(--navy)}
  .icon-btn{width:44px;height:44px;display:flex;align-items:center;justify-content:center;border:0;background:transparent;color:#fff;border-radius:8px;cursor:pointer}
  .icon-btn:hover,.icon-btn[aria-expanded=true]{background:rgba(255,255,255,.10)}
  .icon-btn .ic-close,.icon-btn[aria-expanded=true] .ic-open{display:none}.icon-btn[aria-expanded=true] .ic-close{display:block}
  .side{display:flex;position:fixed;top:56px;left:0;bottom:0;width:50%;z-index:59;overflow-y:auto;padding:12px 8px 24px;
    transform:translateX(-100%);visibility:hidden;transition:transform .25s ease,visibility 0s linear .25s;box-shadow:4px 0 24px rgba(0,0,0,.25)}
  .nav-open .side{transform:none;visibility:visible;transition:transform .25s ease,visibility 0s}
  @media (max-width:600px){.side{width:70%}}
  .nav-scrim{display:block;position:fixed;top:56px;left:0;right:0;bottom:0;z-index:58;background:rgba(10,15,28,.45);opacity:0;pointer-events:none;transition:opacity .25s ease}
  .nav-open .nav-scrim{opacity:1;pointer-events:auto}.nav-open body{overflow:hidden}
  .side .nav-item{height:auto;min-height:44px;padding:10px 12px;line-height:1.3}
  .mwho .who-text{min-width:0}.mwho .who-text b{overflow-wrap:anywhere}
  .side .brand,.side .nav-item.logout{display:none}
  .mwho{display:flex;align-items:center;gap:10px;padding:8px 12px 16px;margin-bottom:8px;border-bottom:1px solid rgba(255,255,255,.08)}
  .mwho .who-text b{color:#fff}.mwho .who-text small{color:var(--navy-muted)}
  .nav-item{height:44px;font-size:15px}
  .top{display:none}
  main{padding:16px}
  body{font-size:16px}
  .has-tabs .mbar{padding-right:12px}
  .tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:57;background:#fff;border-top:1px solid var(--line);box-shadow:0 -2px 12px rgba(20,29,48,.06);padding-bottom:env(safe-area-inset-bottom)}
  .tabbar a{flex:1 1 0;min-width:0;min-height:56px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:12px;font-weight:500;color:var(--muted);text-decoration:none;-webkit-tap-highlight-color:transparent}
  .tabbar a:hover{text-decoration:none;color:var(--ink)}
  .tabbar a[aria-current=page]{color:var(--accent);font-weight:600}
  .has-tabs main{padding-bottom:calc(88px + env(safe-area-inset-bottom))}
  .has-tabs ~ .toast{bottom:calc(72px + env(safe-area-inset-bottom))}
  .account-out{margin:16px 0 0}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
.req{list-style:none;margin:0;padding:0}.req>li{padding:16px 0;border-top:1px solid var(--line)}.req>li:first-child{border-top:0;padding-top:4px}
.req-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.req-head b{font-size:14px;font-weight:600}
.req-body{margin-top:8px;display:flex;flex-direction:column;gap:8px}.req-body p{margin:0}.req-body .table-wrap{margin-top:4px}
.doc-line{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px}.doc-line .btn{height:30px;padding:0 10px;font-size:12px}
.inline-form{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}.inline-form .field{min-width:260px;flex:0 1 360px}.inline-form .form-error{flex-basis:100%}
details.upload summary{cursor:pointer;color:var(--link);font-size:13px;font-weight:500;padding:4px 0}details.upload[open] summary{margin-bottom:12px}
details.upload .form{padding:16px;border:1px solid var(--line);border-radius:8px;background:#FBFCFD}
.field input[type=file]{height:auto;padding:7px 8px;font-size:13px}
.callout{padding:12px 16px;border-radius:8px;background:var(--warn-bg);color:var(--warn);margin-bottom:16px;font-size:13px}.callout.info{background:var(--info-bg);color:var(--info)}
.bad-text{color:var(--bad)}
.field input[type=checkbox]{width:16px;height:16px;padding:0;flex:none;accent-color:var(--accent)}.field.check.wide label{height:auto;align-items:flex-start;line-height:1.45}.field.check.wide input{margin-top:2px}
.pre{white-space:pre-wrap;background:#F5F7FA;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.agreement{max-height:480px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:20px 24px;background:#FBFCFD;font-size:13px;line-height:1.6}
.agreement h2{font-size:16px;margin:0 0 12px}.agreement h3{font-size:13px;font-weight:600;margin:16px 0 4px}.agreement p{margin:0 0 8px}
.sigpad{position:relative;border:1px dashed var(--line-2);border-radius:8px;background:#fff;max-width:620px}
.sigpad canvas{display:block;width:100%;height:auto;aspect-ratio:900/260;touch-action:none;cursor:crosshair}
.sigpad .sig-line{position:absolute;left:24px;right:24px;bottom:28%;border-bottom:1px solid var(--line-2);pointer-events:none}
.sigpad [data-sig-clear]{position:absolute;top:8px;right:8px;height:30px;padding:0 10px;font-size:12px}
.label{font-size:12px;font-weight:500;color:var(--ink-2);letter-spacing:.02em}
.tags li{font-size:12px;padding:6px 0}
textarea.mono-area{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.55;height:auto;padding:10px 12px}
.field textarea{height:auto;padding:8px 10px}
@media (max-width:720px){.field input[type=file],.inline-form select{font-size:16px}.req-head{align-items:flex-start;flex-direction:column;gap:6px}}
.ops-block+.ops-block{margin-top:12px}.card h3.sub{font-size:13px;font-weight:600;margin:8px 0 8px;color:var(--ink-2)}
.unit-list{display:flex;flex-wrap:wrap;gap:8px}.unit-pick{display:flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:6px;padding:0 10px;height:38px;font-size:13px;cursor:pointer}
.unit-pick input{width:16px;height:16px;margin:0}
.timeline{list-style:none;margin:0;padding:0 0 0 16px;border-left:2px solid var(--line)}.timeline li{position:relative;padding:0 0 14px 12px}
.timeline li::before{content:"";position:absolute;left:-23px;top:4px;width:10px;height:10px;border-radius:50%;background:var(--accent);border:2px solid #fff}
.tl-head{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}.tl-head b{font-size:13px}
.thumbs{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.thumbs img{width:72px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--line);display:block}
.cal-wrap{overflow-x:auto}.cal{border-collapse:separate;border-spacing:0;font-size:12px;min-width:100%}
.cal th,.cal td{border-bottom:1px solid var(--line);border-right:1px solid var(--line);padding:4px;text-align:center;vertical-align:top;min-width:58px;height:34px}
.cal thead th{font-weight:500;color:var(--ink-2);background:#F8FAFC;position:sticky;top:0}.cal thead th b{font-size:14px;color:var(--ink)}
.cal .cal-name{text-align:left;min-width:150px;position:sticky;left:0;background:#fff;z-index:1}.cal thead .cal-name{background:#F8FAFC}
.cal .cal-name small{display:block;color:var(--muted);font-weight:400;font-size:11px}
.cal td.today,.cal th.today{background:#FFF7ED}.cal th.sun{color:var(--bad)}
.cal-group td{text-align:left;font-weight:600;background:#F1F5F9;color:var(--ink-2);height:auto;padding:6px 8px}
.chip{display:block;border-radius:4px;padding:2px 4px;margin-bottom:2px;font-size:11px;font-weight:600;text-decoration:none;white-space:nowrap;font-variant-numeric:tabular-nums}
.chip.rental{background:#DBEAFE;color:#1E3A8A}.chip.delivery,.chip.pickup{background:#FEF3C7;color:#92400E}
.cal-legend .chip{display:inline-block;margin:0 4px 0 12px}.cal-legend .chip:first-child{margin-left:0}
.trip-list{display:flex;flex-direction:column;gap:12px;max-width:640px}
.trip-card{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px;color:inherit;text-decoration:none;line-height:1.5}
.trip-card:hover{border-color:var(--accent)}.trip-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.trip-ref{font-size:13px;color:var(--ink-2)}.trip-next{margin-top:8px;color:var(--accent);font-weight:600}
.steps{list-style:none;display:flex;gap:4px;padding:0;margin:0 0 16px;flex-wrap:wrap}
.steps li{flex:1 1 0;min-width:90px;font-size:12px;text-align:center;padding:8px 4px;border-radius:6px;background:#F1F5F9;color:var(--muted)}
.steps li.done{background:#DCFCE7;color:#166534}.steps li.now{background:var(--accent);color:#fff;font-weight:600}
.phone-form{max-width:560px;display:flex;flex-direction:column;gap:14px}.phone-form .field{margin:0}
.btn.big{height:48px;padding:0 20px;font-size:15px}.btn.wide{width:100%;justify-content:center}
.map-btns{display:flex;gap:8px;margin-top:12px}.map-btns .btn{flex:1;justify-content:center}
.count-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}
.count-row input,.count-row select{width:120px;flex:none}.count-row select{width:190px}
.count-set{border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:0}.count-set legend{font-size:13px;padding:0 4px}
.count-3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
@media (max-width:820px){.phone-form input,.phone-form select,.phone-form textarea,.inline-form select,.unit-pick{font-size:16px;min-height:44px}.btn{min-height:44px}
  .steps li{min-width:0;font-size:11px;padding:8px 2px}.count-row select{width:160px}}
.dl.totals{max-width:440px;margin:4px 0 0 auto;grid-template-columns:1fr auto}.dl.totals dd{text-align:right}
CSS;
    }
}
