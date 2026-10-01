@extends('museum.admin.layout')
@section('admin_title', 'ویرایش منبع')
@section('admin')
<h1>{{ \Illuminate\Support\Str::limit($s->title, 120) }}</h1>
<p><a href="{{ url('/museum/sources/'.$s->uuid) }}">صفحه عمومی منبع</a></p>
@if (!empty($s->metadata['compiler_notes']))<div class="notice warn">یادداشت گردآورنده (تأییدنشده): {{ $s->metadata['compiler_notes'] }}</div>@endif
<form method="post" action="{{ route('museum.admin.sources.update', $s) }}" class="card">
  @csrf @include('museum.admin.partials-source-fields', ['s' => $s])
  <label>توضیح تغییر (برای Log)</label><input type="text" name="change_note">
  <p><button class="btn">ذخیره</button></p>
</form>
<h2 class="sec" style="margin-top:24px">Crawler</h2>
<p class="muted">Crawl فقط وقتی اجرا می‌شود که: Crawler فعال باشد، شرایط استفاده توسط انسان بررسی شده باشد، سیاست Crawl منبع «allowed» باشد و robots.txt اجازه دهد.</p>
@foreach ($crawlers as $c)
  <form method="post" action="{{ route('museum.admin.crawlers.update', $c) }}" class="card" style="margin-bottom:10px">
    @csrf
    <strong>{{ $c->name }}</strong> <span class="pill">{{ $c->kind }}</span> <span class="src">آخرین اجرا: {{ $c->last_run_at ?? '—' }}</span>
    <div class="grid">
      <div><label>نوع</label><select name="kind"><option @selected($c->kind === 'http_document')>http_document</option><option @selected($c->kind === 'http_listing')>http_listing</option></select></div>
      <div><label>فاصله درخواست‌ها (ms)</label><input type="number" name="crawl_delay_ms" min="1000" value="{{ $c->crawl_delay_ms }}"></div>
      <div><label>حداکثر سند</label><input type="number" name="max_documents" value="{{ $c->max_documents }}"></div>
    </div>
    <label>URLهای شروع (هر خط یک مورد)</label><textarea name="seed_urls" class="ltr">{{ implode("\n", $c->seed_urls ?? []) }}</textarea>
    <label>الگوهای مجاز</label><textarea name="allowed_patterns" class="ltr">{{ implode("\n", $c->allowed_patterns ?? []) }}</textarea>
    <input type="hidden" name="terms_reviewed" value="0"><label class="check"><input type="checkbox" name="terms_reviewed" value="1" @checked($c->terms_reviewed)> شرایط استفاده و حق نشر را بررسی کردم و گردآوری مجاز است</label>
    <label>یادداشت بررسی شرایط</label><input type="text" name="terms_note">
    <input type="hidden" name="enabled" value="0"><label class="check"><input type="checkbox" name="enabled" value="1" @checked($c->enabled)> فعال</label>
    <label class="check"><input type="checkbox" name="run_now" value="1"> پس از ذخیره اجرا شود</label>
    <p><button class="btn small">ذخیره</button></p>
  </form>
@endforeach
@endsection
