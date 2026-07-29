<?php

declare(strict_types=1);

return [
    // Success messages
    'ticket_created' => 'تم إنشاء تذكرة الدعم.',
    'tickets_retrieved' => 'تم جلب تذاكر الدعم.',
    'ticket_retrieved' => 'تم جلب تذكرة الدعم.',
    'ticket_cancelled' => 'تم إلغاء تذكرة الدعم.',

    // Validation
    'subject_required' => 'يرجى إدخال موضوع للتذكرة.',
    'subject_min' => 'يجب أن يكون الموضوع 3 أحرف على الأقل.',
    'subject_max' => 'لا يجوز أن يتجاوز الموضوع 200 حرف.',
    'category_required' => 'يرجى اختيار فئة.',
    'category_invalid' => 'الفئة المحددة غير صالحة.',
    'message_required' => 'يرجى إدخال رسالة تصف المشكلة.',
    'message_min' => 'يرجى وصف المشكلة بـ 10 أحرف على الأقل.',
    'message_max' => 'لا يجوز أن تتجاوز الرسالة 5000 حرف.',
    'related_order_not_owned' => 'الطلب المحدد لا يخصك.',
    'related_machine_not_owned' => 'الجهاز المحدد لا يخصك.',
    'file_not_owned' => 'المرفق المحدد لا يخصك أو سبق استخدامه.',
    'attachments_max' => 'يمكنك إرفاق 5 ملفات كحد أقصى بتذكرة الدعم.',
    'attachments_invalid' => 'واحد أو أكثر من الملفات المرفقة غير صالح.',
    'cancel_reason_max' => 'لا يجوز أن يتجاوز سبب الإلغاء 500 حرف.',
    'field_forbidden' => 'هذا الحقل غير مسموح به.',

    // State machine
    'invalid_status_transition' => 'لا يمكن نقل تذكرة الدعم من :from إلى :to.',
    'cancel_not_allowed' => 'لا يمكن إلغاء هذه التذكرة بعد الآن.',
    'already_cancelled' => 'تم إلغاء هذه التذكرة بالفعل.',
    'already_closed' => 'تم إغلاق هذه التذكرة.',
    'already_resolved' => 'تم حل هذه التذكرة بالفعل.',

    // Rate limiting
    'rate_limited' => 'عدد كبير جداً من تذاكر الدعم. يرجى المحاولة لاحقاً.',

    // Anonymisation fallback
    'system_sender' => 'النظام',
    'support_fallback_name' => 'الدعم',

    'category' => [
        'order_issue' => 'مشكلة في الطلب',
        'payment_issue' => 'مشكلة في الدفع',
        'maintenance_issue' => 'مشكلة في الصيانة',
        'product_question' => 'سؤال عن المنتج',
        'account_issue' => 'مشكلة في الحساب',
        'feedback' => 'ملاحظات',
        'other' => 'أخرى',
    ],
    'priority' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'urgent' => 'عاجلة',
    ],
    'status' => [
        'open' => 'مفتوحة',
        'assigned' => 'تم التعيين',
        'in_progress' => 'قيد التنفيذ',
        'resolved' => 'تم الحل',
        'closed' => 'مغلقة',
        'cancelled' => 'ملغاة',
    ],
];
