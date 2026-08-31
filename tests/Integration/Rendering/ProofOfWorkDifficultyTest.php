<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Rendering;

use JotformBridge\Rendering\Assets;
use JotformBridge\Submission\Guards\ProofOfWork;
use JotformBridge\Tests\Integration\TestCase;

/**
 * The one number that has to mean the same thing in PHP and in JavaScript.
 *
 * `jotform_bridge_pow_bits` used to move the server alone: the difficulty was
 * written into assets/frontend.js by hand as well, and nothing carried the
 * filtered value across. Filtering it upwards refused every submission on the
 * site, silently — the browser kept solving at sixteen bits and the guard kept
 * asking for more. Filtering it downwards was worse than useless: submissions
 * went through, so nobody noticed, while visitors went on paying the old cost
 * the filter was meant to spare them.
 *
 * Neither direction is visible to a unit test. One class evaluates the filter,
 * a different file states the number, and no test that constructs one object
 * can see that the two disagree. So the check lives here, and it asserts the
 * seam itself: what the page tells the browser, against what the endpoint
 * accepts from it.
 */
final class ProofOfWorkDifficultyTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    public function testThePageTellsTheBrowserWhatTheGuardWillAskFor(): void
    {
        $this->givenAForm('contact');

        jotform_bridge_render('contact');

        $this->assertSame(
            ['contact' => ProofOfWork::BITS],
            $this->localizedPowBits(),
            'The default has to arrive from PHP, not be assumed by the script.'
        );
    }

    public function testAFilteredDifficultyReachesTheBrowserAndIsEnforced(): void
    {
        $this->filter('jotform_bridge_pow_bits', static fn(): int => 20);

        $this->givenAForm('contact');

        jotform_bridge_render('contact');

        $this->assertSame(
            ['contact' => 20],
            $this->localizedPowBits(),
            'A filtered difficulty has to reach the script, or the two sides disagree.'
        );

        // What a browser told the truth will send.
        $this->acceptUpstream();

        $this->assertSame(
            200,
            $this->submitThroughRest('contact', $this->bodyWith($this->solve('contact', 20), 'Sent by a script that was told.'))->get_status()
        );

        // What the old, hardcoded script sent: a valid proof, at the difficulty
        // nobody asked for any more. This is the submission that used to be
        // refused on every filtered site.
        $this->assertSame(
            403,
            $this->submitThroughRest('contact', $this->bodyWith($this->solve('contact', 16), 'Sent by a script that was not.'))->get_status(),
            'A proof solved below the filtered difficulty is not good enough.'
        );
    }

    /**
     * Two integrations of the same form, worth different amounts of work.
     *
     * The filter takes a slug, so a per-page number would have been the wrong
     * shape: the browser is handed a map, and one page carries one settings
     * object however many forms are on it.
     */
    public function testEachIntegrationOnThePageCarriesItsOwnDifficulty(): void
    {
        $this->filter(
            'jotform_bridge_pow_bits',
            static fn(int $bits, string $slug): int => $slug === 'careers' ? 18 : $bits,
            10,
            2
        );

        $this->givenAForm('contact');
        $this->givenAForm('careers');

        $html = jotform_bridge_render('contact') . jotform_bridge_render('careers');

        $this->assertNotSame('', $html);

        $this->assertSame(
            ['contact' => ProofOfWork::BITS, 'careers' => 18],
            $this->localizedPowBits()
        );

        $this->assertSame(
            1,
            substr_count($this->localizedScript(), 'var jotformBridgeSettings ='),
            'A second form must refresh the settings object, not print another one.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function localizedPowBits(): array
    {
        $script = $this->localizedScript();

        $this->assertNotSame('', $script, 'Nothing was localized, so the script was told nothing.');

        $this->assertSame(
            1,
            preg_match('/var jotformBridgeSettings = (\{.*\});/s', $script, $matches),
            'The settings object could not be read back out of the localized data.'
        );

        $settings = json_decode((string) $matches[1], true);

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('powBits', $settings, 'The script is not told the difficulty at all.');

        return array_map('intval', (array) $settings['powBits']);
    }

    private function localizedScript(): string
    {
        $data = wp_scripts()->get_data(Assets::HANDLE, 'data');

        return is_string($data) ? $data : '';
    }

    private function givenAForm(string $slug): void
    {
        $this->createIntegration(['slug' => $slug, 'form_id' => self::FORM_ID, 'mode' => 'auto']);

        $this->syncSchema(self::FORM_ID);
    }

    private function solve(string $slug, int $bits): string
    {
        $timestamp = time();

        for ($nonce = 0; $nonce < 5000000; $nonce++) {
            if (ProofOfWork::meets($slug, $timestamp, $nonce, $bits)) {
                return $timestamp . ':' . $nonce;
            }
        }

        $this->fail(sprintf('No %d-bit proof of work was found.', $bits));
    }

    /**
     * The message differs between the two submissions on purpose: the duplicate
     * guard fingerprints the values and answers before the spam providers run,
     * so two identical bodies would never reach the proof of work at all.
     *
     * @return array<string, mixed>
     */
    private function bodyWith(string $proof, string $message): array
    {
        return [
            'fields' => [
                'full_name.first'   => 'Ada',
                'full_name.last'    => 'Lovelace',
                'email'             => 'ada@example.test',
                'message'           => $message,
                'preferred_contact' => 'E-mail',
            ],
            'spam'   => [ProofOfWork::KEY => $proof],
        ];
    }

    private function acceptUpstream(): void
    {
        $this->mockHttp(
            function (string $url) {
                if (strpos($url, '/submissions') === false) {
                    return null;
                }

                return $this->httpResponse(
                    200,
                    [
                        'responseCode' => 200,
                        'message'      => 'success',
                        'content'      => ['submissionID' => '6100000000000000001'],
                        'limit-left'   => 900,
                    ]
                );
            }
        );
    }
}
