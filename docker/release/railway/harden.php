<?php

/**
 * Rotate the OpenEMR admin password on container start.
 *
 * The demo deployment restores a full database dump, which carries the admin
 * credentials from whatever machine produced it -- in practice the documented
 * default of admin/pass. This instance is reachable on a public URL, so the
 * password is rotated from OE_ADMIN_PASSWORD before Apache ever accepts a
 * request.
 *
 * Why not unlock_admin.php: that routes through AuthUtils::updatePassword(),
 * which treats activeUser === targetUser as a self-service change and verifies
 * the *current* password against a hardcoded value. It succeeds once and then
 * fails on every subsequent boot. This writes the hash the same way
 * Installer::add_initial_user() does -- password_hash() straight into
 * users_secure.password, which AuthHash::passwordVerify() checks with a plain
 * password_verify().
 *
 * Invoked by railway-entrypoint.sh as:
 *     su-exec apache php /opt/openemr-railway/harden.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Database\QueryUtils;

if (php_sapi_name() !== 'cli') {
    exit(1);
}

// Mirrors the bootstrap used by docker/release/utilities/unlock_admin.php.
$_GET['site'] = 'default';
$ignoreAuth = 1;

require_once '/var/www/localhost/htdocs/openemr/interface/globals.php';

$username = getenv('OE_USER') ?: 'admin';
$password = getenv('OE_ADMIN_PASSWORD');

// getenv() returns false when unset, '' when set-but-empty. Both are refusals:
// the entrypoint already guards this, but never silently accept a blank here.
if ($password === false || $password === '') {
    fwrite(STDERR, "[harden] OE_ADMIN_PASSWORD is empty - refusing to continue\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "[harden] password_hash() failed\n");
    exit(1);
}

$failed = false;

try {
    $existing = QueryUtils::fetchSingleValue(
        'SELECT `id` FROM `users_secure` WHERE `username` = ?',
        'id',
        [$username]
    );

    if ($existing === null) {
        fwrite(STDERR, "[harden] no users_secure row for '{$username}' - seed may be incomplete\n");
        exit(1);
    }

    // Also clears the lockout counters, so a dump captured mid-lockout does not
    // arrive with the admin account already blocked.
    QueryUtils::sqlStatementThrowException(
        'UPDATE `users_secure`
            SET `password` = ?,
                `last_update_password` = NOW(),
                `login_fail_counter` = 0,
                `auto_block_emailed` = 0
          WHERE `username` = ?',
        [$hash, $username]
    );

    QueryUtils::sqlStatementThrowException(
        'UPDATE `users` SET `active` = 1 WHERE `username` = ?',
        [$username]
    );
} catch (Exception $e) {
    // The message may carry SQL or connection detail, so it is written to the
    // container log only -- never to a response. Record the failure and fall
    // through, rather than exiting from inside the catch.
    fwrite(STDERR, "[harden] failed to rotate admin password: " . $e->getMessage() . "\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "[harden] admin password rotated for '{$username}'\n");
exit(0);
