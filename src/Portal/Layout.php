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
    public static function page(Actor $actor, string $active, string $title, string $body, string $crumb = ''): void
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
        $search = Nav::icon('search', 16);
        $out = Nav::icon('logout');

        $chrome = <<<HTML
<a class="skip" href="#main">Skip to main content</a>
<div class="shell">
  <nav class="side" aria-label="Main menu">
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
      {$crumbHtml}
      <h1>{$title}</h1>
      {$body}
    </main>
  </div>
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
.brand{display:flex;flex-direction:column;gap:6px;padding:4px 12px 20px}
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
@media (max-width:820px){
  .side{flex:1 1 100%;flex-direction:row;flex-wrap:nowrap;overflow-x:auto;padding:8px;gap:4px;align-items:center}
  .brand{flex-direction:row;align-items:center;padding:4px 8px}.brand span{display:none}.brand img{height:22px}
  .nav-group{flex-direction:row;padding:0;gap:4px}.nav-label{display:none}
  .nav-item{height:44px;white-space:nowrap}.nav-item.logout{margin-top:0}
  .top{padding:0 16px}.search{display:none}
  main{padding:16px}
  body{font-size:16px}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
CSS;
    }
}
