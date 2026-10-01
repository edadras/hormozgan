@extends('museum.admin.layout')
@section('admin_title', 'کارهای AI')
@section('admin')
<h1>کارهای AI</h1>
<div class="tabs"><a href="?">همه</a><a href="?status=failed">ناموفق</a><a href="?status=running">در حال اجرا</a></div>
<div class="scroll-x"><table class="data"><tr><th>#</th><th>نوع</th><th>مدل</th><th>وضعیت</th><th>توکن</th><th>زمان (ms)</th><th>خطا</th><th>شروع</th></tr>
@foreach ($jobs as $j)<tr><td>{{ $j->id }}</td><td>{{ $j->job_type }}</td><td class="ltr">{{ $j->model }}</td><td>{{ $j->status }}</td><td>{{ $j->input_tokens }}/{{ $j->output_tokens }}</td><td>{{ $j->latency_ms }}</td><td>{{ \Illuminate\Support\Str::limit($j->error, 120) }}</td><td class="ltr">{{ $j->started_at }}</td></tr>@endforeach
</table></div>
{{ $jobs->links('museum.partials.pager') }}
@endsection
