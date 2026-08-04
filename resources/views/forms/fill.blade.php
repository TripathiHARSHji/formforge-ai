<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-3xl mx-auto p-8">
    <h1 class="text-3xl font-semibold">{{ $form->title }}</h1>
    <p class="mt-2 text-slate-600">{{ $form->description }}</p>
    <form action="{{ route('forms.submit', ['publicUuid' => $form->public_uuid]) }}" method="POST" class="mt-6 space-y-4 bg-white rounded-xl shadow p-6">
        @csrf
        @foreach($form->schema['fields'] ?? [] as $field)
            <div>
                <label class="block text-sm font-medium" for="{{ $field['key'] ?? $field['id'] }}">{{ $field['label'] ?? $field['id'] }}</label>
                @php($key = $field['key'] ?? $field['id'])
                @if(($field['type'] ?? 'text') === 'textarea')
                    <textarea name="answers[{{ $key }}]" id="{{ $key }}" class="mt-2 w-full rounded border border-slate-300 p-2"></textarea>
                @elseif(($field['type'] ?? 'text') === 'email')
                    <input type="email" name="answers[{{ $key }}]" id="{{ $key }}" class="mt-2 w-full rounded border border-slate-300 p-2" />
                @elseif(($field['type'] ?? 'text') === 'file')
                    <input type="file" name="answers[{{ $key }}]" id="{{ $key }}" class="mt-2 w-full rounded border border-slate-300 p-2" />
                @else
                    <input type="text" name="answers[{{ $key }}]" id="{{ $key }}" class="mt-2 w-full rounded border border-slate-300 p-2" />
                @endif
            </div>
        @endforeach
        <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-white">Submit</button>
    </form>
</div>
</body>
</html>
