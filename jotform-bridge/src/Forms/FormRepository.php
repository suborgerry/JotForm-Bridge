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
 * by the explicit "Sync with Jotform" action on the settings screen, and nothing
 * expires on its own. An expiring list would silently break the integration
 * editor — the form select would empty itself and saving would start failing —
 * at a moment nobody chose.
 */
final class FormRepository
{
    public const OPTION        = 'jotform_bridge_forms';
    public const META_OPTION   = 'jotform_bridge_forms_meta';
    public const HIDDEN_OPTION = 'jotform_bridge_forms_hidden';

    /** The status Jotform reports for a form sitting in the account trash. */
    public const STATUS_DELETED = 'DELETED';

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

    /**
     * In-request memo for the dismissed-form ids, kept for the same reason.
     *
     * @var array<int, string>|null
     */
    private ?array $hiddenMemo = null;

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

        $forms      = $this->withoutHidden($response->data());
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
        $this->memo       = null;
        $this->hiddenMemo = null;

        delete_option(self::OPTION);
        delete_transient(self::LEGACY_TRANSIENT);
        delete_option(self::META_OPTION);
        delete_option(self::HIDDEN_OPTION);
    }

    /**
     * Drops one trashed form from the stored list for good.
     *
     * Only a form Jotform itself reports as DELETED can go: everything else on
     * the screen is a form the account still has, and hiding one of those would
     * only make the integration editor lie about what can be connected.
     *
     * The id is remembered, because /user/forms keeps returning trashed forms
     * and the next sync would otherwise put the row straight back.
     *
     * @return bool False when no trashed form with that id is stored.
     */
    public function hide(string $formId): bool
    {
        $formId = trim($formId);

        if ($formId === '') {
            return false;
        }

        $kept    = [];
        $removed = false;

        foreach ($this->all() as $form) {
            if ((string) ($form['id'] ?? '') === $formId && self::isDeleted($form)) {
                $removed = true;

                continue;
            }

            $kept[] = $form;
        }

        if (!$removed) {
            return false;
        }

        $this->memo = null;

        update_option(self::OPTION, $kept, false);

        $hidden   = $this->hidden();
        $hidden[] = $formId;

        $this->saveHidden($hidden);

        $meta = $this->meta();

        $this->saveMeta(
            [
                'fetched_at' => $meta['fetched_at'],
                'count'      => count($kept),
                'error'      => $meta['error'],
            ]
        );

        return true;
    }

    /**
     * @return array<int, string> Ids dismissed by an administrator.
     */
    public function hidden(): array
    {
        if ($this->hiddenMemo !== null) {
            return $this->hiddenMemo;
        }

        $stored = get_option(self::HIDDEN_OPTION, []);
        $hidden = [];

        foreach (is_array($stored) ? $stored : [] as $id) {
            $id = trim((string) $id);

            if ($id !== '') {
                $hidden[] = $id;
            }
        }

        $this->hiddenMemo = array_values(array_unique($hidden));

        return $this->hiddenMemo;
    }

    /**
     * Applies the dismissed-form list to a freshly fetched account list.
     *
     * A dismissal only holds while Jotform still calls the form deleted: a form
     * restored from the trash comes back on the screen, because it is a form the
     * account can use again. Ids Jotform no longer returns at all are dropped
     * too, so the option cannot grow without end.
     *
     * @param  array<int, array<string, string>> $forms
     * @return array<int, array<string, string>>
     */
    private function withoutHidden(array $forms): array
    {
        $hidden = $this->hidden();

        if ($hidden === []) {
            return $forms;
        }

        $hidden = array_flip($hidden);
        $kept   = [];
        $stays  = [];

        foreach ($forms as $form) {
            $id = (string) ($form['id'] ?? '');

            if (!isset($hidden[$id])) {
                $kept[] = $form;

                continue;
            }

            if (!self::isDeleted($form)) {
                $kept[] = $form;

                continue;
            }

            $stays[] = $id;
        }

        $this->saveHidden($stays);

        return $kept;
    }

    /**
     * @param array<string, string> $form
     */
    private static function isDeleted(array $form): bool
    {
        return strtoupper((string) ($form['status'] ?? '')) === self::STATUS_DELETED;
    }

    /**
     * @param array<int, string> $hidden
     */
    private function saveHidden(array $hidden): void
    {
        $hidden           = array_values(array_unique($hidden));
        $this->hiddenMemo = $hidden;

        if ($hidden === []) {
            delete_option(self::HIDDEN_OPTION);

            return;
        }

        update_option(self::HIDDEN_OPTION, $hidden, false);
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
