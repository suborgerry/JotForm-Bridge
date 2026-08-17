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
     * @return array<int, array<string, string>> Empty when nothing is cached.
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = get_transient(self::TRANSIENT);

        $this->memo = is_array($cached) ? $cached : [];

        return $this->memo;
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

        $forms      = $response->data();
        $this->memo = null;

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
        $this->memo = null;

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
