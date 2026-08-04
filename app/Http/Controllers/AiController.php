<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFormSchemaJob;
use App\Models\AiGenerationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string',
            'form_id' => 'nullable|exists:forms,id',
        ]);

        try {
            $log = AiGenerationLog::create([
                'form_id' => $data['form_id'] ?? null,
                'prompt' => $data['prompt'],
                'model' => config('services.gemini.model', 'gemini-2.5-flash'),
                'status' => 'queued',
                'metadata' => [
                    'mode' => empty($data['form_id']) ? 'create' : 'edit',
                ],
            ]);

            GenerateFormSchemaJob::dispatch($log->id);

            return response()->json([
                'message' => 'AI generation queued.',
                'log_id' => $log->id,
                'status' => $log->status,
                'poll_url' => route('ai.status', ['log' => $log->id]),
            ], 202);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Unable to queue AI generation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status(AiGenerationLog $log): JsonResponse
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $retryErrors = array_map(fn (mixed $msg) => $this->sanitizeForUi((string) $msg), $metadata['retry_errors'] ?? []);

        return response()->json([
            'log_id' => $log->id,
            'status' => $log->status,
            'model' => $log->model,
            'tokens_used' => $log->tokens_used,
            'latency_ms' => $log->latency_ms,
            'schema' => $metadata['schema'] ?? null,
            'error' => isset($metadata['error']) ? $this->sanitizeForUi((string) $metadata['error']) : null,
            'attempts' => $metadata['attempts'] ?? null,
            'fallback_used' => $metadata['fallback_used'] ?? false,
            'retry_errors' => $retryErrors,
            'form_id' => $log->form_id,
            'updated_at' => optional($log->updated_at)?->toIso8601String(),
        ]);
    }

    private function sanitizeForUi(string $message): string
    {
        return preg_replace('/([?&]key=)[^\s&]+/i', '$1***', $message) ?? $message;
    }
}
