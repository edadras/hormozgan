<a class="card door" href="{{ $e['url'] ?? url('/museum/e/'.$e['slug']) }}">
  <span class="pill sea">{{ $e['type_label'] }}</span>
  <h3 style="margin-top:8px">{{ $e['name'] }}</h3>
  @if (!empty($e['name_en']) && $e['name_en'] !== $e['name'])<div class="meta ltr">{{ $e['name_en'] }}</div>@endif
  <div class="meta">{{ $e['facts_count'] }} ادعای ثبت‌شده</div>
</a>
