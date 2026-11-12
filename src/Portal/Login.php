<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Plugin;

/**
 * Canpas look for wp-login.php: colour logo, Work Sans, accent button, plain wording.
 * Behaviour is unchanged (WordPress still handles passwords, resets and 2FA); only presentation.
 */
final class Login
{
    public static function register(): void
    {
        add_action('login_enqueue_scripts', [self::class, 'styles']);
        add_filter('login_headerurl', static fn(): string => Router::url());
        add_filter('login_headertext', static fn(): string => 'Canpas');
        add_filter('login_title', static fn(string $t): string => str_replace([' &#8212; WordPress', ' — WordPress'], ' | Canpas Fleet', $t));
        add_filter('login_message', [self::class, 'message']);
        add_filter('gettext', [self::class, 'labels'], 10, 3);
    }

    public static function message(string $m): string
    {
        $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : 'login';
        if ($action !== 'login' || isset($_GET['loggedout'])) {
            return $m;
        }
        return $m . '<p class="cf-intro">Sign in with the email address registered to your account.</p>';
    }

    public static function labels(string $translated, string $text, string $domain): string
    {
        if ($domain !== 'default' || ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return $translated;
        }
        return match ($text) {
            'Username or Email Address' => 'Email',
            'Log In' => 'Sign in',
            'Remember Me' => 'Keep me signed in on this device',
            default => $translated,
        };
    }

    public static function styles(): void
    {
        $logo = esc_url(Plugin::url('assets/logo-color.png'));
        echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600&display=swap" rel="stylesheet">';
        echo '<style>.login h1 a{background-image:url("' . $logo . '")!important}</style>';
        echo <<<'HTML'
<style>
body.login{background:#F3F5F9;color:#1B2333;font-family:"Work Sans",system-ui,sans-serif;font-size:14px}
#login{width:min(400px,calc(100% - 32px));padding:8vh 0 32px}
.login h1 a{width:180px!important;height:54px!important;background-size:contain!important;background-position:left center!important;margin:0 0 16px!important}
.login h1{text-align:left}
.cf-intro{margin:0 0 12px;color:#5B6475}
.login form{border:0;border-radius:12px;box-shadow:0 1px 2px rgba(20,29,48,.06),0 8px 24px rgba(20,29,48,.08);padding:32px;margin-top:0}
.login label{font-size:12px;font-weight:500;letter-spacing:.02em;color:#3A4458}
.login form .input,.login input[type=text],.login input[type=password]{font-size:16px;min-height:40px;padding:0 12px;border:1px solid #CBD2DE;border-radius:6px;box-shadow:none}
.login form .input:focus{border-color:#1D4ED8;box-shadow:0 0 0 1px #1D4ED8}
.login .forgetmenot label{font-size:13px;font-weight:400}
.login .button-primary{float:none!important;width:100%;min-height:44px;margin-top:12px!important;font-size:14px!important;font-weight:600!important;background:#C2410C!important;border:0!important;border-radius:6px;box-shadow:none;text-shadow:none}
.login .button-primary:hover{background:#9A3412!important}
.login #nav,.login #backtoblog{padding:0 4px;font-size:13px}
.login #nav a,.login #backtoblog a{color:#1D4ED8!important}
.login .message,.login .notice,.login #login_error{border-left-color:#1D4ED8!important;border-radius:6px}
.login #login_error,.login .notice-error{border-left-color:#991B1B!important}
.language-switcher{display:none}
</style>
HTML;
    }
}
