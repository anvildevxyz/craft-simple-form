<?php

namespace anvildev\simpleform\mcp;

use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\mcp\tools\AddFieldTool;
use anvildev\simpleform\mcp\tools\CategorizeSubmissionsTool;
use anvildev\simpleform\mcp\tools\CreateFormTool;
use anvildev\simpleform\mcp\tools\DeleteFieldTool;
use anvildev\simpleform\mcp\tools\DeleteFormTool;
use anvildev\simpleform\mcp\tools\DetectSpamPatternsTool;
use anvildev\simpleform\mcp\tools\ExportSubmissionsTool;
use anvildev\simpleform\mcp\tools\GetFormTool;
use anvildev\simpleform\mcp\tools\GetSubmissionTool;
use anvildev\simpleform\mcp\tools\ListFormsTool;
use anvildev\simpleform\mcp\tools\ListIntegrationsTool;
use anvildev\simpleform\mcp\tools\QuerySubmissionsTool;
use anvildev\simpleform\mcp\tools\ReorderFieldsTool;
use anvildev\simpleform\mcp\tools\SubmissionStatsTool;
use anvildev\simpleform\mcp\tools\SummarizeSubmissionsTool;
use anvildev\simpleform\mcp\tools\ToolInterface;
use anvildev\simpleform\mcp\tools\UpdateFieldTool;
use anvildev\simpleform\mcp\tools\UpdateFormTool;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Craft 5.12 MCP tools for Simple Form. Every method calls the tool class the
 * plugin's own HTTP server already uses, so validation, confirm gates, and
 * submission privacy stay exactly where they are. Null arguments are omitted
 * so an unset optional does not override a tool default.
 */
