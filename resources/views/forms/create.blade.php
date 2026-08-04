<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Form — FormForge AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
    <style>
        *{box-sizing:border-box}
        body{font-family:system-ui,sans-serif}
        .field-card{transition:box-shadow .12s,background .12s;cursor:pointer}
        .field-card:hover{box-shadow:0 2px 12px rgba(99,102,241,.1)}
        .field-card.selected{box-shadow:inset 3px 0 0 #6366f1;background:#faf5ff!important}
        .sortable-ghost{opacity:.35;background:#e0e7ff!important}
        .sortable-chosen{box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:50}
        .drag-handle{cursor:grab;user-select:none;color:#cbd5e1;font-size:1.1rem;line-height:1;padding:2px 4px}
        .drag-handle:hover{color:#6366f1}
        .drag-handle:active{cursor:grabbing}
        .type-btn{transition:background .1s,color .1s;cursor:grab}
        .type-btn:active{cursor:grabbing}
        .type-btn:hover{background:#ede9fe;color:#6366f1}
        .palette-ghost{opacity:.25;background:#e0e7ff!important;border-radius:.5rem}
        #fields-list{min-height:48px}
        .drag-active #fields-list{outline:2px dashed #818cf8;outline-offset:6px;border-radius:12px}
        .drag-active #empty-state{outline:2px dashed #818cf8;outline-offset:6px}
        .cfg-input{width:100%;border-radius:.5rem;border:1px solid #e2e8f0;background:#f8fafc;padding:.375rem .625rem;font-size:.8125rem;outline:none;transition:border .15s,background .15s}
        .cfg-input:focus{border-color:#818cf8;background:#fff}
        .cfg-tab{padding:.5rem 0;font-size:.75rem;font-weight:600;text-align:center;border-bottom:2px solid transparent;color:#94a3b8;cursor:pointer;transition:color .15s,border-color .15s;flex:1}
        .cfg-tab.active{color:#6366f1;border-color:#6366f1}
        #json-editor{tab-size:2}
        .toggle-track{width:2.25rem;height:1.25rem;background:#cbd5e1;border-radius:9999px;position:relative;transition:background .2s;display:inline-block;cursor:pointer}
        .toggle-track.on{background:#6366f1}
        .toggle-thumb{width:1rem;height:1rem;background:#fff;border-radius:50%;position:absolute;top:.125rem;left:.125rem;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
        .toggle-track.on .toggle-thumb{transform:translateX(1rem)}
        @keyframes ff-spin{to{transform:rotate(360deg)}}
        .ff-spinner{width:2.5rem;height:2.5rem;border:4px solid #d1d5db;border-top-color:#10b981;border-radius:9999px;animation:ff-spin .9s linear infinite}
    </style>
</head>
<body class="h-screen flex flex-col bg-slate-100 overflow-hidden text-slate-800 select-none">

<!-- ═══ TOP BAR ═══════════════════════════════════════════════ -->
<header class="h-14 bg-white border-b border-slate-200 flex items-center gap-3 px-4 flex-shrink-0 z-30 shadow-sm">
    <a href="/" class="flex items-center gap-1.5 text-indigo-600 font-bold text-sm shrink-0 hover:text-indigo-800">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        FormForge AI
    </a>
    <a href="{{ route('forms.index') }}" class="text-xs text-slate-600 hover:text-indigo-700 px-2 py-1 rounded border border-slate-200 hover:border-indigo-300 transition">All Forms</a>
    <div class="w-px h-5 bg-slate-200 shrink-0"></div>
    <input id="hdr-title" placeholder="Untitled Form" class="text-sm font-semibold text-slate-700 bg-transparent border-b border-transparent hover:border-slate-300 focus:border-indigo-400 focus:outline-none px-1 py-0.5 w-56 transition select-text" />
    <span id="ai-status" class="text-xs text-slate-500"></span>
    <div class="flex-1"></div>
    <button id="btn-ai" type="button" class="text-xs text-emerald-700 px-3 py-1.5 rounded-lg border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 font-medium transition">Generate with AI</button>
    <button id="btn-json" type="button" class="text-xs text-slate-600 px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 font-mono transition">{ } Schema</button>
    <button id="btn-save" type="button" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-1.5 rounded-full font-semibold transition shadow-sm">{{ !empty($editingForm) ? 'Save Changes' : 'Create Form' }}</button>
</header>

<!-- ═══ 3-PANEL BODY ══════════════════════════════════════════ -->
<div class="flex flex-1 overflow-hidden">

    <!-- LEFT: Field type palette -->
    <aside class="w-52 bg-white border-r border-slate-100 flex-shrink-0 overflow-y-auto">
        <div class="p-3 space-y-4">
            <div id="palette-input">
                <p class="px-2 py-1.5 text-xs font-bold text-slate-400 uppercase tracking-wider">Input Fields</p>
            </div>
            <div id="palette-choice">
                <p class="px-2 py-1.5 text-xs font-bold text-slate-400 uppercase tracking-wider">Choice Fields</p>
            </div>
            <div id="palette-layout">
                <p class="px-2 py-1.5 text-xs font-bold text-slate-400 uppercase tracking-wider">Layout</p>
            </div>
        </div>
    </aside>

    <!-- CENTER: Form canvas -->
    <main class="flex-1 overflow-y-auto bg-slate-100 px-6 py-6 select-text" id="canvas-wrap">
        <div class="max-w-2xl mx-auto space-y-3">

            <!-- Title + description card -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="h-2 bg-gradient-to-r from-indigo-500 to-purple-500"></div>
                <div class="p-5">
                    <input id="form-title" placeholder="Form Title" class="w-full text-2xl font-bold text-slate-800 placeholder-slate-300 border-b-2 border-transparent focus:border-indigo-300 focus:outline-none pb-1 bg-transparent" />
                    <input id="form-desc" placeholder="Form description (optional)" class="mt-3 w-full text-sm text-slate-500 placeholder-slate-300 border-b border-transparent focus:border-slate-200 focus:outline-none bg-transparent" />
                </div>
            </div>

            <!-- Sortable fields -->
            <div id="fields-list"></div>

            <!-- Empty state -->
            <div id="empty-state" class="bg-white rounded-xl shadow-sm py-20 text-center">
                <div class="text-5xl mb-4">📋</div>
                <p class="font-semibold text-slate-600">Your form is empty</p>
                <p class="text-sm text-slate-400 mt-1">Click a field type on the left to get started</p>
            </div>

        </div>
    </main>

    <!-- RIGHT: Config panel -->
    <aside id="cfg-panel" class="w-80 bg-white border-l border-slate-100 flex-shrink-0 overflow-hidden" style="display:none;flex-direction:column">
        <div class="flex items-center gap-2 px-3 py-2.5 border-b border-slate-100 bg-slate-50 flex-shrink-0">
            <span id="cfg-icon" class="text-base leading-none"></span>
            <span id="cfg-type" class="text-xs font-bold text-slate-600 uppercase tracking-wide flex-1"></span>
            <button id="cfg-close" type="button" class="p-1 rounded hover:bg-slate-200 text-slate-400 hover:text-slate-600 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="flex border-b border-slate-100 flex-shrink-0 bg-white">
            <button class="cfg-tab" data-tab="basic">Basic</button>
            <button class="cfg-tab" data-tab="options">Options</button>
            <button class="cfg-tab" data-tab="validation">Validation</button>
        </div>
        <div id="cfg-body" class="p-4 overflow-y-auto flex-1 space-y-3 text-sm select-text"></div>
    </aside>

</div>

<!-- ═══ JSON SCHEMA PANEL (collapsible) ═══════════════════════ -->
<div id="json-panel" class="flex-shrink-0 flex flex-col" style="display:none;height:260px">
    <div class="flex items-center gap-3 px-4 py-2 bg-slate-800 border-t border-slate-700 flex-shrink-0">
        <span class="text-xs text-slate-300 font-mono font-semibold">schema.json</span>
        <span class="text-xs text-slate-500">— edit below then apply, or canvas changes sync here automatically</span>
        <div class="flex-1"></div>
        <button id="btn-json-apply" type="button" class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded transition font-medium">↻ Apply JSON → Canvas</button>
        <button id="btn-json-close" type="button" class="text-xs text-slate-400 hover:text-slate-200 px-2 py-1 rounded hover:bg-slate-700 transition">✕</button>
    </div>
    <textarea id="json-editor" class="flex-1 w-full bg-slate-900 text-emerald-300 text-xs font-mono p-4 focus:outline-none resize-none leading-relaxed" spellcheck="false" placeholder="{}"></textarea>
</div>

<!-- Error/info toast -->
<div id="toast" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 transition-all duration-300 opacity-0 pointer-events-none">
    <div id="toast-msg" class="px-5 py-3 rounded-xl shadow-2xl text-sm font-medium text-white"></div>
</div>

<!-- AI processing overlay -->
<div id="ai-loader" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/45 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl border border-slate-100 p-6 text-center">
        <div class="ff-spinner mx-auto"></div>
        <p class="mt-4 text-sm font-semibold text-slate-800">AI is generating your form...</p>
        <p id="ai-loader-text" class="mt-1 text-xs text-slate-500">Waiting in queue...</p>
    </div>
</div>

<!-- AI prompt panel -->
<div id="ai-panel" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <div class="w-full max-w-xl rounded-2xl bg-white shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
            <h3 class="font-semibold text-slate-800 flex-1">Generate / Edit with AI</h3>
            <button id="btn-ai-close" type="button" class="text-slate-400 hover:text-slate-700">✕</button>
        </div>
        <div class="p-5 space-y-3">
            <p class="text-xs text-slate-500">Describe the form or edit request. Example: add an emergency contact section, make phone required, translate labels to Hindi.</p>
            <textarea id="ai-prompt" rows="5" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-emerald-400 focus:bg-white outline-none transition resize-none" placeholder="Internship application with education history, skills and resume upload"></textarea>
            <div class="flex justify-end gap-2">
                <button id="btn-ai-cancel" type="button" class="px-4 py-2 text-sm rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                <button id="btn-ai-run" type="button" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Run AI</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden POST form -->
<form id="submit-form" action="{{ !empty($editingForm) ? route('forms.update', ['form' => $editingForm->id]) : route('forms.store') }}" method="POST" class="hidden">
    @csrf
    @if(!empty($editingForm))
        @method('PUT')
    @endif
    <input type="hidden" name="title" id="sub-title">
    <input type="hidden" name="description" id="sub-desc">
    <input type="hidden" name="schema" id="sub-schema">
</form>

<script>
/* ================================================================
   FormForge AI — Form Builder
   ================================================================ */

const EDITING_FORM = @json($editingForm);
const INITIAL_AI_PROMPT = @json($initialAiPrompt ?? '');
const AUTO_AI = @json($autoAi ?? false);
const AI_GENERATE_URL = @json(route('ai.generate'));
const AI_STATUS_URL_TEMPLATE = @json(route('ai.status', ['log' => '__LOG__']));
const CSRF_TOKEN = @json(csrf_token());

// ── Field type registry ────────────────────────────────────────
const FT = {
    text:     { label:'Short Text',      icon:'✏️',  cat:'input'  },
    textarea: { label:'Paragraph',        icon:'📝',  cat:'input'  },
    number:   { label:'Number',           icon:'🔢',  cat:'input'  },
    email:    { label:'Email',            icon:'📧',  cat:'input'  },
    phone:    { label:'Phone / Tel',      icon:'📞',  cat:'input'  },
    date:     { label:'Date',             icon:'📅',  cat:'input'  },
    file:     { label:'File Upload',      icon:'📎',  cat:'input'  },
    rating:   { label:'Star Rating',      icon:'⭐',  cat:'input'  },
    dropdown: { label:'Dropdown',         icon:'🔽',  cat:'choice' },
    radio:    { label:'Multiple Choice',  icon:'🔘',  cat:'choice' },
    checkbox: { label:'Checkboxes',       icon:'☑️',  cat:'choice' },
    heading:  { label:'Section Heading',  icon:'📌',  cat:'layout' },
};

const CHOICE_TYPES = ['dropdown','radio','checkbox'];

// ── State ─────────────────────────────────────────────────────
const S = {
    fields: [],
    selId: null,
    tab: 'basic',
    aiPolling: null,
};

// ── Utils ──────────────────────────────────────────────────────
const uid = () => 'f' + Math.random().toString(36).slice(2,8);
const $ = id => document.getElementById(id);
const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const find = id => S.fields.find(f=>f.id===id);

function showToast(msg, ok=false) {
    const t = $('toast'), m = $('toast-msg');
    m.textContent = msg;
    m.className = 'px-5 py-3 rounded-xl shadow-2xl text-sm font-medium text-white ' + (ok ? 'bg-emerald-600' : 'bg-red-600');
    t.style.opacity = '1';
    t.style.pointerEvents = 'auto';
    clearTimeout(t._tid);
    t._tid = setTimeout(() => { t.style.opacity='0'; t.style.pointerEvents='none'; }, 3200);
}

function setAiStatus(text, color = 'slate') {
    const el = $('ai-status');
    if (!el) return;
    el.textContent = text;
    el.className = 'text-xs ' + (
        color === 'error'
            ? 'text-red-600'
            : color === 'ok'
                ? 'text-emerald-600'
                : color === 'warn'
                    ? 'text-amber-700'
                    : 'text-slate-500'
    );
}

function setAiBusy(isBusy, message = 'Waiting in queue...') {
    const overlay = $('ai-loader');
    const text = $('ai-loader-text');
    const btnAi = $('btn-ai');

    if (btnAi) {
        btnAi.disabled = !!isBusy;
        btnAi.classList.toggle('opacity-60', !!isBusy);
        btnAi.classList.toggle('cursor-not-allowed', !!isBusy);
    }

    if (!overlay || !text) return;

    if (isBusy) {
        text.textContent = message;
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    } else {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        text.textContent = 'Waiting in queue...';
    }
}

function shortErrorMessage(msg) {
    const text = String(msg || '').replace(/\s+/g, ' ').trim();
    if (text.length <= 180) return text;
    return text.slice(0, 180) + '...';
}

function openAiPanel(seedPrompt = '') {
    $('ai-panel').classList.remove('hidden');
    $('ai-panel').classList.add('flex');
    $('ai-prompt').value = seedPrompt || '';
    $('ai-prompt').focus();
}

function closeAiPanel() {
    $('ai-panel').classList.add('hidden');
    $('ai-panel').classList.remove('flex');
}

function aiStatusUrl(logId) {
    return AI_STATUS_URL_TEMPLATE.replace('__LOG__', String(logId));
}

function applySchemaToCanvas(parsed) {
    if (!Array.isArray(parsed.fields)) {
        showToast('AI response is missing fields array.');
        return;
    }

    if (parsed.title) {
        $('form-title').value = parsed.title;
        $('hdr-title').value = parsed.title;
    }
    if (parsed.description != null) {
        $('form-desc').value = parsed.description;
    }

    S.fields = parsed.fields.map(f => ({
        id: f.id || uid(),
        type: f.type || 'text',
        label: f.label ?? '',
        key: f.key ?? '',
        placeholder: f.placeholder ?? '',
        helpText: f.helpText ?? '',
        defaultValue: f.defaultValue ?? '',
        required: !!f.required,
        options: Array.isArray(f.options) ? f.options : [],
        maxRating: f.maxRating ?? 5,
        validation: f.validation ?? {minLength:'',maxLength:'',min:'',max:'',pattern:'',fileTypes:'',maxFileSize:''},
    }));
    S.selId = null;
    renderCanvas();
    renderConfig();
}

async function runAi(promptText) {
    const prompt = String(promptText || '').trim();
    if (!prompt) {
        showToast('Enter an AI prompt first.');
        return;
    }

    setAiStatus('Queueing AI job...');
    setAiBusy(true, 'Queueing AI job...');
    $('btn-ai-run').disabled = true;

    try {
        const resp = await fetch(AI_GENERATE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                prompt,
                form_id: EDITING_FORM?.id || null,
            }),
        });

        const payload = await resp.json();
        if (!resp.ok) {
            throw new Error(payload.message || 'AI queue request failed.');
        }

        const logId = payload.log_id;
        if (!logId) {
            throw new Error('Missing AI log ID.');
        }

        closeAiPanel();
        showToast('AI job queued. Generating schema...', true);
        setAiStatus('AI status: queued');
        setAiBusy(true, 'AI is queued. Waiting for worker...');
        startAiPolling(logId);
    } catch (err) {
        setAiBusy(false);
        setAiStatus('AI status: failed', 'error');
        showToast(err.message || 'AI request failed.');
    } finally {
        $('btn-ai-run').disabled = false;
    }
}

function startAiPolling(logId) {
    if (S.aiPolling) {
        clearInterval(S.aiPolling);
        S.aiPolling = null;
    }

    const poll = async () => {
        try {
            const resp = await fetch(aiStatusUrl(logId), { headers: { 'Accept': 'application/json' } });
            const payload = await resp.json();

            if (!resp.ok) {
                throw new Error(payload.message || 'Unable to fetch AI status.');
            }

            setAiStatus('AI status: ' + payload.status);

            if (payload.status === 'queued') {
                setAiBusy(true, 'AI is queued. Waiting for worker...');
            } else if (payload.status === 'processing') {
                setAiBusy(true, 'AI is processing your prompt...');
            }

            if (payload.status === 'completed') {
                clearInterval(S.aiPolling);
                S.aiPolling = null;
                setAiBusy(false);

                if (!payload.schema || !Array.isArray(payload.schema.fields)) {
                    setAiStatus('AI status: invalid schema', 'error');
                    showToast('AI finished but returned invalid schema.');
                    return;
                }

                applySchemaToCanvas(payload.schema);

                if (payload.fallback_used) {
                    const firstError = Array.isArray(payload.retry_errors) ? payload.retry_errors[0] : null;
                    setAiStatus('AI status: completed with fallback', 'warn');
                    showToast(firstError ? shortErrorMessage(firstError) : 'AI used fallback due to generation error.');
                } else {
                    setAiStatus('AI status: completed', 'ok');
                    showToast('AI schema applied to canvas.', true);
                }
            }

            if (payload.status === 'failed') {
                clearInterval(S.aiPolling);
                S.aiPolling = null;
                setAiBusy(false);
                setAiStatus('AI status: failed', 'error');
                const firstError = Array.isArray(payload.retry_errors) ? payload.retry_errors[0] : null;
                showToast(payload.error || firstError || 'AI generation failed.');
            }
        } catch (err) {
            clearInterval(S.aiPolling);
            S.aiPolling = null;
            setAiBusy(false);
            setAiStatus('AI status: failed', 'error');
            showToast(err.message || 'AI polling failed.');
        }
    };

    poll();
    S.aiPolling = setInterval(poll, 1500);
}

function makeField(type) {
    return {
        id: uid(), type,
        label: FT[type]?.label ?? 'Field',
        key: '',
        placeholder: '',
        helpText: '',
        defaultValue: '',
        required: false,
        options: CHOICE_TYPES.includes(type) ? ['Option 1','Option 2'] : [],
        maxRating: 5,
        validation: { minLength:'',maxLength:'',min:'',max:'',pattern:'',fileTypes:'',maxFileSize:'' },
    };
}

// ── Render palette ─────────────────────────────────────────────
function renderPalette() {
    const grps = {input:$('palette-input'), choice:$('palette-choice'), layout:$('palette-layout')};
    Object.entries(FT).forEach(([type,cfg])=>{
        const b = document.createElement('button');
        b.type = 'button';
        b.dataset.addType = type;
        b.className = 'type-btn w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs text-slate-700 text-left';
        b.innerHTML = `<span class="text-base leading-none">${cfg.icon}</span><span class="font-medium flex-1">${cfg.label}</span><span class="text-slate-300 text-xs">⠿</span>`;
        grps[cfg.cat]?.appendChild(b);
    });
}

// ── Palette drag-to-canvas ─────────────────────────────────────
function initPaletteSortable() {
    ['palette-input','palette-choice','palette-layout'].forEach(pid=>{
        const el = $(pid);
        if (!el) return;
        Sortable.create(el, {
            group:     { name: 'ff-fields', pull: 'clone', put: false },
            sort:      false,
            draggable: '.type-btn',
            animation: 100,
            ghostClass:'palette-ghost',
            onStart: () => $('canvas-wrap').classList.add('drag-active'),
            onEnd:   () => $('canvas-wrap').classList.remove('drag-active'),
        });
    });
}

// ── Card HTML ──────────────────────────────────────────────────
function cardHTML(f) {
    const cfg = FT[f.type] ?? {label:f.type,icon:'?'};
    const lbl = f.label || cfg.label;
    const keyBadge = f.key ? `<span class="ml-1 text-xs text-slate-400 font-mono bg-slate-100 px-1 rounded">${esc(f.key)}</span>` : '';
    const reqDot   = f.required ? `<span class="text-red-500 ml-0.5 font-bold">*</span>` : '';

    let preview = '';
    if (f.type==='text'||f.type==='email'||f.type==='phone'||f.type==='number'||f.type==='date')
        preview = `<div class="mt-2 h-7 rounded border border-slate-200 bg-slate-50 flex items-center px-2 text-xs text-slate-400">${esc(f.placeholder)||'Answer...'}</div>`;
    else if (f.type==='textarea')
        preview = `<div class="mt-2 h-12 rounded border border-slate-200 bg-slate-50 p-2 text-xs text-slate-400">${esc(f.placeholder)||'Long answer...'}</div>`;
    else if (f.type==='dropdown')
        preview = `<div class="mt-2 h-7 rounded border border-slate-200 bg-slate-50 flex items-center justify-between px-2 text-xs text-slate-400"><span>${esc(f.options[0]??'Select...')}</span><span>▾</span></div>`;
    else if (f.type==='radio')
        preview = `<div class="mt-2 space-y-1">${f.options.slice(0,3).map(o=>`<div class="flex items-center gap-1.5 text-xs text-slate-500"><div class="w-3 h-3 rounded-full border-2 border-slate-300 flex-shrink-0"></div>${esc(o)}</div>`).join('')}${f.options.length>3?'<div class="text-xs text-slate-400 pl-4">…</div>':''}</div>`;
    else if (f.type==='checkbox')
        preview = `<div class="mt-2 space-y-1">${f.options.slice(0,3).map(o=>`<div class="flex items-center gap-1.5 text-xs text-slate-500"><div class="w-3 h-3 rounded border border-slate-300 flex-shrink-0"></div>${esc(o)}</div>`).join('')}${f.options.length>3?'<div class="text-xs text-slate-400 pl-4">…</div>':''}</div>`;
    else if (f.type==='file')
        preview = `<div class="mt-2 h-7 rounded border border-dashed border-slate-300 bg-slate-50 flex items-center gap-2 px-2 text-xs text-slate-400">📎 Choose file…</div>`;
    else if (f.type==='rating')
        preview = `<div class="mt-2 flex gap-0.5 text-base">${'⭐'.repeat(Math.min(f.maxRating,5))}</div>`;
    else if (f.type==='heading')
        preview = `<div class="mt-1 text-sm font-bold text-slate-700 border-b border-slate-200 pb-1">${esc(lbl)}</div>`;

    return `
    <div class="field-card bg-white rounded-xl shadow-sm overflow-hidden group" data-id="${f.id}">
      <div class="flex items-start gap-2 px-3 py-3">
        <span class="drag-handle mt-0.5">⠿</span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center flex-wrap gap-0.5">
            <span class="text-sm leading-none mr-1">${cfg.icon}</span>
            <span class="text-sm font-semibold text-slate-800">${esc(lbl)}</span>${reqDot}${keyBadge}
          </div>
          ${preview}
          ${f.helpText?`<p class="mt-1 text-xs text-slate-400 italic">${esc(f.helpText)}</p>`:''}
        </div>
        <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition shrink-0">
          <button type="button" data-action="dup" data-id="${f.id}" title="Duplicate"
            class="p-1.5 rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition">
            <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
          </button>
          <button type="button" data-action="del" data-id="${f.id}" title="Delete"
            class="p-1.5 rounded-lg text-slate-300 hover:text-red-600 hover:bg-red-50 transition">
            <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
        </div>
      </div>
    </div>`;
}

// ── Render canvas ──────────────────────────────────────────────
let sortable = null;

function renderCanvas() {
    const list = $('fields-list');
    $('empty-state').style.display = S.fields.length ? 'none' : '';
    list.innerHTML = S.fields.map(cardHTML).join('');
    if (S.selId) {
        const c = list.querySelector(`[data-id="${S.selId}"]`);
        c?.classList.add('selected');
    }
    initSortable();
    syncJSON();
}

function updateCard(id) {
    const f = find(id);
    if (!f) return;
    const existing = $('fields-list').querySelector(`[data-id="${id}"]`);
    if (!existing) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = cardHTML(f);
    const newCard = tmp.firstElementChild;
    if (S.selId===id) newCard.classList.add('selected');
    existing.replaceWith(newCard);
    syncJSON();
}

function initSortable() {
    if (sortable) { sortable.destroy(); sortable=null; }
    const list = $('fields-list');
    if (!list) return;
    sortable = Sortable.create(list, {
        handle:     '.drag-handle',
        group:      { name: 'ff-fields', pull: false, put: true },
        animation:  150,
        ghostClass: 'sortable-ghost',
        chosenClass:'sortable-chosen',
        onAdd(evt) {
            // A palette type-btn was dragged and dropped onto the canvas
            const type = evt.item.dataset.addType;
            const idx  = evt.newDraggableIndex ?? S.fields.length;
            evt.item.remove(); // remove the SortableJS clone
            const f = makeField(type);
            S.fields.splice(idx, 0, f);
            $('canvas-wrap').classList.remove('drag-active');
            renderCanvas();
            openConfig(f.id);
        },
        onEnd() {
            // Reorder within canvas
            const order = [...list.querySelectorAll('.field-card')].map(c=>c.dataset.id);
            S.fields = order.map(id=>find(id)).filter(Boolean);
            syncJSON();
        },
    });
}

// ── Config panel ───────────────────────────────────────────────
function openConfig(id) {
    S.selId = id;
    S.tab = 'basic';
    document.querySelectorAll('.field-card').forEach(c=>c.classList.remove('selected'));
    $('fields-list').querySelector(`[data-id="${id}"]`)?.classList.add('selected');
    renderConfig();
}

function renderConfig() {
    const f = find(S.selId);
    const panel = $('cfg-panel');
    if (!f) { panel.style.display='none'; return; }
    panel.style.display = 'flex';

    const cfg = FT[f.type]??{label:f.type,icon:'?'};
    $('cfg-icon').textContent = cfg.icon;
    $('cfg-type').textContent  = cfg.label;

    document.querySelectorAll('.cfg-tab').forEach(t=>{
        const act = t.dataset.tab===S.tab;
        t.classList.toggle('active',act);
    });

    const body = $('cfg-body');
    if (S.tab==='basic')      body.innerHTML = tabBasic(f);
    else if (S.tab==='options') body.innerHTML = CHOICE_TYPES.includes(f.type) ? tabOptions(f) : '<p class="text-slate-400 text-center py-8 text-xs">No options for this field type.</p>';
    else if (S.tab==='validation') body.innerHTML = tabValidation(f);
}

// Input helpers
const cfgRow = (id,lbl,val,ph='',type='text') =>
    `<div><label class="block text-xs font-semibold text-slate-500 mb-1">${lbl}</label>
     <input type="${type}" id="c-${id}" value="${esc(val)}" placeholder="${esc(ph)}" class="cfg-input select-text" /></div>`;

const cfgToggle = (id,lbl,on) =>
    `<div class="flex items-center justify-between py-0.5">
       <label class="text-sm text-slate-700 cursor-pointer" for="c-${id}">${lbl}</label>
       <span class="toggle-track${on?' on':''}" data-toggle="${id}"><span class="toggle-thumb"></span></span>
     </div>`;

const cfgTextarea = (id,lbl,val,ph='') =>
    `<div><label class="block text-xs font-semibold text-slate-500 mb-1">${lbl}</label>
     <textarea id="c-${id}" placeholder="${esc(ph)}" rows="2" class="cfg-input resize-none select-text">${esc(val)}</textarea></div>`;

function tabBasic(f) {
    const isHead = f.type==='heading';
    return [
        cfgRow('label','Label *',f.label,'Field label'),
        !isHead ? cfgRow('key','Key (data ID)',f.key,'e.g. full_name') : '',
        !isHead ? cfgRow('placeholder','Placeholder',f.placeholder,'Hint text shown inside field') : '',
        cfgRow('helpText','Help text',f.helpText,'Shown below field'),
        !isHead ? cfgRow('defaultValue','Default value',f.defaultValue,'Pre-filled value') : '',
        f.type==='rating' ? cfgRow('maxRating','Max stars',f.maxRating,'5','number') : '',
        !isHead ? cfgToggle('required','Required field',f.required) : '',
    ].join('');
}

function tabOptions(f) {
    const rows = f.options.map((o,i)=>`
        <div class="flex items-center gap-2" data-opt-i="${i}">
            <span class="text-slate-300 text-xs cursor-grab">⠿</span>
            <input type="text" class="opt-inp cfg-input flex-1 select-text" data-opt-i="${i}" value="${esc(o)}" />
            <button type="button" class="opt-del shrink-0 text-slate-300 hover:text-red-500 transition text-xs px-1 py-1" data-opt-i="${i}">✕</button>
        </div>`).join('');
    return `<div><label class="block text-xs font-semibold text-slate-500 mb-2">Options (${f.options.length})</label>
        <div id="opts-list" class="space-y-2">${rows}</div>
        <button type="button" id="add-opt" class="mt-3 w-full py-2 rounded-lg border-2 border-dashed border-slate-200 text-xs text-slate-400 hover:border-indigo-300 hover:text-indigo-500 transition">+ Add option</button>
    </div>`;
}

function tabValidation(f) {
    const v = f.validation??{};
    const rows = [];
    if (['text','textarea','email','phone'].includes(f.type)) {
        rows.push(cfgRow('minLength','Min length',v.minLength,'e.g. 2','number'));
        rows.push(cfgRow('maxLength','Max length',v.maxLength,'e.g. 500','number'));
    }
    if (f.type==='number') {
        rows.push(cfgRow('min','Min value',v.min,'e.g. 0','number'));
        rows.push(cfgRow('max','Max value',v.max,'e.g. 9999','number'));
    }
    if (['text','textarea','phone','number'].includes(f.type))
        rows.push(cfgRow('pattern','Regex pattern',v.pattern,'e.g. ^[A-Za-z]+$'));
    if (f.type==='file') {
        rows.push(cfgRow('fileTypes','Allowed file types',v.fileTypes,'e.g. pdf,docx,jpg'));
        rows.push(cfgRow('maxFileSize','Max file size (MB)',v.maxFileSize,'e.g. 10','number'));
    }
    return rows.length
        ? `<div class="space-y-3">${rows.join('')}</div>`
        : '<p class="text-slate-400 text-center py-8 text-xs">No validation rules for this field type.</p>';
}

// ── JSON editor ─────────────────────────────────────────────────
function schema() {
    return {
        title: $('form-title').value.trim() || 'Untitled Form',
        description: $('form-desc').value.trim(),
        fields: S.fields.map(f=>({...f})),
    };
}

function syncJSON() {
    if ($('json-panel').style.display==='none') return;
    $('json-editor').value = JSON.stringify(schema(),null,2);
}

function applyJSON() {
    let parsed;
    try { parsed = JSON.parse($('json-editor').value); }
    catch(e) { showToast('Invalid JSON: '+e.message); return; }
    if (!Array.isArray(parsed.fields)) { showToast('"fields" must be an array.'); return; }
    if (parsed.title) { $('form-title').value=parsed.title; $('hdr-title').value=parsed.title; }
    if (parsed.description!=null) $('form-desc').value=parsed.description;
    S.fields = parsed.fields.map(f=>({
        id: f.id||uid(), type:f.type||'text', label:f.label??'',
        key:f.key??'', placeholder:f.placeholder??'', helpText:f.helpText??'',
        defaultValue:f.defaultValue??'', required:!!f.required,
        options:Array.isArray(f.options)?f.options:[], maxRating:f.maxRating??5,
        validation:f.validation??{minLength:'',maxLength:'',min:'',max:'',pattern:'',fileTypes:'',maxFileSize:''},
    }));
    S.selId = null;
    renderCanvas();
    renderConfig();
    showToast('Schema applied to canvas.', true);
}

// ── Event wiring ───────────────────────────────────────────────

// Palette: add field
['palette-input','palette-choice','palette-layout'].forEach(pid=>{
    $(pid)?.addEventListener('click',e=>{
        const btn = e.target.closest('[data-add-type]');
        if (!btn) return;
        const f = makeField(btn.dataset.addType);
        S.fields.push(f);
        renderCanvas();
        openConfig(f.id);
        setTimeout(()=>$('fields-list').querySelector(`[data-id="${f.id}"]`)?.scrollIntoView({behavior:'smooth',block:'nearest'}),60);
    });
});

// Canvas: select / dup / del
$('fields-list').addEventListener('click',e=>{
    const del = e.target.closest('[data-action="del"]');
    if (del) {
        const id = del.dataset.id;
        S.fields = S.fields.filter(f=>f.id!==id);
        if (S.selId===id) S.selId=null;
        renderCanvas(); renderConfig(); return;
    }
    const dup = e.target.closest('[data-action="dup"]');
    if (dup) {
        const id = dup.dataset.id;
        const orig = find(id);
        if (!orig) return;
        const copy = {...JSON.parse(JSON.stringify(orig)), id:uid()};
        const idx = S.fields.findIndex(f=>f.id===id);
        S.fields.splice(idx+1,0,copy);
        renderCanvas(); openConfig(copy.id); return;
    }
    const card = e.target.closest('.field-card');
    if (card) { openConfig(card.dataset.id); return; }
});

// Config close
$('cfg-close').addEventListener('click',()=>{ S.selId=null; document.querySelectorAll('.field-card').forEach(c=>c.classList.remove('selected')); renderConfig(); });

// Config tabs
document.querySelectorAll('.cfg-tab').forEach(btn=>btn.addEventListener('click',()=>{ S.tab=btn.dataset.tab; renderConfig(); }));

// Config body: text input changes
$('cfg-body').addEventListener('input',e=>{
    const f = find(S.selId);
    if (!f) return;
    const id = e.target.id;
    // Option inputs
    if (e.target.classList.contains('opt-inp')) {
        const i = parseInt(e.target.dataset.optI);
        if (!isNaN(i)) { f.options[i]=e.target.value; syncJSON(); }
        return;
    }
    if (!id.startsWith('c-')) return;
    const key = id.slice(2);
    const val = e.target.type==='number'?(e.target.value===''?'':Number(e.target.value)):e.target.value;
    const VKEYS = ['minLength','maxLength','min','max','pattern','fileTypes','maxFileSize'];
    if (VKEYS.includes(key)) f.validation[key]=val;
    else if (key==='maxRating') f.maxRating=parseInt(val)||5;
    else f[key]=val;
    updateCard(f.id);
    // Keep focus on the input by NOT calling renderConfig
    syncJSON();
});

// Config body: toggle clicks
$('cfg-body').addEventListener('click',e=>{
    const f = find(S.selId);
    if (!f) return;
    // Toggle
    const tr = e.target.closest('[data-toggle]');
    if (tr) {
        const key = tr.dataset.toggle;
        const val = !tr.classList.contains('on');
        f[key]=val;
        tr.classList.toggle('on',val);
        updateCard(f.id);
        syncJSON();
        return;
    }
    // Add option
    if (e.target.id==='add-opt') {
        f.options.push('Option '+(f.options.length+1));
        renderConfig(); syncJSON(); return;
    }
    // Delete option
    if (e.target.classList.contains('opt-del')) {
        f.options.splice(parseInt(e.target.dataset.optI),1);
        renderConfig(); syncJSON(); return;
    }
});

// JSON panel
$('btn-json').addEventListener('click',()=>{
    const p=$('json-panel');
    const vis = p.style.display==='none';
    p.style.display = vis?'flex':'none';
    if (vis) syncJSON();
});
$('btn-json-close').addEventListener('click',()=>$('json-panel').style.display='none');
$('btn-json-apply').addEventListener('click', applyJSON);

// Title sync
$('form-title').addEventListener('input',()=>{ $('hdr-title').value=$('form-title').value; syncJSON(); });
$('hdr-title').addEventListener('input',()=>{ $('form-title').value=$('hdr-title').value; syncJSON(); });
$('form-desc').addEventListener('input', syncJSON);

// AI panel
$('btn-ai').addEventListener('click', () => openAiPanel(INITIAL_AI_PROMPT));
$('btn-ai-close').addEventListener('click', closeAiPanel);
$('btn-ai-cancel').addEventListener('click', closeAiPanel);
$('btn-ai-run').addEventListener('click', () => runAi($('ai-prompt').value));
$('ai-panel').addEventListener('click', (e) => {
    if (e.target.id === 'ai-panel') {
        closeAiPanel();
    }
});

// Save
$('btn-save').addEventListener('click',()=>{
    const title = $('form-title').value.trim();
    if (!title) { showToast('Enter a form title first.'); $('form-title').focus(); return; }
    const real = S.fields.filter(f=>f.type!=='heading');
    if (!real.length) { showToast('Add at least one input field.'); return; }
    const keys = new Set();
    for (const f of S.fields) {
        if (f.type==='heading') continue;
        const k = (f.key||f.label||'').trim();
        if (!k) { showToast(`Field "${f.label||f.type}" needs a label or key.`); openConfig(f.id); return; }
        if (keys.has(k.toLowerCase())) { showToast(`Duplicate key: "${k}". Keys must be unique.`); openConfig(f.id); return; }
        keys.add(k.toLowerCase());
    }
    const s = schema();
    $('sub-title').value = title;
    $('sub-desc').value  = $('form-desc').value.trim();
    $('sub-schema').value = JSON.stringify(s);
    $('submit-form').submit();
});

// ── Boot ───────────────────────────────────────────────────────
renderPalette();
initPaletteSortable();

if (EDITING_FORM) {
    $('form-title').value = EDITING_FORM.title || '';
    $('hdr-title').value = EDITING_FORM.title || '';
    $('form-desc').value = EDITING_FORM.description || '';

    if (EDITING_FORM.schema && Array.isArray(EDITING_FORM.schema.fields)) {
        applySchemaToCanvas(EDITING_FORM.schema);
    }
}

renderCanvas();

if (INITIAL_AI_PROMPT && AUTO_AI) {
    openAiPanel(INITIAL_AI_PROMPT);
    runAi(INITIAL_AI_PROMPT);
}
</script>
</body>
</html>
