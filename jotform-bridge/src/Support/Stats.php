<?php

declare(strict_types=1);

namespace JotformBridge\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Counts how every submission ended, per integration and per day.
 *
 * This exists because the anti-abuse layers make a new kind of failure
 * possible. A form that quietly rejects real people looks exactly like a form
 * that is working: the visitor sees a message, goes away, and nobody hears
 * about it. Without numbers there is no way to tell a guard that is doing its
 * job from a threshold set too tight, and the second one costs real leads.
 *
 * Every counter is one of the pipeline's own exit points, so the tally is a
 * description of what the code actually did rather than a parallel model of it.
 *
 * Nothing here is personal data: outcome names, counts and the names of fields
 * that failed validation. No values, no addresses, no identifiers. The plugin's
 * promise is that submissions live in Jotform, and a local log of them would
 * break it.
 *
 * The write is one option update per submission, which is the right trade for
 * the traffic a contact form sees. A site sending thousands a day would want
 * this buffered; it is not that site.
 */
final class Stats
{
    public const OPTION = 'jotform_bridge_stats';

    /** Reached Jotform and was accepted. */
    public const OK = 'ok';

    /** No integration with that slug. */
    public const UNKNOWN = 'unknown';

    /** The integration exists but is switched off. */
    public const INACTIVE = 'inactive';

    /** Refused by the rate limit. */
    public const THROTTLED = 'throttled';

    /** Refused by the account-wide quota guard. */
    public const QUOTA = 'quota';

    /** The form has never been synced, or its schema is unusable. */
    public const NO_SCHEMA = 'no_schema';

    /** The visitor's values did not validate. */
    public const INVALID = 'invalid';

    /** The request carried no usable values at all — normally a template bug. */
    public const EMPTY_BODY = 'empty';

    /** The same values had just been accepted. */
    public const DUPLICATE = 'duplicate';

    /** Refused by a spam provider. */
    public const SPAM = 'spam';

    /** Jotform refused it. */
    public const UPSTREAM = 'upstream';

    /**
     * Bucket for requests that never resolved to an integration.
     *
     * Anything a visitor can name has to be counted here rather than under the
     * name they used, or probing the endpoint would grow the option by one key
     * per invented slug.
     */
    public const GLOBAL_SCOPE = '*';

    /** Outcomes in the order the admin screens list them. */
    public const OUTCOMES = [
        self::OK,
        self::INVALID,
        self::EMPTY_BODY,
        self::DUPLICATE,
        self::SPAM,
        self::THROTTLED,
        self::QUOTA,
        self::NO_SCHEMA,
        self::UPSTREAM,
        self::INACTIVE,
        self::UNKNOWN,
    ];

    /** Outcomes that mean a person tried to reach you and did not. */
    public const LOST = [
        self::SPAM,
        self::THROTTLED,
        self::QUOTA,
        self::NO_SCHEMA,
        self::UPSTREAM,
        self::EMPTY_BODY,
    ];

    private const HISTORY_DAYS = 30;
    private const MAX_SLUGS    = 50;
    private const MAX_FIELDS   = 20;

    /**
     * Records one finished submission.
     *
     * @param array<int, string> $fieldErrors Semantic paths that failed validation.
     */
    public function record(string $slug, string $outcome, array $fieldErrors = []): void
    {
        $slug  = $slug === '' ? self::GLOBAL_SCOPE : $slug;
        $today = self::today();
        $all   = $this->all();

        if (!isset($all[$slug]) && count($all) >= self::MAX_SLUGS) {
            $slug = self::GLOBAL_SCOPE;
        }

        $entry = isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
        $days  = isset($entry['days']) && is_array($entry['days']) ? $entry['days'] : [];
        $day   = isset($days[$today]) && is_array($days[$today]) ? $days[$today] : [];

        $counts            = isset($day['counts']) && is_array($day['counts']) ? $day['counts'] : [];
        $counts[$outcome]  = (int) ($counts[$outcome] ?? 0) + 1;
        $day['counts']     = $counts;

        if ($fieldErrors !== []) {
            $day['fields'] = $this->tally(
                isset($day['fields']) && is_array($day['fields']) ? $day['fields'] : [],
                $fieldErrors
            );
        }

        $days[$today]  = $day;
        $entry['days'] = $this->prune($days);

        if ($outcome === self::OK) {
            $entry['last_ok'] = time();
        }

        $all[$slug] = $entry;

        update_option(self::OPTION, $all, false);
    }

