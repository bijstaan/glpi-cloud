<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * A cloud account, plus the two buttons that belong next to one.
 *
 * **Check** proves the credentials work before anybody waits six hours to find
 * out they do not. It is a read-only call into the provider and it stores what
 * the provider says it authenticated as.
 *
 * **Sync now** runs the sweep in this request. It is deliberately gated on
 * UPDATE rather than READ: a sweep costs the entity's API quota.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Settings;
use GlpiPlugin\Glpicloud\Sync;

Session::checkRight(Account::$rightname, READ);

$item = new Account();
$id   = (int) ($_GET['id'] ?? 0);

if (!empty($_POST['add'])) {
    Session::checkRight(Account::$rightname, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    Html::redirect($newid ? Account::getFormURLWithID($newid) : Account::getSearchURL());
} elseif (!empty($_POST['update'])) {
    Session::checkRight(Account::$rightname, UPDATE);
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (!empty($_POST['purge'])) {
    Session::checkRight(Account::$rightname, PURGE);
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, 1);
    Html::redirect(Account::getSearchURL());
} elseif (!empty($_POST['check'])) {
    Session::checkRight(Account::$rightname, UPDATE);
    $item->check((int) $_POST['id'], UPDATE);

    $provider = $item->provider();

    if ($provider === null) {
        Session::addMessageAfterRedirect(
            __s('That provider plugin is not installed.', 'glpicloud'),
            false,
            ERROR
        );
    } else {
        try {
            $result = $provider->check(Sync::accountContext($item));

            if ($result['ok']) {
                $item->update([
                    'id'       => (int) $item->getID(),
                    'identity' => $result['identity'],
                ]);
                Session::addMessageAfterRedirect(
                    sprintf(__s('Credentials work: %s', 'glpicloud'), $result['identity'] ?: $result['message']),
                    true,
                    INFO
                );
            } else {
                Session::addMessageAfterRedirect(
                    sprintf(__s('The provider refused these credentials: %s', 'glpicloud'), $result['message']),
                    false,
                    ERROR
                );
            }
        } catch (Throwable $e) {
            // A provider that throws here is a provider bug, and the page it
            // would otherwise take down is the one you fix credentials on.
            Session::addMessageAfterRedirect(
                sprintf(__s('The provider could not be asked: %s', 'glpicloud'), $e->getMessage()),
                false,
                ERROR
            );
        }
    }

    Html::back();
} elseif (!empty($_POST['sync'])) {
    Session::checkRight(Account::$rightname, UPDATE);
    $item->check((int) $_POST['id'], UPDATE);

    if (!Settings::isEnabled()) {
        Session::addMessageAfterRedirect(
            __s('Cloud sync is switched off under Setup > Cloud, so nothing was collected.', 'glpicloud'),
            false,
            WARNING
        );
    } else {
        $summary = Sync::account($item);

        Session::addMessageAfterRedirect(
            sprintf(
                __s('Sync %1$s: %2$d seen, %3$d new, %4$d changed, %5$d gone.', 'glpicloud'),
                $summary['status'],
                $summary['seen'],
                $summary['new'],
                $summary['changed'],
                $summary['gone']
            ),
            true,
            $summary['errors'] === [] ? INFO : WARNING
        );

        foreach ($summary['errors'] as $error) {
            Session::addMessageAfterRedirect(htmlspecialchars($error), false, ERROR);
        }
    }

    Html::back();
}

Html::header(
    Account::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_account', 'assets')
        : 'assets',
    'glpicloud_account'
);

echo "<div class='glpicloud-surface'>";

if ($id > 0) {
    $item->display(['id' => $id]);

    if (Session::haveRight(Account::$rightname, UPDATE)) {
        echo "<form method='post' class='mt-2'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<button type='submit' name='check' value='1' class='btn btn-outline-primary me-2'>"
           . __s('Check credentials', 'glpicloud') . '</button>';
        echo "<button type='submit' name='sync' value='1' class='btn btn-outline-primary'>"
           . __s('Sync now', 'glpicloud') . '</button>';
        echo '</form>';
    }
} else {
    // display() runs a read check that a not-yet-existing row can never pass,
    // so the "new" form goes straight to showForm().
    $item->showForm(0);
}

echo '</div>';

Html::footer();
