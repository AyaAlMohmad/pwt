<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22 — Admin notification template editor.
 *
 * Read + update only (no CREATE / DELETE in MVP — see
 * docs/architecture/admin-audit-templates-dashboard.md § 9.4).
 *
 * Every successful update writes one `activity('admin.notification_templates')`
 * row with the full before/after diff under log name
 * `notification_template.updated` so super_admins can audit copy churn.
 */
final class NotificationTemplateService
{
    /**
     * Whitelisted sort columns -> actual DB columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'key' => 'key',
        'channel' => 'channel',
        'updated_at' => 'updated_at',
    ];

    /**
     * Paginated list across the template catalogue.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, NotificationTemplate>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        /** @var Builder<NotificationTemplate> $query */
        $query = NotificationTemplate::query();

        if (isset($filters['channel']) && is_string($filters['channel']) && $filters['channel'] !== '') {
            $query->where('channel', $filters['channel']);
        }

        if (isset($filters['key']) && is_string($filters['key']) && trim($filters['key']) !== '') {
            $like = '%'.trim($filters['key']).'%';
            $query->where('key', 'LIKE', $like);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'key';
        $sort = self::SORT_COLUMNS[$sortInput] ?? 'key';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'asc';
        $order = $order === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $order)->orderBy('id', 'asc');

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Resolve a template by primary key. Throws 404 via `findOrFail`.
     */
    public function find(int $id): NotificationTemplate
    {
        /** @var NotificationTemplate $template */
        $template = NotificationTemplate::query()->findOrFail($id);

        return $template;
    }

    /**
     * Update bilingual subject + body + is_active.
     *
     * `key`, `channel`, and `placeholders` are intentionally NOT touched —
     * the catalogue is owned by the seeder. The Form Request strips those
     * keys before they ever reach this method, but we additionally never
     * read them from the payload here as defence-in-depth.
     *
     * @param  array{subject: array<string, string>, body: array<string, string>, is_active: bool}  $payload
     */
    public function update(NotificationTemplate $template, array $payload, User $actor): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $payload, $actor): NotificationTemplate {
            /** @var NotificationTemplate $locked */
            $locked = NotificationTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldSubject = $locked->getTranslations('subject');
            $oldBody = $locked->getTranslations('body');
            $oldIsActive = (bool) $locked->is_active;

            $locked->setTranslations('subject', $payload['subject']);
            $locked->setTranslations('body', $payload['body']);
            $locked->is_active = $payload['is_active'];
            $locked->save();

            $this->logUpdate(
                template: $locked,
                actor: $actor,
                oldSubject: $oldSubject,
                oldBody: $oldBody,
                oldIsActive: $oldIsActive,
            );

            return $locked;
        });
    }

    /**
     * Emit one `admin.notification_templates` activity row with the full
     * before/after diff. The row surfaces under
     * `GET /api/v1/admin/audit-logs?log_name=admin.notification_templates`.
     *
     * @param  array<string, string>  $oldSubject
     * @param  array<string, string>  $oldBody
     */
    private function logUpdate(
        NotificationTemplate $template,
        User $actor,
        array $oldSubject,
        array $oldBody,
        bool $oldIsActive,
    ): void {
        activity('admin.notification_templates')
            ->event('updated')
            ->causedBy($actor)
            ->performedOn($template)
            ->withProperties([
                'old' => [
                    'subject' => $oldSubject,
                    'body' => $oldBody,
                    'is_active' => $oldIsActive,
                ],
                'new' => [
                    'subject' => $template->getTranslations('subject'),
                    'body' => $template->getTranslations('body'),
                    'is_active' => (bool) $template->is_active,
                ],
            ])
            ->log('notification_template.updated');
    }
}