class CraftTools
{
    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_list_forms', description: 'List Simple Form forms (id, handle, name, field count). No submission data.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function listForms(): array
    {
        return $this->run(new ListFormsTool(), []);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_get_form', description: 'Get one form and its fields by id or handle. No submission data.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function getForm(?int $id = null, ?string $handle = null): array
    {
        return $this->run(new GetFormTool(), ['id' => $id, 'handle' => $handle]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_create_form', description: 'Create a form through the same save path as the control panel.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function createForm(string $name, string $handle, ?string $title = null, ?string $description = null, ?string $emailTo = null, ?string $emailSubject = null, ?string $emailBody = null, ?string $emailReplyTo = null, ?int $siteId = null, ?string $propagationMethod = null): array
    {
        return $this->run(new CreateFormTool(), [
            'name' => $name,
            'handle' => $handle,
            'title' => $title,
            'description' => $description,
            'emailTo' => $emailTo,
            'emailSubject' => $emailSubject,
            'emailBody' => $emailBody,
            'emailReplyTo' => $emailReplyTo,
            'siteId' => $siteId,
            'propagationMethod' => $propagationMethod,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_update_form', description: 'Update a form. Only the supplied fields change.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function updateForm(?int $id = null, ?string $handle = null, ?string $name = null, ?string $newHandle = null, ?string $title = null, ?string $description = null, ?string $emailTo = null, ?string $emailSubject = null, ?string $emailBody = null, ?string $emailReplyTo = null, ?int $siteId = null, ?string $propagationMethod = null): array
    {
        return $this->run(new UpdateFormTool(), [
            'id' => $id,
            'handle' => $handle,
            'name' => $name,
            'newHandle' => $newHandle,
            'title' => $title,
            'description' => $description,
            'emailTo' => $emailTo,
            'emailSubject' => $emailSubject,
            'emailBody' => $emailBody,
            'emailReplyTo' => $emailReplyTo,
            'siteId' => $siteId,
            'propagationMethod' => $propagationMethod,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_delete_form', description: 'Delete a form and its fields. Refused unless confirm is true.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function deleteForm(?int $id = null, ?string $handle = null, ?bool $confirm = null): array
    {
        return $this->run(new DeleteFormTool(), ['id' => $id, 'handle' => $handle, 'confirm' => $confirm]);
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_add_field', description: 'Add a field to a form. Same validation as the control panel.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function addField(int $formId, string $type, string $handle, string $label, ?bool $required = null, ?string $helpText = null, ?array $config = null): array
    {
        return $this->run(new AddFieldTool(), [
            'formId' => $formId,
            'type' => $type,
            'handle' => $handle,
            'label' => $label,
            'required' => $required,
            'helpText' => $helpText,
            'config' => $config,
        ]);
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_update_field', description: 'Update one field. Only the supplied values change.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function updateField(int $fieldId, ?string $handle = null, ?string $label = null, ?bool $required = null, ?string $helpText = null, ?array $config = null, ?int $siteId = null): array
    {
        return $this->run(new UpdateFieldTool(), [
            'fieldId' => $fieldId,
            'handle' => $handle,
            'label' => $label,
            'required' => $required,
            'helpText' => $helpText,
            'config' => $config,
            'siteId' => $siteId,
        ]);
    }

    /**
     * @param list<int> $fieldIds
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_reorder_fields', description: 'Reorder a form’s fields. fieldIds is the complete new order.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function reorderFields(int $formId, array $fieldIds): array
    {
        return $this->run(new ReorderFieldsTool(), ['formId' => $formId, 'fieldIds' => $fieldIds]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_delete_field', description: 'Delete one field. Refused unless confirm is true.', annotations: new ToolAnnotations(destructiveHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function deleteField(int $fieldId, ?bool $confirm = null): array
    {
        return $this->run(new DeleteFieldTool(), ['fieldId' => $fieldId, 'confirm' => $confirm]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_list_integrations', description: 'List a form’s integrations and recent delivery status. No secrets.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_FORMS)]
    public function listIntegrations(?int $id = null, ?string $handle = null): array
    {
        return $this->run(new ListIntegrationsTool(), ['id' => $id, 'handle' => $handle]);
    }

    /**
     * @param array<string, mixed>|null $fieldMatch
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_query_submissions', description: 'Query submissions, including stored answers.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function querySubmissions(?string $form = null, ?int $formId = null, ?string $status = null, ?string $dateFrom = null, ?string $dateTo = null, ?array $fieldMatch = null, ?int $offset = null, ?int $limit = null): array
    {
        return $this->run(new QuerySubmissionsTool(), [
            'form' => $form,
            'formId' => $formId,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'fieldMatch' => $fieldMatch,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_get_submission', description: 'Get one submission, including its stored answers.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function getSubmission(int $id): array
    {
        return $this->run(new GetSubmissionTool(), ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $fieldMatch
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_export_submissions', description: 'Export matching submissions. Requires permission to manage submissions.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::MANAGE_SUBMISSIONS)]
    public function exportSubmissions(?string $form = null, ?int $formId = null, ?string $status = null, ?string $dateFrom = null, ?string $dateTo = null, ?array $fieldMatch = null, ?string $format = null): array
    {
        return $this->run(new ExportSubmissionsTool(), [
            'form' => $form,
            'formId' => $formId,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'fieldMatch' => $fieldMatch,
            'format' => $format,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_submission_stats', description: 'Count submissions by status, form, and time.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function submissionStats(?string $form = null, ?int $formId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->run(new SubmissionStatsTool(), [
            'form' => $form,
            'formId' => $formId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    /**
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_summarize_submissions', description: 'Return the free-text corpus of matching submissions.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function summarizeSubmissions(?string $form = null, ?int $formId = null, ?string $dateFrom = null, ?string $dateTo = null, ?array $fields = null): array
    {
        return $this->run(new SummarizeSubmissionsTool(), [
            'form' => $form,
            'formId' => $formId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_categorize_submissions', description: 'Group matching submissions by a field or status.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function categorizeSubmissions(?string $form = null, ?int $formId = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $groupBy = null): array
    {
        return $this->run(new CategorizeSubmissionsTool(), [
            'form' => $form,
            'formId' => $formId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'simple_form_detect_spam_patterns', description: 'Flag likely-spam submissions with explainable heuristics.', annotations: new ToolAnnotations(readOnlyHint: true))]
    #[RequiresPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)]
    public function detectSpamPatterns(?string $form = null, ?int $formId = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $linkThreshold = null): array
    {
        return $this->run(new DetectSpamPatternsTool(), [
            'form' => $form,
            'formId' => $formId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'linkThreshold' => $linkThreshold,
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function run(ToolInterface $tool, array $arguments): array
    {
        return $tool->call(array_filter(
            $arguments,
            static fn(mixed $value): bool => $value !== null,
        ));
    }
}
