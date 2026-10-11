<?php

declare(strict_types=1);

namespace App\Support\Names;

/**
 * Keeps the `users.name` / `users.first_name` / `users.last_name` triplet
 * consistent across every write path (registration, admin create, admin
 * update, self profile update).
 *
 * Rules:
 *  1. A full `name` is split on the first whitespace run — the first word
 *     becomes `first_name`, the remainder becomes `last_name`. A single
 *     word lands entirely in `first_name` and `last_name` stays null.
 *  2. When `first_name` / `last_name` are edited, `name` is recomposed
 *     from them so the display name never drifts from its parts.
 */
final class PersonNameNormaliser
{
    /**
     * Split a display name into its (first, last) parts.
     *
     * @return array{first_name: string|null, last_name: string|null}
     */
    public static function split(?string $name): array
    {
        $name = self::clean($name);

        if ($name === null) {
            return ['first_name' => null, 'last_name' => null];
        }

        $parts = preg_split('/\s+/u', $name, 2);
        if ($parts === false || $parts === []) {
            return ['first_name' => $name, 'last_name' => null];
        }

        return [
            'first_name' => $parts[0],
            'last_name' => self::clean($parts[1] ?? null),
        ];
    }

    /**
     * Recompose a display name from its parts. Null when both are empty.
     */
    public static function compose(?string $firstName, ?string $lastName): ?string
    {
        $parts = array_filter(
            [self::clean($firstName), self::clean($lastName)],
            static fn (?string $part): bool => $part !== null,
        );

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Reconcile the name triplet for a (possibly partial) update payload.
     *
     * Only the keys present in `$payload` are considered "sent by the
     * client"; the current column values are needed to tell an actual
     * edit from an unchanged echo (admin dashboards resend every field).
     *
     * Precedence:
     *  - `first_name` / `last_name` edited  → they win, `name` recomposed.
     *  - otherwise `name` present           → split into first / last
     *    (also backfills rows whose parts were never populated).
     *
     * @param  array<string, mixed>  $payload  Validated request payload.
     * @return array<string, string|null> Subset of name/first_name/last_name to persist.
     */
    public static function reconcile(
        array $payload,
        ?string $currentName = null,
        ?string $currentFirstName = null,
        ?string $currentLastName = null,
    ): array {
        $hasName = array_key_exists('name', $payload);
        $hasFirst = array_key_exists('first_name', $payload);
        $hasLast = array_key_exists('last_name', $payload);

        $name = $hasName ? self::clean(self::asString($payload['name'])) : null;
        $first = $hasFirst ? self::clean(self::asString($payload['first_name'])) : null;
        $last = $hasLast ? self::clean(self::asString($payload['last_name'])) : null;

        $partsEdited = ($hasFirst && $first !== self::clean($currentFirstName))
            || ($hasLast && $last !== self::clean($currentLastName));

        if ($partsEdited) {
            $finalFirst = $hasFirst ? $first : self::clean($currentFirstName);
            $finalLast = $hasLast ? $last : self::clean($currentLastName);
            $composed = self::compose($finalFirst, $finalLast);

            $result = ['first_name' => $finalFirst, 'last_name' => $finalLast];
            if ($composed !== null) {
                $result['name'] = $composed;
            } elseif ($hasName && $name !== null) {
                $result['name'] = $name;
            }

            return $result;
        }

        if ($hasName && $name !== null) {
            $result = ['name' => $name];

            $partsMissing = self::clean($currentFirstName) === null
                && self::clean($currentLastName) === null;

            if ($name !== self::clean($currentName) || $partsMissing) {
                return $result + self::split($name);
            }

            return $result;
        }

        // Nothing meaningful changed — echo back only what was sent so
        // the caller keeps its "explicitly provided" semantics.
        $result = [];
        if ($hasFirst) {
            $result['first_name'] = $first;
        }
        if ($hasLast) {
            $result['last_name'] = $last;
        }

        return $result;
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        $collapsed = $collapsed ?? '';

        return $collapsed === '' ? null : $collapsed;
    }
}
