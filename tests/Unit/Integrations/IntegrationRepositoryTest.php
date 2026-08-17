<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Integrations;

use Brain\Monkey\Functions;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Tests\TestCase;

final class IntegrationRepositoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private IntegrationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

                return true;
            }
        );

        $this->repository = new IntegrationRepository();
    }

    public function testAnIntegrationRoundTripsThroughTheOption(): void
    {
        $errors = $this->repository->save($this->integration('contact'));

        $this->assertSame([], $errors);
        $this->assertArrayHasKey(IntegrationRepository::OPTION, $this->options);

        $stored = $this->repository->get('contact');

        $this->assertNotNull($stored);
        $this->assertSame('Contact', $stored->name());
        $this->assertSame('240000000000001', $stored->formId());
        $this->assertSame(Integration::MODE_CUSTOM, $stored->mode());
        $this->assertSame('contact', $stored->templateSlug());
        $this->assertTrue($stored->isActive());
        $this->assertGreaterThan(0, $stored->createdAt());
    }

    public function testSlugsAreUnique(): void
    {
        $this->repository->save($this->integration('contact'));

        $errors = $this->repository->save($this->integration('contact', 'Another Contact'));

        $this->assertNotSame([], $errors);
        $this->assertCount(1, $this->repository->all());
        $this->assertSame('Contact', (string) $this->repository->get('contact')->name());
    }

    public function testAnIntegrationCanBeSavedOverItself(): void
    {
        $this->repository->save($this->integration('contact'));

        $errors = $this->repository->save($this->integration('contact', 'Renamed'), 'contact');

        $this->assertSame([], $errors);
        $this->assertSame('Renamed', $this->repository->get('contact')->name());
    }

    public function testRenamingASlugFreesTheOldOne(): void
    {
        $this->repository->save($this->integration('contact'));

        $errors = $this->repository->save($this->integration('contact-us'), 'contact');

        $this->assertSame([], $errors);
        $this->assertNull($this->repository->get('contact'));
        $this->assertNotNull($this->repository->get('contact-us'));
    }

    public function testRenamingOntoAnExistingSlugIsRejected(): void
    {
        $this->repository->save($this->integration('contact'));
        $this->repository->save($this->integration('careers'));

        $errors = $this->repository->save($this->integration('contact'), 'careers');

        $this->assertNotSame([], $errors);
        $this->assertNotNull($this->repository->get('careers'));
    }

    public function testSeveralIntegrationsMayShareOneJotformForm(): void
    {
        $this->repository->save($this->integration('consultation'));
        $this->repository->save($this->integration('consultation-popup'));

        $this->assertCount(2, $this->repository->all());
        $this->assertSame(['240000000000001'], $this->repository->usedFormIds());
    }

    public function testValidationRejectsIncompleteIntegrations(): void
    {
        $this->assertNotSame(
            [],
            $this->repository->save(new Integration('contact', '', '240000000000001', 'custom', 'contact', true)),
            'A name is required.'
        );

        $this->assertNotSame(
            [],
            $this->repository->save(new Integration('', 'Contact', '240000000000001', 'custom', 'contact', true)),
            'A slug is required.'
        );

        $this->assertNotSame(
            [],
            $this->repository->save(new Integration('contact', 'Contact', '', 'custom', 'contact', true)),
            'A Jotform form is required.'
        );

        $this->assertNotSame(
            [],
            $this->repository->save(new Integration('contact', 'Contact', '240000000000001', 'custom', '', true)),
            'A custom-template integration needs a template.'
        );

        $this->assertSame([], $this->repository->all());
    }

    public function testAutoModeDoesNotRequireATemplate(): void
    {
        $errors = $this->repository->save(
            new Integration('auto', 'Auto', '240000000000001', Integration::MODE_AUTO, '', true)
        );

        $this->assertSame([], $errors);
        $this->assertSame(Integration::MODE_AUTO, $this->repository->get('auto')->mode());
    }

    public function testDeactivatingKeepsTheIntegrationButExcludesItFromActive(): void
    {
        $this->repository->save($this->integration('contact'));

        $this->assertTrue($this->repository->setActive('contact', false));
        $this->assertCount(1, $this->repository->all());
        $this->assertSame([], $this->repository->active());
        $this->assertFalse($this->repository->get('contact')->isActive());
    }

    public function testDeleteRemovesOnlyTheGivenIntegration(): void
    {
        $this->repository->save($this->integration('contact'));
        $this->repository->save($this->integration('careers'));

        $this->assertTrue($this->repository->delete('contact'));
        $this->assertFalse($this->repository->delete('contact'));
        $this->assertSame(['careers'], array_keys($this->repository->all()));
    }

    public function testInputIsSanitizedIntoASafeSlug(): void
    {
        $integration = Integration::fromInput(
            [
                'name'     => '  Contact <b>Us</b>  ',
                'slug'     => '../../Contact Us!',
                'form_id'  => ' 240000000000001 ',
                'mode'     => 'custom',
                'template' => 'contact',
                'active'   => '1',
            ]
        );

        $this->assertSame('Contact Us', $integration->name());
        $this->assertSame('contactus', $integration->slug());
        $this->assertSame('240000000000001', $integration->formId());
    }

    public function testANonNumericFormIdIsDiscarded(): void
    {
        $integration = Integration::fromInput(
            [
                'name'    => 'Contact',
                'slug'    => 'contact',
                'form_id' => '../../etc/passwd',
            ]
        );

        $this->assertSame('', $integration->formId());
    }

    public function testAnUnknownRenderingModeFallsBackToCustom(): void
    {
        $integration = Integration::fromInput(
            [
                'name' => 'Contact',
                'slug' => 'contact',
                'mode' => 'iframe',
            ]
        );

        $this->assertSame(Integration::MODE_CUSTOM, $integration->mode());
    }

    private function integration(string $slug, string $name = 'Contact'): Integration
    {
        return new Integration(
            $slug,
            $name,
            '240000000000001',
            Integration::MODE_CUSTOM,
            'contact',
            true
        );
    }
}
