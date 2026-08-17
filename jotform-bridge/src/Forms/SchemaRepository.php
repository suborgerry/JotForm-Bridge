<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cached access to the Normalized Schema of a Jotform form.
 *
 * One transient per form ID, plus a small option holding the per-form metadata
 * (last fetch, fingerprint, last error) that must outlive the cache. No custom
 * database table.
 */
final class SchemaRepository
{
    public const TRANSIENT_PREFIX = 'jotform_bridge_schema_';
    public const META_OPTION      = 'jotform_bridge_schema_meta';

    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    private JotformClient $client;

    private SchemaBuilder $builder;

    public function __construct(JotformClient $client, ?SchemaBuilder $builder = null)
    {
        $this->client  = $client;
        $this->builder = $builder ?? new SchemaBuilder();
    }

    /**
     * Returns the cached schema, or null when nothing is cached.
     */
    public function cached(string $formId): ?FormSchema
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return null;
        }

        $stored = get_transient(self::transientKey($formId));

        if (!is_array($stored) || !isset($stored['fields'])) {
            return null;
        }

        return FormSchema::fromArray($stored);
    }

    public function isCached(string $formId): bool
    {
        return $this->cached($formId) !== null;
    }

    /**
     * The normal read path: cache first, one API call when the cache is cold.
     *
     * Rendering a page must never trigger a refresh of an already cached
     * schema — that is what refresh() is for.
     */
    public function get(string $formId): ApiResponse
    {
        $cached = $this->cached($formId);

        if ($cached !== null) {
            return ApiResponse::success(['schema' => $cached]);
        }

        return $this->refresh($formId);
    }

    /**
     * Fetches the questions from Jotform, normalizes them and replaces the
     * cache. On failure the previous cache is left untouched.
     *
     * @return ApiResponse Data is `['schema' => FormSchema, 'changed' => bool]`.
     */
    public function refresh(string $formId): ApiResponse
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return ApiResponse::failure(
                JotformClient::ERROR_UNEXPECTED,
                __('The Jotform form ID is missing or invalid.', 'jotform-bridge')
            );
        }

        $response = $this->client->getFormQuestions($formId);

        if (!$response->isSuccess()) {
            $meta = $this->meta($formId);
            $this->saveMeta(
                $formId,
                [
                    'fetched_at'  => $meta['fetched_at'],
                    'fingerprint' => $meta['fingerprint'],
                    'error'       => $response->errorMessage(),
                ]
            );

            return $response;
        }

        $schema = $this->builder->build($formId, $response->data());

        /**
         * Filters the normalized schema before it is cached.
         *
         * @param FormSchema $schema The normalized schema.
         * @param string     $formId Jotform form ID.
         */
        $filtered = apply_filters('jotform_bridge_normalized_schema', $schema, $formId);

        if ($filtered instanceof FormSchema) {
            $schema = $filtered;
        }

        $previous = $this->meta($formId)['fingerprint'];

        set_transient(self::transientKey($formId), $schema->toArray(), self::CACHE_TTL);

        $this->saveMeta(
            $formId,
            [
                'fetched_at'  => time(),
                'fingerprint' => $schema->fingerprint(),
                'error'       => '',
            ]
        );

        return ApiResponse::success(
            [
                'schema'  => $schema,
                'changed' => $previous !== '' && $previous !== $schema->fingerprint(),
            ],
            $response->status()
        );
    }

    /**
     * Drops the cached schema of one form. Metadata is kept so the previous
     * fingerprint can still be compared after the next refresh.
     */
    public function forget(string $formId): void
    {
        $formId = self::normalizeFormId($formId);

        if ($formId !== '') {
            delete_transient(self::transientKey($formId));
        }
    }

    /**
     * Drops every cached schema and all metadata.
     *
     * Static because plugin deactivation has no built services to work with.
     */
    public static function flushAll(): void
    {
        $meta = get_option(self::META_OPTION, []);

        if (is_array($meta)) {
            foreach (array_keys($meta) as $formId) {
                delete_transient(self::transientKey((string) $formId));
            }
        }

        delete_option(self::META_OPTION);
    }

    /**
     * @return array{fetched_at:int, fingerprint:string, error:string}
     */
    public function meta(string $formId): array
    {
        $formId = self::normalizeFormId($formId);
        $all    = $this->allMeta();
        $meta   = isset($all[$formId]) && is_array($all[$formId]) ? $all[$formId] : [];

        return [
            'fetched_at'  => isset($meta['fetched_at']) ? (int) $meta['fetched_at'] : 0,
            'fingerprint' => isset($meta['fingerprint']) ? (string) $meta['fingerprint'] : '',
            'error'       => isset($meta['error']) ? (string) $meta['error'] : '',
        ];
    }

    public static function transientKey(string $formId): string
    {
        return self::TRANSIENT_PREFIX . self::normalizeFormId($formId);
    }

    /**
     * Jotform form IDs are numeric strings; anything else is rejected rather
     * than sanitized into a different form's cache key.
     */
    private static function normalizeFormId(string $formId): string
    {
        $formId = trim($formId);

        return ctype_digit($formId) ? $formId : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function allMeta(): array
    {
        $meta = get_option(self::META_OPTION, []);

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array{fetched_at:int, fingerprint:string, error:string} $meta
     */
    private function saveMeta(string $formId, array $meta): void
    {
        $all           = $this->allMeta();
        $all[$formId]  = $meta;

        update_option(self::META_OPTION, $all, false);
    }
}
