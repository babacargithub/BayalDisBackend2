@props(['success' => null, 'error' => null])

<div>
    @if($success)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $error }}</div>
    @endif
    @if($errors->any())
        <ul class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            @foreach($errors->all() as $validationMessage)
                <li>{{ $validationMessage }}</li>
            @endforeach
        </ul>
    @endif
</div>
