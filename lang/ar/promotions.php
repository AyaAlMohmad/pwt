<?php

declare(strict_types=1);

return [
    // Success messages
    'banners_retrieved' => 'تم استرجاع البانرات.',
    'promotions_retrieved' => 'تم استرجاع العروض.',
    'testimonials_retrieved' => 'تم استرجاع آراء العملاء.',
    'validated' => 'تم التحقق من رمز العرض.',

    // Validation messages
    'invalid_filters' => 'قيمة واحدة أو أكثر من معايير التصفية غير صحيحة.',
    'code_required' => 'يرجى إدخال رمز العرض.',
    'subtotal_required' => 'إجمالي السلة مطلوب.',
    'subtotal_invalid' => 'يجب أن يكون إجمالي السلة رقمًا غير سالب.',
    'currency_required' => 'العملة مطلوبة.',
    'currency_invalid' => 'العملة غير مدعومة.',

    // /validate outcomes
    'reason' => [
        'applied' => 'تم تطبيق رمز العرض — لقد وفّرت :amount.',
        'code_unknown' => 'هذا الرمز غير صالح.',
        'not_started' => 'هذا الرمز لم يبدأ بعد.',
        'expired' => 'هذا الرمز منتهي الصلاحية.',
        'inactive' => 'هذا الرمز غير متاح.',
        'min_order_not_met' => 'أضف :amount إضافية إلى سلتك لاستخدام هذا الرمز.',
        'currency_mismatch' => 'هذا الرمز لا ينطبق على عملة :currency.',
        'scope_mismatch' => 'هذا الرمز لا ينطبق على المنتجات في سلتك.',
        'per_user_limit_reached' => 'لقد استخدمت هذا الرمز من قبل.',
        'total_limit_reached' => 'هذا الرمز لم يعد متاحًا.',
    ],

    'rate_limited' => 'محاولات كثيرة جدًا. يرجى الانتظار قليلاً.',
];
