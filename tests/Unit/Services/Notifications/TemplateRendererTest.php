<?php

declare(strict_types=1);

use App\Models\NotificationTemplate;
use App\Services\Notifications\TemplateRenderer;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| TemplateRenderer unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 5.4.
| Minimal `{{var}}` and `{{nested.path}}` substitution. Unknown
| placeholders render to empty string. Locale-aware via translatable JSON.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->renderer = new TemplateRenderer;
});

describe('TemplateRenderer::renderString()', function (): void {
    it('substitutes top-level placeholders', function (): void {
        $out = $this->renderer->renderString('Hello {{name}}', ['name' => 'Layla']);

        expect($out)->toBe('Hello Layla');
    });

    it('substitutes nested dot-notation placeholders', function (): void {
        $out = $this->renderer->renderString(
            'Order #{{order.public_id}} for {{user.name}}',
            ['order' => ['public_id' => 'PUB-42'], 'user' => ['name' => 'Layla']],
        );

        expect($out)->toBe('Order #PUB-42 for Layla');
    });

    it('renders unknown placeholders as empty string (does NOT leave the literal token)', function (): void {
        $out = $this->renderer->renderString('Hi {{missing}}!', []);

        expect($out)->toBe('Hi !');
    });

    it('renders non-scalar resolved values as empty string', function (): void {
        $out = $this->renderer->renderString(
            'Value: {{arr}}',
            ['arr' => ['nested' => 'data']],
        );

        expect($out)->toBe('Value: ');
    });

    it('ignores whitespace inside the placeholder braces', function (): void {
        $out = $this->renderer->renderString('Hi {{   name   }}', ['name' => 'Layla']);

        expect($out)->toBe('Hi Layla');
    });

    it('preserves the template literally when there are no placeholders', function (): void {
        $out = $this->renderer->renderString('Just a normal sentence.', ['name' => 'unused']);

        expect($out)->toBe('Just a normal sentence.');
    });

    it('casts numeric scalar context values to string', function (): void {
        $out = $this->renderer->renderString('Order #{{id}}', ['id' => 4815]);

        expect($out)->toBe('Order #4815');
    });
});

describe('TemplateRenderer::render() and renderBilingual()', function (): void {
    it('renders subject + body in the requested locale (en)', function (): void {
        $template = NotificationTemplate::factory()->state([
            'subject' => ['en' => 'Hello {{user.name}}', 'ar' => 'مرحبا {{user.name}}'],
            'body' => ['en' => 'Body EN', 'ar' => 'النص العربي'],
        ])->create();

        $out = $this->renderer->render($template, 'en', ['user' => ['name' => 'Layla']]);

        expect($out)->toBeArray()
            ->and($out['title'])->toBe('Hello Layla')
            ->and($out['body'])->toBe('Body EN');
    });

    it('renders subject + body in the requested locale (ar)', function (): void {
        $template = NotificationTemplate::factory()->state([
            'subject' => ['en' => 'Hello {{user.name}}', 'ar' => 'مرحبا {{user.name}}'],
            'body' => ['en' => 'Body EN', 'ar' => 'النص العربي'],
        ])->create();

        $out = $this->renderer->render($template, 'ar', ['user' => ['name' => 'ليلى']]);

        expect($out['title'])->toBe('مرحبا ليلى');
        expect($out['body'])->toBe('النص العربي');
    });

    it('renderBilingual returns both locales rendered against the same context', function (): void {
        $template = NotificationTemplate::factory()->state([
            'subject' => ['en' => 'EN: {{order.public_id}}', 'ar' => 'AR: {{order.public_id}}'],
            'body' => ['en' => 'EN body {{order.public_id}}', 'ar' => 'AR body {{order.public_id}}'],
        ])->create();

        $out = $this->renderer->renderBilingual($template, ['order' => ['public_id' => 'XYZ']]);

        expect($out['title']['en'])->toBe('EN: XYZ');
        expect($out['title']['ar'])->toBe('AR: XYZ');
        expect($out['body']['en'])->toBe('EN body XYZ');
        expect($out['body']['ar'])->toBe('AR body XYZ');
    });

    it('falls back to en translation when ar is missing in renderBilingual', function (): void {
        $template = NotificationTemplate::factory()->state([
            'subject' => ['en' => 'Hello {{name}}'],
            'body' => ['en' => 'Body {{name}}'],
        ])->create();

        $out = $this->renderer->renderBilingual($template, ['name' => 'Layla']);

        expect($out['title']['en'])->toBe('Hello Layla');
        expect($out['title']['ar'])->toBe('Hello Layla');
        expect($out['body']['en'])->toBe('Body Layla');
        expect($out['body']['ar'])->toBe('Body Layla');
    });
});
