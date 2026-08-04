<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiFormSchemaService
{
    private const MAX_ATTEMPTS = 3;

    /** @var string[] */
    private array $defaultModelFallbacks = [
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-1.5-pro-latest',
    ];

    /** @var array<string,string> */
    private array $typeAliases = [
        'short_text' => 'text',
        'long_text' => 'textarea',
        'paragraph' => 'textarea',
        'integer' => 'number',
        'float' => 'number',
        'tel' => 'phone',
        'telephone' => 'phone',
        'select' => 'dropdown',
        'multi_select' => 'checkbox',
        'multiple_choice' => 'radio',
        'choice' => 'dropdown',
        'attachment' => 'file',
        'section' => 'heading',
    ];

    /** @var string[] */
    private array $allowedTypes = [
        'text',
        'textarea',
        'number',
        'email',
        'phone',
        'date',
        'file',
        'rating',
        'dropdown',
        'radio',
        'checkbox',
        'heading',
        'url',
    ];

    public function generate(string $prompt, ?array $existingSchema = null): array
    {
        $startedAt = microtime(true);
        $model = $this->normalizeConfiguredModel((string) config('services.gemini.model', 'gemini-2.5-flash'));
        $discoveredModels = $this->discoverGenerateContentModels();
        $modelCandidates = $this->resolveModelCandidates($model, $discoveredModels);
        $attemptErrors = [];
        $lastUsage = null;

        foreach ($modelCandidates as $candidateModel) {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                try {
                    $lastError = $attempt > 1 ? ($attemptErrors[count($attemptErrors) - 1] ?? null) : null;
                    $response = $this->callGemini($candidateModel, $prompt, $existingSchema, $lastError);
                    $lastUsage = $response['usage'] ?? null;

                    $decoded = $this->decodeModelJson($response['text'] ?? '');
                    if (! is_array($decoded)) {
                        throw new RuntimeException('Model output is not valid JSON object.');
                    }

                    $schema = $this->normalizeSchema($decoded, $existingSchema);
                    $validationErrors = $this->validateSchema($schema);
                    if (! empty($validationErrors)) {
                        throw new RuntimeException('Schema validation failed: ' . implode('; ', $validationErrors));
                    }

                    return [
                        'schema' => $schema,
                        'model' => $response['model'] ?? $candidateModel,
                        'tokens_used' => $this->extractTokenCount($lastUsage),
                        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        'attempts' => $attempt,
                        'metadata' => [
                            'strategy' => 'gemini_json_contract_with_repair_retry',
                            'retry_errors' => $attemptErrors,
                            'fallback_used' => false,
                            'ssl_verify' => $this->resolveSslVerifyLabel(),
                            'model_candidates' => $modelCandidates,
                            'discovered_models' => $discoveredModels,
                            'model_used' => $candidateModel,
                        ],
                    ];
                } catch (Throwable $e) {
                    $sanitized = $this->sanitizeErrorMessage($e->getMessage());
                    $attemptErrors[] = '[' . $candidateModel . '] ' . $sanitized;

                    // Do not retry an unavailable model; switch to next candidate immediately.
                    if ($this->isModelNotFoundError($sanitized)) {
                        break;
                    }
                }
            }
        }

        // Deterministic fallback guarantees a valid schema even if model output keeps failing.
        $fallback = $this->buildFallbackSchema($prompt, $existingSchema);

        return [
            'schema' => $fallback,
            'model' => $model,
            'tokens_used' => $this->extractTokenCount($lastUsage),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'attempts' => self::MAX_ATTEMPTS,
            'metadata' => [
                'strategy' => 'gemini_json_contract_with_repair_retry',
                'retry_errors' => $attemptErrors,
                'fallback_used' => true,
                'ssl_verify' => $this->resolveSslVerifyLabel(),
                'model_candidates' => $modelCandidates,
                'discovered_models' => $discoveredModels,
                'model_used' => null,
            ],
        ];
    }

    /** @return string[] */
    private function resolveModelCandidates(string $configuredModel, array $discoveredModels = []): array
    {
        $configuredModel = $this->normalizeConfiguredModel($configuredModel);
        $fromConfig = (string) config('services.gemini.fallback_models', '');
        $extra = array_filter(array_map(fn (string $model): string => $this->normalizeConfiguredModel($model), explode(',', $fromConfig)));

        $all = array_merge([$configuredModel], $extra, $this->defaultModelFallbacks, $discoveredModels);
        $all = array_values(array_filter($all, static fn (string $model) => $model !== ''));

        return array_values(array_unique($all));
    }

    private function normalizeConfiguredModel(string $model): string
    {
        $normalized = trim($model);
        $lower = strtolower($normalized);

        if ($normalized === '' || $lower === 'gemini-1.5-flash' || $lower === 'gemini-1.5-flash-latest') {
            return 'gemini-2.5-flash';
        }

        return $normalized;
    }

    /** @return string[] */
    private function discoverGenerateContentModels(): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(25)
                ->withOptions(['verify' => $this->resolveSslVerifyOption()])
                ->get('https://generativelanguage.googleapis.com/v1beta/models');

            if (! $response->successful()) {
                return [];
            }

            $models = [];
            foreach (($response->json('models') ?? []) as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '' || ! str_starts_with($name, 'models/')) {
                    continue;
                }

                $methods = $row['supportedGenerationMethods'] ?? [];
                if (! is_array($methods) || ! in_array('generateContent', $methods, true)) {
                    continue;
                }

                $short = substr($name, strlen('models/'));
                if (! is_string($short) || trim($short) === '') {
                    continue;
                }

                if (! str_contains(strtolower($short), 'gemini')) {
                    continue;
                }

                $models[] = $short;
            }

            // Prefer flash/pro variants first.
            usort($models, static function (string $a, string $b): int {
                $score = static function (string $value): int {
                    $lower = strtolower($value);
                    if (str_contains($lower, '2.5-flash')) {
                        return 100;
                    }
                    if (str_contains($lower, '2.5-pro')) {
                        return 90;
                    }
                    if (str_contains($lower, 'flash')) {
                        return 80;
                    }
                    if (str_contains($lower, 'pro')) {
                        return 70;
                    }

                    return 10;
                };

                return $score($b) <=> $score($a);
            });

            return array_values(array_unique($models));
        } catch (Throwable) {
            return [];
        }
    }

    private function isModelNotFoundError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'no longer available')
            || str_contains($lower, 'not_found')
            || str_contains($lower, 'not supported for generatecontent')
            || (str_contains($lower, 'not found') && (str_contains($lower, 'models/') || str_contains($lower, 'model')));
    }

    private function callGemini(string $model, string $prompt, ?array $existingSchema, ?string $lastError): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model);

        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->userPrompt($prompt, $existingSchema, $lastError);

        $client = Http::acceptJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(40)
            ->withOptions(['verify' => $this->resolveSslVerifyOption()]);

        $response = $client->post($url, [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemPrompt],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $userPrompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini request failed: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        return [
            'text' => $text,
            'usage' => $json['usageMetadata'] ?? null,
            'model' => $json['modelVersion'] ?? $model,
        ];
    }

    private function resolveSslVerifyOption(): bool|string
    {
        $verify = config('services.gemini.ssl_verify', true);
        $caBundle = (string) config('services.gemini.ca_bundle', '');

        if ($caBundle !== '' && file_exists($caBundle)) {
            return $caBundle;
        }

        return filter_var($verify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $verify;
    }

    private function resolveSslVerifyLabel(): string
    {
        $verifyOption = $this->resolveSslVerifyOption();
        if (is_string($verifyOption)) {
            return 'ca_bundle';
        }

        return $verifyOption ? 'enabled' : 'disabled';
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $clean = preg_replace('/([?&]key=)[^\s&]+/i', '$1***', $message) ?? $message;

        if (str_contains($clean, 'cURL error 60')) {
            return 'SSL certificate validation failed (cURL 60). Configure GEMINI_CA_BUNDLE or set GEMINI_SSL_VERIFY=false temporarily.';
        }

        return $clean;
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are a form schema generator.',
            'Output only a JSON object, no markdown, no prose.',
            'Contract:',
            '{',
            '  "title": "string",',
            '  "description": "string optional",',
            '  "fields": [',
            '    {',
            '      "id": "string",',
            '      "type": "text|textarea|number|email|phone|date|file|rating|dropdown|radio|checkbox|heading|url",',
            '      "label": "string",',
            '      "key": "string (not required for heading)",',
            '      "required": true|false,',
            '      "placeholder": "string optional",',
            '      "helpText": "string optional",',
            '      "defaultValue": "string|number|array optional",',
            '      "options": ["string"] optional for dropdown/radio/checkbox,',
            '      "maxRating": number optional for rating,',
            '      "validation": {',
            '        "minLength": number|string optional,',
            '        "maxLength": number|string optional,',
            '        "min": number|string optional,',
            '        "max": number|string optional,',
            '        "pattern": "string optional",',
            '        "fileTypes": "string optional",',
            '        "maxFileSize": number|string optional',
            '      }',
            '    }',
            '  ]',
            '}',
            'Rules:',
            '- Ensure every non-heading field has unique key.',
            '- Use sensible labels, placeholders, and validation.',
            '- Never invent unsupported field types.',
        ]);
    }

    private function userPrompt(string $prompt, ?array $existingSchema, ?string $lastError): string
    {
        $lines = [
            'User instruction:',
            $prompt,
        ];

        if (is_array($existingSchema)) {
            $lines[] = '';
            $lines[] = 'Existing form schema to modify (preserve and edit according to instruction):';
            $lines[] = json_encode($existingSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } else {
            $lines[] = '';
            $lines[] = 'Create a new schema from scratch.';
        }

        if ($lastError) {
            $lines[] = '';
            $lines[] = 'Previous output failed validation. Fix these issues exactly:';
            $lines[] = $lastError;
        }

        return implode("\n", $lines);
    }

    private function decodeModelJson(string $raw): ?array
    {
        $candidates = [];

        $trimmed = trim($raw);
        if ($trimmed !== '') {
            $candidates[] = $trimmed;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $raw, $matches)) {
            $candidates[] = trim($matches[1]);
        }

        $first = strpos($raw, '{');
        $last = strrpos($raw, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $candidates[] = substr($raw, $first, $last - $first + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $repaired = $this->repairJsonString($candidate);
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function repairJsonString(string $json): string
    {
        $json = str_replace(["\u{201c}", "\u{201d}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $json);
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        return $json;
    }

    private function normalizeSchema(array $schema, ?array $existingSchema = null): array
    {
        $title = trim((string) ($schema['title'] ?? $existingSchema['title'] ?? 'Untitled Form'));
        if ($title === '') {
            $title = 'Untitled Form';
        }

        $description = trim((string) ($schema['description'] ?? $existingSchema['description'] ?? ''));

        $rawFields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $fields = [];
        $usedKeys = [];

        foreach ($rawFields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = $this->normalizeType((string) ($field['type'] ?? 'text'));
            $label = trim((string) ($field['label'] ?? $field['lable'] ?? ''));
            if ($label === '') {
                $label = Str::title(str_replace('_', ' ', (string) ($field['key'] ?? $field['id'] ?? ('field_' . ($index + 1)))));
            }

            $id = trim((string) ($field['id'] ?? ''));
            if ($id === '') {
                $id = 'field_' . ($index + 1);
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ($type !== 'heading') {
                $key = $this->normalizeKey($key !== '' ? $key : ($field['id'] ?? $label), $index + 1);
                $keyLower = strtolower($key);
                $suffix = 2;
                while (isset($usedKeys[$keyLower])) {
                    $key = $key . '_' . $suffix;
                    $keyLower = strtolower($key);
                    $suffix++;
                }
                $usedKeys[$keyLower] = true;
            }

            $normalized = [
                'id' => $id,
                'type' => $type,
                'label' => $label,
                'required' => $type === 'heading' ? false : (bool) ($field['required'] ?? false),
            ];

            if ($type !== 'heading') {
                $normalized['key'] = $key;
            }

            foreach (['placeholder', 'helpText', 'defaultValue'] as $prop) {
                if (array_key_exists($prop, $field)) {
                    $normalized[$prop] = $field[$prop];
                }
            }

            if (in_array($type, ['dropdown', 'radio', 'checkbox'], true)) {
                $options = array_values(array_filter(
                    array_map('strval', is_array($field['options'] ?? null) ? $field['options'] : []),
                    static fn (string $option) => trim($option) !== ''
                ));

                if (count($options) < 2) {
                    $options = ['Option 1', 'Option 2'];
                }

                $normalized['options'] = $options;
            }

            if ($type === 'rating') {
                $maxRating = (int) ($field['maxRating'] ?? 5);
                $normalized['maxRating'] = max(3, min(10, $maxRating));
            }

            if (is_array($field['validation'] ?? null)) {
                $normalized['validation'] = $field['validation'];
            }

            $fields[] = $normalized;
        }

        if (empty($fields)) {
            $fields[] = [
                'id' => 'field_1',
                'type' => 'text',
                'label' => 'Name',
                'key' => 'name',
                'required' => true,
                'placeholder' => 'Enter your name',
            ];
        }

        return [
            'title' => $title,
            'description' => $description,
            'fields' => $fields,
        ];
    }

    /** @return string[] */
    private function validateSchema(array $schema): array
    {
        $errors = [];

        if (! is_string($schema['title'] ?? null) || trim($schema['title']) === '') {
            $errors[] = 'Title is required.';
        }

        if (! is_array($schema['fields'] ?? null) || count($schema['fields']) === 0) {
            $errors[] = 'At least one field is required.';

            return $errors;
        }

        $seenKeys = [];
        foreach ($schema['fields'] as $index => $field) {
            if (! is_array($field)) {
                $errors[] = 'Field #' . ($index + 1) . ' must be an object.';
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            if (! in_array($type, $this->allowedTypes, true)) {
                $errors[] = 'Field #' . ($index + 1) . ' has unsupported type: ' . $type;
            }

            if ($type !== 'heading') {
                $key = strtolower((string) ($field['key'] ?? ''));
                if ($key === '') {
                    $errors[] = 'Field #' . ($index + 1) . ' is missing key.';
                } elseif (isset($seenKeys[$key])) {
                    $errors[] = 'Duplicate key: ' . $key;
                }
                $seenKeys[$key] = true;
            }

            if (in_array($type, ['dropdown', 'radio', 'checkbox'], true)) {
                if (! is_array($field['options'] ?? null) || count($field['options']) < 2) {
                    $errors[] = 'Field #' . ($index + 1) . ' needs at least 2 options.';
                }
            }
        }

        return $errors;
    }

    private function buildFallbackSchema(string $prompt, ?array $existingSchema): array
    {
        if (is_array($existingSchema) && ! empty($existingSchema['fields'])) {
            return $this->normalizeSchema($existingSchema, $existingSchema);
        }

        $lower = Str::lower($prompt);
        $fields = [
            [
                'id' => 'name',
                'type' => 'text',
                'label' => 'Full Name',
                'key' => 'full_name',
                'required' => true,
                'placeholder' => 'Enter your full name',
            ],
        ];

        if (Str::contains($lower, 'email')) {
            $fields[] = [
                'id' => 'email',
                'type' => 'email',
                'label' => 'Email',
                'key' => 'email',
                'required' => true,
                'placeholder' => 'name@example.com',
            ];
        }

        if (Str::contains($lower, 'phone')) {
            $fields[] = [
                'id' => 'phone',
                'type' => 'phone',
                'label' => 'Phone Number',
                'key' => 'phone',
                'required' => false,
                'placeholder' => 'Enter phone number',
            ];
        }

        if (Str::contains($lower, 'resume') || Str::contains($lower, 'upload')) {
            $fields[] = [
                'id' => 'resume',
                'type' => 'file',
                'label' => 'Resume Upload',
                'key' => 'resume',
                'required' => false,
                'validation' => ['fileTypes' => 'pdf,doc,docx', 'maxFileSize' => 10],
            ];
        }

        return [
            'title' => Str::title($prompt),
            'description' => '',
            'fields' => $fields,
        ];
    }

    private function normalizeType(string $type): string
    {
        $key = strtolower(trim($type));
        if (isset($this->typeAliases[$key])) {
            $key = $this->typeAliases[$key];
        }

        if (! in_array($key, $this->allowedTypes, true)) {
            return 'text';
        }

        return $key;
    }

    private function normalizeKey(string $value, int $index): string
    {
        $slug = Str::of($value)->lower()->replace(['-', ' '], '_')->slug('_')->value();

        return $slug !== '' ? $slug : 'field_' . $index;
    }

    private function extractTokenCount(mixed $usageMetadata): ?int
    {
        if (! is_array($usageMetadata)) {
            return null;
        }

        $tokenCount = $usageMetadata['totalTokenCount']
            ?? (($usageMetadata['promptTokenCount'] ?? 0) + ($usageMetadata['candidatesTokenCount'] ?? 0));

        return is_numeric($tokenCount) ? (int) $tokenCount : null;
    }
}
