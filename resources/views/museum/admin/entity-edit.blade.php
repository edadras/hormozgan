@extends('museum.admin.layout')
@section('admin_title', $e->displayName())
@section('admin')
<h1>{{ $e->displayName() }} <span class="pill">{{ $e->type->name_fa }}</span> <span class="pill">{{ $e->visibility }}</span></h1>
<p><a href="{{ $e->publicUrl() }}">مشاهده صفحه</a> @if($e->wikidata_id)· <a class="ltr" href="https://www.wikidata.org/wiki/{{ $e->wikidata_id }}" rel="noopener" target="_blank">{{ $e->wikidata_id }}</a>@endif</p>

<form method="post" action="{{ route('museum.admin.entities.publish', $e) }}" class="card" style="margin-bottom:16px">
  @csrf
  @if ($problems)<div class="notice warn">شرایط انتشار برقرار نیست: {{ implode('؛ ', $problems) }}</div>@endif
  <input type="text" name="reason" placeholder="دلیل (برای انتشار اجباری یا خروج از انتشار الزامی)" style="max-width:420px">
  @if ($e->visibility !== 'published')
    <button class="btn small alt" name="action" value="publish">انتشار</button>
    @if ($problems)<label class="check"><input type="checkbox" name="force" value="1"> انتشار با وجود هشدار (با ذکر دلیل)</label>@endif
  @else
    <button class="btn small danger" name="action" value="unpublish">خروج از انتشار</button>
  @endif
</form>

<form method="post" action="{{ route('museum.admin.entities.update', $e) }}" class="card">
  @csrf
  <div class="grid">
    @foreach (['canonical_name' => 'نام اصلی', 'name_fa' => 'نام فارسی', 'name_en' => 'نام انگلیسی', 'name_ar' => 'نام عربی', 'name_local' => 'نام محلی', 'name_local_latin' => 'آوانویسی لاتین نام محلی', 'latitude' => 'عرض جغرافیایی', 'longitude' => 'طول جغرافیایی'] as $k => $label)
      <div><label>{{ $label }}</label><input type="text" name="{{ $k }}" value="{{ old($k, $e->{$k}) }}" @if(in_array($k, ['name_en', 'name_local_latin', 'latitude', 'longitude'])) class="ltr" @endif></div>
    @endforeach
    <div><label>Alias جدید</label><input type="text" name="new_alias"></div>
    @if ($e->place)<div><label>والد (slug)</label><input type="text" name="parent_slug" placeholder="{{ optional(\App\Models\Museum\Entity::find($e->place->parent_id))->slug }}"></div>@endif
  </div>
  <label>خلاصه ویراستاری (فارسی) — ادعاهای توصیفی را به‌صورت Fact با منبع ثبت کنید</label><textarea name="summary_fa">{{ old('summary_fa', $e->summary_fa) }}</textarea>
  <label>Summary (English)</label><textarea name="summary_en" class="ltr">{{ old('summary_en', $e->summary_en) }}</textarea>
  <p><button class="btn">ذخیره</button></p>
  <p class="src">Aliasها: @foreach ($e->aliases as $a)<span class="pill">{{ $a->alias }} <small>{{ $a->alias_type }}</small></span> @endforeach</p>
</form>

<h2 class="sec" style="margin-top:24px">Factها</h2>
<div class="scroll-x"><table class="data"><tr><th>ویژگی</th><th>مقدار</th><th>وضعیت</th><th>منبع</th><th>اصلاح (با دلیل)</th></tr>
@foreach ($facts as $f)
  <tr><td>{{ $f->property->label_fa }} @if($f->qualifiers)<div class="src ltr">{{ json_encode($f->qualifiers) }}</div>@endif</td>
    <td @class(['unknown' => $f->is_unknown])>{{ $f->displayValue() }} @if($f->conflict_id)<span class="pill red">اختلاف</span>@endif</td>
    <td>@include('museum.partials.status', ['status' => $f->verification_status])</td>
    <td class="src">@foreach ($f->sources as $s){{ \Illuminate\Support\Str::limit($s->source->title, 50) }}@if($s->page_number) ، ص {{ $s->page_number }}@endif<br>@endforeach</td>
    <td>@if (!in_array($f->value_type, ['entity', 'geo', 'json', 'unknown']))
      <form method="post" action="{{ route('museum.admin.facts.update', $f) }}" style="display:flex;gap:4px;flex-wrap:wrap">@csrf
        <input type="text" name="value" value="{{ $f->value_type === 'text' || $f->value_type === 'string' ? $f->value_text : $f->displayValue() }}" style="max-width:140px">
        <input type="text" name="reason" placeholder="دلیل" required style="max-width:120px"><button class="btn small">اصلاح</button></form>@endif</td></tr>
