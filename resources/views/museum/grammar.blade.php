@extends('museum.layout')
@section('title', 'دستور زبان')
@section('content')
<h1>دستور زبان گویش‌های هرمزگان</h1>
<div class="tabs">
  <a href="{{ route('museum.grammar') }}" @class(['on' => !$topic])>همه</a>
  @foreach (['phonology','pronunciation','transcription','pronouns','nouns','pluralization','adjectives','possession','verbs','conjugation','present','past','future','negation','questions','imperative','prepositions','syntax'] as $t)
    <a href="{{ route('museum.grammar', ['topic' => $t]) }}" @class(['on' => $topic === $t])>{{ __('museum.grammar.'.$t) }}</a>
  @endforeach
</div>
@forelse ($rules as $rule)
  <article class="card section">
    <span class="pill sea">{{ $rule->dialect?->displayName() }}</span> <span class="pill">{{ __('museum.grammar.'.$rule->topic) }}</span>
    <h2 style="margin:.3em 0">{{ $rule->title }}</h2>
    <p>{{ $rule->description }}</p>
    @if ($rule->paradigm)<div class="scroll-x"><table class="data">@foreach ($rule->paradigm as $row)<tr>@foreach ((array) $row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</table></div>@endif
    @if ($rule->examples->count())<h3>مثال‌ها</h3><ul class="list">@foreach ($rule->examples as $ex)<li><span><strong>{{ $ex->text_local }}</strong> @if($ex->gloss)<span class="muted ltr">{{ $ex->gloss }}</span>@endif<br>{{ $ex->translation_fa }}</span></li>@endforeach</ul>@endif
    <p class="src">منبع: @foreach ($rule->citations as $c)<a href="{{ url('/museum/sources/'.$c->source->uuid) }}">{{ $c->source->citationLabel() }}</a>@if($c->page_number) ، ص {{ $c->page_number }}@endif @endforeach</p>
  </article>
@empty
  @include('museum.partials.empty', ['title' => 'هنوز قاعده دستوری مستندی منتشر نشده است.', 'text' => 'هر قاعده باید با مثال واقعی و منبع ثبت شود؛ منابعی مانند پژوهش‌های گویش بندری در صف پردازش‌اند.'])
@endforelse
@endsection
