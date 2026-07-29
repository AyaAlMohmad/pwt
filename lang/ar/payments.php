<?php

declare(strict_types=1);

return [
    // Success messages
    'initiated' => 'تم بدء عملية الدفع',
    'session_created' => 'تم إنشاء جلسة الدفع',
    'replay_session' => 'تم إنشاء جلسة الدفع مسبقاً',
    'cod_confirmed' => 'تم تأكيد الدفع عند الاستلام. الطلب في انتظار التأكيد.',
    'replay_initiated' => 'تم بدء عملية الدفع مسبقاً',
    'replay_cod' => 'تم تأكيد الدفع عند الاستلام مسبقاً',
    'status_retrieved' => 'تم استرداد حالة الدفع',
    'webhook_accepted' => 'تم قبول الإشعار',
    'webhook_replayed' => 'تمت معالجة حدث الدفع مسبقاً',

    // Error messages
    'signature_invalid' => 'توقيع غير صالح.',
    'not_payable' => 'لا يمكن دفع هذا الطلب في حالته الحالية.',
    'not_payable_with_status' => 'الطلب في حالة ":status" ولا يمكن دفعه.',
    'already_initiated' => 'هناك عملية دفع نشطة بالفعل لهذا الطلب.',
    'method_mismatch' => 'طريقة الدفع لا تطابق الطلب.',
    'invalid_state_transition' => 'لا يمكن نقل الدفع من :from إلى :to.',
    'order_not_owned' => 'الطلب المحدد غير متاح.',
    'rate_limited' => 'محاولات دفع كثيرة جداً. يرجى المحاولة لاحقاً.',
    'gateway_unavailable' => 'بوابة الدفع غير متاحة مؤقتاً. يرجى المحاولة مرة أخرى.',
    'forbidden' => 'هذه عملية دفع تخص مستخدماً آخر.',
];
