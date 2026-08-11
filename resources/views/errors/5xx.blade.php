<x-error-page
    :status="isset($exception) ? $exception->getStatusCode() : '5xx'"
    title="مشكلة تقنية مؤقتة"
    message="واجهنا مشكلة داخلية غير متوقعة أثناء تحميل الصفحة. جرّب تحديث الصفحة بعد قليل، أو رجّع للرئيسية في أي وقت."
    icon="warning"
/>