<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * `ip` dedupe fingerprint decoupled from the (possibly masked) `sourceIp`
 * display column (#326, fixing #315). Adds `ipHash` — a SHA-256 hash of the
 * submitter's *full* IP, always computed from the raw address regardless of
 * `ipCapturePolicy` — so anonymized-mode masking of `sourceIp` can no longer
 * collide distinct visitors sharing an IPv4 /24 or IPv6 /48 into a
 * false-positive duplicate, nor cause a genuine repeat to be missed when the
 * masking policy changes between submissions.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260710_000001_add_ip_hash extends Migration
{
    public function safeUp(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'ipHash')) {
            $this->addColumn($submissions, 'ipHash', $this->char(64)->after('sourceIp'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if ($this->db->columnExists($submissions, 'ipHash')) {
            $this->dropColumn($submissions, 'ipHash');
        }

        return true;
    }
}
