<?php

declare(strict_types=1);

/**
 * CLI — remove one-time codes that lapsed without ever being used.
 *
 * ## Usage
 *
 * ```
 * php ezdoc/cli/purge-expired-vouchers.php             # dry run, default
 * php ezdoc/cli/purge-expired-vouchers.php apply
 * ```
 *
 * Meant for a daily schedule. Dry run first: a cleanup command whose only mode
 * is "delete and tell you afterwards" gets run once, nervously, then never
 * scheduled.
 *
 * ## What is kept, and why
 *
 * **Claimed vouchers are never removed.** A claimed row is the link between a
 * signature and the code that authorised it — which number asked for it, when,
 * whether the number came from the document or was typed. That is the question
 * a dispute actually asks, and it is the reason the table is worth keeping at
 * all. Only codes nobody spent are noise.
 *
 * ## Grace period
 *
 * Unclaimed rows are kept for a while past their expiry rather than deleted the
 * moment they lapse. A code that expired ten minutes ago is still evidence:
 * "the patient says they requested one and it never worked" is answerable only
 * while the row exists. A day is long enough for that conversation to happen.
 *
 * The rows are tiny — a phone number and a hash — so keeping them costs
 * essentially nothing next to what they answer.
 */

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$mode = $argv[1] ?? 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], true)) {
    fwrite(STDERR, "Usage: php purge-expired-vouchers.php [dry-run|apply]\n");
    exit(2);
}
$apply = $mode === 'apply';

/** Hours a lapsed, unclaimed voucher is kept before it can be removed. */
$graceHours = (int) (getenv('EZDOC_VOUCHER_GRACE_HOURS') ?: 24);

if (!isset($conn) || !($conn instanceof mysqli)) {
    $bootstrap = getenv('EZDOC_BOOTSTRAP') ?: '';
    if ($bootstrap === '' || !is_file($bootstrap)) {
        fwrite(STDERR, "No database handle. Point EZDOC_BOOTSTRAP at the file that creates \$conn.\n");
        exit(2);
    }
    require_once $bootstrap;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "The bootstrap file did not produce a mysqli \$conn.\n");
    exit(2);
}

$db = new \Ezdoc\Db\Mysqli\MysqliConnection($conn);

if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_otp_vouchers') === []) {
    fwrite(STDERR, "Table `ezdoc_otp_vouchers` does not exist — nothing to purge.\n");
    exit(0);
}

$repo = new \Ezdoc\Signature\Otp\OtpVoucherRepository($db);
$cutoff = (new \DateTimeImmutable('-' . $graceHours . ' hours'))->format('Y-m-d H:i:s');

printf("Grace   : %d hours past expiry\n", $graceHours);
printf("Cutoff  : %s\n", $cutoff);
printf("Mode    : %s\n\n", $apply ? 'APPLY — rows will be deleted' : 'dry run — nothing is deleted');

$claimed = $repo->countClaimed();
$stale = $repo->countExpired($cutoff);

printf("Claimed, kept as audit trail : %d\n", $claimed);
printf("Lapsed and never used        : %d\n\n", $stale);

if ($stale === 0) {
    echo "Nothing to remove.\n";
    exit(0);
}

if (!$apply) {
    echo "Nothing was deleted. Re-run with `apply` when the numbers look right.\n";
    exit(0);
}

$removed = $repo->purgeExpired($cutoff);
printf("Removed: %d\n", $removed);

exit(0);