    /**
     * Totals for one integration over the last N days, today included.
     *
     * @return array{counts: array<string, int>, attempts: int, ok: int, lost: int}
     */
    public function summary(string $slug, int $days = 7): array
    {
        $counts   = [];
        $attempts = 0;

        foreach ($this->window($slug, $days) as $day) {
            foreach ($day['counts'] as $outcome => $count) {
                $counts[$outcome] = ($counts[$outcome] ?? 0) + (int) $count;
                $attempts        += (int) $count;
            }
        }

        $lost = 0;

        foreach (self::LOST as $outcome) {
            $lost += $counts[$outcome] ?? 0;
        }

        return [
            'counts'   => $counts,
            'attempts' => $attempts,
            'ok'       => $counts[self::OK] ?? 0,
            'lost'     => $lost,
        ];
    }

    /**
     * The fields visitors fail most often, worst first.
     *
     * Not an anti-abuse number at all: a field that half the submissions trip
     * over is usually a validator that is stricter than the form suggests, or a
     * template that does not say what it wants.
     *
     * @return array<string, int>
     */
    public function fieldErrors(string $slug, int $days = 7, int $limit = 5): array
    {
        $fields = [];

        foreach ($this->window($slug, $days) as $day) {
            foreach ($day['fields'] as $path => $count) {
                $fields[(string) $path] = ($fields[(string) $path] ?? 0) + (int) $count;
            }
        }

        arsort($fields);

        return array_slice($fields, 0, $limit, true);
    }

    public function lastSuccess(string $slug): int
    {
        $all = $this->all();

        return isset($all[$slug]['last_ok']) ? (int) $all[$slug]['last_ok'] : 0;
    }

    /**
     * A one-word verdict for the admin screens.
     *
     * `broken` is the one worth building all of this for: attempts are arriving
     * and none of them are getting through, which is invisible from anywhere
     * else until somebody complains.
     *
     * @return string One of: idle, ok, broken, noisy.
     */
    public function health(string $slug): string
    {
        $today = $this->summary($slug, 1);

        if ($today['attempts'] === 0) {
            return 'idle';
        }

        if ($today['ok'] === 0) {
            return 'broken';
        }

        $rejected = ($today['counts'][self::SPAM] ?? 0) + ($today['counts'][self::THROTTLED] ?? 0);

        if ($today['attempts'] >= 10 && $rejected >= (int) ceil($today['attempts'] * 0.9)) {
            return 'noisy';
        }

        return 'ok';
    }

    /**
     * Drops the tally of one integration, e.g. when it is deleted.
     */
    public function forget(string $slug): void
    {
        $all = $this->all();

        if (!isset($all[$slug])) {
            return;
        }

        unset($all[$slug]);

        update_option(self::OPTION, $all, false);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * The last N days of one integration, normalized.
     *
     * @return array<string, array{counts: array<string, int>, fields: array<string, int>}>
     */
    private function window(string $slug, int $days): array
    {
        $all   = $this->all();
        $entry = isset($all[$slug]['days']) && is_array($all[$slug]['days']) ? $all[$slug]['days'] : [];
        $from  = self::today($days - 1);

        $window = [];

        foreach ($entry as $day => $data) {
            if ((string) $day < $from || !is_array($data)) {
                continue;
            }

            $window[(string) $day] = [
                'counts' => isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : [],
                'fields' => isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [],
            ];
        }

        return $window;
    }

    /**
     * @param array<string, int>  $fields
     * @param array<int, string>  $paths
     *
     * @return array<string, int>
     */
    private function tally(array $fields, array $paths): array
    {
        foreach ($paths as $path) {
            $path = (string) $path;

            if ($path === '') {
                continue;
            }

            $fields[$path] = (int) ($fields[$path] ?? 0) + 1;
        }

        arsort($fields);

        return array_slice($fields, 0, self::MAX_FIELDS, true);
    }

    /**
     * @param array<string, mixed> $days
     *
     * @return array<string, mixed>
     */
    private function prune(array $days): array
    {
        if (count($days) <= self::HISTORY_DAYS) {
            return $days;
        }

        krsort($days);

        return array_slice($days, 0, self::HISTORY_DAYS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * UTC, like the quota guard: a day boundary that follows a timezone setting
     * is a day boundary that can be moved.
     */
    private static function today(int $daysAgo = 0): string
    {
        return gmdate('Y-m-d', time() - $daysAgo * DAY_IN_SECONDS);
    }
}
