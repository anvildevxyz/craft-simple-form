<?php

namespace fabianhaef\simpleform\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType as GraphQLObjectType;

/**
 * Base for Simple Form GraphQL object types: registers the type once with the
 * entity registry and wires its lazy field resolver, leaving subclasses to
 * declare only their name, description, and field definitions.
 *
 * Mirrors the pattern used by the sibling Beacon plugin.
 *
 * @phpstan-type GqlFieldDefinition array{type: \GraphQL\Type\Definition\Type, description?: string, resolve?: callable, args?: array<string, mixed>}
 * @phpstan-type GqlFieldDefinitionMap array<string, GqlFieldDefinition>
 */
abstract class SimpleFormObjectType extends ObjectType
{
    abstract public static function getName(): string;

    abstract protected static function getDescription(): string;

    /** @return GqlFieldDefinitionMap */
    abstract public static function getFieldDefinitions(): array;

    public static function getType(): GraphQLObjectType
    {
        $class = static::class;

        return GqlEntityRegistry::getOrCreate(static::getName(), fn() => new $class([
            'name' => static::getName(),
            'fields' => $class . '::getFieldDefinitions',
            'description' => static::getDescription(),
        ]));
    }
}
