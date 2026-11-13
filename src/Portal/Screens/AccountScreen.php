<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Account;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Tenancy\Actor;

/** Your own account: who you are, change password, log out. Open to every role. */
final class AccountScreen
{
    public static function render(Actor $actor): void
    {
        $user = wp_get_current_user();
        $rows = [
            'Name' => $user->display_name,
            'Role' => str_replace('Canpas: ', '', Roles::LABELS[$actor->role] ?? ''),
            'Email' => $user->user_email,
        ];
        if ($actor->companyId !== null) {
            $rows['Company'] = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $actor->companyId));
        }
        $list = '<ul class="list">';
        foreach ($rows as $k => $v) {
            $list .= '<li><span class="muted">' . Ui::e($k) . '</span><b>' . Ui::e($v) . '</b></li>';
        }
        $list .= '</ul>';
        $logout = '<p class="account-out"><a class="btn danger wide" href="' . esc_url(wp_logout_url(Router::url())) . '">Log out</a></p>';

        $form = Ui::form('account/password', [
            'current' => ['Current password', 'password-current', true],
            'new' => ['New password', 'password', true],
            'confirm' => ['Repeat new password', 'password', true],
        ], [], [], 'Change password');
        $hint = '<p class="small muted">At least ' . Account::MIN_LENGTH . ' characters. You stay signed in on this device.</p>';

        Layout::page($actor, 'account', 'Account', '<div class="split">'
            . Ui::card('Your details', $list . $logout)
            . Ui::card('Change password', $hint . $form)
            . '</div>');
    }
}
