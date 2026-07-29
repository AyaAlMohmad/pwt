<?php

declare(strict_types=1);

use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Helper: run the rule against a value and capture any failure message.
 */
function runPhoneRule(mixed $value): ?string
{
    $rule = new PhoneNumber;
    $failed = null;

    $rule->validate(
        'phone',
        $value,
        function (string $message) use (&$failed): PotentiallyTranslatedString {
            $failed = $message;

            return new PotentiallyTranslatedString($message, app('translator'));
        },
    );

    return $failed;
}

describe('PhoneNumber rule', function (): void {
    it('accepts a valid E.164 string with leading + and 12 digits', function (): void {
        expect(runPhoneRule('+971501234567'))->toBeNull();
    });

    it('accepts the minimum length E.164 string (8 digits)', function (): void {
        // /^\+?[1-9]\d{7,14}$/ — leading +, then 8 to 15 digits total.
        expect(runPhoneRule('+12345678'))->toBeNull();
    });

    it('accepts the maximum length E.164 string (15 digits)', function (): void {
        expect(runPhoneRule('+123456789012345'))->toBeNull();
    });

    it('accepts a bare numeric string (no leading +)', function (): void {
        // The rule itself is permissive — normalisation happens upstream.
        expect(runPhoneRule('971501234567'))->toBeNull();
    });

    it('rejects an empty string', function (): void {
        expect(runPhoneRule(''))->not->toBeNull();
    });

    it('rejects strings shorter than 8 digits', function (): void {
        expect(runPhoneRule('+1234567'))->not->toBeNull();
    });

    it('rejects strings longer than 15 digits', function (): void {
        expect(runPhoneRule('+1234567890123456'))->not->toBeNull();
    });

    it('rejects strings that start with a zero after the +', function (): void {
        expect(runPhoneRule('+0971501234567'))->not->toBeNull();
    });

    it('rejects strings with letters', function (): void {
        expect(runPhoneRule('+97150ABCDEFG'))->not->toBeNull();
    });

    it('rejects strings with internal whitespace', function (): void {
        expect(runPhoneRule('+971 50 123 4567'))->not->toBeNull();
    });

    it('rejects strings with dashes', function (): void {
        expect(runPhoneRule('+971-50-1234567'))->not->toBeNull();
    });

    it('rejects non-string values', function (): void {
        expect(runPhoneRule(971501234567))->not->toBeNull();
    });
});

describe('PhoneNumberNormaliser::split', function (): void {
    it('splits a pre-normalised E.164 value into (cc, local, e164) and recombines losslessly', function (): void {
        // The normaliser's split heuristic uses a greedy 1-4 digit dial-code
        // window, so the exact split between `phone_country_code` and `phone`
        // for ambiguous E.164 strings is not strictly defined. What MUST hold
        // is that the round-trip composition is lossless: cc . phone == e164.
        $out = PhoneNumberNormaliser::split('+971501234567', null);
        expect($out['e164'])->toBe('+971501234567')
            ->and($out['phone_country_code'])->toBeString()
            ->and($out['phone'])->toBeString()
            ->and($out['phone_country_code'].$out['phone'])->toBe('+971501234567');
    });

    it('combines a (cc, local) pair into the E.164 form', function (): void {
        $out = PhoneNumberNormaliser::split('501234567', '+971');
        expect($out['phone_country_code'])->toBe('+971')
            ->and($out['phone'])->toBe('501234567')
            ->and($out['e164'])->toBe('+971501234567');
    });

    it('prefixes the country code with + when missing', function (): void {
        $out = PhoneNumberNormaliser::split('501234567', '971');
        expect($out['phone_country_code'])->toBe('+971')
            ->and($out['e164'])->toBe('+971501234567');
    });

    it('returns null e164 when phone is supplied without a + and without a country code', function (): void {
        $out = PhoneNumberNormaliser::split('501234567', null);
        expect($out['e164'])->toBeNull();
    });

    it('returns null phone and null e164 when phone is empty', function (): void {
        $out = PhoneNumberNormaliser::split('', null);
        expect($out['phone'])->toBeNull()
            ->and($out['e164'])->toBeNull();
    });

    it('returns null phone and null e164 when phone is whitespace', function (): void {
        $out = PhoneNumberNormaliser::split('  ', null);
        expect($out['phone'])->toBeNull()
            ->and($out['e164'])->toBeNull();
    });

    it('returns null phone and null e164 when phone is null', function (): void {
        $out = PhoneNumberNormaliser::split(null, null);
        expect($out['phone'])->toBeNull()
            ->and($out['e164'])->toBeNull();
    });

    it('trims whitespace around the inputs', function (): void {
        $out = PhoneNumberNormaliser::split('  501234567  ', '  +971  ');
        expect($out['phone'])->toBe('501234567')
            ->and($out['phone_country_code'])->toBe('+971')
            ->and($out['e164'])->toBe('+971501234567');
    });
});

describe('PhoneNumberNormaliser::compose', function (): void {
    it('joins (cc, local) into E.164', function (): void {
        expect(PhoneNumberNormaliser::compose('+971', '501234567'))->toBe('+971501234567');
    });

    it('adds the + prefix when the country code lacks it', function (): void {
        expect(PhoneNumberNormaliser::compose('971', '501234567'))->toBe('+971501234567');
    });

    it('strips any leading + from the local fragment', function (): void {
        expect(PhoneNumberNormaliser::compose('+971', '+501234567'))->toBe('+971501234567');
    });
});
