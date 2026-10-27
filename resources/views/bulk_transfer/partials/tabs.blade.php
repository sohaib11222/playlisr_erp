{{-- Tabs shared by the Transfers pages (single sidebar entry). --}}
<div class="tr-actions" style="margin-bottom:14px;">
  @foreach(['bulk' => 'Transfer Items', 'receive' => 'Receive Transfer', 'log' => 'Transfer Log'] as $seg => $label)
    <a href="{{ url('/transfers/' . $seg) }}" class="tr-btn" style="{{ request()->segment(2) == $seg ? 'background:var(--tr-sel); color:var(--tr-sel-ink); border-color:#BCD6F7;' : '' }}">{{ $label }}</a>
  @endforeach
</div>
