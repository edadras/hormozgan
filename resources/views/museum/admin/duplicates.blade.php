@extends('museum.admin.layout')
@section('admin_title', 'موارد تکراری')
@section('admin')
<h1>موارد تکراری احتمالی</h1>
<p class="muted">ادغام غیرمخرب است: نسخه کامل مدخل ادغام‌شده نگهداری و نام‌هایش به Alias تبدیل می‌شوند. روستاهای هم‌نام را ادغام نکنید.</p>
@forelse ($dups as $d)
  <form method="post" action="{{ route('museum.admin.duplicates.decide', $d) }}" class="card" style="margin-bottom:10px">
    @csrf
    <div class="grid">
      @foreach (['A' => $d->entityA, 'B' => $d->entityB] as $k => $e)
        <div><strong>{{ $k }}:</strong> <a href="{{ route('museum.admin.entities.edit', $e) }}">{{ $e->displayName() }}</a> <span class="pill">{{ $e->type->name_fa }}</span><div class="src">{{ $e->wikidata_id }} · {{ $e->facts_count }} fact · {{ $e->latitude }},{{ $e->longitude }}</div></div>
      @endforeach
    </div>
    <div class="src">امتیاز {{ round($d->score, 2) }} · {{ $d->method }} · <span class="ltr">{{ json_encode($d->evidence, JSON_UNESCAPED_UNICODE) }}</span></div>
    <input type="text" name="reason" required placeholder="دلیل تصمیم" style="margin:8px 0">
    <button class="btn small alt" name="decision" value="merge_a_into_b">ادغام A در B</button>
    <button class="btn small alt" name="decision" value="merge_b_into_a">ادغام B در A</button>
    <button class="btn small ghost" name="decision" value="distinct">متفاوت‌اند</button>
  </form>
@empty
  <div class="empty">مورد تکراری در صف نیست.</div>
@endforelse
{{ $dups->links('museum.partials.pager') }}
@endsection
