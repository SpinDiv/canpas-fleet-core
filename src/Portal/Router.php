<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Front-end portal under /fleet/. Every page is rendered by the plugin outside the theme.
 * Portal users never see wp-admin or the WordPress toolbar.
 */
final class Router
{
    private const REWRITE_VERSION = '1';

    public static function register(): void
    {
        add_rewrite_rule('^fleet/?$', 'index.php?cf_page=home', 'top');
        add_rewrite_rule('^fleet/([a-z0-9-]+)/?$', 'index.php?cf_page=$matches[1]', 'top');
        add_filter('query_vars', static fn(array $v): array => array_merge($v, ['cf_page']));
        add_action('template_redirect', [self::class, 'dispatch'], 0);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 10, 3);
        add_filter('show_admin_bar', static fn(bool $show): bool => $show && current_user_can('manage_options'));
        add_action('admin_init', [self::class, 'keepOutOfAdmin'], 0);

        if (get_option('canpas_fleet_rewrite_version') !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('canpas_fleet_rewrite_version', self::REWRITE_VERSION, false);
        }
    }

    public static function url(string $slug = ''): string
    {
        return home_url('/fleet/' . ($slug !== '' ? trailingslashit($slug) : ''));
    }

    public static function dispatch(): void
    {
        $page = sanitize_key((string) get_query_var('cf_page'));
        if ($page === '') {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::url($page === 'home' ? '' : $page)));
            exit;
        }

        try {
            $actor = Actor::current();
        } catch (DomainError $e) {
            // Website administrators have no portal data access by design.
            if (current_user_can('manage_options')) {
                wp_safe_redirect(admin_url());
                exit;
            }
            Layout::message('No portal access', 'This account is not set up for the Canpas portal. Please contact Canpas.', 403);
            exit;
        }

        if ($actor->role === Roles::PENDING) {
            Layout::message('Your account is awaiting approval', 'Canpas is reviewing your company details. You will get an email as soon as you can book.');
            exit;
        }
        if (!$actor->companyActive()) {
            Layout::message('Your company account is on hold', 'Bookings are paused for your company. Please contact Canpas to resolve this.', 403);
            exit;
        }

        if ($page === 'home') {
            $home = Nav::home($actor);
            if ($home === '') {
                Layout::message('Welcome to Canpas', 'Your account has no pages yet. Please contact Canpas.');
                exit;
            }
            $page = $home;
        }

        if (!isset(Nav::PAGES[$page])) {
            Layout::message('Page not found', 'This page does not exist.', 404, ['label' => 'Go to my dashboard', 'href' => self::url()]);
            exit;
        }
        if (!Nav::canOpen($actor, $page)) {
            Layout::message('Not available for your account', 'Your role cannot open this page.', 403, ['label' => 'Go to my dashboard', 'href' => self::url()]);
            exit;
        }

        Pages::render($actor, $page);
        exit;
    }

    /** Portal users never land in wp-admin; AJAX and admin-post keep working. */
    public static function keepOutOfAdmin(): void
    {
        if (wp_doing_ajax() || current_user_can('manage_options') || ($GLOBALS['pagenow'] ?? '') === 'admin-post.php') {
            return;
        }
        $user = wp_get_current_user();
        if (array_intersect((array) $user->roles, Roles::all())) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    /** @param \WP_User|\WP_Error $user */
    public static function loginRedirect(string $to, string $requested, $user): string
    {
        if ($user instanceof \WP_User && array_intersect((array) $user->roles, Roles::all())) {
            return str_contains($requested, '/fleet/') ? $requested : self::url();
        }
        return $to;
    }
}
