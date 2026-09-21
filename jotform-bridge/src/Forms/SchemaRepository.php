<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, manually synchronized storage of the Normalized Schema: one option
 * per form ID with no TTL, written only by sync(). Reads never contact
 * Jotform. A separate option holds per-form metadata (last sync, fingerprint,
 * last error, plugin version).
 */
final class SchemaRepository
{
    public const OPTION_PREFIX = 'jotform_bridge_schema_';
    public const META_OPTION   = 'jotform_bridge_schema_meta';

    /** Where versions up to 0.1.0 kept the schema; only ever deleted. */
    public const LEGACY_TRANSIENT_PREFIX = 'jotform_bridge_schema_';

    /** The form has never been synced. */
    public const ERROR_NOT_SYNCED = 'schema_not_synced';

    private JotformClient $client;

    private SchemaBuilder $builder;

    public function __construct(JotformClient $client)
    {
        $this->client  = $client;
        $this->builder = new SchemaBuilder();
    }

    /** The stored schema, or null when the form was never synced. */
    public function stored(string $formId): ?FormSchema
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return null;
        }

        $stored = get_option(self::optionKey($formId), false);

        if (!is_array($stored) || !isset($stored['fields'])) {
            return null;
        }

        return FormSchema::fromArray($stored);
    }

    public function isSynced(string $formId): bool
    {
        return $this->stored($formId) !== null;
    }

    /** The read path for rendering and submissions; never contacts Jotform. */
    public function get(string $formId): ApiResponse
    {
        $stored = $this->stored($formId);

        if ($stored !== null) {
            return ApiResponse::success(['schema' => $stored]);
        }

        return ApiResponse::failure(
            self::ERROR_NOT_SYNCED,
            __('This form has not been synced yet. Open the integration and press Sync Schema.', 'jotform-bridge')
        );
    }

    /**
     * Fetches, normalizes and replaces the stored schema. On failure the
     * previous schema is left untouched and the error is recorded in the meta.
     *
     * @return ApiResponse Data is `['schema' => FormSchema, 'changed' => bool]`.
     */
    public function sync(string $formId): ApiResponse
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
                    'synced_at'   => $meta['synced_at'],
                    'fingerprint' => $meta['fingerprint'],
                    'version'     => $meta['version'],
                    'error'       => $response->errorMessage(),
                ]
            );

            return $response;
        }

        $schema = $this->builder->build($formId, $response->data());

        /**
         * Filters the normalized schema before it is stored.
         *
         * @param FormSchema $schema The normalized schema.
         * @param string     $formId Jotform form ID.
         */
        $filtered = apply_filters('jotform_bridge_normalized_schema', $schema, $formId);

        if ($filtered instanceof FormSchema) {
            $schema = $filtered;
        }

        $previous = $this->meta($formId)['fingerprint'];

        update_option(self::optionKey($formId), $schema->toArray(), false);

        $this->saveMeta(
            $formId,
            [
                'synced_at'   => time(),
                'fingerprint' => $schema->fingerprint(),
                'version'     => JOTFORM_BRIDGE_VERSION,
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

    /** Drops the stored schema of one form; metadata is kept. */
    public function forget(string $formId): void
    {
        $formId = self::normalizeFormId($formId);

        if ($formId !== '') {
            delete_option(self::optionKey($formId));
            delete_transient(self::LEGACY_TRANSIENT_PREFIX . $formId);
        }
    }

    /** Drops every stored schema and all metadata; for uninstall and tests. */
    public static function flushAll(): void
    {
        $meta = get_option(self::META_OPTION, []);

        if (is_array($meta)) {
            foreach (array_keys($meta) as $formId) {
                delete_option(self::optionKey((string) $formId));
                delete_transient(self::LEGACY_TRANSIENT_PREFIX . (string) $formId);
            }
        }

        delete_option(self::META_OPTION);
    }

    /** Removes the legacy schema transients; stored options are untouched. */
    public static function purgeLegacyTransients(): void
    {
        $meta = get_option(self::META_OPTION, []);

        if (!is_array($meta)) {
            return;
        }

        foreach (array_keys($meta) as $formId) {
            delete_transient(self::LEGACY_TRANSIENT_PREFIX . (string) $formId);
        }
    }

    /**
     * @return array{synced_at:int, fingerprint:string, version:string, error:string}
     */
    public function meta(string $formId): array
    {
        $formId = self::normalizeFormId($formId);
        $all    = $this->allMeta();
        $meta   = isset($all[$formId]) && is_array($all[$formId]) ? $all[$formId] : [];

        return [
            'synced_at'   => isset($meta['synced_at']) ? (int) $meta['synced_at'] : 0,
            'fingerprint' => isset($meta['fingerprint']) ? (string) $meta['fingerprint'] : '',
            'version'     => isset($meta['version']) ? (string) $meta['version'] : '',
            'error'       => isset($meta['error']) ? (string) $meta['error'] : '',
        ];
    }

    /** Whether the stored schema was written by an older plugin version. */
    public function isStale(string $formId): bool
    {
        $meta = $this->meta($formId);

        return $meta['synced_at'] > 0 && $meta['version'] !== JOTFORM_BRIDGE_VERSION;
    }

    public static function optionKey(string $formId): string
    {
        return self::OPTION_PREFIX . self::normalizeFormId($formId);
    }

    /** Digits only; anything else becomes ''. */
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
     * @param array{synced_at:int, fingerprint:string, version:string, error:string} $meta
     */
    private function saveMeta(string $formId, array $meta): void
    {
        $all          = $this->allMeta();
        $all[$formId] = $meta;

        update_option(self::META_OPTION, $all, false);
    }
}
