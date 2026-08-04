<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-5xl mx-auto p-8">
    <div class="rounded-2xl bg-white p-8 shadow">
        <h1 class="text-4xl font-semibold">FormForge AI</h1>
        <p class="mt-3 text-lg text-slate-600">A working AI-powered form builder scaffold with schema-driven forms, submissions, AI generation, and document import workflows.</p>
        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <form action="{{ route('forms.store') }}" method="POST" class="rounded-xl border border-slate-200 p-4">
                @csrf
                <h2 class="text-xl font-semibold">Create a form</h2>
                <input name="title" placeholder="Form title" class="mt-3 w-full rounded border border-slate-300 p-2" required />
                <textarea name="description" placeholder="Description" class="mt-3 w-full rounded border border-slate-300 p-2"></textarea>
                <input type="hidden" name="schema" value='{"title":"Demo form","fields":[{"id":"name","type":"text","label":"Name","key":"name","required":true}]}'>
                <button class="mt-4 rounded bg-blue-600 px-4 py-2 text-white">Create</button>
            </form>
            <form action="{{ route('ai.generate') }}" method="POST" class="rounded-xl border border-slate-200 p-4">
                @csrf
                <h2 class="text-xl font-semibold">Generate with AI</h2>
                <textarea name="prompt" placeholder="Internship application with phone and resume upload" class="mt-3 w-full rounded border border-slate-300 p-2" required></textarea>
                <button class="mt-4 rounded bg-emerald-600 px-4 py-2 text-white">Generate</button>
            </form>
            <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data" class="rounded-xl border border-slate-200 p-4">
                @csrf
                <h2 class="text-xl font-semibold">Import document</h2>
                <input type="file" name="file" class="mt-3 w-full" accept=".docx,.xlsx" required />
                <button class="mt-4 rounded bg-violet-600 px-4 py-2 text-white">Import</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
