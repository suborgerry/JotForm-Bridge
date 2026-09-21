<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One record per connected Jotform form, written only by "Connect form".
 * Stored with no TTL. A record is a label for an ID; the ID itself lives on
 * the Integration.
 *
 * @phpstan-type ConnectedForm array{id:string, title:string, status:string, updated:string, connected_at:int}
 */
final class FormRepository
{
    /** A map keyed by form ID; not the legacy list option below. */
    public const OPTION = 'jotform_bridge_connected_forms';

    /** Options versions up to 0.1.0 kept the account list in. Only deleted. */
    public const LEGACY_OPTION        = 'jotform_bridge_forms';
    public const LEGACY_META_OPTION   = 'jotform_bridge_forms_meta';
    public const LEGACY_HIDDEN_OPTION = 'jotform_bridge_forms_hidden';
    public const LEGACY_TRANSIENT     = 'jotform_bridge_forms';

    /** The status Jotform reports for a form sitting in the account trash. */
    public const STATUS_DELETED = 'DELETED';

    private JotformClient $client;

    /**
     * In-request memo.
     *
     * @var array<int|string, ConnectedForm>|null
     */
    private ?array $memo = null;

    public function __construct(JotformClient $client)
    {
        $this->client = $client;
    }

    /**
     * Every connected form, keyed by form ID. PHP coerces the numeric keys to
     * integers; read `$form['id']` for the string.
     *
     * @return array<int|string, ConnectedForm>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = get_option(self::OPTION, []);
        $forms  = [];

        foreach (is_array($stored) ? $stored : [] as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            $formId = trim((string) $key);

            if ($formId === '' || !ctype_digit($formId)) {
                continue;
            }

            $forms[$formId] = self::normalize($formId, $record);
        }

        $this->memo = $forms;

        return $forms;
    }

    /**
     * @return ConnectedForm|null
     */
    public function get(string $formId): ?array
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return null;
        }

        $all = $this->all();

        return $all[$formId] ?? null;
    }

    public function has(string $formId): bool
    {
        return $this->get($formId) !== null;
    }

    /** The stored title, or '' when the form was never connected. */
    public function title(string $formId): string
    {
        $form = $this->get($formId);

        return $form !== null ? $form['title'] : '';
    }

    public function isDeleted(string $formId): bool
    {
        $form = $this->get($formId);

        return $form !== null && strtoupper($form['status']) === self::STATUS_DELETED;
    }

    /**
     * Asks Jotform about one form and stores the record; on failure nothing
     * stored is touched.
     *
     * @return ApiResponse Data is the stored record on success.
     */
    public function connect(string $formId): ApiResponse
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return ApiResponse::failure(
                JotformClient::ERROR_UNEXPECTED,
                __('Enter a Jotform form ID: it is the run of digits in the form URL.', 'jotform-bridge')
            );
        }

        $response = $this->client->getForm($formId);

        if (!$response->isSuccess()) {
            return $response;
        }

        $record = self::normalize($formId, $response->data());
        $record['connected_at'] = time();

        $stored          = $this->all();
        $stored[$formId] = $record;

        $this->save($stored);

        return ApiResponse::success($record, $response->status(), $response->meta());
    }

    /** Drops one local record. */
    public function forget(string $formId): void
    {
        $formId = self::normalizeFormId($formId);

        if ($formId === '') {
            return;
        }

        $stored = $this->all();

        if (!isset($stored[$formId])) {
            return;
        }

        unset($stored[$formId]);

        $this->save($stored);
    }

    public function flush(): void
    {
        $this->memo = null;

        delete_option(self::OPTION);
    }

    /**
     * @param array<int|string, ConnectedForm> $forms
     */
    private function save(array $forms): void
    {
        $this->memo = $forms;

        if ($forms === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $forms, false);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return ConnectedForm
     */
    private static function normalize(string $formId, array $record): array
    {
        return [
            'id'           => $formId,
            'title'        => isset($record['title']) ? (string) $record['title'] : '',
            'status'       => isset($record['status']) ? (string) $record['status'] : '',
            'updated'      => isset($record['updated']) ? (string) $record['updated'] : '',
            'connected_at' => isset($record['connected_at']) ? (int) $record['connected_at'] : 0,
        ];
    }

    /** Digits only; anything else becomes ''. */
    private static function normalizeFormId(string $formId): string
    {
        $formId = trim($formId);

        return ctype_digit($formId) ? $formId : '';
    }
}
