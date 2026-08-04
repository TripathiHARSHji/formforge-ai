<?php

namespace App\Http\Controllers;

use App\Jobs\ParseImportFileJob;
use App\Models\Form;
use App\Models\FormImport;
use App\Services\DocumentFormImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportController extends Controller
{
    private const QUEUE_THRESHOLD_BYTES = 1500000;

    public function __construct(private readonly DocumentFormImportService $importService)
    {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:12288',
        ]);

        $file = $validated['file'];
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['docx', 'xlsx'], true)) {
            return back()->withErrors([
                'file' => 'Please upload a .docx or .xlsx file.',
            ])->withInput();
        }

        $path = $file->store('imports', 'local');
        $type = $extension === 'docx' ? 'word' : 'excel';
        $size = (int) $file->getSize();

        try {
            $import = FormImport::create([
                'source_type' => $type,
                'file_path' => $path,
                'status' => $size >= self::QUEUE_THRESHOLD_BYTES ? 'queued' : 'processing',
                'summary' => null,
                'metadata' => [
                    'original_name' => $file->getClientOriginalName(),
                    'size_bytes' => $size,
                    'queued_by_size' => $size >= self::QUEUE_THRESHOLD_BYTES,
                    'uploaded_at' => now()->toIso8601String(),
                    'file_disk' => 'local',
                ],
            ]);
        } catch (Throwable $e) {
            return back()->withErrors([
                'file' => 'Import setup failed: ' . $e->getMessage(),
            ])->withInput();
        }

        if ($size >= self::QUEUE_THRESHOLD_BYTES) {
            ParseImportFileJob::dispatch($import->id);

            return redirect()->route('imports.status.page', ['import' => $import->id])
                ->with('status', 'Upload received. Parsing is queued for this large file.');
        }

        try {
            $absolutePath = $this->resolveImportFilePath($import);
            if (! is_file($absolutePath)) {
                throw new RuntimeException('Uploaded file could not be found on disk for parsing.');
            }

            $result = $this->importService->parseAndRefine($absolutePath, $import->source_type);
            $this->markImportParsed($import, $result);
        } catch (Throwable $e) {
            $import->update([
                'status' => 'failed',
                'metadata' => array_merge($import->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            return redirect()->route('imports.status.page', ['import' => $import->id])
                ->with('status', 'Import failed to parse. Open details to review unparseable content.');
        }

        return redirect()->route('imports.preview', ['import' => $import->id])
            ->with('status', 'Import parsed. Review and map fields before creating the form.');
    }

    public function statusPage(FormImport $import)
    {
        return view('imports.status', ['import' => $import]);
    }

    public function status(FormImport $import)
    {
        $metadata = $import->metadata ?? [];

        return response()->json([
            'id' => $import->id,
            'status' => $import->status,
            'summary' => $import->summary,
            'error' => $metadata['error'] ?? null,
            'preview_url' => $import->status === 'parsed'
                ? route('imports.preview', ['import' => $import->id])
                : null,
            'updated_at' => optional($import->updated_at)->toIso8601String(),
        ]);
    }

    public function preview(FormImport $import)
    {
        if (! in_array($import->status, ['parsed', 'completed'], true)) {
            return redirect()->route('imports.status.page', ['import' => $import->id]);
        }

        $metadata = $import->metadata ?? [];
        $previewSchema = $metadata['preview_schema'] ?? null;

        if (! is_array($previewSchema) || ! is_array($previewSchema['fields'] ?? null)) {
            throw new RuntimeException('Preview schema is not available for this import.');
        }

        return view('imports.preview', [
            'import' => $import,
            'schema' => $previewSchema,
            'warnings' => is_array($metadata['warnings'] ?? null) ? $metadata['warnings'] : [],
            'unparseableBlocks' => is_array($metadata['unparseable_blocks'] ?? null) ? $metadata['unparseable_blocks'] : [],
            'ambiguities' => is_array($metadata['ambiguities'] ?? null) ? $metadata['ambiguities'] : [],
            'layout' => (string) ($metadata['layout'] ?? ''),
            'layoutNotes' => is_array($metadata['layout_notes'] ?? null) ? $metadata['layout_notes'] : [],
            'hybridSplit' => is_array($metadata['hybrid_split'] ?? null) ? $metadata['hybrid_split'] : null,
        ]);
    }

    public function commit(Request $request, FormImport $import)
    {
        if ($import->status !== 'parsed') {
            return redirect()->route('imports.status.page', ['import' => $import->id]);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'schema' => 'required|string',
        ]);

        $decodedSchema = json_decode($data['schema'], true);
        if (! is_array($decodedSchema) || ! is_array($decodedSchema['fields'] ?? null)) {
            return back()->withErrors(['schema' => 'Mapped schema is invalid JSON.'])->withInput();
        }

        $normalizedSchema = $this->importService->normalizeSchema([
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'fields' => $decodedSchema['fields'],
        ]);

        $form = Form::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'schema' => $normalizedSchema,
            'public_uuid' => (string) Str::uuid(),
            'status' => 'draft',
        ]);

        $import->update([
            'form_id' => $form->id,
            'status' => 'completed',
            'summary' => array_merge($import->summary ?? [], [
                'mapped_fields' => count($normalizedSchema['fields'] ?? []),
            ]),
            'metadata' => array_merge($import->metadata ?? [], [
                'committed_at' => now()->toIso8601String(),
            ]),
        ]);

        return redirect()->route('forms.edit', ['form' => $form->id])
            ->with('status', 'Imported form created. You can continue editing in the form builder.');
    }

    private function markImportParsed(FormImport $import, array $result): void
    {
        $import->update([
            'status' => 'parsed',
            'summary' => [
                'parsed_fields' => count($result['schema']['fields'] ?? []),
                'ambiguities' => count($result['ambiguities'] ?? []),
                'unparseable_blocks' => count($result['unparseable_blocks'] ?? []),
                'layout' => $result['layout'] ?? null,
            ],
            'metadata' => array_merge($import->metadata ?? [], [
                'preview_schema' => $result['schema'] ?? ['fields' => []],
                'warnings' => $result['warnings'] ?? [],
                'unparseable_blocks' => $result['unparseable_blocks'] ?? [],
                'ambiguities' => $result['ambiguities'] ?? [],
                'layout' => $result['layout'] ?? null,
                'layout_notes' => $result['layout_notes'] ?? [],
                'hybrid_split' => $result['hybrid_split'] ?? null,
                'ai_metadata' => $result['ai_metadata'] ?? null,
                'parsed_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function resolveImportFilePath(FormImport $import): string
    {
        $metadata = $import->metadata ?? [];
        $disk = (string) ($metadata['file_disk'] ?? 'local');
        $relativePath = (string) $import->file_path;

        if ($relativePath === '') {
            return storage_path('app/imports/missing-file');
        }

        try {
            if (Storage::disk($disk)->exists($relativePath)) {
                return Storage::disk($disk)->path($relativePath);
            }
        } catch (Throwable) {
            // Fall through to static candidates below.
        }

        $candidates = [
            storage_path('app/' . $relativePath),
            storage_path('app/public/' . $relativePath),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Return most likely local path for downstream error message context.
        return storage_path('app/' . $relativePath);
    }
}
