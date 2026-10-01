@extends('museum.admin.layout')
@section('admin_title', 'Importها')
@section('admin')
<h1>گزارش Importها</h1>
<div class="scroll-x"><table class="data"><tr><th>Importer</th><th>وضعیت</th><th>منابع</th><th>اسناد</th><th>Fact</th><th>Entity جدید</th><th>تطبیق</th><th>تکراری</th><th>اختلاف</th><th>خطا</th><th>شروع</th></tr>
@foreach ($batches as $b)<tr><td><a href="{{ route('museum.admin.imports.show', $b) }}">{{ $b->importer }}</a></td><td>{{ $b->status }}</td><td>{{ $b->sources_accepted }}/{{ $b->sources_found }}</td><td>{{ $b->documents_processed }}</td><td>{{ $b->facts_extracted }}</td><td>{{ $b->entities_created }}</td><td>{{ $b->entities_matched }}</td><td>{{ $b->duplicates }}</td><td>{{ $b->conflicts }}</td><td>{{ $b->errors }}</td><td class="ltr">{{ $b->started_at }}</td></tr>@endforeach
</table></div>
{{ $batches->links('museum.partials.pager') }}
@endsection
