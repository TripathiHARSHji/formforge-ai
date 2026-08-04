<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

class DocumentFormImportService
{
    /** @var string[] */
    private array $supportedTypes = [
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

    public function __construct(private readonly AiFormSchemaService $aiService)
    {
    }

    public function parseAndRefine(string $absolutePath, string $sourceType): array
    {
        $source = strtolower(trim($sourceType));

        $payload = match ($source) {
            'word' => $this->parseDocx($absolutePath),
            'excel' => $this->parseXlsx($absolutePath),
            default => throw new RuntimeException('Unsupported import source type: ' . $source),
        };

        return $this->refineAmbiguousFieldsWithAi($payload, $source);
    }

    public function normalizeSchema(array $schema): array
    {
        $schema['title'] = trim((string) ($schema['title'] ?? 'Imported form'));
        if ($schema['title'] === '') {
            $schema['title'] = 'Imported form';
        }

        $schema['description'] = (string) ($schema['description'] ?? '');
        $schema['fields'] = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        $seenKeys = [];

        foreach ($schema['fields'] as $index => $field) {
            $type = $this->normalizeType((string) ($field['type'] ?? 'text'));

            $label = trim((string) ($field['label'] ?? 'Field ' . ($index + 1)));
            if ($label === '') {
                $label = 'Field ' . ($index + 1);
            }

            $fieldId = trim((string) ($field['id'] ?? ''));
            if ($fieldId === '') {
                $fieldId = 'field_' . ($index + 1);
            }

            $fieldKey = '';
            if ($type !== 'heading') {
                $baseKey = $this->normalizeKey((string) ($field['key'] ?? $label), $index + 1);
                $fieldKey = $baseKey;
                $suffix = 2;
                while (isset($seenKeys[strtolower($fieldKey)])) {
                    $fieldKey = $baseKey . '_' . $suffix;
                    $suffix++;
                }
                $seenKeys[strtolower($fieldKey)] = true;
            }

            $normalized = [
                'id' => $fieldId,
                'type' => $type,
                'label' => $label,
                'required' => $type === 'heading' ? false : (bool) ($field['required'] ?? false),
            ];

            if ($type !== 'heading') {
                $normalized['key'] = $fieldKey;
            }

            if (array_key_exists('placeholder', $field)) {
                $normalized['placeholder'] = (string) $field['placeholder'];
            }
            if (array_key_exists('helpText', $field)) {
                $normalized['helpText'] = (string) $field['helpText'];
            }
            if (array_key_exists('defaultValue', $field)) {
                $normalized['defaultValue'] = $field['defaultValue'];
            }
            if (array_key_exists('options', $field) && is_array($field['options'])) {
                $normalized['options'] = array_values(array_filter(array_map(
                    fn (mixed $v): string => trim((string) $v),
                    $field['options']
                ), fn (string $v): bool => $v !== ''));
            }
            if (array_key_exists('maxRating', $field)) {
                $normalized['maxRating'] = max(1, (int) $field['maxRating']);
            }
            if (array_key_exists('validation', $field) && is_array($field['validation'])) {
                $normalized['validation'] = $field['validation'];
            }

            $schema['fields'][$index] = $normalized;
        }

        return $schema;
    }

    private function parseDocx(string $absolutePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Unable to open .docx archive.');
        }

        $xml = $this->readZipEntry($zip, 'word/document.xml');
        $zip->close();

        if (! is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('The .docx file does not contain word/document.xml.');
        }

        preg_match_all('/<w:p\b[^>]*>[\s\S]*?<\/w:p>/i', $xml, $paragraphMatches);
        $paragraphs = $paragraphMatches[0] ?? [];

        $fields = [];
        $ambiguities = [];
        $unparseable = [];
        $warnings = [];
        $currentChoiceTarget = null;

        foreach ($paragraphs as $paragraphXml) {
            $line = $this->extractDocxParagraphText($paragraphXml);
            if ($line === '') {
                continue;
            }

            if ($this->isHeadingParagraph($paragraphXml, $line)) {
                $fields[] = [
                    'id' => 'heading_' . (count($fields) + 1),
                    'type' => 'heading',
                    'label' => $line,
                    'required' => false,
                ];
                $currentChoiceTarget = null;
                continue;
            }

            $option = $this->extractOptionLabel($line);
            if ($option !== null && $currentChoiceTarget !== null) {
                $options = $fields[$currentChoiceTarget]['options'] ?? [];
                $options[] = $option;
                $fields[$currentChoiceTarget]['options'] = array_values(array_unique($options));
                $existingType = $fields[$currentChoiceTarget]['type'] ?? 'dropdown';
                if ($existingType === 'dropdown') {
                    $fields[$currentChoiceTarget]['type'] = 'checkbox';
                }
                continue;
            }

            $question = $this->extractQuestionCandidate($line);
            if ($question === null) {
                $unparseable[] = $line;
                $currentChoiceTarget = null;
                continue;
            }

            $inferred = $this->inferTypeAndValidation($question);
            $field = [
                'id' => 'field_' . (count($fields) + 1),
                'type' => $inferred['type'],
                'label' => $question,
                'key' => $this->normalizeKey($question, count($fields) + 1),
                'required' => $this->detectRequiredFromText($question),
            ];

            if (! empty($inferred['validation'])) {
                $field['validation'] = $inferred['validation'];
            }
            if (! empty($inferred['placeholder'])) {
                $field['placeholder'] = $inferred['placeholder'];
            }

            $inlineOptions = $this->extractInlineOptions($line);
            if (! empty($inlineOptions)) {
                $field['options'] = $inlineOptions;
                $field['type'] = 'dropdown';
                $ambiguities[] = [
                    'field_id' => $field['id'],
                    'field_key' => $field['key'],
                    'reason' => 'Choice options found inline; AI can suggest radio vs dropdown.',
                ];
                $currentChoiceTarget = count($fields);
            } else {
                if ($inferred['ambiguous']) {
                    $ambiguities[] = [
                        'field_id' => $field['id'],
                        'field_key' => $field['key'],
                        'reason' => $inferred['ambiguity_reason'],
                    ];
                }
                $currentChoiceTarget = $field['type'] === 'dropdown' ? count($fields) : null;
            }

            $fields[] = $field;
        }

        foreach ($fields as $index => $field) {
            if (! in_array($field['type'], ['dropdown', 'radio', 'checkbox'], true)) {
                continue;
            }
            if (empty($field['options']) || ! is_array($field['options'])) {
                $warnings[] = 'Field "' . $field['label'] . '" is a choice field without options; defaulting to text.';
                $fields[$index]['type'] = 'text';
                unset($fields[$index]['options']);
                $ambiguities[] = [
                    'field_id' => $field['id'],
                    'field_key' => (string) ($field['key'] ?? ''),
                    'reason' => 'Choice field had no extractable options.',
                ];
            }
        }

        if (empty($fields)) {
            throw new RuntimeException('No form-like content could be extracted from the .docx file.');
        }

        return [
            'schema' => $this->normalizeSchema([
                'title' => 'Imported Word Form',
                'description' => 'Imported from .docx (deterministic parse first, AI refinement for ambiguity).',
                'fields' => $fields,
            ]),
            'layout' => 'word_headings_questions',
            'layout_notes' => [
                'Headings become section fields.',
                'Question-like lines become input fields.',
                'Bullet and checkbox lines near a question become options.',
            ],
            'warnings' => array_values(array_unique($warnings)),
            'unparseable_blocks' => array_values(array_unique($unparseable)),
            'ambiguities' => $ambiguities,
            'strategy' => 'deterministic_first',
            'deterministic_fields_count' => count($fields),
        ];
    }

