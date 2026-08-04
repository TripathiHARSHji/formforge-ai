<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-6xl mx-auto p-8">
    <h1 class="text-3xl font-semibold">{{ $form->title }}</h1>
    <p class="mt-2 text-slate-600">{{ $form->description }}</p>
    <div class="mt-6 bg-white rounded-xl shadow p-6">
        <h2 class="text-xl font-semibold">Schema</h2>
        <pre class="mt-4 overflow-x-auto rounded bg-slate-900 text-slate-100 p-4">{{ json_encode($form->schema, JSON_PRETTY_PRINT) }}</pre>
    </div>
    <div class="mt-6 flex gap-4">
        <a href="{{ route('forms.fill', ['publicUuid' => $form->public_uuid]) }}" class="rounded bg-blue-600 px-4 py-2 text-white">Open public URL</a>
        <a href="{{ route('forms.export', ['form' => $form->id]) }}" class="rounded bg-slate-700 px-4 py-2 text-white">Export submissions</a>
    </div>
</div>
</body>
</html>
