<a class="card door" href="{{ $e['url'] ?? url('/museum/e/'.$e['slug']) }}">
  @if (!empty($e['image']))
    <img class="card-img" loading="lazy" src="{{ $e['image']['thumb'] }}" onerror="this.onerror=null;this.src='{{ $e['image']['url'] }}'" alt="{{ $e['name'] }}" title="{{ $e['image']['credit'] }}">
  @endif
  <span class="pill sea">{{ $e['type_label'] }}</span>
  <h3 style="margin-top:8px">{{ $e['name'] }}</h3>
  @if (!empty($e['name_en']) && $e['name_en'] !== $e['name'])<div class="meta ltr">{{ $e['name_en'] }}</div>@endif
  <div class="meta">{{ $e['facts_count'] }} ادعای ثبت‌شده</div>
</a>
