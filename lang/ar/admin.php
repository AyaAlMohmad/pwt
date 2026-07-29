<?php

declare(strict_types=1);

return [
    // Auth
    'login_success' => 'مرحبًا بعودتك.',
    'logout_success' => 'تم تسجيل خروجك.',
    'me_ok' => 'موافق',
    'inactive' => 'تم تعطيل حساب المسؤول هذا.',

    // Users (Phase 15)
    'users' => [
        'created' => 'تم إنشاء المستخدم.',
        'updated' => 'تم تحديث المستخدم.',
        'deactivated' => 'تم تعطيل المستخدم.',
        'cannot_deactivate_self' => 'لا يمكنك تعطيل حسابك الخاص.',
        'cannot_change_own_role' => 'لا يمكنك تغيير دورك الخاص.',
        'last_super_admin' => 'لا يمكنك إزالة آخر مدير عام نشط.',
        'role_change_forbidden' => 'يمكن للمدير العام فقط تغيير دور المستخدم.',
        'cannot_modify_super_admin' => 'لا يمكنك تعديل بيانات مدير عام آخر.',
        'trashed_forbidden' => 'لست مخولاً لعرض المستخدمين المعطلين.',
        'admin_email_required' => 'يجب أن يكون لدى المدير عنوان بريد إلكتروني.',
        'phone_pair_required' => 'يجب توفير رقم الهاتف ورمز الدولة معًا.',
        'empty_update_body' => 'يجب توفير حقل واحد قابل للتحديث على الأقل.',
    ],

    // Orders (Phase 17)
    'orders' => [
        'status_updated' => 'تم تحديث حالة الطلب.',
        'cancelled' => 'تم إلغاء الطلب.',
        'installation_scheduled' => 'تمت جدولة التركيب.',
        'note_added' => 'تمت إضافة الملاحظة.',
        'retrieved' => 'تم استرجاع الطلبات.',
        'note_required' => 'الملاحظة مطلوبة.',
        'note_min' => 'يجب أن تتكون الملاحظة من 3 أحرف على الأقل.',
        'note_max_transition' => 'لا يمكن أن تتجاوز ملاحظة الانتقال 1000 حرف.',
        'note_max_standalone' => 'لا يمكن أن تتجاوز الملاحظة 2000 حرف.',
        'reason_required' => 'سبب الإلغاء مطلوب.',
        'reason_min' => 'يجب أن يتكون سبب الإلغاء من 5 أحرف على الأقل.',
        'reason_max' => 'لا يمكن أن يتجاوز سبب الإلغاء 1000 حرف.',
        'status_required' => 'حقل الحالة مطلوب.',
        'status_invalid' => 'الحالة المحددة غير صالحة.',
        'scheduled_for_at_required' => 'تاريخ ووقت الجدولة مطلوبان.',
        'scheduled_for_at_invalid' => 'يجب أن يكون تاريخ ووقت الجدولة بصيغة ISO-8601 صالحة.',
        'scheduled_for_at_future' => 'يجب أن يكون تاريخ ووقت الجدولة في المستقبل.',
        'technician_invalid' => 'الفني المحدد ليس مسؤول عمليات نشطًا.',
        'date_range_invalid' => 'نطاق التاريخ غير صالح: يجب أن يكون date_to في تاريخ date_from أو بعده.',
        'country_unknown' => 'رمز الدولة المحدد غير معروف.',
        'period_invalid' => 'الفترة المحددة غير صالحة.',
        'trashed_forbidden' => 'لست مخولاً لعرض الطلبات المحذوفة.',
        'forbidden_money_field' => 'الحقول المالية محسوبة من قبل الخادم ولا يمكن تقديمها هنا.',
        'notes_on_terminal' => 'لا يمكن إضافة ملاحظات لطلب منتهٍ (:status).',
        'use_schedule_endpoint' => 'استخدم نقطة جدولة التركيب لتحديد التاريخ والفني.',
        'schedule_requires_confirmed' => 'يمكن جدولة الطلبات المؤكدة فقط. الحالة الحالية: :status.',
        'payment_not_captured' => 'يجب إتمام عملية الدفع قبل تغيير حالة الطلب.',
    ],

    // Machines (Phase 18)
    'machines' => [
        'retrieved' => 'تم استرجاع الأجهزة.',
        'due_retrieved' => 'تم استرجاع الأجهزة المستحقة للصيانة.',
        'show_retrieved' => 'تم استرجاع الجهاز.',
        'created' => 'تم إنشاء الجهاز.',
        'updated' => 'تم تحديث الجهاز.',
        'not_found' => 'لم يتم العثور على الجهاز.',
        'duplicate_serial' => 'الرقم التسلسلي مستخدم بالفعل لجهاز آخر.',
        'address_not_owned' => 'العنوان المحدد لا يخص العميل المحدد.',
        'user_not_customer' => 'المستخدم المحدد ليس حساب عميل نشطًا.',
        'product_inactive' => 'لا يمكن إنشاء جهاز لمنتج غير نشط.',
        'resource_trashed' => 'لا يمكن تعديل جهاز محذوف.',
        'override_requires_interval' => 'لا يمكن تحديد تاريخ الصيانة التالية إلا عند ضبط فترة تغيير الفلتر.',
        'trashed_forbidden' => 'لست مخولاً لعرض الأجهزة المحذوفة.',
        'forbidden_field' => 'هذا الحقل يديره الخادم ولا يمكن تقديمه هنا.',
    ],

    // Maintenance (Phase 18)
    'maintenance' => [
        'retrieved' => 'تم استرجاع طلبات الصيانة.',
        'show_retrieved' => 'تم استرجاع طلب الصيانة.',
        'created' => 'تم إنشاء طلب الصيانة بالنيابة عن العميل.',
        'created_batch' => 'تم إنشاء طلبات الصيانة بالنيابة عن العميل.',
        'assigned' => 'تم تعيين طلب الصيانة.',
        'status_updated' => 'تم تحديث حالة طلب الصيانة.',
        'closed' => 'تم إغلاق طلب الصيانة.',
        'not_found' => 'لم يتم العثور على طلب الصيانة.',
        'technician_invalid' => 'الفني المحدد ليس مسؤول عمليات نشطًا.',
        'user_not_customer' => 'المستخدم المحدد ليس حساب عميل نشطًا.',
        'machine_not_owned' => 'الجهاز المحدد لا يخص العميل المحدد أو غير نشط.',
        'use_assign_endpoint' => 'استخدم نقطة التعيين لتحديد المستخدم المُعيَّن.',
        'use_close_endpoint' => 'استخدم نقطة الإغلاق لإدخال ملاحظات الحل.',
        'only_in_progress_can_close' => 'يمكن إغلاق الطلبات قيد التنفيذ فقط.',
        'cannot_assign_in_state' => 'لا يمكن تعيين هذا الطلب في حالته الحالية.',
        'resource_trashed' => 'لا يمكن تعديل طلب صيانة محذوف.',
        'completed_at_invalid' => 'يجب أن يكون تاريخ الإتمام في أو قبل اليوم وفي أو بعد تاريخ تركيب الجهاز.',
        'forbidden_field' => 'هذا الحقل يديره الخادم ولا يمكن تقديمه هنا.',
        'trashed_forbidden' => 'لست مخولاً لعرض طلبات الصيانة المحذوفة.',
        'no_machines' => 'لم يتم تحديد أي أجهزة للإنشاء المجمّع.',
    ],

    // Support (Phase 19)
    'support' => [
        'retrieved' => 'تم استرجاع تذاكر الدعم.',
        'show_retrieved' => 'تم استرجاع تذكرة الدعم.',
        'updated' => 'تم تحديث تذكرة الدعم.',
        'replied' => 'تم إرسال الرد.',
        'not_found' => 'لم يتم العثور على تذكرة الدعم.',
        'trashed_forbidden' => 'لست مخولاً لعرض تذاكر الدعم المحذوفة.',
        'trashed_modification' => 'لا يمكن تعديل تذكرة دعم محذوفة.',
        'ticket_terminal' => 'لا يمكن الرد على تذكرة مغلقة أو ملغاة. استخدم ملاحظة داخلية بدلاً من ذلك.',
        'invalid_assignee' => 'المستخدم المحدد غير مؤهل لاستلام تذاكر الدعم.',
        'empty_update_body' => 'يجب توفير قيمة واحدة على الأقل من بين الحالة أو الأولوية أو معرّف المسؤول المعيَّن.',
        'assignee_required_for_assigned' => 'معرّف المسؤول المعيَّن مطلوب عندما تكون الحالة "assigned".',
        'resolution_required' => 'ملخص الحل مطلوب عند تحديد الحالة كمُحلولة.',
        'cancellation_reason_required' => 'سبب الإلغاء مطلوب عند إلغاء التذكرة.',
        'status_invalid' => 'الحالة المحددة غير صالحة.',
        'priority_invalid' => 'الأولوية المحددة غير صالحة.',
        'forbidden_field' => 'هذا الحقل يديره الخادم ولا يمكن تقديمه هنا.',
        'date_range_invalid' => 'نطاق التاريخ غير صالح: يجب أن يكون date_to في تاريخ date_from أو بعده.',
        'reply_body_required' => 'نص الرد مطلوب.',
        'reply_body_min' => 'يجب أن يحتوي الرد على حرف واحد على الأقل.',
        'reply_body_max' => 'لا يمكن أن يتجاوز الرد 5000 حرف.',
        'attachments_max' => 'يمكنك إرفاق 5 ملفات كحد أقصى مع الرد.',
        'attachments_invalid' => 'واحد أو أكثر من الملفات المرفقة غير صالح.',
    ],

    // Banners (Phase 20)
    'banners' => [
        'retrieved' => 'تم استرجاع البانرات.',
        'show_retrieved' => 'تم استرجاع البانر.',
        'created' => 'تم إنشاء البانر.',
        'updated' => 'تم تحديث البانر.',
        'deleted' => 'تم حذف البانر.',
        'not_found' => 'لم يتم العثور على البانر.',
        'date_range_invalid' => 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.',
        'duplicate_code' => 'يوجد بانر بنفس الرمز بالفعل.',
    ],

    // Promotions (Phase 20)
    'promotions' => [
        'retrieved' => 'تم استرجاع العروض الترويجية.',
        'show_retrieved' => 'تم استرجاع العرض الترويجي.',
        'created' => 'تم إنشاء العرض الترويجي.',
        'updated' => 'تم تحديث العرض الترويجي.',
        'deleted' => 'تم حذف العرض الترويجي.',
        'not_found' => 'لم يتم العثور على العرض الترويجي.',
        'date_range_invalid' => 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.',
        'duplicate_code' => 'يوجد عرض ترويجي بنفس الرمز بالفعل.',
        'code_format' => 'يمكن أن يحتوي رمز العرض الترويجي على أحرف وأرقام وشرطات وشرطات سفلية فقط.',
        'percent_over_hundred' => 'لا يمكن أن تتجاوز نسبة الخصم 100.',
        'max_discount_only_for_percent' => 'الحد الأقصى للخصم متاح فقط للعروض الترويجية من نوع النسبة المئوية.',
        'has_redemptions' => 'لا يمكن حذف عرض ترويجي تم استخدامه. قم بتعطيله بدلاً من ذلك.',
    ],

    // Testimonials (Phase 20)
    'testimonials' => [
        'retrieved' => 'تم استرجاع الشهادات.',
        'show_retrieved' => 'تم استرجاع الشهادة.',
        'created' => 'تم إنشاء الشهادة.',
        'updated' => 'تم تحديث الشهادة.',
        'deleted' => 'تم حذف الشهادة.',
        'not_found' => 'لم يتم العثور على الشهادة.',
    ],

    // Reports (Phase 21)
    'reports' => [
        'sales_generated' => 'تم إنشاء تقرير المبيعات.',
        'orders_generated' => 'تم إنشاء تقرير الطلبات.',
        'maintenance_generated' => 'تم إنشاء تقرير الصيانة.',
        'customers_generated' => 'تم إنشاء تقرير العملاء.',
        'forbidden' => 'لست مخولاً لعرض هذا التقرير.',
        'export_truncated' => 'تم اقتطاع التصدير عند :cap صفًا. قم بتضييق عوامل التصفية لاسترجاع الصفوف المتبقية وعددها :remaining.',
        'rate_limited' => 'عدد كبير جدًا من طلبات التصدير. يرجى الانتظار دقيقة قبل المحاولة مرة أخرى.',
        'date_range_invalid' => 'نطاق التاريخ غير صالح: يجب أن يكون date_to في تاريخ date_from أو بعده.',
        'window_too_wide' => 'لا يمكن أن يتجاوز نطاق التاريخ 366 يومًا.',
        'country_unknown' => 'رمز الدولة المحدد غير معروف.',
        'product_unknown' => 'لم يتم العثور على المنتج المحدد.',
        'assignee_unknown' => 'لم يتم العثور على المستخدم المعيَّن.',
        'status_invalid' => 'قيمة الحالة المحددة غير صالحة.',
        'format_required' => 'صيغة التصدير مطلوبة.',
        'format_invalid' => 'يجب أن تكون صيغة التصدير إما csv أو xlsx.',
        'sales_cancelled_only_note' => 'تعكس إجماليات الملخص الطلبات الملغاة فقط ولذلك تعرض إيرادات صفرية. وسّع عامل تصفية الحالة لتضمين الطلبات النشطة.',
    ],

    // Audit logs (Phase 22)
    'audit-logs' => [
        'retrieved' => 'تم استرجاع سجلات التدقيق.',
        'date_range_invalid' => 'نطاق التاريخ غير صالح: يجب أن يكون date_to في تاريخ date_from أو بعده.',
        'actor_unknown' => 'لم يتم العثور على المستخدم المحدد.',
    ],

    // Notification templates (Phase 22)
    'templates' => [
        'retrieved' => 'تم استرجاع قوالب الإشعارات.',
        'updated' => 'تم تحديث قالب الإشعار.',
        'not_found' => 'لم يتم العثور على قالب الإشعار.',
        'subject_required' => 'حقل الموضوع مطلوب.',
        'subject_en_required' => 'الموضوع باللغة الإنجليزية مطلوب.',
        'subject_ar_required' => 'الموضوع باللغة العربية مطلوب.',
        'body_required' => 'حقل النص مطلوب.',
        'body_en_required' => 'النص باللغة الإنجليزية مطلوب.',
        'body_ar_required' => 'النص باللغة العربية مطلوب.',
        'is_active_required' => 'حقل التفعيل مطلوب.',
    ],

    // Dashboard (Phase 22)
    'dashboard' => [
        'retrieved' => 'تم استرجاع لقطة لوحة التحكم.',
    ],

    // Payment Settings (Phase 22)
    'payment_settings' => [
        'retrieved' => 'تم استرجاع إعدادات الدفع.',
        'updated' => 'تم تحديث إعدادات الدفع.',
    ],

    // Mail Settings (Phase 22)
    'mail_settings' => [
        'retrieved' => 'تم استرجاع إعدادات البريد.',
        'updated' => 'تم تحديث إعدادات البريد.',
    ],

    // Catalog (Phase 16)
    'catalog' => [
        'category_created' => 'تم إنشاء الفئة.',
        'category_updated' => 'تم تحديث الفئة.',
        'category_deleted' => 'تم حذف الفئة.',
        'category_has_products' => 'لا يمكن حذف فئة تحتوي على منتجات. يرجى نقل أو حذف المنتجات أولاً.',
        'product_created' => 'تم إنشاء المنتج.',
        'product_updated' => 'تم تحديث المنتج.',
        'product_deleted' => 'تم حذف المنتج.',
        'product_status_updated' => 'تم تحديث حالة المنتج.',
        'product_has_active_orders' => 'لا يمكن حذف منتج مرتبط بطلبات نشطة. يرجى إلغاء أو إكمال الطلبات أولاً.',
        'product_code_immutable' => 'لا يمكن تغيير رمز المنتج بعد إنشائه.',
        'image_attached' => 'تم إرفاق الصورة.',
        'image_detached' => 'تمت إزالة الصورة.',
        'image_invalid_file_type' => 'الملف المحدد ليس صورة منتج.',
        'image_cross_product' => 'الصورة المحددة لا تخص هذا المنتج.',
    ],
];
