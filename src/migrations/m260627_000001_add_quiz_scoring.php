<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Quiz scoring (#241): a per-form quiz-mode flag + grade-band config on
 * `simpleform_forms`, and the computed score stored on each row of
 * `simpleform_submissions` (raw + max + percentage + grade band).
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on every setup on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000001_add_quiz_scoring extends Migration
{
    public function safeUp(): bool
    {
        $forms = '{{%simpleform_forms}}';
        if (!$this->db->columnExists($forms, 'quizMode')) {
            $this->addColumn($forms, 'quizMode', $this->boolean()->notNull()->defaultValue(false)->after('editWindowMinutes'));
        }
        if (!$this->db->columnExists($forms, 'quizGradeBands')) {
            $this->addColumn($forms, 'quizGradeBands', $this->text()->after('quizMode'));
        }

        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'quizScore')) {
            $this->addColumn($submissions, 'quizScore', $this->integer()->after('orderId'));
        }
        if (!$this->db->columnExists($submissions, 'quizMaxScore')) {
            $this->addColumn($submissions, 'quizMaxScore', $this->integer()->after('quizScore'));
        }
        if (!$this->db->columnExists($submissions, 'quizPercentage')) {
            $this->addColumn($submissions, 'quizPercentage', $this->integer()->after('quizMaxScore'));
        }
        if (!$this->db->columnExists($submissions, 'quizGrade')) {
            $this->addColumn($submissions, 'quizGrade', $this->string(32)->after('quizPercentage'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = '{{%simpleform_forms}}';
        foreach (['quizGradeBands', 'quizMode'] as $column) {
            if ($this->db->columnExists($forms, $column)) {
                $this->dropColumn($forms, $column);
            }
        }

        $submissions = '{{%simpleform_submissions}}';
        foreach (['quizGrade', 'quizPercentage', 'quizMaxScore', 'quizScore'] as $column) {
            if ($this->db->columnExists($submissions, $column)) {
                $this->dropColumn($submissions, $column);
            }
        }

        return true;
    }
}
