<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Preview - FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800">
<div class="max-w-6xl mx-auto px-6 py-8 space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-2xl font-bold">Preview & Mapping</h1>
            <p class="text-sm text-slate-500">Import #{{ $import->id }} - verify detected field types before creating your editable form.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('imports.status.page', ['import' => $import->id]) }}" class="rounded-full border border-slate-300 px-4 py-2 text-sm hover:bg-white">Status</a>
            <a href="{{ route('forms.index') }}" class="rounded-full border border-slate-300 px-4 py-2 text-sm hover:bg-white">All Forms</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h2 class="text-base font-semibold">Hybrid Parse Strategy</h2>
                <p class="text-sm text-slate-600 mt-2">Deterministic parsing extracts structure first. AI only refines ambiguous type and validation hints when needed.</p>
                <div class="grid md:grid-cols-2 gap-3 mt-4 text-sm">
                    <div class="rounded-xl bg-slate-50 p-3 border border-slate-200">
                        <p class="text-slate-500">Deterministic layout</p>
                        <p class="font-semibold">{{ $layout ?: '-' }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3 border border-slate-200">
                        <p class="text-slate-500">Ambiguous fields</p>
                        <p class="font-semibold">{{ count($ambiguities) }}</p>
                    </div>
                </div>
                @if(is_array($hybridSplit))
                    <div class="mt-3 text-xs text-slate-500 space-y-1">
                        <p>Deterministic fields: {{ $hybridSplit['deterministic']['fields_count'] ?? count($schema['fields'] ?? []) }}</p>
                        <p>AI attempted: {{ !empty($hybridSplit['ai_refinement']['attempted']) ? 'yes' : 'no' }}</p>
                        <p>AI applied: {{ !empty($hybridSplit['ai_refinement']['applied']) ? 'yes' : 'no' }}</p>
                    </div>
                @endif
                @if(!empty($layoutNotes))
                    <ul class="mt-3 list-disc pl-5 text-sm text-slate-600 space-y-1">
                        @foreach($layoutNotes as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="text-base font-semibold">Field Mapping</h2>
                    <p class="text-sm text-slate-500 mt-1">Edit labels, keys, and types. Choice options use comma-separated values.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" id="fields-table">
                        <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="text-left px-3 py-2">Label</th>
                            <th class="text-left px-3 py-2">Key</th>
                            <th class="text-left px-3 py-2">Type</th>
                            <th class="text-left px-3 py-2">Required</th>
                            <th class="text-left px-3 py-2">Options</th>
                        </tr>
                        </thead>
                        <tbody id="fields-body" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h3 class="font-semibold">Form Details</h3>
                <form id="commit-form" action="{{ route('imports.commit', ['import' => $import->id]) }}" method="POST" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label class="text-xs text-slate-500">Title</label>
                        <input id="form-title" name="title" value="{{ old('title', $schema['title'] ?? 'Imported form') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Description</label>
                        <textarea id="form-description" name="description" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('description', $schema['description'] ?? '') }}</textarea>
                    </div>
                    <input type="hidden" id="schema-json" name="schema">
                    @error('schema')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full rounded-full bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white">Create Editable Form</button>
                </form>
            </div>

            @if(!empty($warnings))
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
                    <h3 class="text-sm font-semibold text-amber-800">Warnings</h3>
                    <ul class="mt-2 text-xs text-amber-800 list-disc pl-4 space-y-1">
                        @foreach($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($unparseableBlocks))
                <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">
                    <h3 class="text-sm font-semibold text-rose-800">Unparseable Blocks</h3>
                    <p class="text-xs text-rose-700 mt-1">These lines could not be mapped automatically. You can manually add fields later in the builder.</p>
                    <ul class="mt-2 text-xs text-rose-800 list-disc pl-4 space-y-1 max-h-56 overflow-y-auto">
                        @foreach($unparseableBlocks as $block)
                            <li>{{ $block }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($ambiguities))
                <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4">
                    <h3 class="text-sm font-semibold text-blue-800">Ambiguous Detections</h3>
                    <ul class="mt-2 text-xs text-blue-800 list-disc pl-4 space-y-1 max-h-56 overflow-y-auto">
                        @foreach($ambiguities as $item)
                            <li>{{ ($item['field_key'] ?? $item['field_id'] ?? 'field') }}: {{ $item['reason'] ?? 'Review this field.' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
const INITIAL_SCHEMA = @json($schema);
const CHOICE_TYPES = ['dropdown', 'radio', 'checkbox'];
const SUPPORTED_TYPES = ['text','textarea','number','email','phone','date','file','rating','dropdown','radio','checkbox','heading','url'];

const fieldsBody = document.getElementById('fields-body');
const schemaJsonInput = document.getElementById('schema-json');
const commitForm = document.getElementById('commit-form');

const state = {
    fields: Array.isArray(INITIAL_SCHEMA.fields) ? INITIAL_SCHEMA.fields : [],
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderFields() {
    fieldsBody.innerHTML = '';

    state.fields.forEach((field, index) => {
        const tr = document.createElement('tr');
        const isHeading = field.type === 'heading';
        const optionsValue = Array.isArray(field.options) ? field.options.join(', ') : '';

        tr.innerHTML = `
            <td class="px-3 py-2">
                <input data-index="${index}" data-prop="label" class="w-full rounded border border-slate-300 px-2 py-1" value="${escapeHtml(field.label || '')}">
            </td>
            <td class="px-3 py-2">
                <input data-index="${index}" data-prop="key" class="w-full rounded border border-slate-300 px-2 py-1" value="${escapeHtml(field.key || '')}" ${isHeading ? 'disabled' : ''}>
            </td>
            <td class="px-3 py-2">
                <select data-index="${index}" data-prop="type" class="w-full rounded border border-slate-300 px-2 py-1">
                    ${SUPPORTED_TYPES.map(type => `<option value="${type}" ${field.type === type ? 'selected' : ''}>${type}</option>`).join('')}
                </select>
            </td>
            <td class="px-3 py-2 text-center">
                <input data-index="${index}" data-prop="required" type="checkbox" ${field.required ? 'checked' : ''} ${isHeading ? 'disabled' : ''}>
            </td>
            <td class="px-3 py-2">
                <input data-index="${index}" data-prop="options" class="w-full rounded border border-slate-300 px-2 py-1" value="${escapeHtml(optionsValue)}" ${CHOICE_TYPES.includes(field.type) ? '' : 'disabled'}>
            </td>
        `;

        fieldsBody.appendChild(tr);
    });
}

function readFieldsFromDom() {
    const rows = fieldsBody.querySelectorAll('tr');

    rows.forEach((row, index) => {
        const labelEl = row.querySelector('[data-prop="label"]');
        const keyEl = row.querySelector('[data-prop="key"]');
        const typeEl = row.querySelector('[data-prop="type"]');
        const requiredEl = row.querySelector('[data-prop="required"]');
        const optionsEl = row.querySelector('[data-prop="options"]');

        const next = { ...state.fields[index] };
        next.label = String(labelEl?.value || '').trim();
        next.type = String(typeEl?.value || 'text').trim();

        if (next.type === 'heading') {
            delete next.key;
            next.required = false;
            delete next.options;
        } else {
            next.key = String(keyEl?.value || '').trim();
            next.required = !!requiredEl?.checked;
            if (CHOICE_TYPES.includes(next.type)) {
                const options = String(optionsEl?.value || '')
                    .split(',')
                    .map(v => v.trim())
                    .filter(v => v.length > 0);
                next.options = options;
            } else {
                delete next.options;
            }
        }

        state.fields[index] = next;
    });
}

fieldsBody.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (target.matches('select[data-prop="type"]')) {
        readFieldsFromDom();
        renderFields();
    }
});

commitForm.addEventListener('submit', (event) => {
    readFieldsFromDom();

    const title = document.getElementById('form-title').value.trim();
    const description = document.getElementById('form-description').value.trim();

    if (!title) {
        event.preventDefault();
        alert('Title is required.');
        return;
    }

    const payload = {
        title,
        description,
        fields: state.fields,
    };

    schemaJsonInput.value = JSON.stringify(payload);
});

renderFields();
</script>
</body>
</html>
