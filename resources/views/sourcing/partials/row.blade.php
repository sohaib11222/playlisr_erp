@php
    $category = $row['category'];
    $priorityColors = ['high' => 'danger', 'medium' => 'warning', 'low' => 'default'];
    $margin = ($row['target_buy_price'] !== null && $row['avg_sell_price'])
        ? $row['avg_sell_price'] - $row['target_buy_price']
        : null;
@endphp
<tr>
    <td>
        @if($isParent)
            <strong>{{ $category->name }}</strong>
        @else
            <span style="padding-left:20px; color:#666;">&#8627; {{ $category->name }}</span>
        @endif
    </td>
    <td>
        @if($canManage)
            <form action="{{ action('SourcingController@updatePriority', $category->id) }}" method="POST" style="display:inline-block;">
                @csrf
                <select name="priority" class="form-control input-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;">
                    @foreach($priorityLabels as $key => $label)
                        <option value="{{ $key }}" @if($row['priority'] === $key) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        @else
            <span class="label label-{{ $priorityColors[$row['priority']] ?? 'default' }}">{{ $priorityLabels[$row['priority']] ?? ucfirst($row['priority']) }}</span>
        @endif
    </td>
    <td>
        @if($canManage)
            <form action="{{ action('SourcingController@updateTargetPrice', $category->id) }}" method="POST" class="form-inline">
                @csrf
                <div class="input-group input-group-sm" style="width:110px;">
                    <span class="input-group-addon">$</span>
                    <input type="number" step="0.01" min="0" name="target_buy_price" class="form-control" value="{{ $row['target_buy_price'] }}">
                </div>
                <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-check"></i></button>
            </form>
        @else
            {{ $row['target_buy_price'] !== null ? '$' . number_format($row['target_buy_price'], 2) : '—' }}
        @endif
    </td>
    <td>{{ $row['avg_sell_price'] ? '$' . number_format($row['avg_sell_price'], 2) : '—' }}</td>
    <td>{{ $margin !== null ? '$' . number_format($margin, 2) : '—' }}</td>
    <td>{{ (int) $row['units_sold'] }}</td>
    <td>${{ number_format($row['revenue'], 2) }}</td>
    <td>
        <details>
            <summary>{{ $row['notes']->count() }} tip{{ $row['notes']->count() === 1 ? '' : 's' }}</summary>
            <ul class="list-unstyled" style="margin-top:8px;">
                @foreach($row['notes'] as $note)
                    <li style="margin-bottom:6px;">
                        {{ $note->description }}
                        <div class="text-muted"><small>{{ $note->createdBy->user_full_name ?? '' }} &middot; {{ $note->created_at->format('M j, Y') }}</small></div>
                        @if($canManage || $note->created_by == auth()->id())
                            <form action="{{ action('SourcingController@destroyIdea', $note->id) }}" method="POST" style="display:inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-link btn-xs text-danger" style="padding:0;" onclick="return confirm('Remove this tip?')">Remove</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
            <form action="{{ action('SourcingController@storeIdea', $category->id) }}" method="POST">
                @csrf
                <div class="input-group input-group-sm">
                    <input type="text" name="description" class="form-control" placeholder="Add a sourcing tip..." maxlength="2000" required>
                    <span class="input-group-btn">
                        <button type="submit" class="btn btn-default"><i class="fa fa-plus"></i></button>
                    </span>
                </div>
            </form>
        </details>
    </td>
</tr>
