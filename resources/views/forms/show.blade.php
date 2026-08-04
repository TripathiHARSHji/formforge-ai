<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->title }} — FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f0ebf8] min-h-screen text-slate-800">
<div class="max-w-5xl mx-auto px-6 py-8 space-y-6">

    {{-- Nav bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 rounded-full bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white transition">
            + Create new form
        </a>
        <a href="{{ route('forms.fill', ['publicUuid'=>$form->public_uuid]) }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-full border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-4 py-2 text-sm font-medium transition">
            &#128279; Open public URL
        </a>
        <a href="{{ route('forms.export', ['form'=>$form->id]) }}" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 text-emerald-700 hover:bg-emerald-50 px-4 py-2 text-sm font-medium transition">
            &#8659; Export JSON
        </a>
        <a href="{{ route('forms.export', ['form'=>$form->id, 'format' => 'csv']) }}" class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 text-slate-700 hover:bg-slate-100 px-4 py-2 text-sm font-medium transition">
            &#8659; Export CSV
        </a>
    </div>

    {{-- Form info --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="h-2 bg-gradient-to-r from-indigo-500 to-purple-500"></div>
        <div class="p-6 flex items-start gap-4">
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-slate-800">{{ $form->title }}</h1>
                @if($form->description)<p class="mt-1 text-slate-500">{{ $form->description }}</p>@endif
            </div>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full {{ $form->status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                {{ ucfirst($form->status ?? 'draft') }}
            </span>
        </div>
        <div class="px-6 pb-4 flex gap-4 text-sm text-slate-500">
            <span>{{ count($form->schema['fields'] ?? []) }} fields</span>
            <span>&#8226;</span>
            <span>{{ $totalSubmissions }} submission{{ $totalSubmissions == 1 ? '' : 's' }}</span>
            <span>&#8226;</span>
            <span>UUID: <code class="text-xs bg-slate-100 px-1 rounded">{{ $form->public_uuid }}</code></span>
        </div>
    </div>

    {{-- Submissions --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center gap-4 flex-wrap">
            <h2 class="text-base font-bold text-slate-700 flex-1">Submissions</h2>
            <form method="GET" class="flex items-center gap-2">
                <input name="search" value="{{ $search }}" placeholder="Search responses…" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm focus:border-indigo-400 focus:outline-none w-52" />
                <button type="submit" class="rounded-lg bg-slate-700 text-white px-3 py-1.5 text-xs font-medium hover:bg-slate-800 transition">Search</button>
                @if($search)<a href="{{ request()->url() }}" class="text-xs text-slate-400 hover:text-slate-600 transition">Clear</a>@endif
            </form>
        </div>

        @if($submissions->isEmpty())
            <div class="py-16 text-center text-slate-400">
                <div class="text-4xl mb-3">&#128203;</div>
                <p>{{ $search ? 'No submissions match your search.' : 'No submissions yet.' }}</p>
            </div>
        @else
            @php $fields = collect($form->schema['fields'] ?? [])->filter(fn($f)=>($f['type']??'')<>'heading'); @endphp
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide w-8">#</th>
                            <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">Submitted</th>
                            @foreach($fields as $f)
                                <th class="text-left px-4 py-3 text-xs font-bold text-slate-500 uppercase tracking-wide">
                                    {{ $f['key'] ?? $f['id'] ?? '?' }}
                                    @if(!empty($f['label']) && $f['label'] !== ($f['key']??''))
                                        <span class="font-normal normal-case text-slate-400">({{ $f['label'] }})</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($submissions as $idx => $sub)
                            @php
                                $subId   = is_array($sub) ? ($sub['id'] ?? '?')   : ($sub->id ?? '?');
                                $subAt   = is_array($sub) ? ($sub['created_at'] ?? '') : ($sub->created_at ? $sub->created_at->format('Y-m-d H:i') : '');
                                $answers = is_array($sub) ? ($sub['answers'] ?? []) : ($sub->answers ?? []);
                            @endphp
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 text-slate-400 text-xs">{{ $subId }}</td>
                                <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap">{{ $subAt }}</td>
                                @foreach($fields as $f)
                                    @php $k=$f['key']??$f['id']??''; $v=$answers[$k]??null; @endphp
                                    <td class="px-4 py-3 text-slate-700 max-w-xs truncate">
                                        {{ is_array($v) ? implode(', ', $v) : ($v ?? '—') }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($totalPages > 1)
                <div class="px-5 py-4 border-t border-slate-100 flex items-center gap-2 text-sm flex-wrap">
                    <span class="text-slate-500 text-xs flex-1">Page {{ $currentPage }} of {{ $totalPages }} &mdash; {{ $totalSubmissions }} total</span>
                    @if($currentPage > 1)
                        <a href="{{ request()->fullUrlWithQuery(['page'=>$currentPage-1]) }}" class="px-3 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 transition text-slate-600">&#8592; Prev</a>
                    @endif
                    @for($p=max(1,$currentPage-2); $p<=min($totalPages,$currentPage+2); $p++)
                        <a href="{{ request()->fullUrlWithQuery(['page'=>$p]) }}" class="px-3 py-1 rounded-lg border {{ $p==$currentPage ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-200 hover:bg-slate-50 text-slate-600' }} transition">{{ $p }}</a>
                    @endfor
                    @if($currentPage < $totalPages)
                        <a href="{{ request()->fullUrlWithQuery(['page'=>$currentPage+1]) }}" class="px-3 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 transition text-slate-600">Next &#8594;</a>
                    @endif
                </div>
            @endif
        @endif
    </div>

    {{-- Schema viewer (collapsible) --}}
    <details class="bg-white rounded-xl shadow-sm overflow-hidden">
        <summary class="px-5 py-4 cursor-pointer font-semibold text-sm text-slate-700 hover:bg-slate-50 select-none">&#123;&#125; View raw schema</summary>
        <div class="px-5 pb-5">
            <pre class="mt-2 overflow-x-auto rounded-lg bg-slate-900 text-emerald-300 text-xs p-4 leading-relaxed">{{ json_encode($form->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </details>

</div>
</body>
</html>