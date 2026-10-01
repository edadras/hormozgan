@extends('museum.admin.layout')
@section('admin_title', 'گزارش Import')
@section('admin')
<h1>{{ $b->importer }} <span class="pill">{{ $b->status }}</span></h1>
<p class="src ltr">{{ $b->uuid }} · {{ $b->input_reference }}</p>
<table class="data">@foreach (\App\Museum\Importers\ImportRun::reportLines($b) as [$label, $n])<tr><th>{{ $label }}</th><td>{{ number_format($n) }}</td></tr>@endforeach</table>
@if ($b->error_log)<h2 class="sec" style="margin-top:20px">خطاها</h2><pre class="json">{{ json_encode($b->error_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
@endsection
