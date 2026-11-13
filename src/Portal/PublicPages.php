<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Plugin;

/**
 * Pages open to signed-out visitors: sign in (/fleet/login) and company sign-up (/fleet/register).
 * Sign-in posts to wp-login.php, so WordPress still checks the password and two-factor plugins
 * still run; only the page people see is ours.
 */
final class PublicPages
{
    public static function register(): void
    {
        add_filter('login_url', [self::class, 'loginUrl'], 10, 3);
        add_action('wp_login_failed', [self::class, 'loginFailed']);
    }

    /** Every "please sign in" link in WordPress points to the Canpas sign-in page. */
    public static function loginUrl(string $url, string $redirect, bool $forceReauth): string
    {
        $u = Router::url('login');
        if ($redirect !== '') {
            $u = add_query_arg('redirect_to', rawurlencode($redirect), $u);
        }
        return $forceReauth ? add_query_arg('reauth', '1', $u) : $u;
    }

    /** A wrong password on our page comes back to our page, not to wp-login.php. */
    public static function loginFailed(): void
    {
        if (empty($_POST['cf_login'])) {
            return;
        }
        $back = add_query_arg('login', 'failed', Router::url('login'));
        $to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
        if ($to !== '') {
            $back = add_query_arg('redirect_to', rawurlencode($to), $back);
        }
        wp_safe_redirect($back);
        exit;
    }

    public static function login(): void
    {
        // WordPress refuses a sign-in without its test cookie; set it as wp-login.php does.
        $secure = 'https' === parse_url(wp_login_url(), PHP_URL_SCHEME);
        setcookie(TEST_COOKIE, 'WP Cookie check', 0, COOKIEPATH, COOKIE_DOMAIN, $secure, true);
        if (SITECOOKIEPATH !== COOKIEPATH) {
            setcookie(TEST_COOKIE, 'WP Cookie check', 0, SITECOOKIEPATH, COOKIE_DOMAIN, $secure, true);
        }

        $redirect = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash((string) $_GET['redirect_to'])) : Router::url();
        $failed = isset($_GET['login']) && $_GET['login'] === 'failed';
        $out = isset($_GET['loggedout']);
        $action = esc_url(site_url('wp-login.php', 'login_post'));
        $lost = esc_url(wp_lostpassword_url());
        $register = esc_url(Router::url('register'));
        $logoColor = esc_url(Plugin::url('assets/logo-color.png'));
        $redirectE = esc_attr($redirect);

        $notice = '';
        if ($failed) {
            $notice = '<p class="alert bad" role="alert">The email or password is not right. Try again, or reset your password.</p>';
        } elseif ($out) {
            $notice = '<p class="alert" role="status">You have signed out.</p>';
        }

