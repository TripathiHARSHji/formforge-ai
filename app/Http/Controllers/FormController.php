<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FormController extends Controller
{
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

    public function export($formIdentifier)
    {
        $form = $this->resolveForm($formIdentifier);

        if (! $form) {
            abort(404);
        }

        $rows = $this->databaseIsAvailable()
            ? FormSubmission::where('form_id', $form->id)->get()
            : $this->loadFallbackSubmissions($form->id);

        $csv = fopen('php://temp', 'r+');
        $headers = ['id', 'submitted_at'];
        foreach ($form->schema['fields'] ?? [] as $field) {
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
            foreach ($form->schema['fields'] ?? [] as $field) {
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
        foreach ($schema['fields'] as $index => $field) {
            $key = $this->normalizeFieldKey(
                (string) ($field['key'] ?? $field['id'] ?? ''),
                $index + 1
            );

            $schema['fields'][$index]['id']       = $field['id'] ?? ('field_' . ($index + 1));
            $schema['fields'][$index]['key']      = $key;
            $schema['fields'][$index]['type']     = $field['type'] ?? 'text';
            $schema['fields'][$index]['label']    = $field['label'] ?? $field['lable'] ?? $key;
            $schema['fields'][$index]['required'] = (bool) ($field['required'] ?? false);
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
        $key = trim($value);

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
