<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Install migration — the plugin's full schema in one place.
 *
 * Collapsed from the original `m240614_000001_init` + the incremental column/table
 * migrations (pre-launch, so there is no upgrade history to preserve). Craft runs
 * this automatically on `plugin/install`; no separate `migrate/all` is needed.
 *
 * @author Fabian Haefliger
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        // Forms — SHARED data, keyed by the element id (one row per element).
        $this->createTable('{{%simpleform_forms}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(255)->notNull(),
            'name' => $this->string(255)->notNull(),
            'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
            'allowSaveResume' => $this->boolean()->notNull()->defaultValue(false),
            'allowEditing' => $this->boolean()->notNull()->defaultValue(false),
            'editWindowMinutes' => $this->integer()->notNull()->defaultValue(0),
            'quizMode' => $this->boolean()->notNull()->defaultValue(false),
            'quizGradeBands' => $this->text(),
            'autoCaptureAttribution' => $this->boolean()->notNull()->defaultValue(false),
            'capturePartials' => $this->boolean()->notNull()->defaultValue(false),
            'renderMode' => $this->string(20)->notNull()->defaultValue('standard'),
            'preventDuplicates' => $this->boolean()->notNull()->defaultValue(false),
            'duplicateWindowMinutes' => $this->integer()->notNull()->defaultValue(0),
            'duplicateKey' => $this->string(16)->notNull()->defaultValue('email'),
            'templatePath' => $this->string(255),
            'useCustomTemplate' => $this->boolean()->notNull()->defaultValue(false),
            'requireLogin' => $this->boolean()->notNull()->defaultValue(false),
            'submissionsPerUser' => $this->integer(),
            'guestLimitKey' => $this->string(16)->notNull()->defaultValue('none'),
            'openDate' => $this->dateTime(),
            'closeDate' => $this->dateTime(),
            'submissionLimit' => $this->integer()->unsigned(),
            'postSubmitAction' => $this->string(20)->notNull()->defaultValue('message'),
            'redirectEntryId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->addPrimaryKey(null, '{{%simpleform_forms}}', ['id']);
        $this->createIndex(null, '{{%simpleform_forms}}', ['handle'], true);
        $this->addForeignKey(null, '{{%simpleform_forms}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', 'CASCADE');

        // Per-site form content — translatable columns, one row per (form, site).
        $this->createTable('{{%simpleform_forms_sites}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'description' => $this->text(),
            'emailTo' => $this->string(255),
            'emailSubject' => $this->string(255),
            'emailReplyTo' => $this->string(255),
            'emailBody' => $this->text(),
            'loginRequiredMessage' => $this->text(),
            'userLimitMessage' => $this->text(),
            'closedMessage' => $this->text(),
            'submitMessage' => $this->text(),
            'errorMessage' => $this->text(),
            'redirectUrl' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_forms_sites}}', ['formId', 'siteId'], true);
        $this->addForeignKey(null, '{{%simpleform_forms_sites}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_forms_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        // Fields — SHARED structural data.
        $this->createTable('{{%simpleform_fields}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'type' => $this->string(50)->notNull(),
            'name' => $this->string(255)->notNull(),
            'required' => $this->boolean()->notNull()->defaultValue(false),
            'config' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_fields}}', ['formId']);
        $this->addForeignKey(null, '{{%simpleform_fields}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');

        // Per-site field content — translatable label/help/option labels.
        $this->createTable('{{%simpleform_fields_sites}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'label' => $this->string(255),
            'helpText' => $this->text(),
            'optionLabels' => $this->json(),
            'errorMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_fields_sites}}', ['fieldId', 'siteId'], true);
        $this->addForeignKey(null, '{{%simpleform_fields_sites}}', ['fieldId'], '{{%simpleform_fields}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_fields_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        // Submissions — element-bound (id is the element id).
        $this->createTable('{{%simpleform_submissions}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'data' => $this->json(),
            'userId' => $this->integer(),
            'readStatus' => $this->enum('readStatus', ['new', 'read', 'archived', 'spam'])->defaultValue('new'),
            'workflowStatus' => $this->string(64),
            'spamReason' => $this->string(64),
            'sourceIp' => $this->string(45),
            'paymentStatus' => $this->string(20),
            'paymentAmount' => $this->decimal(14, 4),
            'orderId' => $this->integer(),
            'couponCode' => $this->string(64),
            'discountAmount' => $this->decimal(14, 4),
            'quizScore' => $this->integer(),
            'quizMaxScore' => $this->integer(),
            'quizPercentage' => $this->integer(),
            'quizGrade' => $this->string(32),
            'attribution' => $this->json(),
            'editTokenHash' => $this->char(64),
            'editTokenExpires' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_submissions}}', ['formId']);
        $this->createIndex(null, '{{%simpleform_submissions}}', ['siteId']);
        $this->createIndex(null, '{{%simpleform_submissions}}', ['orderId']);
        $this->createIndex(null, '{{%simpleform_submissions}}', ['workflowStatus']);
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['userId'], '{{%users}}', ['id'], 'SET NULL', 'CASCADE');

        // Integrations — global connector definitions.
        $this->createTable('{{%simpleform_integrations}}', [
            'id' => $this->primaryKey(),
            'type' => $this->string(50)->notNull(),
            'name' => $this->string(255)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'settings' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Form ↔ integration attachments.
        $this->createTable('{{%simpleform_form_integrations}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'integrationId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_form_integrations}}', ['formId', 'integrationId'], true);
        $this->createIndex(null, '{{%simpleform_form_integrations}}', ['integrationId']);
        $this->addForeignKey(null, '{{%simpleform_form_integrations}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_form_integrations}}', ['integrationId'], '{{%simpleform_integrations}}', ['id'], 'CASCADE', 'CASCADE');

        // Integration dispatch log.
        $this->createTable('{{%simpleform_integration_logs}}', [
            'id' => $this->primaryKey(),
            'integrationId' => $this->integer()->notNull(),
            'submissionId' => $this->integer(),
            'status' => $this->enum('status', ['pending', 'success', 'failed'])->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'responseCode' => $this->integer(),
            'elementId' => $this->integer(),
            'elementType' => $this->string(255),
            'message' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_integration_logs}}', ['integrationId']);
        $this->createIndex(null, '{{%simpleform_integration_logs}}', ['submissionId']);
        $this->addForeignKey(null, '{{%simpleform_integration_logs}}', ['integrationId'], '{{%simpleform_integrations}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_integration_logs}}', ['submissionId'], '{{%simpleform_submissions}}', ['id'], 'SET NULL', 'CASCADE');

        // Per-form notifications.
        $this->createTable('{{%simpleform_notifications}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'recipientType' => $this->string(20)->notNull()->defaultValue('fixed'),
            'recipient' => $this->string(255)->notNull()->defaultValue(''),
            'subject' => $this->string(255),
            'replyTo' => $this->string(255),
            'body' => $this->text(),
            'attachPdf' => $this->boolean()->notNull()->defaultValue(false),
            'attachUploads' => $this->boolean()->notNull()->defaultValue(false),
            'conditional' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_notifications}}', ['formId']);
        $this->addForeignKey(null, '{{%simpleform_notifications}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');

        // Save-&-resume drafts.
        $this->createTable('{{%simpleform_form_drafts}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'tokenHash' => $this->char(64)->notNull(),
            'data' => $this->json(),
            'passive' => $this->boolean()->notNull()->defaultValue(false),
            'dateExpires' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_form_drafts}}', ['tokenHash'], true);
        $this->createIndex(null, '{{%simpleform_form_drafts}}', ['dateExpires']);
        $this->createIndex(null, '{{%simpleform_form_drafts}}', ['formId']);
        $this->addForeignKey(null, '{{%simpleform_form_drafts}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', null);

        // Audit log.
        $this->createTable('{{%simpleform_audit_log}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer(),
            'action' => $this->string(60)->notNull(),
            'targetType' => $this->string(60)->notNull(),
            'targetId' => $this->integer(),
            'summary' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_audit_log}}', ['dateCreated']);
        $this->addForeignKey(null, '{{%simpleform_audit_log}}', ['userId'], '{{%users}}', ['id'], 'SET NULL', 'CASCADE');

        // Payment coupons (#246) — site-owner discount codes for the Commerce
        // payment path.
        $this->createTable('{{%simpleform_coupons}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(64)->notNull(),
            'type' => $this->string(16)->notNull()->defaultValue('fixed'),
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'expiryDate' => $this->dateTime(),
            'maxUsages' => $this->integer()->unsigned(),
            'usageCount' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_coupons}}', ['code'], true);

        return true;
    }

    public function safeDown(): bool
    {
        // Drop children before parents (FK-safe order).
        $this->dropTableIfExists('{{%simpleform_audit_log}}');
        $this->dropTableIfExists('{{%simpleform_form_drafts}}');
        $this->dropTableIfExists('{{%simpleform_notifications}}');
        $this->dropTableIfExists('{{%simpleform_integration_logs}}');
        $this->dropTableIfExists('{{%simpleform_form_integrations}}');
        $this->dropTableIfExists('{{%simpleform_integrations}}');
        $this->dropTableIfExists('{{%simpleform_submissions}}');
        $this->dropTableIfExists('{{%simpleform_fields_sites}}');
        $this->dropTableIfExists('{{%simpleform_fields}}');
        $this->dropTableIfExists('{{%simpleform_forms_sites}}');
        $this->dropTableIfExists('{{%simpleform_forms}}');

        return true;
    }
}
