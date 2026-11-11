<form method="post" action="{{ url('/reports/subscriptions/ignore') }}" style="display:inline;">
    @csrf
    <input type="hidden" name="key" value="{{ $k }}">
    @if (!empty($undo))
        <input type="hidden" name="undo" value="1">
        <button class="sw-x" style="color:#1565c0;">Put back</button>
    @else
        <button class="sw-x" title="Hide this merchant from the report">Not a subscription</button>
    @endif
</form>
