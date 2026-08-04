<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FormController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 12;

        if ($this->databaseIsAvailable()) {
            try {
                $query = Form::query()->withCount('submissions')->orderByDesc('created_at');
                if ($search !== '') {
                    $query->where('title', 'like', '%' . $search . '%');
                }

                $forms = $query->paginate($perPage)->withQueryString();

                return view('forms.index', compact('forms', 'search'));
            } catch (Throwable) {
                // Fall through to file-backed listing.
            }
        }

        $store = $this->loadStore();
        $rows = collect($store['forms'] ?? [])->map(function (array $form) use ($store): array {
            $submissionCount = count(array_filter(
                $store['submissions'] ?? [],
                fn (array $submission) => (int) ($submission['form_id'] ?? 0) === (int) ($form['id'] ?? 0)
            ));

            $form['submissions_count'] = $submissionCount;

            return $form;
        });

        if ($search !== '') {
            $rows = $rows->filter(fn (array $form) => str_contains(strtolower((string) ($form['title'] ?? '')), strtolower($search)));
        }

        /** @var Collection<int, array> $rows */
        $rows = $rows->sortByDesc('created_at')->values();
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values();

        $forms = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('forms.index', compact('forms', 'search'));
    }

    public function create(Request $request)
    {
        return view('forms.create', [
            'editingForm' => null,
            'initialAiPrompt' => (string) $request->query('ai_prompt', ''),
            'autoAi' => $request->boolean('auto_ai', false),
        ]);
    }

    public function edit($formIdentifier, Request $request)
    {
        $form = $this->resolveForm($formIdentifier);
        if (! $form) {
            abort(404);
        }

        return view('forms.create', [
            'editingForm' => $form,
            'initialAiPrompt' => (string) $request->query('ai_prompt', ''),
            'autoAi' => $request->boolean('auto_ai', false),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'schema' => 'required',
        ]);

        $schema = $this->decodeSchema($data['schema']);
        $normalizedSchema = $this->normalizeSchema($schema);

        $form = $this->databaseIsAvailable()
            ? $this->persistWithDatabase($data, $normalizedSchema)
            : $this->persistWithFallback($data, $normalizedSchema);

        return redirect()->route('forms.show', ['form' => $form->id])->with('status', 'Form created.');
    }

    public function update(Request $request, $formIdentifier)
    {
        $form = $this->resolveForm($formIdentifier);
        if (! $form) {
            abort(404);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'schema' => 'required',
        ]);

        $schema = $this->decodeSchema($data['schema']);
        $normalizedSchema = $this->normalizeSchema($schema);

        if ($this->databaseIsAvailable()) {
            try {
                $dbForm = Form::find($form->id);
                if ($dbForm) {
                    FormVersion::create([
                        'form_id' => $dbForm->id,
                        'schema' => $dbForm->schema ?? ['fields' => []],
                        'note' => 'Manual edit',
                    ]);

                    $dbForm->update([
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'schema' => $normalizedSchema,
                    ]);

                    return redirect()->route('forms.show', ['form' => $dbForm->id])->with('status', 'Form updated.');
                }
            } catch (Throwable) {
                // Fall through to file-backed update.
            }
        }

        $updatedForm = $this->updateFallbackForm((int) $form->id, $data, $normalizedSchema);

        return redirect()->route('forms.show', ['form' => $updatedForm->id])->with('status', 'Form updated.');
    }

    public function show($formIdentifier, Request $request)
    {
        $form = $this->resolveForm($formIdentifier);

        if (! $form) {
            abort(404);
        }

        $search      = $request->input('search', '');
        $currentPage = max(1, (int) $request->input('page', 1));
        $perPage     = 20;

        [$submissions, $totalSubmissions] = $this->loadPagedSubmissions($form, $search, $currentPage, $perPage);
        $totalPages = max(1, (int) ceil($totalSubmissions / $perPage));

        return view('forms.show', compact('form', 'submissions', 'totalSubmissions', 'currentPage', 'totalPages', 'search'));
    }

    public function fill(string $publicUuid)
    {
        $form = $this->resolveForm($publicUuid);

        if (! $form) {
            abort(404);
        }

        $submissionState = $this->getSubmissionState($form);

        return view('forms.fill', compact('form', 'submissionState'));
    }

    public function submit(Request $request, string $publicUuid)
    {
        $form = $this->resolveForm($publicUuid);

        if (! $form) {
            abort(404);
        }

        $rules    = [];
        $messages = [];

        foreach ($form->schema['fields'] ?? [] as $field) {
            $type = $field['type'] ?? 'text';
            $key  = $field['key'] ?? null;
            if (! $key || $type === 'heading') {
                continue;
            }

            $v        = $field['validation'] ?? [];
            $required = ! empty($field['required']);
            $inputKey = "answers.{$key}";

            $fieldRules = [$required ? 'required' : 'nullable'];

            switch ($type) {
                case 'email':
                    $fieldRules[] = 'email';
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    if (isset($v['min']) && $v['min'] !== '') {
                        $fieldRules[] = 'min:' . $v['min'];
                    }
                    if (isset($v['max']) && $v['max'] !== '') {
                        $fieldRules[] = 'max:' . $v['max'];
                    }
                    break;
                case 'file':
                    $fieldRules = [$required ? 'required' : 'nullable', 'file'];
                    if (! empty($v['fileTypes'])) {
                        $fieldRules[] = 'mimes:' . preg_replace('/\s+/', '', $v['fileTypes']);
                    }
                    if (! empty($v['maxFileSize'])) {
                        $fieldRules[] = 'max:' . ((int) $v['maxFileSize'] * 1024);
                    }
                    $inputKey = "answers_files.{$key}";
                    break;
                case 'checkbox':
                    $fieldRules[] = 'array';
                    break;
                case 'url':
                    $fieldRules[] = 'url';
                    break;
            }

            if (! empty($v['minLength']) && ! in_array($type, ['number', 'file'])) {
                $fieldRules[] = 'min:' . $v['minLength'];
            }
            if (! empty($v['maxLength']) && ! in_array($type, ['number', 'file'])) {
                $fieldRules[] = 'max:' . $v['maxLength'];
            }
            if (! empty($v['pattern']) && ! in_array($type, ['file'])) {
                $fieldRules[] = 'regex:/' . str_replace('/', '\/', $v['pattern']) . '/';
            }

            $rules[$inputKey] = implode('|', $fieldRules);
            $messages["{$inputKey}.required"]  = ($field['label'] ?? $key) . ' is required.';
            $messages["{$inputKey}.email"]     = ($field['label'] ?? $key) . ' must be a valid email address.';
            $messages["{$inputKey}.numeric"]   = ($field['label'] ?? $key) . ' must be a number.';
            $messages["{$inputKey}.url"]       = ($field['label'] ?? $key) . ' must be a valid URL.';
        }

        // Merge file inputs into answers for validation
        $mergedInput = $request->all();
        if ($request->hasFile('answers')) {
            $mergedInput['answers_files'] = $request->file('answers');
        }
        $validator = validator($mergedInput, $rules, $messages);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $answers = $request->input('answers', []);
        // Store uploaded file paths
        foreach ($form->schema['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'file') {
                $key = $field['key'] ?? null;
                if ($key && $request->hasFile("answers.{$key}")) {
                    try {
                        $path = $request->file("answers.{$key}")->store('submissions', 'public');
                        $answers[$key] = $path;
                    } catch (Throwable) {
                        $answers[$key] = $request->file("answers.{$key}")->getClientOriginalName();
                    }
                }
            }
        }

        if ($this->databaseIsAvailable()) {
            try {
                FormSubmission::create(['form_id' => $form->id, 'answers' => $answers]);
            } catch (Throwable) {
                $this->persistSubmissionFallback($form, $answers);
            }
        } else {
            $this->persistSubmissionFallback($form, $answers);
        }

        return redirect()->route('forms.fill', ['publicUuid' => $form->public_uuid])->with('status', 'Submission received.');
    }

    public function export($formIdentifier, Request $request)
    {
        $form = $this->resolveForm($formIdentifier);

        if (! $form) {
            abort(404);
        }

        $rows = $this->databaseIsAvailable()
            ? FormSubmission::where('form_id', $form->id)->get()
            : $this->loadFallbackSubmissions($form->id);

        $fields = $form->schema['fields'] ?? [];

        if (strtolower((string) $request->query('format', 'json')) !== 'csv') {
            $submissions = [];
            foreach ($rows as $index => $row) {
                $rowId = is_array($row) ? ($row['id'] ?? ($index + 1)) : ($row->id ?? ($index + 1));
                $createdAt = is_array($row)
                    ? ($row['created_at'] ?? null)
                    : ($row->created_at ? $row->created_at->toIso8601String() : null);
                $answers = is_array($row) ? ($row['answers'] ?? []) : ($row->answers ?? []);

                $submissions[] = [
                    'id' => $rowId,
                    'submitted_at' => $createdAt,
                    'answers' => $answers,
                ];
            }

            $payload = [
                'form' => [
                    'id' => $form->id,
                    'title' => $form->title,
                    'description' => $form->description,
                    'public_uuid' => $form->public_uuid,
                    'status' => $form->status,
                ],
                'schema' => $form->schema,
                'fields' => $fields,
                'submissions_count' => count($submissions),
                'submissions' => $submissions,
            ];

            return response()->json(
                $payload,
                200,
                ['Content-Disposition' => 'attachment; filename="' . Str::slug($form->title) . '-submissions.json"'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $csv = fopen('php://temp', 'r+');
        $headers = ['id', 'submitted_at'];
        foreach ($fields as $field) {
            $headers[] = $field['key'] ?? $field['id'];
        }
        fputcsv($csv, $headers);
        foreach ($rows as $index => $row) {
            $rowId = is_array($row) ? ($row['id'] ?? ($index + 1)) : ($row->id ?? ($index + 1));
            $createdAt = is_array($row)
                ? ($row['created_at'] ?? '')
                : ($row->created_at ? $row->created_at->toDateTimeString() : '');
            $answers = is_array($row) ? ($row['answers'] ?? []) : ($row->answers ?? []);

            $values = [$rowId, $createdAt];
            foreach ($fields as $field) {
                $key = $field['key'] ?? $field['id'];
                $values[] = $answers[$key] ?? '';
            }
            fputcsv($csv, $values);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . Str::slug($form->title) . '-submissions.csv"',
        ]);
    }

    private function persistWithDatabase(array $data, array $schema): object
    {
        try {
            $form = Form::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'schema' => $schema,
                'public_uuid' => (string) Str::uuid(),
                'status' => 'draft',
            ]);

            return $this->normalizeStoredForm($form);
        } catch (Throwable) {
            return $this->persistWithFallback($data, $schema);
        }
    }

    private function persistWithFallback(array $data, array $schema): object
    {
        $store = $this->loadStore();
        $formId = $this->nextFormId($store);
        $form = (object) [
            'id' => $formId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'schema' => $schema,
            'public_uuid' => (string) Str::uuid(),
            'status' => 'draft',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $store['forms'][] = (array) $form;
        $this->saveStore($store);

        return $form;
    }

    private function persistSubmissionFallback(object $form, array $answers): void
    {
        $store = $this->loadStore();
        $store['submissions'][] = [
            'id' => $this->nextSubmissionId($store),
            'form_id' => $form->id,
            'answers' => $answers,
            'created_at' => now()->toIso8601String(),
        ];
        $this->saveStore($store);
    }

    private function updateFallbackForm(int $formId, array $data, array $schema): object
    {
        $store = $this->loadStore();

        foreach ($store['forms'] as $index => $form) {
            if ((int) ($form['id'] ?? 0) !== $formId) {
                continue;
            }

            $store['forms'][$index]['title'] = $data['title'];
            $store['forms'][$index]['description'] = $data['description'] ?? null;
            $store['forms'][$index]['schema'] = $schema;
            $store['forms'][$index]['updated_at'] = now()->toIso8601String();

            $this->saveStore($store);

            return (object) $store['forms'][$index];
        }

        abort(404);
    }

    private function getSubmissionState(object $form): array
    {
        $submissions = $this->loadSubmissionsForForm($form);
        $latestSubmission = $submissions[0] ?? null;

        return [
            'has_submission' => ! empty($submissions),
            'submission_count' => count($submissions),
            'latest_submission' => $latestSubmission,
        ];
    }

    private function loadSubmissionsForForm(object $form): array
    {
        if ($this->databaseIsAvailable()) {
            try {
                $rows = FormSubmission::where('form_id', $form->id)
                    ->orderByDesc('created_at')
                    ->get();

                if ($rows->isNotEmpty()) {
                    return $rows->all();
                }
            } catch (Throwable) {
                // Fall through to the file-backed store.
            }
        }

        return $this->loadFallbackSubmissions($form->id);
    }

    private function loadPagedSubmissions(object $form, string $search, int $page, int $perPage): array
    {
        if ($this->databaseIsAvailable()) {
            try {
                $query = FormSubmission::where('form_id', $form->id)->orderByDesc('created_at');
                if ($search !== '') {
                    $query->whereRaw('LOWER(CAST(answers AS CHAR)) LIKE ?', ['%' . strtolower($search) . '%']);
                }
                $total = (clone $query)->count();
                $rows  = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

                return [$rows, $total];
            } catch (Throwable) {
                // Fall through.
            }
        }

        // File fallback
        $all = $this->loadFallbackSubmissions($form->id);
        if ($search !== '') {
            $all = array_values(array_filter($all, fn ($s) => str_contains(strtolower(json_encode($s['answers'] ?? [])), strtolower($search))));
        }
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return [$slice, $total];
    }

    private function resolveForm(mixed $identifier): ?object
    {
        if ($identifier instanceof Form) {
            return $this->normalizeStoredForm($identifier);
        }

        if ($identifier === null) {
            return null;
        }

        if ($this->databaseIsAvailable()) {
            try {
                $form = is_numeric($identifier)
                    ? Form::find($identifier)
                    : Form::where('public_uuid', $identifier)->first();

                if ($form) {
                    return $this->normalizeStoredForm($form);
                }
            } catch (Throwable) {
                // Fall through to the file-backed store.
            }
        }

        $store = $this->loadStore();

        foreach ($store['forms'] ?? [] as $formData) {
            if ((string) ($formData['id'] ?? '') === (string) $identifier || (string) ($formData['public_uuid'] ?? '') === (string) $identifier) {
                return (object) $formData;
            }
        }

        return null;
    }

    private function decodeSchema(mixed $schema): array
    {
        if (is_array($schema)) {
            return $schema;
        }

        if (is_string($schema)) {
            $decoded = json_decode($schema, true);

            return is_array($decoded) ? $decoded : ['title' => 'Untitled form', 'fields' => []];
        }

        return ['title' => 'Untitled form', 'fields' => []];
    }

    private function normalizeSchema(array $schema): array
    {
        $schema['fields'] = $schema['fields'] ?? [];
        $supportedTypes = ['text','textarea','number','email','phone','date','file','rating','dropdown','radio','checkbox','heading','url'];
        $typeAliases = [
            'tel' => 'phone',
            'telephone' => 'phone',
            'select' => 'dropdown',
            'short_text' => 'text',
            'long_text' => 'textarea',
            'multiple_choice' => 'radio',
            'multi_select' => 'checkbox',
            'attachment' => 'file',
            'section' => 'heading',
        ];
        $seenKeys = [];

        foreach ($schema['fields'] as $index => $field) {
            $rawType = strtolower(trim((string) ($field['type'] ?? 'text')));
            $type = $typeAliases[$rawType] ?? $rawType;
            if (! in_array($type, $supportedTypes, true)) {
                $type = 'text';
            }

            $key = '';
            if ($type !== 'heading') {
                $key = $this->normalizeFieldKey(
                    (string) ($field['key'] ?? $field['id'] ?? $field['label'] ?? ''),
                    $index + 1
                );

                $base = $key;
                $n = 2;
                while (isset($seenKeys[strtolower($key)])) {
                    $key = $base . '_' . $n;
                    $n++;
                }
                $seenKeys[strtolower($key)] = true;
            }

            $schema['fields'][$index]['id']       = $field['id'] ?? ('field_' . ($index + 1));
            if ($type !== 'heading') {
                $schema['fields'][$index]['key'] = $key;
            } else {
                unset($schema['fields'][$index]['key']);
            }
            $schema['fields'][$index]['type']     = $type;
            $schema['fields'][$index]['label']    = $field['label'] ?? $field['lable'] ?? $key;
            $schema['fields'][$index]['required'] = $type === 'heading' ? false : (bool) ($field['required'] ?? false);
            // Preserve optional properties verbatim
            foreach (['placeholder','helpText','defaultValue','options','maxRating','validation'] as $prop) {
                if (array_key_exists($prop, $field)) {
                    $schema['fields'][$index][$prop] = $field[$prop];
                }
            }
        }

        return $schema;
    }

    private function normalizeFieldKey(string $value, int $index): string
    {
        $key = Str::of($value)
            ->lower()
            ->replace(['-', ' '], '_')
            ->slug('_')
            ->value();

        return $key !== '' ? $key : 'field_' . $index;
    }

    private function normalizeStoredForm(mixed $form): object
    {
        if ($form instanceof Form) {
            return (object) [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'schema' => $form->schema ?? ['fields' => []],
                'public_uuid' => $form->public_uuid,
                'status' => $form->status,
                'created_at' => $form->created_at,
                'updated_at' => $form->updated_at,
            ];
        }

        return (object) $form;
    }

    private function databaseIsAvailable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function loadStore(): array
    {
        $path = $this->storagePath();
        if (! file_exists($path)) {
            return ['forms' => [], 'submissions' => []];
        }

        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return ['forms' => [], 'submissions' => []];
        }

        return [
            'forms' => $decoded['forms'] ?? [],
            'submissions' => $decoded['submissions'] ?? [],
        ];
    }

    private function saveStore(array $store): void
    {
        $path = $this->storagePath();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function loadFallbackSubmissions(int $formId): array
    {
        $store = $this->loadStore();

        return array_values(array_filter($store['submissions'] ?? [], fn (array $submission) => (int) ($submission['form_id'] ?? 0) === $formId));
    }

    private function nextFormId(array $store): int
    {
        $maxId = 0;
        foreach ($store['forms'] ?? [] as $form) {
            $maxId = max($maxId, (int) ($form['id'] ?? 0));
        }

        return $maxId + 1;
    }

    private function nextSubmissionId(array $store): int
    {
        $maxId = 0;
        foreach ($store['submissions'] ?? [] as $submission) {
            $maxId = max($maxId, (int) ($submission['id'] ?? 0));
        }

        return $maxId + 1;
    }

    private function storagePath(): string
    {
        return storage_path('app/formforge-data.json');
    }
}