        $form = <<<HTML
<form class="auth-card" method="post" action="{$action}">
  <img class="auth-logo" src="{$logoColor}" alt="Canpas" width="134" height="40">
  <div class="auth-title"><h2>Sign in</h2><p>Use the email address registered to your account.</p></div>
  {$notice}
  <div class="field"><label for="user_login">Email</label>
    <input id="user_login" name="log" type="text" autocomplete="username" inputmode="email" placeholder="name@company.com" required autofocus></div>
  <div class="field"><div class="label-row"><label for="user_pass">Password</label><a href="{$lost}">Forgot password?</a></div>
    <div class="pw"><input id="user_pass" name="pwd" type="password" autocomplete="current-password" required>
    <button type="button" class="pw-toggle" data-toggle-password aria-controls="user_pass" aria-label="Show password">Show</button></div></div>
  <label class="check"><input type="checkbox" name="rememberme" value="forever"> Keep me signed in on this device</label>
  <input type="hidden" name="redirect_to" value="{$redirectE}">
  <input type="hidden" name="testcookie" value="1">
  <input type="hidden" name="cf_login" value="1">
  <button type="submit" class="btn primary block">Sign in</button>
  <div class="or"><span>New customer?</span></div>
  <a class="btn block" href="{$register}">Request a company account</a>
  <p class="fine">Canpas staff: after your password you will be asked for the 6-digit code from your authenticator app.</p>
</form>
HTML;
        self::shell('Sign in', $form);
    }

    public static function registerPage(): void
    {
        $login = esc_url(Router::url('login'));
        $logoColor = esc_url(Plugin::url('assets/logo-color.png'));
        $form = <<<HTML
<form class="auth-card wide" data-endpoint="signup" data-after="message" enctype="multipart/form-data" novalidate>
  <img class="auth-logo" src="{$logoColor}" alt="Canpas" width="134" height="40">
  <div class="auth-title"><h2>Request a company account</h2><p>Canpas reviews every request, usually within one working day. You can book once your company is approved.</p></div>
  <fieldset><legend>Company</legend>
    <div class="field"><label for="company_name">Registered company name</label><input id="company_name" name="company_name" required maxlength="191" autocomplete="organization"></div>
    <div class="two">
      <div class="field"><label for="tin">TIN</label><input id="tin" name="tin" required inputmode="numeric" placeholder="123-456-789-000"></div>
      <div class="field"><label for="phone">Mobile number</label><input id="phone" name="phone" required inputmode="tel" autocomplete="tel" placeholder="0917 123 4567"></div>
    </div>
    <div class="field"><label for="address">Company address</label><input id="address" name="address" required maxlength="255" autocomplete="street-address"></div>
    <div class="field"><label for="permit">Business permit or DTI/SEC registration</label>
      <input id="permit" name="permit" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" required>
      <small>PDF or photo, up to 10 MB. Only Canpas staff can see it.</small></div>
  </fieldset>
  <fieldset><legend>Your sign-in</legend>
    <div class="field"><label for="contact_name">Your full name</label><input id="contact_name" name="contact_name" required autocomplete="name"></div>
    <div class="field"><label for="email">Work email</label><input id="email" name="email" type="email" required autocomplete="email"></div>
    <div class="field"><label for="password">Password</label>
      <div class="pw"><input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
      <button type="button" class="pw-toggle" data-toggle-password aria-controls="password" aria-label="Show password">Show</button></div>
      <small>At least 10 characters.</small></div>
  </fieldset>
  <div class="hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
  <p class="form-error" role="alert" hidden></p>
  <button type="submit" class="btn primary block">Send request</button>
  <p class="fine">By sending this request you agree that Canpas keeps your company details and documents to manage your rentals, as required by the Data Privacy Act. Already have an account? <a href="{$login}">Sign in</a>.</p>
</form>
HTML;
        self::shell('Request a company account', $form);
    }

    private static function shell(string $title, string $card): void
    {
        $logoWhite = esc_url(Plugin::url('assets/logo-white.png'));
        $year = gmdate('Y');
        $check = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#7FD0A6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l4 4 10-10"/></svg>';
        $body = <<<HTML
<div class="auth">
  <section class="auth-brand">
    <div class="auth-brand-top"><img src="{$logoWhite}" alt="Canpas" width="160" height="48"><span>Fleet &amp; Equipment Portal</span></div>
    <div class="auth-pitch">
      <p class="auth-headline">Book trucks and equipment. Track every delivery and return.</p>
      <ul>
        <li>{$check}<span>Approved company accounts only — every order, document and payment belongs to your company.</span></li>
        <li>{$check}<span>Live status from request to return, with delivery photos and signed receipts.</span></li>
        <li>{$check}<span>Agreements, IDs and statements kept privately in one place.</span></li>
      </ul>
    </div>
    <p class="auth-foot">© {$year} Canpas Philippines · Private system for approved customers and Canpas staff</p>
  </section>
  <main id="main" class="auth-main">{$card}</main>
</div>
HTML;
        Layout::bare($title, $body, self::css());
    }

    private static function css(): string
    {
        return <<<'CSS'
.auth{min-height:100vh;display:flex;flex-wrap:wrap}
.auth-brand{flex:1 1 480px;background:var(--navy);color:#E7ECF5;padding:56px 64px;display:flex;flex-direction:column;justify-content:space-between;gap:48px}
.auth-brand-top{display:flex;flex-direction:column;align-items:flex-start;gap:10px}.auth-brand-top img{height:48px;width:auto}
.auth-brand-top span{font-size:12px;color:#9AA6BC;letter-spacing:.08em;text-transform:uppercase}
.auth-pitch{max-width:460px;display:flex;flex-direction:column;gap:24px}
.auth-headline{margin:0;font-size:32px;line-height:1.2;font-weight:600;color:#fff}
.auth-pitch ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:14px;color:#C9D2E3}
.auth-pitch li{display:flex;gap:12px;align-items:flex-start}.auth-pitch svg{flex:none;margin-top:2px}
.auth-foot{margin:0;font-size:12px;color:#8592A8}
.auth-main{flex:1 1 480px;display:flex;align-items:center;justify-content:center;padding:48px 24px;max-width:none}
.auth-card{width:100%;max-width:400px;background:#fff;border-radius:12px;box-shadow:0 1px 2px rgba(20,29,48,.06),0 8px 24px rgba(20,29,48,.08);padding:40px;display:flex;flex-direction:column;gap:18px}
.auth-card.wide{max-width:520px}
.auth-logo{height:40px;width:auto;align-self:flex-start}
.auth-title{display:flex;flex-direction:column;gap:6px}.auth-title h2{margin:0;font-size:22px;line-height:1.25}.auth-title p{margin:0;color:var(--muted)}
.auth-card .field input{height:40px;font-size:14px}
.auth-card small{font-size:12px;color:var(--muted)}
.label-row{display:flex;justify-content:space-between;align-items:baseline}.label-row a{font-size:12px;font-weight:500}
.pw{display:flex;align-items:center;border:1px solid var(--line-2);border-radius:6px;background:#fff}
.pw input{border:0!important;flex:1;min-width:0}
.pw-toggle{height:38px;padding:0 12px;border:0;background:transparent;font:inherit;font-size:12px;font-weight:500;color:var(--ink-2);cursor:pointer}
.check{display:flex;align-items:center;gap:8px;font-size:14px;color:var(--ink-2);cursor:pointer}.check input{width:16px;height:16px;accent-color:var(--accent)}
.btn.block{width:100%;height:44px;font-size:14px}
.or{display:flex;align-items:center;gap:12px;color:#8592A8;font-size:12px}.or::before,.or::after{content:"";flex:1;height:1px;background:var(--line)}
.fine{margin:0;font-size:12px;line-height:1.5;color:var(--muted)}
.alert{margin:0;padding:10px 12px;border-radius:6px;background:var(--info-bg);color:var(--info);font-size:13px}.alert.bad{background:var(--bad-bg);color:var(--bad)}
fieldset{border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
legend{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;padding:0}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field input[type=file]{height:auto;padding:8px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
@media (max-width:1024px){.auth-brand{display:none}.auth-main{flex:1 1 100%;min-height:100vh;min-height:100dvh;align-items:center;justify-content:center;padding:32px 16px}}
@media (max-width:820px){.auth-card{padding:24px}.two{grid-template-columns:1fr}.auth-main{padding:24px 16px}}
CSS;
    }
}
