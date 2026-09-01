<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Rest;

use JotformBridge\Rest\SubmissionController;
use JotformBridge\Submission\SubmissionPipeline;
use JotformBridge\Tests\TestCase;

/**
 * The controller's own behaviour: when it builds the pipeline, and that
 * nothing on the registration path builds one at all.
 */
final class SubmissionControllerTest extends TestCase
{
    /**
     * The route exists on every request; the machinery behind it is needed on
     * almost none of them.
     */
    public function testThePipelineIsNotBuiltUntilSomethingIsSubmitted(): void
    {
        $built = 0;

        new SubmissionController(
            static function () use (&$built): SubmissionPipeline {
                $built++;

                throw new \RuntimeException('Not expected to be called.');
            }
        );

        $this->assertSame(0, $built);
    }

    /**
     * Registering the route must not build it either — that is the whole point.
     */
    public function testRegisteringTheRouteDoesNotBuildThePipeline(): void
    {
        $built = 0;

        $controller = new SubmissionController(
            static function () use (&$built): SubmissionPipeline {
                $built++;

                throw new \RuntimeException('Not expected to be called.');
            }
        );

        \Brain\Monkey\Functions\when('register_rest_route')->justReturn(true);

        $controller->register();
        $controller->registerRoutes();

        $this->assertSame(0, $built);
    }

    public function testTheEndpointUrlNeedsNoPipelineAtAll(): void
    {
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn(string $path): string => 'https://example.com/wp-json/' . $path
        );

        $this->assertSame(
            'https://example.com/wp-json/jotform-bridge/v1/submit/contact',
            SubmissionController::endpoint('contact')
        );
    }
}
