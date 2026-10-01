@extends('museum.layout')
@section('title', 'زبان و گویش‌ها')
@section('content')
<h1>🗣️ اطلس گویش‌های هرمزگان</h1>
<p class="muted">گویش‌ها با هم مخلوط نمی‌شوند: هر واژه، جمله و قاعده به گویش و مکان مشخص (تا سطح روستا و محله) و به منبع یا گوینده بومی پیوند دارد. تلفظ‌ها فقط از صدای گویشوران بومی‌اند.</p>
<div class="tabs">
  <a class="on" href="{{ route('museum.language') }}">نمای کلی</a>
  <a href="{{ route('museum.section', ['language', 'type' => 'word']) }}">بانک واژه</a>
  <a href="{{ route('museum.compare') }}">مقایسه گویش‌ها</a>
  <a href="{{ route('museum.grammar') }}">دستور زبان</a>
  <a href="{{ route('museum.section', ['language', 'type' => 'proverb']) }}">ضرب‌المثل‌ها</a>
</div>
<section class="section"><h2>گویش‌ها</h2>
  @if ($dialects->isEmpty()) @include('museum.partials.empty') @else
  <div class="grid">@foreach ($dialects as $d) @include('museum.partials.entity-card', ['e' => app(\App\Museum\Support\EntityPresenter::class)->summary($d)]) @endforeach</div>@endif
</section>
<section class="section"><h2>مقایسه یک مفهوم در گویش‌ها</h2>
  @if ($concepts->isEmpty()) @include('museum.partials.empty', ['title' => 'هنوز مفهومی با واژه‌های مستند در چند گویش ثبت نشده است.']) @else
  <div class="tabs">@foreach ($concepts as $c)<a href="{{ route('museum.compare', ['concept' => $c->key]) }}">{{ $c->gloss_fa }}</a>@endforeach</div>@endif
</section>
<section class="section"><h2>دستور زبان</h2>
  @if ($grammarTopics->isEmpty()) @include('museum.partials.empty') @else
  <div class="tabs">@foreach ($grammarTopics as $t => $n)<a href="{{ route('museum.grammar', ['topic' => $t]) }}">{{ __('museum.grammar.'.$t) }} ({{ $n }})</a>@endforeach</div>@endif
</section>
<section class="section"><h2>جمله‌های محلی</h2>
  @if ($sentences->isEmpty()) @include('museum.partials.empty') @else
  <div class="scroll-x"><table class="data"><tr><th>جمله محلی</th><th>فارسی معیار</th><th>English</th><th>دسته</th></tr>
  @foreach ($sentences as $s)<tr><td>{{ $s->text_local }}</td><td>{{ $s->text_fa }}</td><td class="ltr">{{ $s->text_en }}</td><td>{{ $s->category?->name_fa }}</td></tr>@endforeach</table></div>@endif
</section>
<section class="section"><h2>ضرب‌المثل‌ها و اصطلاحات</h2>
  @if ($proverbs->isEmpty()) @include('museum.partials.empty') @else
  <ul class="list">@foreach ($proverbs as $p)<li><a href="{{ $p->publicUrl() }}">{{ $p->proverb?->text_local ?? $p->canonical_name }}</a><span class="muted">{{ $p->proverb?->figurative_meaning }}</span></li>@endforeach</ul>@endif
</section>
@endsection
