<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Audit log (#114): direct logging, recent() filtering, prune(), and that a
 * real mutation chokepoint (integration save) produces an entry.
 */
class AuditServiceTest extends SimpleFormTestCase
{
    private function rowCount(): int
    {
        return (int) (new Query())->from('{{%simpleform_audit_log}}')->count();
    }

    public function testLogAndRecentFilter(): void
    {
        $this->requireCraft();
        $audit = Plugin::getInstance()->getAudit();

        $audit->log('form.save', 'form', 5, 'Contact');
        $audit->log('form.delete', 'form', 5, 'Contact');

        $all = $audit->recent(100);
        $this->assertGreaterThanOrEqual(2, count($all));

        $deletes = $audit->recent(100, 'form.delete');
        $this->assertNotEmpty($deletes);
        foreach ($deletes as $row) {
            $this->assertSame('form.delete', $row['action']);
        }
    }

    public function testPruneRemovesAgedRows(): void
    {
        $this->requireCraft();
        $audit = Plugin::getInstance()->getAudit();
        $audit->log('form.save', 'form', 1, 'Old');

        // Backdate the row beyond the retention window.
        $old = Db::prepareDateForDb((new \DateTime('now', new \DateTimeZone('UTC')))->modify('-400 days'));
        \Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_audit_log}}', ['dateCreated' => $old], ['action' => 'form.save'])
            ->execute();

        $before = $this->rowCount();
        $deleted = $audit->prune(365);
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertLessThan($before, $this->rowCount());

        // Disabled prune is a no-op.
        $this->assertSame(0, $audit->prune(0));
    }

    public function testIntegrationSaveIsAudited(): void
    {
        $this->requireCraft();

        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Audited hook';
        $integration->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($integration));

        $entries = Plugin::getInstance()->getAudit()->recent(50);
        $found = false;
        foreach ($entries as $row) {
            if ($row['targetType'] === 'integration' && (int) $row['targetId'] === (int) $integration->id) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'saving an integration should write an audit entry');
    }
}
