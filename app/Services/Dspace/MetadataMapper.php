<?php

namespace App\Services\Dspace;

use App\Models\Submission;
use App\Services\Settings;

/** Builds the DSpace 7+ REST metadata object for a submission using the admin's field mapping. */
class MetadataMapper
{
    /** Form keys whose values may hold several people/items separated by ";" or new lines. */
    private const MULTI_VALUE = ['pembimbing'];

    public function __construct(private Settings $settings) {}

    /** @return array<string, array<int, array{value: string, language: ?string}>> */
    public function map(Submission $submission): array
    {
        $values = $this->formValues($submission);
        $metadata = [];

        foreach ((array) $this->settings->get('dspace.metadata_map') as $formKey => $field) {
            $field = trim((string) $field);
            $value = $values[$formKey] ?? null;
            if ($field === '' || $value === null || trim((string) $value) === '') {
                continue;
            }

            $parts = in_array($formKey, self::MULTI_VALUE, true)
                ? preg_split('/\s*[;\n]\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY)
                : [(string) $value];
            foreach ($parts as $part) {
                $metadata[$field][] = ['value' => trim($part), 'language' => null];
            }
        }

        foreach ((array) $this->settings->get('dspace.fixed_metadata') as $field => $value) {
            if (filled($value) && ! isset($metadata[$field])) {
                $metadata[$field][] = ['value' => (string) $value, 'language' => null];
            }
        }

        $metadata['dc.date.issued'] ??= [['value' => $submission->created_at->format('Y'), 'language' => null]];

        return $metadata;
    }

    /** Every value the admin can map: dedicated columns, master data names, and dynamic extra fields. */
    public function formValues(Submission $submission): array
    {
        return array_merge($submission->extra ?? [], [
            'nim' => $submission->nim,
            'nama' => $submission->nama,
            'judul' => $submission->judul,
            'fakultas' => $submission->faculty?->name,
            'prodi' => $submission->studyProgram?->name,
        ]);
    }
}
