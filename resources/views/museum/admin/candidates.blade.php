@extends('museum.admin.layout')
@section('admin_title', 'کشف')
@section('admin')
<h1>Discovery Engine — Candidateها</h1>
<div class="tabs">@foreach (['in_review' => 'نیازمند بررسی', 'needs_evidence' => 'نیازمند شواهد بیشتر', 'pending' => 'در انتظار پردازش', 'accepted' => 'پذیرفته', 'rejected' => 'ردشده'] as $k => $label)<a href="?status={{ $k }}" @class(['on' => $status === $k])>{{ $label }}</a>@endforeach</div>
@forelse ($candidates as $c)
  <div class="card" style="margin-bottom:10px">
    <span class="pill">{{ $c->kind }}</span> <span class="pill sea">{{ $c->entity_type_key }}</span>
    <strong>{{ $c->surface_form }}</strong>
    <span class="src">· شواهد از {{ $c->evidence_count }} منبع · اطمینان {{ $c->confidence_score !== null ? round($c->confidence_score * 100).'٪' : '—' }}</span>
    @if ($c->rejection_reason)<div class="src">دلیل: {{ $c->rejection_reason }}</div>@endif
    @if ($c->matchedEntity)<div class="src">تطبیق احتمالی: <a href="{{ route('museum.admin.entities.edit', $c->matchedEntity) }}">{{ $c->matchedEntity->displayName() }}</a> ({{ $c->match_score }})</div>@endif
    @if ($c->evidence_quote)<div class="quote">{{ $c->evidence_quote }}</div><div class="src">{{ $c->source?->citationLabel() }} @if($c->extract?->page_number) ، ص {{ $c->extract->page_number }}@endif</div>@endif
    @if ($c->kind !== 'entity')<pre class="json">{{ json_encode($c->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
    @if (in_array($status, ['in_review', 'needs_evidence']) && $c->kind === 'entity')
      <form method="post" action="{{ route('museum.admin.candidates.decide', $c) }}" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
        @csrf
        <button class="btn small alt" name="decision" value="create">ایجاد Entity جدید (پیش‌نویس)</button>
        <input type="text" name="entity_slug" placeholder="slug برای پیوند به Entity موجود" style="max-width:260px"><button class="btn small ghost" name="decision" value="link">پیوند</button>
        <input type="text" name="reason" placeholder="دلیل رد" style="max-width:200px"><button class="btn small danger" name="decision" value="reject">رد</button>
      </form>
    @endif
  </div>
@empty
  <div class="empty">موردی نیست.</div>
@endforelse
{{ $candidates->links('museum.partials.pager') }}
@endsection
