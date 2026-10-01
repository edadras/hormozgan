@extends('museum.layout')
@section('title', $s->title)
@section('content')
<span class="pill sea">{{ $s->source_type }}</span>
<h1>{{ $s->title }}</h1>
<table class="facts card">
  @foreach (['author' => 'پدیدآورنده', 'publisher' => 'ناشر', 'publication_date' => 'تاریخ انتشار', 'container_title' => 'مجموعه / نشریه', 'isbn' => 'ISBN', 'doi' => 'DOI', 'archive_reference' => 'شناسه آرشیو', 'language' => 'زبان'] as $k => $label)
    @if ($s->{$k})<tr><th>{{ $label }}</th><td @if(in_array($k, ['isbn','doi'])) class="ltr" @endif>{{ $s->{$k} }}</td></tr>@endif
  @endforeach
  @if ($s->url)<tr><th>نشانی</th><td><a class="ltr" href="{{ $s->url }}" rel="noopener" target="_blank">{{ \Illuminate\Support\Str::limit($s->url, 80) }}</a></td></tr>@endif
  <tr><th>پروانه / حق نشر</th><td>{{ $s->licenseEnum()->label() }} · {{ $s->copyright_status }}</td></tr>
  <tr><th>سطح اعتبار منبع</th><td>{{ $s->reliability_tier }} از ۵</td></tr>
  <tr><th>وضعیت بررسی منبع</th><td>@include('museum.partials.status', ['status' => $s->verification_status])</td></tr>
  @if ($s->retrieved_at)<tr><th>تاریخ دریافت</th><td>{{ $s->retrieved_at }}</td></tr>@endif
  @if ($s->notes)<tr><th>یادداشت</th><td>{{ $s->notes }}</td></tr>@endif
  @if (!empty($s->metadata['compiler_notes']))<tr><th>یادداشت گردآورنده (تأییدنشده)</th><td>{{ $s->metadata['compiler_notes'] }}</td></tr>@endif
</table>
<section class="section">
  <h2 class="sec">ادعاهای منتشرشده از این منبع ({{ number_format($facts->total()) }})</h2>
  @if ($facts->isEmpty())
    @include('museum.partials.empty', ['title' => 'هنوز ادعای منتشرشده‌ای از این منبع وجود ندارد.', 'text' => 'این منبع در صف پردازش و بررسی است.'])
  @else
    <ul class="list">@foreach ($facts as $f)
      <li><span><a href="{{ $f->entity->publicUrl() }}">{{ $f->entity->displayName() }}</a> — {{ $f->property->label_fa }}: <strong>{{ $f->displayValue() }}</strong></span><a class="src" href="{{ url('/museum/facts/'.$f->uuid) }}">جزئیات</a></li>
    @endforeach</ul>
    {{ $facts->links('museum.partials.pager') }}
  @endif
</section>
@endsection