@endforeach</table></div>

<details class="card" style="margin-top:16px" open><summary><strong>ثبت Fact جدید (با منبع)</strong></summary>
<form method="post" action="{{ route('museum.admin.entities.facts.store', $e) }}">
  @csrf
  <div class="grid">
    <div><label>ویژگی</label><select name="property">@foreach ($properties as $p)<option value="{{ $p->key }}">{{ $p->label_fa }} ({{ $p->datatype }})</option>@endforeach</select></div>
    <div><label>مقدار</label><input type="text" name="value"></div>
    <div><label>یا Entity (slug)</label><input type="text" name="value_entity_slug"></div>
    <div><label>سال سرشماری (برای جمعیت)</label><input type="number" name="census_year"></div>
    <div><label>محدوده مکانی (slug، برای تفاوت منطقه‌ای)</label><input type="text" name="scope_place_slug"></div>
  </div>
  <label class="check"><input type="checkbox" name="unknown" value="1"> مقدار در منابع «نامعلوم» است (UNKNOWN)</label>
  <div class="grid">
    <div><label>منبع</label><select name="source_id" required>@foreach ($sources as $s)<option value="{{ $s->id }}">{{ \Illuminate\Support\Str::limit($s->title, 80) }}</option>@endforeach</select></div>
    <div><label>صفحه</label><input type="text" name="page"></div>
    <div><label>وضعیت</label><select name="status"><option value="unverified">تأییدنشده</option><option value="source_verified">تأیید با منبع</option><option value="expert_verified">تأیید کارشناس</option></select></div>
  </div>
  <label>متن عین منبع (Quote)</label><textarea name="quote"></textarea>
  <p><button class="btn">ثبت</button></p>
</form></details>

<details class="card" style="margin-top:16px"><summary><strong>نام تاریخی</strong></summary>
  <ul class="list">@foreach ($e->historicalNames as $h)<li><span>{{ $h->name }} — {{ $h->period_label }} {{ $h->year_from }}</span><span class="src">{{ $h->source?->title }} @include('museum.partials.status', ['status' => $h->verification_status])</span></li>@endforeach</ul>
  <form method="post" action="{{ route('museum.admin.entities.historical-names.store', $e) }}">
    @csrf
    <div class="grid">
      <div><label>نام تاریخی</label><input type="text" name="name" required></div>
      <div><label>تلفظ محلی</label><input type="text" name="local_pronunciation"></div>
      <div><label>دوره</label><input type="text" name="period_label"></div>
      <div><label>از سال</label><input type="number" name="year_from"></div>
      <div><label>تا سال</label><input type="number" name="year_to"></div>
      <div><label>منبع</label><select name="source_id" required>@foreach ($sources as $s)<option value="{{ $s->id }}">{{ \Illuminate\Support\Str::limit($s->title, 60) }}</option>@endforeach</select></div>
      <div><label>صفحه</label><input type="text" name="page_number"></div>
      <div><label>وضعیت</label><select name="verification_status"><option value="unverified">تأییدنشده</option><option value="source_verified">تأیید با منبع</option><option value="expert_verified">تأیید کارشناس</option></select></div>
    </div>
    <label>معنی</label><input type="text" name="meaning"><label>علت نام‌گذاری</label><input type="text" name="naming_reason">
    <label>متن عین منبع</label><textarea name="quote"></textarea>
    <p><button class="btn small">ثبت</button></p>
  </form>
</details>
@endsection
