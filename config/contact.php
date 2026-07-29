<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Contact form (Phase 13)
|--------------------------------------------------------------------------
|
| Wiring for the public guest contact-form endpoint.
| See docs/architecture/contact.md § 8.5.
*/

return [

    /*
     * Toggle for the email `dns` RFC-MX check. Default true in production;
     * tests / CI / local envs without DNS lookup may set it to false to
     * accept addresses with non-resolving domains.
     */
    'validate_email_dns' => env('CONTACT_EMAIL_VALIDATE_DNS', true),

];
