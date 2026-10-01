@extends('museum.layout')
@section('title', 'تاریخ')
@section('content')
<h1>📜 تاریخ هرمزگان</h1>
<div class="tabs">
  <a class="on" href="{{ route('museum.history') }}">خط زمان</a>
  <a href="{{ route('museum.history.map') }}">هرمزگان در گذر زمان</a>
  <a href="{{ route('museum.section', ['history', 'type' => 'document']) }}">اسناد</a>
  <a href="{{ route('museum.section', ['history', 'type' => 'historical_site']) }}">مکان‌های تاریخی</a>
</div>
<section class="section"><h2>خط زمان</h2>
  <form method="get" action="{{ url('/api/museum/history') }}" class="muted">فیلتر از طریق API: <span class="ltr">/api/museum/history?from=1800&amp;to=1950&amp;place=…&amp;topic=…</span></form>
  @if ($events->isEmpty()) @include('museum.partials.empty', ['title' => 'هنوز رویداد تاریخی مستندی منتشر نشده است.']) @else
  <div class="timeline">@foreach ($events as $ev)
    <div class="ev"><strong>{{ $ev->year_from }}@if($ev->year_to && $ev->year_to != $ev->year_from)–{{ $ev->year_to }}@endif</strong> — <a href="{{ $ev->publicUrl() }}">{{ $ev->displayName() }}</a> <span class="pill">{{ $ev->event_type }}</span></div>
  @endforeach</div>@endif
</section>
<section class="section"><h2>نام‌های تاریخی</h2>
  @if ($names->isEmpty()) @include('museum.partials.empty', ['title' => 'هنوز نام تاریخی تأییدشده‌ای منتشر نشده است.', 'text' => 'منابعی مانند فرهنگ آبادی‌های ۱۳۵۵ و گزتیرهای ۱۹۰۸ و ۱۹۱۰ در صف پردازش‌اند.']) @else
  <div class="scroll-x"><table class="data"><tr><th>نام کنونی</th><th>نام تاریخی</th><th>دوره</th><th>معنی</th><th>منبع</th></tr>
  @foreach ($names as $n)<tr><td><a href="{{ $n->entity->publicUrl() }}">{{ $n->entity->displayName() }}</a></td><td>{{ $n->name }}</td><td>{{ $n->period_label }} {{ $n->year_from }}</td><td>{{ $n->meaning }}</td><td class="src">{{ $n->source?->citationLabel() }} {{ $n->page_number ? '، ص '.$n->page_number : '' }}</td></tr>@endforeach
  </table></div>@endif
</section>
<section class="section"><h2>آن روز / امروز</h2>
  @if ($pairs->isEmpty()) @include('museum.partials.empty', ['title' => 'هنوز جفت عکس قدیم و جدید با مجوز انتشار ثبت نشده است.']) @else
  <div class="grid wide">@foreach ($pairs as $p)
    <div class="card"><h3>{{ $p->place?->displayName() }}</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px"><figure style="margin:0"><img loading="lazy" src="{{ $p->thenMedia->publicUrl('thumb') ?? $p->thenMedia->publicUrl() }}" alt="آن روز"><figcaption class="muted">{{ $p->thenMedia->year }}</figcaption></figure>
      <figure style="margin:0"><img loading="lazy" src="{{ $p->nowMedia->publicUrl('thumb') ?? $p->nowMedia->publicUrl() }}" alt="امروز"><figcaption class="muted">{{ $p->nowMedia->year }}</figcaption></figure></div></div>
  @endforeach</div>@endif
</section>
@endsection
