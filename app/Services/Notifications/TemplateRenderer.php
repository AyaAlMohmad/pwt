<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;

/**
 * Renders a {@see NotificationTemplate} into a `{title, body}` tuple
 * for the requested locale, substituting `{{placeholder}}` tokens
 * (supports dotted paths e.g. `{{order.public_id}}`).
 *
 * The renderer is deliberately minimal:
 *   - No conditionals.
 *   - No loops.
 *   - No helper functions.
 *   - Unknown placeholders render to empty string (avoids leaking
 *     template syntax to customers when authoring goes wrong).
 *
 * See docs/architecture/notifications.md § 5.4.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{title: string, body: string}
     */
    public function render(NotificationTemplate $template, string $locale, array $context): array
    {
        $subject = (string) $template->getTranslation('subject', $locale);
        $body = (string) $template->getTranslation('body', $locale);

        return [
            'title' => $this->renderString($subject, $context),
            'body' => $this->renderString($body, $context),
        ];
    }

    /**
     * Render both translations (en + ar) of subject + body into a flat
     * array — used by the dispatcher to persist a translatable
     * `notifications.title` / `notifications.body`.
     *
     * @param  array<string, mixed>  $context
     * @return array{title: array<string, string>, body: array<string, string>}
     */
    public function renderBilingual(NotificationTemplate $template, array $context): array
    {
        $subjectTranslations = $template->getTranslations('subject');
        $bodyTranslations = $template->getTranslations('body');

        $titleOut = [];
        foreach (['en', 'ar'] as $locale) {
            $subjectRaw = is_string($subjectTranslations[$locale] ?? null)
                ? $subjectTranslations[$locale]
                : (string) ($subjectTranslations['en'] ?? '');
            $titleOut[$locale] = $this->renderString($subjectRaw, $context);
        }

        $bodyOut = [];
        foreach (['en', 'ar'] as $locale) {
            $bodyRaw = is_string($bodyTranslations[$locale] ?? null)
                ? $bodyTranslations[$locale]
                : (string) ($bodyTranslations['en'] ?? '');
            $bodyOut[$locale] = $this->renderString($bodyRaw, $context);
        }

        return ['title' => $titleOut, 'body' => $bodyOut];
    }

    /**
     * Substitute {{placeholder}} tokens against the context. Supports
     * dotted paths (e.g. `{{order.public_id}}` resolves
     * `$context['order']['public_id']`).
     *
     * @param  array<string, mixed>  $context
     */
    public function renderString(string $template, array $context): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $value = data_get($context, $m[1]);

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );

        return $result ?? $template;
    }
}
