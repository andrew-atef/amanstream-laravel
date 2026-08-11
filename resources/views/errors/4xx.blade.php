<x-error-page
    :status="isset($exception) ? $exception->getStatusCode() : '4xx'"
    title="مشكلة في طلبك"
    message="في حاجة غلطت في طلب الصفحة ده. ممكن تكون الرابط ناقص أو في مشكلة مؤقتة — رجّع للرئيسية أو استعرض كل المراجعات."
    icon="sad"
/>