<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use JotformBridge\Integrations\Integration;
use JotformBridge\Plugin;
use JotformBridge\Submission\Guards\ProofOfWork;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Base class for the tests that run against a real WordPress.
 *
 * It gives each test the three things a request has and a PHPUnit process does
 * not: a plugin booted from scratch, storage with nothing in it, and an end to
 * the request — wp_die() and wp_safe_redirect() become exceptions rather than
 * the end of the test run.
 *
 * It also makes the network impossible to reach by accident. Any HTTP request
 * a test did not mock throws, so an unmocked upstream call is a loud failure
 * rather than a live call to Jotform.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /** @var array<int, int> Posts created by the test, removed afterwards. */
    private array $posts = [];

    /** @var array<int, array{hook:string, callback:callable, priority:int}> */
    private array $filters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
        $_FILES   = [];

        wp_set_current_user(0);

        // Before the interceptors, not after: the reset removes every callback
        // belonging to a class in the plugin's namespace, and this class is in
        // it. Registering first would mean sweeping our own hooks away.
        Runtime::newRequest();

        $this->interceptExit();
        $this->blockNetwork();
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $postId) {
            wp_delete_post($postId, true);
        }

        $this->posts = [];

        foreach ($this->filters as $filter) {
            remove_filter($filter['hook'], $filter['callback'], $filter['priority']);
        }

        $this->filters = [];

        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    /**
     * Adds a filter for the duration of one test.
     */
    protected function filter(string $hook, callable $callback, int $priority = 10, int $args = 4): void
    {
        add_filter($hook, $callback, $priority, $args);

        $this->filters[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }

    protected function plugin(): Plugin
    {
        return Plugin::instance();
    }

    /**
     * The administrator wp_install() created, as the current user.
     */
    protected function actAsAdministrator(): int
    {
        $user = get_user_by('login', 'admin');

        $this->assertNotFalse($user, 'The installed administrator is missing.');

        wp_set_current_user($user->ID);

        return (int) $user->ID;
    }

    /**
     * A logged-in user without `manage_options`, for the capability checks.
     */
    protected function actAsSubscriber(): int
    {
        $userId = username_exists('subscriber');

        if (!is_int($userId)) {
            $userId = wp_insert_user(
                [
                    'user_login' => 'subscriber',
                    'user_pass'  => wp_generate_password(),
                    'user_email' => 'subscriber@example.test',
                    'role'       => 'subscriber',
                ]
            );

            $this->assertIsInt($userId, 'The subscriber could not be created.');
        }

        wp_set_current_user($userId);

        return $userId;
    }

    /**
     * Fills $_POST and $_REQUEST the way a form submission would.
     *
     * @param array<string, mixed> $data
     */
    protected function submitForm(array $data): void
    {
        $_POST    = $data;
        $_REQUEST = array_merge($_REQUEST, $data);
    }

    /**
     * Runs a handler that is expected to end in a redirect.
     */
    protected function expectRedirect(callable $handler): RedirectException
    {
        try {
            $handler();
        } catch (RedirectException $redirect) {
            return $redirect;
        }

        $this->fail('The handler did not redirect.');
    }

    /**
     * Runs a handler that is expected to end in wp_die().
     */
    protected function expectWpDie(callable $handler): WpDieException
    {
        try {
            $handler();
        } catch (WpDieException $died) {
            return $died;
        }

        $this->fail('The handler did not call wp_die().');
    }

    /**
     * Runs an admin-ajax handler and decodes what it answered.
     *
     * wp_send_json() ends the request with wp_die() once WordPress believes it
     * is serving Ajax, and prints the JSON on the way out — so the answer has
     * to be caught out of the output buffer rather than returned.
     *
     * The HTTP status is not part of what comes back, and cannot be: WordPress
     * only calls status_header() when headers_sent() is false, and by the time
     * PHPUnit has printed its first dot it is true. What the handler answers is
     * assertable; the status it would have set alongside is not.
     *
     * @return array{success: bool, data: mixed}
     */
    protected function ajax(callable $handler): array
    {
        $this->doingAjax();

        ob_start();

        try {
            $handler();
        } catch (WpDieException $died) {
            // Expected: this is how wp_send_json() ends.
        } finally {
            $body = (string) ob_get_clean();
        }

        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded, 'The Ajax handler did not answer with JSON: ' . $body);
        $this->assertArrayHasKey('success', $decoded);

        return [
            'success' => (bool) $decoded['success'],
            'data'    => $decoded['data'] ?? null,
        ];
    }

    /**
     * Makes WordPress believe it is serving admin-ajax.
     *
     * Without it check_ajax_referer() answers a bad nonce with die() rather
     * than with the filtered wp_die(), and that would take the test run with
     * it.
     */
    protected function doingAjax(): void
    {
        $this->filter('wp_doing_ajax', '__return_true', 10, 0);
    }

    /**
     * Answers the plugin's HTTP requests from the test instead of the network.
     *
     * The callback receives the URL and the request arguments and returns a
     * response array, a WP_Error, or null to leave the request unanswered — in
     * which case the block below turns it into a failure.
     */
    protected function mockHttp(callable $responder): void
    {
        $this->filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($responder) {
                $response = $responder((string) $url, is_array($args) ? $args : []);

                return $response ?? $preempt;
            },
            10,
            3
        );
    }

    /**
     * A wp_remote_*() style response carrying a Jotform envelope.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function httpResponse(int $status, array $body): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $status, 'message' => get_status_header_desc($status)],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * A sanitized Jotform API fixture, shared with the unit suite.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        $path = dirname(__DIR__) . '/Fixtures/Jotform/' . $name . '.json';

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, sprintf('Fixture %s is not valid JSON.', $name));

        return $decoded;
    }

    /**
     * Stores an integration through the repository the plugin itself uses.
     *
     * Writing the option directly would be quicker and wrong: the repository
     * memoizes within a request, and a test that went behind it would be
     * asserting against a copy the handler under test cannot see.
     *
     * @param array<string, mixed> $values
     */
    protected function createIntegration(array $values = []): Integration
    {
        $integration = Integration::fromInput(
            array_merge(
                [
                    'name'    => 'Contact',
                    'slug'    => 'contact',
                    'form_id' => '240000000000001',
                    'mode'    => Integration::MODE_AUTO,
                ],
                $values
            )
        );

        $errors = $this->plugin()->integrations()->save($integration);

        $this->assertSame([], $errors, 'The integration could not be stored.');

        return $integration;
    }

    /**
     * Syncs a schema the way the admin action does: through the repository,
     * with Jotform's answer coming from a fixture.
     */
    protected function syncSchema(string $formId, string $fixture = 'form-questions'): void
    {
        $questions = $this->fixture($fixture);

        $this->mockHttp(
            function (string $url) use ($questions) {
                return strpos($url, '/questions') !== false
                    ? $this->httpResponse(200, $questions)
                    : null;
            }
        );

        $response = $this->plugin()->schemas()->sync($formId);

        $this->assertTrue($response->isSuccess(), 'The schema fixture did not sync: ' . $response->errorMessage());
    }

    /**
     * Dispatches a real request through the REST server.
     *
     * @param array<string, mixed> $body
     */
    protected function submitThroughRest(string $slug, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/jotform-bridge/v1/submit/' . $slug);

        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }

    /**
     * Solves the proof of work the way the frontend script does.
     *
     * The guard refuses a submission that arrives without one, so a test that
     * wants to reach the upstream boundary has to pay the same 65,000 hashes a
     * visitor's browser pays.
     *
     * The difficulty comes from ProofOfWork::bits() rather than from the
     * constant, because that is where the script gets it too: a test that
     * always solved at 16 could not tell whether a filtered site still works.
     */
    protected function proofOfWork(string $slug): string
    {
        $timestamp = time();
        $bits      = ProofOfWork::bits($slug);

        for ($nonce = 0; $nonce < 5000000; $nonce++) {
            if (ProofOfWork::meets($slug, $timestamp, $nonce, $bits)) {
                return $timestamp . ':' . $nonce;
            }
        }

        $this->fail('No proof of work was found.');
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function createPage(array $args = []): int
    {
        $postId = wp_insert_post(
            array_merge(
                [
                    'post_title'  => 'Thank you',
                    'post_name'   => 'thank-you',
                    'post_type'   => 'page',
                    'post_status' => 'publish',
                ],
                $args
            ),
            true
        );

        $this->assertIsInt($postId, 'The page could not be created.');

        $this->posts[] = $postId;

        return $postId;
    }

    /**
     * Turns the two ways a request ends into exceptions.
     */
    private function interceptExit(): void
    {
        $handler = static function ($message, $title = '', $args = []): void {
            throw new WpDieException(
                $message instanceof WP_Error ? (string) $message->get_error_message() : (string) $message,
                is_array($args) && isset($args['response']) ? (int) $args['response'] : 0
            );
        };

        // The filter is asked for the handler, not called as one: WordPress
        // takes what comes back and calls that with the message.
        foreach (['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler'] as $hook) {
            $this->filter($hook, static fn(): callable => $handler, 10, 0);
        }

        $this->filter(
            'wp_redirect',
            static function ($location, $status = 302) {
                throw new RedirectException((string) $location, (int) $status);
            },
            10,
            2
        );
    }

    /**
     * Makes an unmocked HTTP request a test failure.
     */
    private function blockNetwork(): void
    {
        $this->filter(
            'pre_http_request',
            static function ($preempt, $args, $url) {
                // Something earlier answered this one; only an unanswered
                // request is a test that would have gone to the network.
                if ($preempt !== false) {
                    return $preempt;
                }

                throw new RuntimeException(
                    'The test made an HTTP request nothing mocked: ' . (string) $url
                );
            },
            999,
            3
        );
    }
}
