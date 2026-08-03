<?php

declare(strict_types=1);

/**
 * Global UUID helpers — thin wrappers ke Ezdoc\UUID class.
 *
 * Backward compat untuk existing procedural code. New code sebaiknya pakai
 * Ezdoc\UUID::v7() langsung.
 */

if (defined('EZDOC_UUID_LOADED')) return;
define('EZDOC_UUID_LOADED', true);

function ezdoc_uuid_v7(): string
{
    return \Ezdoc\UUID::v7();
}

function ezdoc_uuid_v4(): string
{
    return \Ezdoc\UUID::v4();
}

function ezdoc_uuid_v7_timestamp_ms(string $uuid): ?int
{
    return \Ezdoc\UUID::extractTimestampMs($uuid);
}
