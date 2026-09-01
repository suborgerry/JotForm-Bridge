<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, manually synchronized storage of the Normalized Schema.
 *
 * The schema is not a cache. Nothing here expires, nothing here refetches: one
 * option per form ID, written only by sync(), which runs only when an
 * administrator presses "Sync Schema" for that integration. A page view, a
 * submission and a compatibility check all read what is stored and never reach
 * out to Jotform — so a Jotform outage, a slow API or an expired cache entry
 * cannot influence a request a visitor is waiting on.
 *
 * The price is explicit and deliberate: a form changed in Jotform stays
 * unchanged here until somebody syncs it. That is the point — the site owner
 * decides when the contract between the theme and Jotform moves.
 *
 * A small option holds the per-form metadata (last sync, fingerprint, last
 * error, plugin version at sync time) that has to outlive the schema itself.
 * No custom database table.
 */
final class SchemaRepository
{
    public const OPTION_PREFIX = 'jotform_bridge_schema_';
    public const META_OPTION   = 'jotform_bridge_schema_meta';

    /**
     * Where versions up to 0.1.0 kept the schema. Only ever deleted, never read:
     * a schema that lived in a transient was written by a different storage
     * contract, and re-syncing it by hand is one click.
     */
    public const LEGACY_TRANSIENT_PREFIX = 'jotform_bridge_schema_';

    /**
     * Returned when a form has never been synced. Distinct from an API error:
     * nothing failed, the administrator simply has not synced yet.
     */
    public const ERROR_NOT_SYNCED = 'schema_not_synced';

    private JotformClient $client;

    private SchemaBuilder $builder;

    public function __construct(JotformClient $client)
    {
        $this->client  = $client;
        $this->builder = new SchemaBuilder();
    }

    /**
     * Returns the stored schema, or null when the form was never synced.
     */
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

    /**
     * The read path used by rendering and by submissions.
     *
     * Never contacts Jotform: what is stored is the answer, and "nothing is
     * stored" is a failure the caller has to handle rather than something this
     * class silently fixes behind the request.
     */
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
     * The write path: fetches the questions from Jotform, normalizes them and
     * replaces what is stored. Called only from the explicit admin action.
     *
     * On failure the previously stored schema is left untouched, so a failed
     * sync degrades to "still running on the previous definition" rather than
     * to a form that stops working.
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

    /**
     * Drops the stored schema of one form. Metadata is kept so the previous
     * fingerprint can still be compared after the next sync.
     */
    public function forget(string $formId): void
    {
        $formId = self::normalizeFormId($formId);

        if ($formId !== '') {
            delete_option(self::optionKey($formId));
            delete_transient(self::LEGACY_TRANSIENT_PREFIX . $formId);
        }
    }

    /**
     * Drops every stored schema and all metadata.
     *
     * Nothing in the plugin lifecycle calls this any more — a schema is
     * configuration-grade state now, and losing it means every form on the site
     * needs a manual sync. It exists for uninstall and for tests.
     *
     * Static because uninstall has no built services to work with.
     */
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

    /**
     * Removes the transients versions up to 0.1.0 kept the schemas in.
     *
     * Only the legacy copies go: what an administrator synced under the current
     * storage rule is untouched.
     */
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

    /**
     * Whether the stored schema was written by an older plugin version.
     *
     * Not acted upon automatically: normalization can change between versions,
     * so the administrator is told to re-sync instead of having the schema
     * discarded under a running site.
     */
    public function isStale(string $formId): bool
    {
        $meta = $this->meta($formId);

        return $meta['synced_at'] > 0 && $meta['version'] !== JOTFORM_BRIDGE_VERSION;
    }

    public static function optionKey(string $formId): string
    {
        return self::OPTION_PREFIX . self::normalizeFormId($formId);
    }

    /**
     * Jotform form IDs are numeric strings; anything else is rejected rather
     * than sanitized into a different form's storage key.
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
     * @param array{synced_at:int, fingerprint:string, version:string, error:string} $meta
     */
    private function saveMeta(string $formId, array $meta): void
    {
        $all          = $this->allMeta();
        $all[$formId] = $meta;

        update_option(self::META_OPTION, $all, false);
    }
}
