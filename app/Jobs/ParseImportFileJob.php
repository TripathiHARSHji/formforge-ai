<?php

namespace App\Jobs;

use App\Models\FormImport;
use App\Services\DocumentFormImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ParseImportFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(private readonly int $importId)
    {
    }

    public function handle(DocumentFormImportService $service): void
    {
        $import = FormImport::find($this->importId);
        if (! $import) {
            return;
        }

        $import->update([
            'status' => 'processing',
        ]);

        try {
            $absolutePath = $this->resolveImportFilePath($import);
            if (! is_file($absolutePath)) {
                throw new RuntimeException('Uploaded file could not be found on disk for parsing.');
            }

            $result = $service->parseAndRefine($absolutePath, $import->source_type);

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
        } catch (Throwable $e) {
            $import->update([
                'status' => 'failed',
                'metadata' => array_merge($import->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            throw $e;
        }
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

        return storage_path('app/' . $relativePath);
    }
}
