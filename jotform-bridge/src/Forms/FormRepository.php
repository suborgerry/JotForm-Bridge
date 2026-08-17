<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cached access to the Jotform account form list.
 *
 * Jotform is contacted only on an explicit admin action; admin page views and
 * frontend requests read the transient.
 */
final class FormRepository
{
    public const TRANSIENT  = 'jotform_bridge_forms';
    public const META_OPTION = 'jotform_bridge_forms_meta';

    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    private JotformClient $client;

    public function __construct(JotformClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<int, array<string, string>> Empty when nothing is cached.
     */
    public function all(): array
    {
        $cached = get_transient(self::TRANSIENT);

        return is_array($cached) ? $cached : [];
    }

    public function isCached(): bool
    {
        return is_array(get_transient(self::TRANSIENT));
    }

    /**
     * Fetches the list from Jotform and replaces the cache on success.
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

        $forms = $response->data();

        set_transient(self::TRANSIENT, $forms, self::CACHE_TTL);

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
        delete_transient(self::TRANSIENT);
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
