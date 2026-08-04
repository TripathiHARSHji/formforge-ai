<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $form->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .star-btn{cursor:pointer;font-size:1.75rem;color:#cbd5e1;transition:color .1s;line-height:1;background:none;border:none;padding:0}
        .star-btn.lit{color:#f59e0b}
    </style>
</head>
<body class="bg-[#f0ebf8] min-h-screen text-slate-800">
<div class="max-w-2xl mx-auto px-4 py-10 space-y-4">

<div><a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 text-sm text-indigo-600 font-medium hover:text-indigo-800">+ Create new form</a></div>

<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <div class="h-2 bg-gradient-to-r from-indigo-500 to-purple-500"></div>
    <div class="p-6">
        <h1 class="text-2xl font-bold">{{ $form->title }}</h1>
        @if($form->description)<p class="mt-2 text-slate-500">{{ $form->description }}</p>@endif
    </div>
</div>

@if(session('status'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-emerald-800 text-sm font-medium">&#10003; {{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
        <p class="text-red-700 font-semibold text-sm mb-2">Please fix:</p>
        <ul class="list-disc list-inside space-y-1 text-red-600 text-sm">
            @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul>
    </div>
@endif

@php
    $schemaFields  = $form->schema['fields'] ?? [];
    $hasSubmission = (bool)(($submissionState ?? [])['has_submission'] ?? false);
    $latestSub     = ($submissionState ?? [])['latest_submission'] ?? null;
@endphp

@if($hasSubmission)
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h2 class="text-lg font-bold text-slate-700 mb-1">Response submitted &#10003;</h2>
        <p class="text-sm text-slate-500 mb-4">Your latest response:</p>
        @php $answers = is_array($latestSub) ? ($latestSub['answers'] ?? []) : ($latestSub->answers ?? []); @endphp
        <dl class="space-y-3">
            @foreach($schemaFields as $sf)
                @if(($sf['type'] ?? '') === 'heading') @continue @endif
                @php $k=$sf['key']??$sf['id']??''; $lbl=$sf['label']??$k; $ans=$answers[$k]??null; @endphp
                <div class="border-b border-slate-100 pb-3">
                    <dt class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-0.5">{{ $k }}@if($lbl && $lbl !== $k) <span class="text-slate-400 font-normal">({{ $lbl }})</span>@endif</dt>
                    <dd class="text-sm text-slate-800">{{ is_array($ans) ? implode(', ', $ans) : ($ans ?? '—') }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@else
    @php $hasFile = collect($schemaFields)->contains(fn($f)=>($f['type']??'')==='file'); @endphp
    <form action="{{ route('forms.submit', ['publicUuid'=>$form->public_uuid]) }}" method="POST" @if($hasFile) enctype="multipart/form-data" @endif class="bg-white rounded-xl shadow-sm p-6 space-y-6">
        @csrf
        @foreach($schemaFields as $field)
            @php
                $type  = $field['type'] ?? 'text';
                $key   = $field['key']  ?? $field['id'] ?? '';
                $label = $field['label'] ?? $key;
                $ph    = $field['placeholder'] ?? '';
                $help  = $field['helpText'] ?? '';
                $req   = !empty($field['required']);
                $opts  = $field['options'] ?? [];
                $def   = $field['defaultValue'] ?? '';
                $old   = old("answers.$key", $def);
                $ic    = 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-indigo-400 focus:bg-white focus:outline-none transition';
            @endphp
            @if($type==='heading')
                <div class="pt-2 border-t border-slate-100">
                    <h3 class="text-base font-bold text-slate-700">{{ $label }}</h3>
                    @if($help)<p class="text-xs text-slate-400 mt-0.5">{{ $help }}</p>@endif
                </div>
            @else
                <div>
                    <label for="field-{{ $key }}" class="block text-sm font-semibold text-slate-700 mb-1">
                        {{ $key }}@if($req)<span class="text-red-500 ml-0.5">*</span>@endif
                    </label>
                    @if($label && $label !== $key)<p class="text-xs text-indigo-500 italic mb-1.5">{{ $label }}</p>@endif

                    @if($type==='textarea')
                        <textarea id="field-{{ $key }}" name="answers[{{ $key }}]" rows="4" placeholder="{{ $ph ?: $label }}" @if($req)required @endif class="{{ $ic }} resize-y">{{ $old }}</textarea>
                    @elseif($type==='dropdown')
                        <select id="field-{{ $key }}" name="answers[{{ $key }}]" @if($req)required @endif class="{{ $ic }}">
                            <option value="">— Select —</option>
                            @foreach($opts as $o)<option value="{{ $o }}" @selected($old==$o)>{{ $o }}</option>@endforeach
                        </select>
                    @elseif($type==='radio')
                        <div class="space-y-2 mt-1">
                            @foreach($opts as $o)
                                <label class="flex items-center gap-2 cursor-pointer text-sm text-slate-700">
                                    <input type="radio" name="answers[{{ $key }}]" value="{{ $o }}" @checked($old==$o) @if($req)required @endif class="shrink-0" />{{ $o }}
                                </label>
                            @endforeach
                        </div>
                    @elseif($type==='checkbox')
                        <div class="space-y-2 mt-1">
                            @foreach($opts as $o)
                                <label class="flex items-center gap-2 cursor-pointer text-sm text-slate-700">
                                    <input type="checkbox" name="answers[{{ $key }}][]" value="{{ $o }}" @checked(is_array($old)&&in_array($o,$old)) class="rounded shrink-0" />{{ $o }}
                                </label>
                            @endforeach
                        </div>
                    @elseif($type==='file')
                        <input id="field-{{ $key }}" type="file" name="answers[{{ $key }}]" @if($req)required @endif
                            @if(!empty($field['validation']['fileTypes'])) accept="{{ collect(explode(',', $field['validation']['fileTypes']))->map(fn($t)=>'.'.trim($t))->implode(',') }}" @endif
                            class="w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                    @elseif($type==='rating')
                        @php $maxR = intval($field['maxRating'] ?? 5) ?: 5; @endphp
                        <div class="flex gap-1 mt-1" id="stars-{{ $key }}">
                            @for($r=1; $r<=$maxR; $r++)
                                <button type="button" class="star-btn{{ (int)$old>=$r?' lit':'' }}" data-stars="{{ $key }}" data-val="{{ $r }}">&#9733;</button>
                            @endfor
                        </div>
                        <input type="hidden" id="rating-{{ $key }}" name="answers[{{ $key }}]" value="{{ $old }}" />
                    @else
                        <input id="field-{{ $key }}" type="{{ $type==='phone'?'tel':$type }}" name="answers[{{ $key }}]"
                            value="{{ $old }}" placeholder="{{ $ph ?: $label }}" @if($req)required @endif class="{{ $ic }}" />
                    @endif

                    @if($help)<p class="text-xs text-slate-400 mt-1">{{ $help }}</p>@endif
                    @error("answers.$key")<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            @endif
        @endforeach
        <div class="pt-2">
            <button type="submit" class="rounded-full bg-indigo-600 hover:bg-indigo-700 px-8 py-2.5 text-sm font-semibold text-white transition shadow-sm">Submit</button>
        </div>
    </form>
@endif

</div>
<script>
document.querySelectorAll('[data-stars]').forEach(btn=>{
    btn.addEventListener('click',()=>{
        const k=btn.dataset.stars,v=parseInt(btn.dataset.val);
        document.getElementById('rating-'+k).value=v;
        document.querySelectorAll(`[data-stars="${k}"]`).forEach(s=>s.classList.toggle('lit',parseInt(s.dataset.val)<=v));
    });
    btn.addEventListener('mouseenter',()=>{
        const k=btn.dataset.stars,v=parseInt(btn.dataset.val);
        document.querySelectorAll(`[data-stars="${k}"]`).forEach(s=>s.classList.toggle('lit',parseInt(s.dataset.val)<=v));
    });
});
document.querySelectorAll('[id^="stars-"]').forEach(wrap=>{
    wrap.addEventListener('mouseleave',()=>{
        const k=wrap.id.slice(6),cur=parseInt(document.getElementById('rating-'+k)?.value||0);
        wrap.querySelectorAll('.star-btn').forEach(s=>s.classList.toggle('lit',parseInt(s.dataset.val)<=cur));
    });
});
</script>
</body>
</html>