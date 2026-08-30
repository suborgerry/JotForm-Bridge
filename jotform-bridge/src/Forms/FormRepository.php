<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the plugin knows about the Jotform forms integrations actually use.
 *
 * One record per connected form, written only by the explicit "Connect form"
 * button in the integration editor. This used to be a copy of the whole account
 * form list, refreshed by "Sync with Jotform" on the settings screen; that is
 * gone, and nothing anywhere asks Jotform what forms an account has.
 *
 * The reasoning, recorded because the old design was deliberate too:
 *
 * * the list was the largest thing the plugin stored and the least of it was
 *   used — a site with three integrations kept every form on the account, and
 *   a shared agency account can hold hundreds;
 * * it was fetched with `limit=1000` and no paging, so a large account was
 *   silently truncated and the missing forms could never be selected;
 * * "Remove from list" and its option existed only to hide rows nobody wanted
 *   to see. A store that holds only what is referenced has nothing to hide.
 *
 * Like the schema this is stored rather than cached: no TTL, nothing expires on
 * its own, and no page view or submission ever writes it. A record is a *name*
 * for an ID — the ID itself, the authoritative part, lives on the Integration —
 * so a stale title is cosmetic and a missing one costs nothing but a label.
 */
final class FormRepository
{
    /**
     * Deliberately not the old `jotform_bridge_forms`.
     *
     * That option held a list; this one holds a map keyed by form ID. Both are
     * integer-keyed arrays of arrays once PHP has coerced the numeric keys, so
     * no honest check could tell an old value from a new one. Reusing the name
     * would have meant guessing, and guessing wrong means an account list being
     * read as connected forms. A new name and a plain delete of the old one is
     * the version of this that cannot be subtly wrong.
     */
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
     * In-request memo. The integrations list asks for a title once per row, and
     * the answer cannot change within one request.
     *
     * @var array<int|string, array<string, string|int>>|null
     */
    private ?array $memo = null;

    public function __construct(JotformClient $client)
    {
        $this->client = $client;
    }

    /**
     * Every connected form, keyed by form ID.
     *
     * The key comes back as an integer, not a string: PHP coerces a numeric
     * string array key on the way in and there is no way to stop it. Lookups by
     * string ID still work, because the same coercion applies to them — but
     * code iterating this should read `$form['id']`, which is a string by
     * construction, rather than the key it arrived under.
     *
     * @return array<int|string, array{id:string, title:string, status:string, updated:string, connected_at:int}>
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

            // PHP turns a numeric string key into an integer on the way in, so
            // the id is read back from the key rather than trusted to be a
            // string, and the record's own id is only a fallback.
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
     * @return array{id:string, title:string, status:string, updated:string, connected_at:int}|null
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

    /**
     * The stored title, or an empty string when the form was never connected.
     */
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
     * Asks Jotform about one form and stores what came back.
     *
     * The only write path, and it runs only when an administrator presses
     * "Connect form". On failure nothing stored is touched: a form that was
     * connected yesterday keeps its title through a Jotform outage rather than
     * losing its label because a network call timed out.
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

    /**
     * Drops one record. The form itself is untouched: this is a local label.
     */
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
     * @param array<int|string, array<string, string|int>> $forms
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
     * @return array{id:string, title:string, status:string, updated:string, connected_at:int}
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

    /**
     * Jotform form IDs are numeric strings; anything else is rejected rather
     * than sanitized into some other form's record.
     */
    private static function normalizeFormId(string $formId): string
    {
        $formId = trim($formId);

        return ctype_digit($formId) ? $formId : '';
    }
}
