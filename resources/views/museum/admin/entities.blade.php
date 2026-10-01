@extends('museum.admin.layout')
@section('admin_title', 'مدخل‌ها')
@section('admin')
<h1>مدخل‌ها</h1>
<form method="get" class="grid">
  <div><select name="type"><option value="">همه انواع</option>@foreach ($types as $t)<option value="{{ $t->key }}" @selected(request('type') === $t->key)>{{ $t->name_fa }} ({{ $t->domain }})</option>@endforeach</select></div>
  <div><select name="visibility"><option value="">همه</option>@foreach (['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'hidden' => 'پنهان'] as $k => $l)<option value="{{ $k }}" @selected(request('visibility') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div><input type="text" name="q" value="{{ request('q') }}" placeholder="نام"></div>
  <div><button class="btn small">فیلتر</button></div>
</form>
<div class="scroll-x"><table class="data" style="margin-top:12px"><tr><th>نام</th><th>نوع</th><th>وضعیت</th><th>انتشار</th><th>Fact</th><th>منبع</th></tr>
@foreach ($entities as $e)<tr><td><a href="{{ route('museum.admin.entities.edit', $e) }}">{{ $e->displayName() }}</a> <span class="src">{{ $e->name_en }}</span></td><td>{{ $e->type->name_fa }}</td>
  <td>@include('museum.partials.status', ['status' => $e->verification_status])</td><td>{{ $e->visibility }}</td><td>{{ $e->facts_count }}</td><td>{{ $e->sources_count }}</td></tr>@endforeach
</table></div>
{{ $entities->links('museum.partials.pager') }}
<details class="card" style="margin-top:20px"><summary><strong>مدخل جدید</strong></summary>
  <form method="post" action="{{ route('museum.admin.entities.create') }}" class="grid">@csrf
    <div><label>نوع</label><select name="type">@foreach ($types as $t)<option value="{{ $t->key }}">{{ $t->name_fa }}</option>@endforeach</select></div>
    <div><label>نام (فارسی)</label><input type="text" name="canonical_name" required></div>
    <div><label>نام انگلیسی</label><input type="text" name="name_en"></div>
    <div><label>&nbsp;</label><button class="btn">ایجاد پیش‌نویس</button></div>
  </form>
</details>
@endsection
