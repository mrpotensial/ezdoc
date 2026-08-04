<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * Turns the actor ids stored in audit records into names a person can read.
 *
 * ## Why the library cannot do this itself
 *
 * Everything ezdoc records about who did something is an integer, because that
 * is all {@see RoleProvider::currentUserId()} promises. Where that integer maps
 * to a human — which table, which column, whether names live in the same
 * database at all — belongs to the host application. The library asking would
 * mean the library knowing, and it does not.
 *
 * So it asks the host instead, and works without an answer.
 *
 * ## Why names rather than ids
 *
 * An audit trail is read by people, during arguments. "#18 signed this" answers
 * nothing without a second lookup nobody has time for. Internal ids are also
 * the one thing in the record with no business meaning: they leak the shape and
 * size of the user table and identify nobody outside this system.
 *
 * Every audit UI worth copying resolves them. Confluence and Google Docs show
 * display names; DocuSign's Certificate of Completion shows name and email.
 * None of them show a database key.
 *
 * ## Why the whole batch at once
 *
 * A history panel renders sixty rows. One lookup per row is sixty queries for
 * one screen — the N+1 problem, and the reason Doctrine and Eloquent both grew
 * eager loading. The signature is plural so the obvious implementation is also
 * the right one.
 *
 * ## Wiring it
 *
 * Optional. Without it, the history falls back to `#18`, which is worse but
 * still usable — a history with no actor at all would not be.
 *
 * ```php
 * // Anywhere before the request is served — typically the bootstrap hook:
 * $GLOBALS['EZDOC_ACTOR_DIRECTORY'] = new MyDirectory($pdo);
 *
 * // Or, for a directory that can build itself:
 * define('EZDOC_ACTOR_DIRECTORY_CLASS', MyDirectory::class);
 * ```
 *
 * Same resolution order as the OTP provider: a ready-made instance wins, then a
 * static `fromGlobals()` factory, then a no-argument constructor.
 */
interface ActorDirectory
{
    /**
     * Display names for the given actor ids.
     *
     * Implementations SHOULD issue one query. Ids with no match SHOULD be
     * omitted rather than mapped to a placeholder — the caller decides what an
     * unknown actor looks like, and that decision is about presentation.
     *
     * Implementations MUST NOT throw for unknown ids, an empty list, or ids
     * that do not exist. This runs while rendering an audit trail; a directory
     * that fails should cost names, not the whole history.
     *
     * @param list<int> $ids Actor ids, already unique.
     * @return array<int,string> id => display name, for the ids that resolved.
     */
    public function displayNames(array $ids): array;
}
