<?php

declare(strict_types=1);

return [
    // Success messages
    'order_placed' => 'تم تقديم الطلب',
    'order_replayed' => 'تم تقديم الطلب مسبقاً',
    'orders_retrieved' => 'تم استرجاع الطلبات',
    'order_retrieved' => 'تم استرجاع الطلب',
    'cancel_succeeded' => 'تم إلغاء الطلب',
    'cancel_with_refund_note' => 'تم إلغاء الطلب. سيتواصل معك فريقنا بخصوص إجراءات الاسترداد.',
    'tracking_retrieved' => 'تم استرجاع حالة التتبع',
    'dashboard_retrieved' => 'تم استرجاع لوحة التحكم',

    // Validation messages
    'items_required' => 'مطلوب على الأقل عنصر واحد.',
    'items_max' => 'لا يمكن إضافة أكثر من :max عناصر في الطلب الواحد.',
    'product_id_required' => 'معرّف المنتج مطلوب لكل عنصر.',
    'quantity_min' => 'يجب أن تكون الكمية 1 على الأقل.',
    'quantity_max' => 'لا يمكن تجاوز 50 وحدة لكل عنصر.',
    'term_invalid' => 'نوع العقد المحدد غير صالح.',
    'delivery_address_required' => 'عنوان التوصيل مطلوب.',
    'delivery_date_required' => 'تاريخ التوصيل مطلوب.',
    'delivery_date_invalid' => 'يجب أن يكون تاريخ التوصيل اليوم أو لاحقاً.',
    'delivery_time_slot_required' => 'فترة التوصيل مطلوبة.',
    'delivery_time_slot_invalid' => 'فترة التوصيل المحددة غير صالحة.',
    'customer_name_required' => 'الاسم الكامل مطلوب.',
    'customer_email_required' => 'عنوان البريد الإلكتروني مطلوب.',
    'customer_phone_required' => 'رقم الهاتف مطلوب.',
    'card_number_required' => 'رقم البطاقة مطلوب عند الدفع بالبطاقة.',
    'card_expiry_required' => 'تاريخ انتهاء البطاقة مطلوب عند الدفع بالبطاقة.',
    'card_cvc_required' => 'رمز CVC مطلوب عند الدفع بالبطاقة.',
    'address_not_owned' => 'العنوان المحدد غير متاح.',
    'payment_method_invalid' => 'طريقة الدفع المحددة غير صالحة.',
    'notes_max' => 'لا يمكن أن تتجاوز الملاحظات 500 حرف.',
    'field_forbidden' => 'هذا الحقل غير مسموح به؛ يحسب الخادم القيم المالية.',
    'idempotency_key_invalid' => 'مفتاح الـ Idempotency يجب أن يكون من 8 إلى 80 حرفاً (أحرف، أرقام، شرطات، وشرطات سفلية).',
    'cancel_reason_required' => 'سبب الإلغاء مطلوب.',
    'cancel_reason_min' => 'يجب أن يكون سبب الإلغاء 5 أحرف على الأقل.',
    'cancel_reason_max' => 'لا يمكن أن يتجاوز سبب الإلغاء 500 حرف.',

    // Cart / catalog availability messages
    'product_unavailable' => 'أحد المنتجات في سلتك غير متاح.',
    'product_not_found' => 'المنتج غير موجود.',
    'product_inactive' => 'هذا المنتج غير متاح حالياً.',
    'product_out_of_stock' => 'المنتج غير متوفر.',
    'product_quote_only' => 'هذا المنتج يتطلب عرض سعر — يرجى التواصل مع المبيعات.',
    'price_not_available' => 'لا يوجد سعر لهذا المنتج بالعملة :currency.',

    // Promo errors
    'promo_invalid' => 'تعذر تطبيق رمز الترويج.',

    // State machine
    'invalid_status_transition' => 'لا يمكن نقل الطلب من :from إلى :to.',
    'cancel_not_allowed' => 'لم يعد بالإمكان إلغاء هذا الطلب. يرجى التواصل مع الدعم.',
    'cancel_already_cancelled' => 'تم إلغاء هذا الطلب مسبقاً.',

    // Rate limit
    'rate_limited' => 'طلبات كثيرة. يرجى الإبطاء.',

    // Milestones
    'milestone' => [
        'quotation_approved' => 'تمت الموافقة على العرض',
        'deposit_invoiced' => 'تم إصدار فاتورة العربون',
        'in_production' => 'قيد الإنتاج',
        'phased_delivery' => 'توصيل متدرج',
        'installation_training' => 'التركيب وتدريب الموظفين',
    ],

    // Tracking next-step labels
    'next_step' => [
        'pending_cod' => 'بانتظار تأكيد فريقنا',
        'pending_online' => 'بانتظار تأكيد الدفع',
        'confirmed' => 'بانتظار جدولة التوصيل',
        'scheduled' => 'بانتظار الإرسال',
        'out_for_delivery' => 'في الطريق إليك — يرجى التواجد في العنوان',
        'installed' => 'بانتظار توقيع التسليم النهائي',
    ],
];
