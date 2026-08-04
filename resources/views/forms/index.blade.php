<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Forms - FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f0ebf8] min-h-screen text-slate-800">
<div class="max-w-6xl mx-auto px-6 py-8 space-y-6">
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ url('/') }}" class="inline-flex items-center gap-2 rounded-full border border-slate-300 text-slate-700 hover:bg-white px-4 py-2 text-sm font-medium transition">Home</a>
        <a href="{{ route('forms.create') }}" class="inline-flex items-center gap-2 rounded-full bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white transition">+ Create Form</a>
        <div class="flex-1"></div>
        <form method="GET" class="flex items-center gap-2">
            <input name="search" value="{{ $search }}" placeholder="Search forms..." class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none w-64" />
            <button type="submit" class="rounded-lg bg-slate-700 text-white px-3 py-2 text-xs font-medium hover:bg-slate-800 transition">Search</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h1 class="text-xl font-bold text-slate-800">All Available Forms</h1>
            <p class="text-sm text-slate-500 mt-1">Manage every form here, then edit normally or with AI assistance.</p>
        </div>

        @if($forms->isEmpty())
            <div class="px-6 py-16 text-center text-slate-400">
                <div class="text-4xl mb-3">🧾</div>
                <p>No forms found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Title</th>
                        <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Status</th>
                        <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Fields</th>
                        <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Submissions</th>
                        <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Updated</th>
                        <th class="text-right px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                    @foreach($forms as $form)
                        @php
                            $schema = is_array($form['schema'] ?? null) ? $form['schema'] : ($form->schema ?? ['fields' => []]);
                            $fieldsCount = count($schema['fields'] ?? []);
                            $subsCount = $form['submissions_count'] ?? ($form->submissions_count ?? 0);
                            $updatedAt = $form['updated_at'] ?? ($form->updated_at ?? null);
                            $formId = $form['id'] ?? $form->id;
                            $status = $form['status'] ?? $form->status ?? 'draft';
                            $title = $form['title'] ?? $form->title ?? 'Untitled Form';
                        @endphp
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-4 py-3 font-medium text-slate-700">{{ $title }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-semibold px-2.5 py-1 rounded-full {{ $status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $fieldsCount }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $subsCount }}</td>
                            <td class="px-4 py-3 text-slate-500 text-xs">
                                {{ $updatedAt ? \Illuminate\Support\Carbon::parse($updatedAt)->format('Y-m-d H:i') : '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('forms.show', ['form' => $formId]) }}" class="text-xs rounded-full border border-slate-300 text-slate-700 hover:bg-slate-100 px-3 py-1.5">View</a>
                                    <a href="{{ route('forms.edit', ['form' => $formId]) }}" class="text-xs rounded-full border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-3 py-1.5">Edit</a>
                                    <a href="{{ route('forms.edit', ['form' => $formId, 'auto_ai' => 1]) }}" class="text-xs rounded-full bg-emerald-600 text-white hover:bg-emerald-700 px-3 py-1.5">AI Edit</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-6 py-4 border-t border-slate-100">
                {{ $forms->links() }}
            </div>
        @endif
    </div>
</div>
</body>
</html>
