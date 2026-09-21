<?php

declare(strict_types=1);

namespace JotformBridge\Api;

if (!defined('ABSPATH')) {
    exit;
}

/** Stores the outcome of the last connection test for the settings screen. */
final class ConnectionState
{
    public const OPTION = 'jotform_bridge_connection';

    public const STATUS_UNKNOWN   = 'unknown';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_FAILED    = 'failed';

    /**
     * @return array{status:string, checked_at:int, account:string, message:string}
     */
    public function get(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $status = isset($stored['status']) ? (string) $stored['status'] : self::STATUS_UNKNOWN;

        if (!in_array($status, [self::STATUS_CONNECTED, self::STATUS_FAILED], true)) {
            $status = self::STATUS_UNKNOWN;
        }

        return [
            'status'     => $status,
            'checked_at' => isset($stored['checked_at']) ? (int) $stored['checked_at'] : 0,
            'account'    => isset($stored['account']) ? (string) $stored['account'] : '',
            'message'    => isset($stored['message']) ? (string) $stored['message'] : '',
        ];
    }

    public function recordSuccess(string $account): void
    {
        update_option(
            self::OPTION,
            [
                'status'     => self::STATUS_CONNECTED,
                'checked_at' => time(),
                'account'    => $account,
                'message'    => '',
            ],
            false
        );
    }

    public function recordFailure(string $message): void
    {
        update_option(
            self::OPTION,
            [
                'status'     => self::STATUS_FAILED,
                'checked_at' => time(),
                'account'    => '',
                'message'    => $message,
            ],
            false
        );
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }
}
