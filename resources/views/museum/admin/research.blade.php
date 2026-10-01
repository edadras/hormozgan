@extends('museum.admin.layout')
@section('admin_title', 'Research Agent')
@section('admin')
<h1>Research Agent</h1>
<p class="muted">Agent منابع ثبت‌شده و متن‌های پردازش‌شده را برای یک موضوع جستجو می‌کند، Crawlerهای مجاز را در صف می‌گذارد، ادعاها را با شواهد استخراج و با منابع دیگر مقایسه می‌کند و همه نتایج را به صف‌های بررسی می‌فرستد. هیچ چیز را منتشر نمی‌کند.</p>
<form method="post" action="{{ route('museum.admin.research.store') }}" class="big-search" style="background:var(--card);border:1px solid var(--line)">@csrf
  <input type="text" name="topic" required placeholder="موضوع، مثلاً: نان مهیاوه، محله‌های قدیمی بندرعباس"><button>شروع</button></form>
<div class="scroll-x"><table class="data" style="margin-top:16px"><tr><th>موضوع</th><th>وضعیت</th><th>Candidate</th><th>اختلاف</th><th>مراحل</th></tr>
@foreach ($tasks as $t)<tr><td>{{ $t->topic }}</td><td>{{ $t->status }}</td><td>{{ $t->candidates_count }}</td><td>{{ $t->contradictions_count }}</td>
  <td><details><summary>{{ count($t->steps ?? []) }} مرحله</summary><pre class="json">{{ json_encode($t->steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>@endforeach
</table></div>
{{ $tasks->links('museum.partials.pager') }}
@endsection
