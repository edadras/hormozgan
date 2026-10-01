@extends('museum.layout')
@section('title', 'از موزه بپرسید')
@section('content')
<h1>از موزه بپرسید</h1>
<p class="muted">پاسخ‌ها فقط از پایگاه دانش موزه ساخته می‌شوند و هر جمله به منبع خود ارجاع دارد. اگر اطلاعات کافی نباشد، موزه همین را می‌گوید.</p>
<form method="post" action="{{ route('museum.ask') }}" class="big-search" style="margin-bottom:20px">
  @csrf
  <input type="text" name="question" value="{{ $question }}" placeholder="مثلاً: غذاهای قدیمی مردم قشم که با ماهی درست می‌شده چه بوده؟" required minlength="3" maxlength="1000">
  <button>بپرس</button>
</form>
@error('question')<div class="notice err">{{ $message }}</div>@enderror
@if ($result)
  @if ($result->status === 'answered')
    <div class="card"><div class="answer">{!! preg_replace('/\[(\d+)\]/', '<sup><a href="#c$1">[$1]</a></sup>', e($result->answer)) !!}</div></div>
  @elseif ($result->status === 'insufficient_context')
    <div class="notice warn">پایگاه دانش موزه هنوز اطلاعات کافی و مستند برای پاسخ به این پرسش ندارد. @if($result->answer)<br>{{ $result->answer }}@endif</div>
  @elseif ($result->status === 'retrieval_only')
    <div class="notice">پاسخ‌گوی هوشمند در حال حاضر فعال نیست؛ موارد مستند مرتبط در زیر آمده‌اند.</div>
  @else
    <div class="notice warn">پاسخ معتبر و مستندی تولید نشد؛ موارد مرتبط در زیر آمده‌اند.</div>
  @endif
  @if (count($result->citations ?? []))
    <section class="section"><h2 class="sec">منابع</h2>
      <ol class="list">@foreach ($result->citations as $c)
        <li id="c{{ $c['n'] }}"><span><strong>[{{ $c['n'] }}]</strong> {{ \Illuminate\Support\Str::limit($c['excerpt'], 160) }}<br>
          <span class="src">{{ $c['source'] }}@if($c['page']) ، ص {{ $c['page'] }}@endif</span></span>
          <a class="src" href="{{ $c['url'] }}">مشاهده منبع</a></li>
      @endforeach</ol>
    </section>
  @endif
@endif
@endsection
