<?php

namespace anvildev\simpleform\gql\queries;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\gql\resolvers\FormGqlResolver;
use anvildev\simpleform\gql\types\FormType;
use Craft;
use craft\gql\base\Query as BaseQuery;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

/**
 * Read-only GraphQL queries for Simple Form *schemas* (form metadata + fields).
 *
 * Submission data is deliberately NOT queryable here — there is no submissions
 * query and no submission type, so a token scoped to read forms can never read
 * what people submitted.
 *
 * Gated by the `simpleForms:read` schema component.
 */
class FormQueries extends BaseQuery
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('simpleForms', 'read')) {
            return [];
        }

        // Force-register the form type so introspection (`__type(name: "SimpleForm")`)
        // works even before a query references it.
        GqlEntityRegistry::getOrCreate(FormType::getName(), fn() => FormType::getType());

        return [
            'simpleForm' => [
                'type' => FormType::getType(),
                'args' => [
                    'handle' => ['type' => Type::string(), 'description' => 'Fetch the form with this handle.'],
                    'id' => ['type' => Type::int(), 'description' => 'Fetch the form with this id.'],
                    'siteId' => ['type' => Type::int(), 'description' => 'Resolve labels/title for this site. Defaults to the primary site.'],
                ],
                'description' => 'Returns a single form schema by handle or id, or null when not found.',
                'resolve' => [self::class, 'resolveForm'],
            ],
            'simpleForms' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(FormType::getType()))),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Resolve labels/titles for this site. Defaults to the primary site.'],
                ],
                'description' => 'Returns all form schemas for the given (or primary) site.',
                'resolve' => [self::class, 'resolveForms'],
            ],
        ];
    }

    /**
     * @param mixed $source
     * @param array{handle?: string, id?: int, siteId?: int} $args
     * @return array<string, mixed>|null
     */
    public static function resolveForm(mixed $source, array $args): ?array
    {
        $siteId = self::resolveSiteId($args['siteId'] ?? null);

        $query = Form::find()->siteId($siteId);
        if (isset($args['id'])) {
            $query->id((int) $args['id']);
        } elseif (isset($args['handle']) && $args['handle'] !== '') {
            $query->handle((string) $args['handle']);
        } else {
            return null;
        }

        $form = $query->one();

        return $form instanceof Form ? FormGqlResolver::resolve($form) : null;
    }

    /**
     * @param mixed $source
     * @param array{siteId?: int} $args
     * @return list<array<string, mixed>>
     */
    public static function resolveForms(mixed $source, array $args): array
    {
        $siteId = self::resolveSiteId($args['siteId'] ?? null);

        /** @var list<Form> $forms */
        $forms = Form::find()->siteId($siteId)->all();
        Form::eagerLoadFields($forms);

        return array_map([FormGqlResolver::class, 'resolve'], $forms);
    }

    private static function resolveSiteId(?int $siteId): int
    {
        if ($siteId !== null && $siteId > 0) {
            return $siteId;
        }

        return (int) Craft::$app->getSites()->getPrimarySite()->id;
    }
}
