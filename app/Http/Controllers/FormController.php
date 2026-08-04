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

    public function show($formIdentifier)
    {
        $form = $this->resolveForm($formIdentifier);

        if (! $form) {
            abort(404);
        }

        return view('forms.show', compact('form'));
    }

    public function fill(string $publicUuid)
    {
        $form = $this->resolveForm($publicUuid);

        if (! $form) {
            abort(404);
        }

        return view('forms.fill', compact('form'));
    }

    public function submit(Request $request, string $publicUuid)
    {
        $form = $this->resolveForm($publicUuid);

        if (! $form) {
            abort(404);
        }

        $schema = $form->schema;
        $answers = $request->input('answers', []);

        foreach ($schema['fields'] ?? [] as $field) {
            $key = $field['key'] ?? null;
            if (! $key) {
                continue;
            }
            if (($field['required'] ?? false) && empty($answers[$key] ?? null)) {
                return back()->withErrors(["answers.$key" => 'This field is required.']);
            }
        }

        if ($this->databaseIsAvailable()) {
            try {
                FormSubmission::create([
                    'form_id' => $form->id,
                    'answers' => $answers,
                ]);
            } catch (Throwable) {
                $this->persistSubmissionFallback($form, $answers);
            }
        } else {
            $this->persistSubmissionFallback($form, $answers);
        }

        return redirect()->back()->with('status', 'Submission received.');
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
            $schema['fields'][$index]['id'] = $field['id'] ?? 'field_' . ($index + 1);
            $schema['fields'][$index]['key'] = $field['key'] ?? $field['id'] ?? 'field_' . ($index + 1);
            $schema['fields'][$index]['required'] = (bool) ($field['required'] ?? false);
        }

        return $schema;
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
