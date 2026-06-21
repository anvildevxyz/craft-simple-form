<?php

namespace fabianhaef\simpleform\mcp\tools\support;

use fabianhaef\simpleform\elements\Form;

/**
 * Shapes {@see Form} elements and their resolved field sets into the structured
 * JSON returned by the form-management MCP tools, so every tool reports a form
 * the same way.
 */
final class FormPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function form(Form $form): array
    {
        return [
            'id' => (int)$form->id,
            'siteId' => (int)$form->siteId,
            'handle' => $form->handle,
            'name' => $form->name,
            'title' => $form->title,
            'description' => $form->description,
            'emailTo' => $form->emailTo,
            'emailSubject' => $form->emailSubject,
            'emailReplyTo' => $form->emailReplyTo,
            'emailBody' => $form->emailBody,
            'propagationMethod' => $form->propagationMethod->value,
            'fields' => self::fields($form),
        ];
    }

    /**
     * The form's resolved field set, in a stable tool shape.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(Form $form): array
    {
        $fields = [];
        foreach ($form->getFields() as $row) {
            $fields[] = [
                'id' => (int)$row['id'],
                'type' => (string)$row['type'],
                'handle' => (string)$row['name'],
                'label' => (string)$row['label'],
                'required' => (bool)$row['required'],
                'helpText' => (string)($row['helpText'] ?? ''),
                'sortOrder' => (int)$row['sortOrder'],
                'config' => $row['config'],
            ];
        }

        return $fields;
    }
}
