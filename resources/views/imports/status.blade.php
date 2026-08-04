<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Status - FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800">
<div class="max-w-3xl mx-auto px-6 py-10">
    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('forms.index') }}" class="text-sm rounded-full border border-slate-300 px-4 py-2 hover:bg-white">All Forms</a>
        <a href="{{ url('/') }}" class="text-sm rounded-full border border-slate-300 px-4 py-2 hover:bg-white">Home</a>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h1 class="text-xl font-bold">Document Import Status</h1>
            <p class="text-sm text-slate-500 mt-1">Import #{{ $import->id }} - {{ strtoupper($import->source_type) }}</p>
        </div>

        <div class="p-6 space-y-4">
            <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Current Status</p>
                <p id="status-text" class="text-base font-semibold mt-1 text-slate-800">{{ ucfirst($import->status) }}</p>
                <p id="status-sub" class="text-sm text-slate-500 mt-1">Waiting for parser updates...</p>
            </div>

            <div id="error-box" class="hidden rounded-xl border border-red-200 bg-red-50 p-4">
                <p class="text-sm font-semibold text-red-700">Import failed</p>
                <p id="error-text" class="text-sm text-red-700 mt-1"></p>
            </div>

            <div id="summary-box" class="hidden rounded-xl border border-slate-200 p-4 bg-white">
                <p class="text-sm font-semibold text-slate-700 mb-2">Parser Summary</p>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Fields:</span> <strong id="sum-fields">0</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Ambiguities:</span> <strong id="sum-amb">0</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Unparseable blocks:</span> <strong id="sum-unp">0</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Layout:</span> <strong id="sum-layout">-</strong></div>
                </div>
            </div>

            <div id="action-box" class="hidden">
                <a id="preview-link" href="#" class="inline-flex items-center rounded-full bg-indigo-600 hover:bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white">Open Preview & Mapping</a>
            </div>
        </div>
    </div>
</div>

<script>
const STATUS_URL = @json(route('imports.status', ['import' => $import->id]));
const statusText = document.getElementById('status-text');
const statusSub = document.getElementById('status-sub');
const summaryBox = document.getElementById('summary-box');
const errorBox = document.getElementById('error-box');
const errorText = document.getElementById('error-text');
const actionBox = document.getElementById('action-box');
const previewLink = document.getElementById('preview-link');

let pollTimer = null;

function setSummary(summary) {
    document.getElementById('sum-fields').textContent = summary?.parsed_fields ?? 0;
    document.getElementById('sum-amb').textContent = summary?.ambiguities ?? 0;
    document.getElementById('sum-unp').textContent = summary?.unparseable_blocks ?? 0;
    document.getElementById('sum-layout').textContent = summary?.layout ?? '-';
    summaryBox.classList.remove('hidden');
}

async function poll() {
    try {
        const response = await fetch(STATUS_URL, { headers: { 'Accept': 'application/json' } });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload?.message || 'Status fetch failed.');
        }

        const status = String(payload.status || 'unknown');
        statusText.textContent = status.charAt(0).toUpperCase() + status.slice(1);

        if (payload.summary) {
            setSummary(payload.summary);
        }

        if (status === 'queued') {
            statusSub.textContent = 'Large file detected. Waiting in queue...';
            return;
        }
        if (status === 'processing') {
            statusSub.textContent = 'Parser is processing your document...';
            return;
        }
        if (status === 'parsed' && payload.preview_url) {
            statusSub.textContent = 'Parsing completed. Review detected fields before creating the form.';
            previewLink.href = payload.preview_url;
            actionBox.classList.remove('hidden');
            clearInterval(pollTimer);
            return;
        }
        if (status === 'completed' && payload.preview_url) {
            previewLink.href = payload.preview_url;
            actionBox.classList.remove('hidden');
            clearInterval(pollTimer);
            return;
        }
        if (status === 'failed') {
            statusSub.textContent = 'Parser could not complete this file.';
            errorText.textContent = payload.error || 'Unknown parsing error.';
            errorBox.classList.remove('hidden');
            clearInterval(pollTimer);
            return;
        }

        statusSub.textContent = 'Waiting for parser updates...';
    } catch (err) {
        statusSub.textContent = err?.message || 'Unable to fetch status right now.';
    }
}

poll();
pollTimer = setInterval(poll, 2000);
</script>
</body>
</html>
