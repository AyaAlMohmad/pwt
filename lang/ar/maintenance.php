<?php

declare(strict_types=1);

return [
    'request_submitted' => 'تم إرسال طلب الصيانة.',
    'requests_submitted' => 'تم إرسال طلبات الصيانة.',
    'requests_retrieved' => 'تم جلب طلبات الصيانة.',
    'request_retrieved' => 'تم جلب طلب الصيانة.',
    'request_cancelled' => 'تم إلغاء طلب الصيانة.',
    'request_rescheduled' => 'تم إعادة جدولة زيارة الصيانة.',
    'machines_retrieved' => 'تم جلب الأجهزة.',
    'machine_retrieved' => 'تم جلب الجهاز.',

    'machine_not_owned' => 'هذا الجهاز لا يخصك أو غير نشط.',
    'file_not_owned' => 'أحد الملفات المرفقة غير متاح.',
    'description_required' => 'يرجى إدخال وصف للمشكلة.',
    'description_min' => 'يرجى تقديم وصف أكثر تفصيلاً (10 أحرف على الأقل).',
    'description_max' => 'لا يجوز أن يتجاوز الوصف 2000 حرف.',
    'issue_type_invalid' => 'نوع المشكلة المحدد غير صالح.',
    'preferred_date_invalid' => 'يجب أن يكون التاريخ المفضل ضمن الـ 90 يوم القادمة.',
    'media_max' => 'يمكنك إرفاق 5 صور كحد أقصى بطلب الصيانة.',
    'media_invalid' => 'واحد أو أكثر من الملفات المرفقة غير صالح.',
    'cancel_reason_required' => 'يرجى إدخال سبب الإلغاء.',
    'cancel_reason_min' => 'يجب أن يكون سبب الإلغاء على الأقل 5 أحرف.',
    'cancel_reason_max' => 'لا يجوز أن يتجاوز سبب الإلغاء 500 حرف.',
    'preferred_date_required' => 'التاريخ المفضل مطلوب.',
    'preferred_time_invalid' => 'الوقت المفضل طويل جداً.',
    'reschedule_not_allowed' => 'لا يمكن إعادة جدولة طلب الصيانة بعد الآن.',

    'invalid_status_transition' => 'لا يمكن نقل طلب الصيانة من :from إلى :to.',
    'cancel_not_allowed' => 'لا يمكن إلغاء طلب الصيانة بعد الآن.',
    'already_completed' => 'تم إكمال هذا الطلب بالفعل.',
    'already_cancelled' => 'تم إلغاء هذا الطلب بالفعل.',

    'issue_type' => [
        'filter_change' => 'تغيير الفلتر',
        'leak' => 'تسرب',
        'noise' => 'ضوضاء',
        'low_pressure' => 'ضغط ماء منخفض',
        'water_quality' => 'جودة المياه',
        'error_code' => 'رمز خطأ',
        'installation_fix' => 'إصلاح التركيب',
        'other' => 'أخرى',
    ],
    'priority' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'urgent' => 'عاجلة',
    ],
    'status' => [
        'new' => 'جديد',
        'assigned' => 'تم التعيين',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
    ],
];
