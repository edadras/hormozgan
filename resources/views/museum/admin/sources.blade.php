@extends('museum.admin.layout')
@section('admin_title', 'منابع')
@section('admin')
<h1>Source Registry</h1>
<form method="get" style="max-width:380px"><input type="text" name="q" value="{{ request('q') }}" placeholder="جستجو در عنوان"></form>
<div class="scroll-x"><table class="data" style="margin-top:12px"><tr><th>عنوان</th><th>نوع</th><th>فاز</th><th>اعتبار</th><th>پروانه</th><th>Crawl</th><th>استنادها</th></tr>
@foreach ($sources as $s)
  <tr><td><a href="{{ route('museum.admin.sources.edit', $s) }}">{{ \Illuminate\Support\Str::limit($s->title, 90) }}</a></td><td>{{ $s->source_type }}</td><td>{{ $s->phase }}</td><td>{{ $s->reliability_tier }}</td>
    <td>{{ $s->license }}</td><td><span @class(['pill', 'green' => $s->crawl_policy === 'allowed', 'gold' => $s->crawl_policy === 'pending_review', 'red' => $s->crawl_policy === 'forbidden'])>{{ $s->crawl_policy }}</span></td><td>{{ number_format($s->fact_sources_count) }}</td></tr>
@endforeach</table></div>
{{ $sources->links('museum.partials.pager') }}
<details class="card" style="margin-top:20px"><summary><strong>ثبت منبع جدید</strong></summary>
  <form method="post" action="{{ route('museum.admin.sources.store') }}">@csrf @include('museum.admin.partials-source-fields') <p><button class="btn">ثبت</button></p></form>
</details>
@endsection