    private function parseXlsx(string $absolutePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Unable to open .xlsx archive.');
        }

        $sharedStringsXml = (string) ($this->readZipEntry($zip, 'xl/sharedStrings.xml') ?: '');
        $workbookXml = (string) ($this->readZipEntry($zip, 'xl/workbook.xml') ?: '');
        $workbookRelsXml = (string) ($this->readZipEntry($zip, 'xl/_rels/workbook.xml.rels') ?: '');

        if ($workbookXml === '' || $workbookRelsXml === '') {
            $zip->close();
            throw new RuntimeException('Workbook metadata is missing from .xlsx file.');
        }

        $sharedStrings = $this->parseSharedStrings($sharedStringsXml);
        $firstSheetPath = $this->resolveFirstWorksheetPath($workbookXml, $workbookRelsXml);
        $sheetXml = (string) ($this->readZipEntry($zip, $firstSheetPath) ?: '');
        $zip->close();

        if ($sheetXml === '') {
            throw new RuntimeException('Unable to read the first worksheet from .xlsx file.');
        }

        $rows = $this->parseWorksheetRows($sheetXml, $sharedStrings);
        if (empty($rows)) {
            throw new RuntimeException('Worksheet appears to be empty.');
        }

        return $this->parseXlsxRows($rows);
    }

    private function parseXlsxRows(array $rows): array
    {
        $warnings = [];
        $ambiguities = [];
        $unparseable = [];

        $headerInfo = $this->detectStructuredHeader($rows);
        if ($headerInfo !== null) {
            [$headerRowIndex, $mapping] = $headerInfo;
            $fields = [];
            $lastSection = null;

            for ($rowIndex = $headerRowIndex + 1; $rowIndex < count($rows); $rowIndex++) {
                $row = $rows[$rowIndex];

                $question = trim((string) ($row[$mapping['question']] ?? ''));
                $section = isset($mapping['section']) ? trim((string) ($row[$mapping['section']] ?? '')) : '';
                $rawType = isset($mapping['type']) ? trim((string) ($row[$mapping['type']] ?? '')) : '';
                $requiredRaw = isset($mapping['required']) ? trim((string) ($row[$mapping['required']] ?? '')) : '';
                $optionsRaw = isset($mapping['options']) ? trim((string) ($row[$mapping['options']] ?? '')) : '';
                $validationRaw = isset($mapping['validation']) ? trim((string) ($row[$mapping['validation']] ?? '')) : '';

                if ($question === '' && $section === '' && $rawType === '' && $optionsRaw === '') {
                    continue;
                }

                if ($section !== '' && strtolower($section) !== strtolower((string) $lastSection)) {
                    $fields[] = [
                        'id' => 'heading_' . (count($fields) + 1),
                        'type' => 'heading',
                        'label' => $section,
                        'required' => false,
                    ];
                    $lastSection = $section;
                }

                if ($question === '') {
                    $unparseable[] = 'Row ' . ($rowIndex + 1) . ' has metadata but no question text.';
                    continue;
                }

                $normalizedType = $this->normalizeType($rawType);
                $inferred = $this->inferTypeAndValidation($question);
                $type = $rawType !== '' ? $normalizedType : $inferred['type'];

                $field = [
                    'id' => 'field_' . (count($fields) + 1),
                    'type' => $type,
                    'label' => $question,
                    'key' => $this->normalizeKey($question, count($fields) + 1),
                    'required' => $requiredRaw !== ''
                        ? $this->isTruthy($requiredRaw)
                        : $this->detectRequiredFromText($question),
                ];

                $parsedOptions = $this->parseDelimitedValues($optionsRaw);
                if (! empty($parsedOptions)) {
                    $field['options'] = $parsedOptions;
                    if (! in_array($field['type'], ['dropdown', 'radio', 'checkbox'], true)) {
                        $field['type'] = 'dropdown';
                        $ambiguities[] = [
                            'field_id' => $field['id'],
                            'field_key' => $field['key'],
                            'reason' => 'Options provided but type was not a choice type.',
                        ];
                    }
                }

                $validation = $this->parseValidationFromString($validationRaw);
                if (! empty($validation)) {
                    $field['validation'] = $validation;
                } elseif (! empty($inferred['validation'])) {
                    $field['validation'] = $inferred['validation'];
                }

                if ($rawType === '' && $inferred['ambiguous']) {
                    $ambiguities[] = [
                        'field_id' => $field['id'],
                        'field_key' => $field['key'],
                        'reason' => $inferred['ambiguity_reason'],
                    ];
                }

                if (in_array($field['type'], ['dropdown', 'radio', 'checkbox'], true) && empty($field['options'])) {
                    $ambiguities[] = [
                        'field_id' => $field['id'],
                        'field_key' => $field['key'],
                        'reason' => 'Choice type without options in spreadsheet row.',
                    ];
                    $warnings[] = 'Row ' . ($rowIndex + 1) . ' appears to be a choice field but has no options.';
                }

                $fields[] = $field;
            }

            if (empty($fields)) {
                throw new RuntimeException('Structured layout was detected but no fields were extracted.');
            }

            return [
                'schema' => $this->normalizeSchema([
                    'title' => 'Imported Excel Form',
                    'description' => 'Imported from .xlsx structured layout (deterministic parse first, AI refinement for ambiguity).',
                    'fields' => $fields,
                ]),
                'layout' => 'excel_structured_layout',
                'layout_notes' => [
                    'Supported columns: section, question, type, required, options, validation.',
                    'Rows with a new section value add a heading automatically.',
                ],
                'warnings' => array_values(array_unique($warnings)),
                'unparseable_blocks' => array_values(array_unique($unparseable)),
                'ambiguities' => $ambiguities,
                'strategy' => 'deterministic_first',
                'deterministic_fields_count' => count($fields),
            ];
        }

        $headerRow = $this->firstNonEmptyRow($rows);
        if ($headerRow === null) {
            throw new RuntimeException('Could not detect any non-empty rows in worksheet.');
        }

        $fields = [];
        foreach ($headerRow as $colIndex => $value) {
            $label = trim((string) $value);
            if ($label === '') {
                continue;
            }

            $inferred = $this->inferTypeAndValidation($label);
            $field = [
                'id' => 'field_' . (count($fields) + 1),
                'type' => $inferred['type'],
                'label' => $label,
                'key' => $this->normalizeKey($label, count($fields) + 1),
                'required' => false,
            ];

            if (! empty($inferred['validation'])) {
                $field['validation'] = $inferred['validation'];
            }

            if ($inferred['ambiguous']) {
                $ambiguities[] = [
                    'field_id' => $field['id'],
                    'field_key' => $field['key'],
                    'reason' => $inferred['ambiguity_reason'],
                ];
            }

            $fields[] = $field;
        }

        if (empty($fields)) {
            throw new RuntimeException('Header-row layout was detected but headers are empty.');
        }

        return [
            'schema' => $this->normalizeSchema([
                'title' => 'Imported Excel Form',
                'description' => 'Imported from .xlsx header-row layout (deterministic parse first, AI refinement for ambiguity).',
                'fields' => $fields,
            ]),
            'layout' => 'excel_header_row_layout',
            'layout_notes' => [
                'Fallback mode: first non-empty row becomes field labels.',
                'All columns become editable fields in the preview mapping step.',
            ],
            'warnings' => $warnings,
            'unparseable_blocks' => $unparseable,
            'ambiguities' => $ambiguities,
            'strategy' => 'deterministic_first',
            'deterministic_fields_count' => count($fields),
        ];
    }

    private function refineAmbiguousFieldsWithAi(array $payload, string $sourceType): array
    {
        $ambiguities = $payload['ambiguities'] ?? [];
        $schema = $payload['schema'] ?? ['fields' => []];

        $split = [
            'deterministic' => [
                'status' => 'completed',
                'fields_count' => (int) ($payload['deterministic_fields_count'] ?? count($schema['fields'] ?? [])),
                'layout' => (string) ($payload['layout'] ?? 'unknown'),
            ],
            'ai_refinement' => [
                'attempted' => false,
                'applied' => false,
                'ambiguous_fields' => count($ambiguities),
                'notes' => [],
            ],
        ];

        if (empty($ambiguities)) {
            $split['ai_refinement']['notes'][] = 'No ambiguous fields detected; AI refinement skipped.';
            $payload['hybrid_split'] = $split;

            return $payload;
        }

        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            $split['ai_refinement']['notes'][] = 'AI refinement skipped because GEMINI_API_KEY is not configured.';
            $payload['hybrid_split'] = $split;

            return $payload;
        }

        $split['ai_refinement']['attempted'] = true;

        try {
            $instruction = $this->buildAmbiguityPrompt($sourceType, $schema, $ambiguities);
            $result = $this->aiService->generate($instruction, $schema);

            $candidate = is_array($result['schema'] ?? null) ? $result['schema'] : null;
            if ($candidate === null || ! is_array($candidate['fields'] ?? null)) {
                throw new RuntimeException('AI refinement returned an invalid schema payload.');
            }

            $payload['schema'] = $this->mergeAiInferenceIntoDraft($schema, $candidate);
            $split['ai_refinement']['applied'] = true;
            $split['ai_refinement']['notes'][] = 'AI updated only type and validation-related attributes for ambiguous fields.';
            $payload['ai_metadata'] = [
                'model' => $result['model'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'attempts' => $result['attempts'] ?? null,
                'fallback_used' => (bool) (($result['metadata']['fallback_used'] ?? false)),
                'retry_errors' => $result['metadata']['retry_errors'] ?? [],
            ];
        } catch (Throwable $e) {
            $payload['warnings'][] = 'AI refinement could not be applied: ' . $e->getMessage();
            $split['ai_refinement']['notes'][] = 'AI refinement failed and deterministic fields were kept.';
        }

        $payload['hybrid_split'] = $split;

        return $payload;
    }

    private function mergeAiInferenceIntoDraft(array $draftSchema, array $aiSchema): array
    {
        $allowedProperties = ['type', 'required', 'validation', 'options', 'maxRating', 'placeholder', 'helpText'];

        $aiByKey = [];
        foreach ($aiSchema['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            $id = trim((string) ($field['id'] ?? ''));
            if ($key !== '') {
                $aiByKey['key:' . strtolower($key)] = $field;
            }
            if ($id !== '') {
                $aiByKey['id:' . strtolower($id)] = $field;
            }
        }

        foreach ($draftSchema['fields'] as $index => $draftField) {
            if (! is_array($draftField)) {
                continue;
            }

            $match = null;
            $lookupKey = trim((string) ($draftField['key'] ?? ''));
            $lookupId = trim((string) ($draftField['id'] ?? ''));

            if ($lookupKey !== '' && isset($aiByKey['key:' . strtolower($lookupKey)])) {
                $match = $aiByKey['key:' . strtolower($lookupKey)];
            } elseif ($lookupId !== '' && isset($aiByKey['id:' . strtolower($lookupId)])) {
                $match = $aiByKey['id:' . strtolower($lookupId)];
            }

            if (! is_array($match)) {
                continue;
            }

            foreach ($allowedProperties as $property) {
                if (! array_key_exists($property, $match)) {
                    continue;
                }
                if ($property === 'type') {
                    $draftSchema['fields'][$index]['type'] = $this->normalizeType((string) $match['type']);
                    continue;
                }
                $draftSchema['fields'][$index][$property] = $match[$property];
            }
        }

        return $this->normalizeSchema($draftSchema);
    }

    private function buildAmbiguityPrompt(string $sourceType, array $schema, array $ambiguities): string
    {
        $lines = [
            'You are refining a deterministically parsed form schema from an uploaded ' . strtoupper($sourceType) . ' document.',
            'Only update ambiguous fields.',
            'Do not change field labels, ids, keys, order, title, or description.',
            'Only modify these properties when needed: type, required, validation, options, maxRating, placeholder, helpText.',
            'If unsure, keep the original value.',
            '',
            'Ambiguous fields list:',
        ];

        foreach ($ambiguities as $ambiguous) {
            $lines[] = '- field_id=' . (string) ($ambiguous['field_id'] ?? '')
                . ', field_key=' . (string) ($ambiguous['field_key'] ?? '')
                . ', reason=' . (string) ($ambiguous['reason'] ?? 'unknown');
        }

        $lines[] = '';
        $lines[] = 'Return strict JSON following the existing schema contract.';

        return implode("\n", $lines);
    }

    private function extractDocxParagraphText(string $paragraphXml): string
    {
        preg_match_all('/<w:t\b[^>]*>([\s\S]*?)<\/w:t>/i', $paragraphXml, $matches);
        $parts = $matches[1] ?? [];

        if (empty($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            $text .= html_entity_decode((string) $part, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function isHeadingParagraph(string $paragraphXml, string $line): bool
    {
        if (preg_match('/w:pStyle\b[^>]*w:val="Heading[1-9]"/i', $paragraphXml)) {
            return true;
        }

        if (strlen($line) <= 80 && preg_match('/^[A-Z][A-Za-z0-9\s\-\/]{2,}$/', $line) && ! str_contains($line, '?')) {
            return true;
        }

        return false;
    }

    private function extractQuestionCandidate(string $line): ?string
    {
        $normalized = trim($line);
        if ($normalized === '') {
            return null;
        }

        $startsLikeOption = preg_match('/^(?:[\-\*•]|\d+[\.)]|[a-zA-Z][\.)])\s+/', $normalized) === 1;
        if ($startsLikeOption && ! str_contains($normalized, '?')) {
            return null;
        }

        if (
            str_contains($normalized, '?')
            || str_ends_with($normalized, ':')
            || preg_match('/\b(name|email|phone|date|address|upload|select|choose|agree|rating)\b/i', $normalized)
        ) {
            return rtrim($normalized, ':');
        }

        if (mb_strlen($normalized) <= 120) {
            return $normalized;
        }

        return null;
    }

    private function extractOptionLabel(string $line): ?string
    {
        $normalized = trim($line);
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/^[\-\*•]\s+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^\d+[\.)]\s+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^\[[^\]]*\]\s*/u', '', $normalized) ?? $normalized;

        if ($normalized === '' || str_contains($normalized, '?')) {
            return null;
        }

        if (mb_strlen($normalized) > 80) {
            return null;
        }

        return trim($normalized);
    }

    private function extractInlineOptions(string $line): array
    {
        $parts = preg_split('/[:\-]\s*/', $line, 2);
        if (! is_array($parts) || count($parts) < 2) {
            return [];
        }

        $tail = trim((string) $parts[1]);
        if ($tail === '') {
            return [];
        }

        $delimited = $this->parseDelimitedValues($tail);

        return count($delimited) >= 2 ? $delimited : [];
    }

    private function parseDelimitedValues(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\||,|;|\/|\\n)\s*/', $trimmed) ?: [];

        $result = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value === '') {
                continue;
            }
            if (preg_match('/^[\-\*•]\s+/', $value)) {
                $value = trim((string) preg_replace('/^[\-\*•]\s+/', '', $value));
            }
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    private function inferTypeAndValidation(string $label): array
    {
        $lower = strtolower($label);

        if (str_contains($lower, 'email')) {
            return ['type' => 'email', 'validation' => ['pattern' => '^[^\s@]+@[^\s@]+\.[^\s@]+$'], 'placeholder' => 'name@example.com', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(phone|mobile|contact number|telephone|tel)\b/i', $label)) {
            return ['type' => 'phone', 'validation' => ['minLength' => 7, 'maxLength' => 20], 'placeholder' => '+1 555 555 5555', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(date|dob|birth)\b/i', $label)) {
            return ['type' => 'date', 'validation' => [], 'placeholder' => '', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(url|website|portfolio|linkedin)\b/i', $label)) {
            return ['type' => 'url', 'validation' => [], 'placeholder' => 'https://', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(upload|resume|cv|attachment|file)\b/i', $label)) {
            return ['type' => 'file', 'validation' => [], 'placeholder' => '', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(rate|rating|score)\b/i', $label)) {
            return ['type' => 'rating', 'validation' => [], 'placeholder' => '', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(age|count|years|number|qty|quantity|total)\b/i', $label)) {
            return ['type' => 'number', 'validation' => [], 'placeholder' => '', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }
        if (preg_match('/\b(select|choose|pick|option)\b/i', $label)) {
            return ['type' => 'dropdown', 'validation' => [], 'placeholder' => '', 'ambiguous' => true, 'ambiguity_reason' => 'Selection intent detected but options/type style may need confirmation.'];
        }
        if (str_contains($lower, 'address') || str_contains($lower, 'describe')) {
            return ['type' => 'textarea', 'validation' => [], 'placeholder' => '', 'ambiguous' => false, 'ambiguity_reason' => ''];
        }

        return ['type' => 'text', 'validation' => [], 'placeholder' => '', 'ambiguous' => true, 'ambiguity_reason' => 'Generic field text with no strong type markers.'];
    }

    private function detectRequiredFromText(string $label): bool
    {
        return preg_match('/\b(required|mandatory|must)\b/i', $label) === 1;
    }

    private function normalizeType(string $raw): string
    {
        $candidate = strtolower(trim($raw));
        if ($candidate === '') {
            return 'text';
        }

        $candidate = $this->typeAliases[$candidate] ?? $candidate;

        if (! in_array($candidate, $this->supportedTypes, true)) {
            return 'text';
        }

        return $candidate;
    }

    private function normalizeKey(string $value, int $index): string
    {
        $key = Str::of($value)
            ->lower()
            ->replace(['-', ' '], '_')
            ->slug('_')
            ->value();

        return $key !== '' ? $key : 'field_' . $index;
    }

    private function parseSharedStrings(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $sx = $this->safeSimpleXml($xml);
        if (! $sx) {
            return [];
        }

        $sx->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $result = [];
        $nodes = $sx->xpath('//s:si') ?: [];
        foreach ($nodes as $node) {
            $textNodes = $node->xpath('.//*[local-name()="t"]') ?: [];
            $text = '';
            foreach ($textNodes as $textNode) {
                $text .= (string) $textNode;
            }
            $result[] = trim($text);
        }

        return $result;
    }

    private function resolveFirstWorksheetPath(string $workbookXml, string $relsXml): string
    {
        $workbook = $this->safeSimpleXml($workbookXml);
        $rels = $this->safeSimpleXml($relsXml);
        if (! $workbook || ! $rels) {
            throw new RuntimeException('Failed to parse workbook metadata XML.');
        }

        $workbook->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = $workbook->xpath('//s:sheets/s:sheet[1]')[0] ?? null;
        if (! $sheet) {
            throw new RuntimeException('Workbook has no sheets.');
        }

        $sheetAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = trim((string) ($sheetAttrs['id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('Workbook sheet relation id is missing.');
        }

        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationshipNodes = $rels->xpath('//r:Relationship') ?: [];

        foreach ($relationshipNodes as $relationship) {
            $attrs = $relationship->attributes();
            $id = (string) ($attrs['Id'] ?? '');
            if ($id !== $rid) {
                continue;
            }

            $target = (string) ($attrs['Target'] ?? '');
            if ($target === '') {
                continue;
            }

            return 'xl/' . ltrim($target, '/');
        }

        throw new RuntimeException('Unable to resolve worksheet target for relation id ' . $rid . '.');
    }

    private function parseWorksheetRows(string $sheetXml, array $sharedStrings): array
    {
        $sheet = $this->safeSimpleXml($sheetXml);
        if (! $sheet) {
            throw new RuntimeException('Failed to parse worksheet XML.');
        }

        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $sheet->xpath('//s:sheetData/s:row') ?: [];

        $rows = [];

        foreach ($rowNodes as $rowNode) {
            $row = [];
            $cellNodes = $rowNode->xpath('./*[local-name()="c"]') ?: [];
            foreach ($cellNodes as $cellNode) {
                $attrs = $cellNode->attributes();
                $ref = (string) ($attrs['r'] ?? '');
                $type = (string) ($attrs['t'] ?? '');
                $colIndex = $this->columnIndexFromCellRef($ref);

                $valueNode = $cellNode->xpath('./*[local-name()="v"]')[0] ?? null;
                $rawValue = $valueNode ? (string) $valueNode : '';

                if ($type === 's') {
                    $sharedIndex = (int) $rawValue;
                    $value = (string) ($sharedStrings[$sharedIndex] ?? '');
                } else {
                    $value = (string) $rawValue;
                }

                $row[$colIndex] = trim($value);
            }

            if (! empty($row)) {
                ksort($row);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function detectStructuredHeader(array $rows): ?array
    {
        $aliases = [
            'section' => ['section', 'group', 'category'],
            'question' => ['question', 'field', 'prompt', 'label'],
            'type' => ['type', 'field type', 'input type'],
            'required' => ['required', 'mandatory', 'is required'],
            'options' => ['options', 'choices', 'values'],
            'validation' => ['validation', 'rules', 'constraints'],
        ];

        $limit = min(5, count($rows));

        for ($i = 0; $i < $limit; $i++) {
            $row = $rows[$i];
            $mapping = [];

            foreach ($row as $col => $cellValue) {
                $normalized = strtolower(trim((string) $cellValue));
                if ($normalized === '') {
                    continue;
                }

                foreach ($aliases as $target => $candidates) {
                    if (isset($mapping[$target])) {
                        continue;
                    }
                    if (in_array($normalized, $candidates, true)) {
                        $mapping[$target] = $col;
                    }
                }
            }

            if (isset($mapping['question'])) {
                return [$i, $mapping];
            }
        }

        return null;
    }

    private function parseValidationFromString(string $raw): array
    {
        $validation = [];
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $validation;
        }

        $parts = preg_split('/\s*[;,]\s*/', $trimmed) ?: [];
        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $part, 2));
            if ($name === '' || $value === '') {
                continue;
            }
            $validation[$name] = is_numeric($value) ? (float) $value : $value;
        }

        return $validation;
    }

    private function firstNonEmptyRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            $values = array_filter($row, fn (mixed $v): bool => trim((string) $v) !== '');
            if (! empty($values)) {
                return $row;
            }
        }

        return null;
    }

    private function isTruthy(string $raw): bool
    {
        $value = strtolower(trim($raw));

        return in_array($value, ['1', 'true', 'yes', 'y', 'required', 'mandatory'], true);
    }

    private function columnIndexFromCellRef(string $cellRef): int
    {
        if ($cellRef === '') {
            return 0;
        }

        if (! preg_match('/^([A-Z]+)\d+$/i', $cellRef, $matches)) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    private function safeSimpleXml(string $xml): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml);
        libxml_clear_errors();

        return $element instanceof SimpleXMLElement ? $element : null;
    }

    private function readZipEntry(ZipArchive $zip, string $entryPath): string|false
    {
        $candidates = [
            $entryPath,
            str_replace('/', '\\', $entryPath),
            str_replace('\\', '/', $entryPath),
        ];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $contents = $zip->getFromName($candidate);
            if ($contents !== false) {
                return $contents;
            }
        }

        return false;
    }
}
