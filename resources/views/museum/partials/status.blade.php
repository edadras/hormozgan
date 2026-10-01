@php
  $map = ['source_verified' => ['sea', 'تأیید با منبع'], 'community_verified' => ['green', 'تأیید جامعه'], 'expert_verified' => ['gold', 'تأیید کارشناس'],
          'ai_extracted' => ['', 'استخراج AI'], 'unverified' => ['', 'تأییدنشده'], 'disputed' => ['red', 'محل اختلاف'], 'rejected' => ['red', 'ردشده']];
  [$cls, $label] = $map[$status] ?? ['', $status];
@endphp
<span class="pill badge-status {{ $cls }}" title="وضعیت تأیید">{{ $label }}</span>
