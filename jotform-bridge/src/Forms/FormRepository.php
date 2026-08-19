<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable, manually synchronized copy of the Jotform account form list.
 *
 * Like the schema, this is stored rather than cached: Jotform is contacted only
 * by the explicit "Refresh Forms" action on the settings screen, and nothing
 * expires on its own. An expiring list would silently break the integration
 * editor — the form select would empty itself and saving would start failing —
 * at a moment nobody chose.
 */
final class FormRepository
{
    public const OPTION      = 'jotform_bridge_forms';
    public const META_OPTION = 'jotform_bridge_forms_meta';

    /** Where versions up to 0.1.0 kept the list. Only ever deleted. */
    public const LEGACY_TRANSIENT = 'jotform_bridge_forms';

    private JotformClient $client;

    /**
     * In-request memo. The admin list asks for the form list once per row, and
     * the answer cannot change within one request.
     *
     * @var array<int, array<string, string>>|null
     */
    private ?array $memo = null;

    public function __construct(JotformClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<int, array<string, string>> Empty when nothing is stored.
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = get_option(self::OPTION, false);

        $this->memo = is_array($stored) ? $stored : [];

        return $this->memo;
    }

    public function isSynced(): bool
    {
        return is_array(get_option(self::OPTION, false));
    }

    /**
     * Fetches the list from Jotform and replaces what is stored, on success.
     */
    public function refresh(): ApiResponse
    {
        $response = $this->client->getForms();

        if (!$response->isSuccess()) {
            $this->saveMeta(
                [
                    'fetched_at' => $this->meta()['fetched_at'],
                    'count'      => $this->meta()['count'],
                    'error'      => $response->errorMessage(),
                ]
            );

            return $response;
        }

        $forms      = $response->data();
        $this->memo = null;

        update_option(self::OPTION, $forms, false);

        $this->saveMeta(
            [
                'fetched_at' => time(),
                'count'      => count($forms),
                'error'      => '',
            ]
        );

        return $response;
    }

    public function flush(): void
    {
        $this->memo = null;

        delete_option(self::OPTION);
        delete_transient(self::LEGACY_TRANSIENT);
        delete_option(self::META_OPTION);
    }

    /**
     * @return array{fetched_at:int, count:int, error:string}
     */
    public function meta(): array
    {
        $meta = get_option(self::META_OPTION, []);

        if (!is_array($meta)) {
            $meta = [];
        }

        return [
            'fetched_at' => isset($meta['fetched_at']) ? (int) $meta['fetched_at'] : 0,
            'count'      => isset($meta['count']) ? (int) $meta['count'] : 0,
            'error'      => isset($meta['error']) ? (string) $meta['error'] : '',
        ];
    }

    /**
     * @param array{fetched_at:int, count:int, error:string} $meta
     */
    private function saveMeta(array $meta): void
    {
        update_option(self::META_OPTION, $meta, false);
    }
}
