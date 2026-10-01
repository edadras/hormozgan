<?php

namespace App\Http\Controllers\Museum\Api;

use App\Museum\Search\RagService;
use Illuminate\Http\Request;

class AskController extends ApiController
{
    public function ask(Request $r, RagService $rag)
    {
        $data = $r->validate(['question' => 'required|string|min:3|max:1000']);
        $q = $rag->ask($data['question'], $r->user()?->id, $r->ip());

        return response()->json([
            'id' => $q->uuid,
            'status' => $q->status,
            'answer' => $q->answer,
            'citations' => $q->citations,
            'note' => match ($q->status) {
                'insufficient_context' => 'پایگاه دانش موزه اطلاعات کافی برای پاسخ به این پرسش ندارد.',
                'retrieval_only' => 'پاسخ‌گویی هوشمند فعال نیست؛ منابع مرتبط فهرست شده‌اند.',
                'rejected_uncited' => 'پاسخ تولیدشده فاقد ارجاع معتبر بود و نمایش داده نمی‌شود؛ منابع مرتبط فهرست شده‌اند.',
                'error' => 'خطا در تولید پاسخ؛ منابع مرتبط فهرست شده‌اند.',
                default => null,
            },
        ]);
    }
}
