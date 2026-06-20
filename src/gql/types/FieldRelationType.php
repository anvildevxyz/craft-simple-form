<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * The element-relation configuration for a relation field type (entry, category,
 * tag, user, asset), exposed so a headless client can render its own picker:
 * the element type, the allowed sources, single/multi, the limit, and the
 * resolved allowed options (element id + title) for the requested site.
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FieldRelationType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldRelation';
    }

    protected static function getDescription(): string
    {
        return 'Element-relation configuration for a relation field (entry, '
            . 'category, tag, user, asset). Submit the chosen element ids under '
            . '`field_<id>`; the server validates membership in the allowed source.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'elementType' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The related element type: one of entry, category, '
                    . 'tag, user, asset.',
            ],
            'sources' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'The allowed source handles (section/group/volume), '
                    . 'or `["*"]` for any source of this element type.',
            ],
            'multiple' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether more than one element may be selected.',
            ],
            'limit' => [
                'type' => Type::int(),
                'description' => 'Maximum selectable elements when multiple, or null '
                    . 'for no limit.',
            ],
            'options' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldRelationOptionType::getType()))),
                'description' => 'The allowed elements (id + title) resolved for the '
                    . 'requested site, ready to render as a picker.',
            ],
        ];
    }
}
