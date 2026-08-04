<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f0ebf8] min-h-screen text-slate-900">

{{-- Top Bar --}}
<header class="bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-5xl mx-auto px-8 py-4 flex items-center justify-between">
        <div class="flex items-center gap-2 text-indigo-600 font-bold text-xl tracking-tight">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            FormForge AI
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('forms.index') }}"
               class="flex items-center gap-2 rounded-full border border-slate-300 hover:bg-slate-50 px-5 py-2 text-sm font-medium text-slate-700 transition">
                All Forms
            </a>
            <a href="{{ route('forms.create') }}"
               class="flex items-center gap-2 rounded-full bg-indigo-600 hover:bg-indigo-700 px-5 py-2 text-sm font-medium text-white transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Create Form
            </a>
        </div>
    </div>
</header>

<div class="max-w-5xl mx-auto px-8 py-12">

    {{-- Hero --}}
    <div class="text-center mb-12">
        <h1 class="text-5xl font-bold text-slate-800">Build forms in seconds</h1>
        <p class="mt-4 text-lg text-slate-500">Create, generate with AI, or import — share a link and collect responses instantly.</p>
        <a href="{{ route('forms.create') }}"
           class="mt-8 inline-flex items-center gap-2 rounded-full bg-indigo-600 hover:bg-indigo-700 px-8 py-3 text-base font-semibold text-white shadow-lg transition">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Create a new form
        </a>
    </div>

    {{-- Other actions --}}
    <div class="grid gap-6 md:grid-cols-2">

        {{-- AI Generate --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 text-lg">&#10024;</div>
                <h2 class="text-lg font-semibold">Generate with AI</h2>
            </div>
            <form action="{{ route('forms.create') }}" method="GET">
                <textarea name="ai_prompt" rows="3" placeholder="Describe your form… e.g. Internship application with phone and resume upload" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-emerald-400 focus:bg-white outline-none transition resize-none" required></textarea>
                <input type="hidden" name="auto_ai" value="1">
                <button class="mt-3 rounded-full bg-emerald-600 hover:bg-emerald-700 px-5 py-2 text-sm font-medium text-white transition">Generate</button>
            </form>
        </div>

        {{-- Import --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-violet-100 flex items-center justify-center text-violet-600 text-lg">&#128196;</div>
                <h2 class="text-lg font-semibold">Import document</h2>
            </div>
            <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <p class="text-sm text-slate-500 mb-3">Upload a .docx or .xlsx file to extract a form schema automatically.</p>
                <input type="file" name="file" class="w-full text-sm text-slate-600 file:mr-3 file:rounded-full file:border-0 file:bg-violet-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-violet-700 hover:file:bg-violet-100" accept=".docx,.xlsx" required />
                <button class="mt-3 rounded-full bg-violet-600 hover:bg-violet-700 px-5 py-2 text-sm font-medium text-white transition">Import</button>
            </form>
        </div>

    </div>
</div>

</body>
</html>
