<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Max upload size (kilobytes)
    |--------------------------------------------------------------------------
    |
    | Contract: 5 MB hard cap per CLAUDE.md § 16.8.
    | This value backs:
    |   - the Laravel `max:` validator rule on `UploadFileRequest`
    |   - the PHP-layer tripwire that returns 413 when the request body
    |     exceeds the cap (before validation runs).
    |
    | PHP's `post_max_size` / `upload_max_filesize` should be set slightly
    | above this (e.g. 8M / 6M respectively) so the request still reaches
    | Laravel and the controller can shape the 413 response.
    */

    'max_kb' => env('UPLOAD_MAX_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Allowed mime types
    |--------------------------------------------------------------------------
    */

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed extensions
    |--------------------------------------------------------------------------
    |
    | The Laravel `mimes:` rule consults the PHP/Laravel mime map by
    | extension. Both this and `allowed_mimes` are enforced so a renamed
    | file (e.g. `evil.png` containing PHP bytes) is rejected.
    */

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

];
