<?php

namespace App\Livewire\Admin\Settings;

use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Services\ActivityLogger;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Fakultas & Program Studi')]
class Faculties extends Component
{
    /** @var array<int, array{name: string, code: string, dspace_collection_uuid: ?string, is_active: bool, sort: int}> */
    public array $rows = [];

    public array $newFaculty = ['code' => '', 'name' => ''];

    /** @var array<int, string> faculty id => new programme name */
    public array $newProgram = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->rows = Faculty::orderBy('sort')->get()->mapWithKeys(fn (Faculty $f) => [$f->id => [
            'code' => $f->code, 'name' => $f->name, 'dspace_collection_uuid' => $f->dspace_collection_uuid,
            'is_active' => $f->is_active, 'sort' => $f->sort,
        ]])->all();
    }

    public function save(): void
    {
        $this->validate([
            'rows.*.name' => ['required', 'string', 'max:150'],
            'rows.*.sort' => ['required', 'integer'],
            'rows.*.dspace_collection_uuid' => ['nullable', 'uuid'],
        ], ['rows.*.dspace_collection_uuid.uuid' => 'UUID koleksi DSpace tidak valid.']);

        foreach ($this->rows as $id => $row) {
            Faculty::whereKey($id)->update([
                'name' => $row['name'],
                'sort' => (int) $row['sort'],
                'is_active' => (bool) $row['is_active'],
                'dspace_collection_uuid' => $row['dspace_collection_uuid'] ?: null,
            ]);
        }
        ActivityLogger::admin('settings.faculties_updated');
        $this->flash = 'Data fakultas disimpan.';
    }

    public function addFaculty(): void
    {
        $this->validate([
            'newFaculty.code' => ['required', 'alpha_dash', 'max:20', 'unique:faculties,code'],
            'newFaculty.name' => ['required', 'string', 'max:150'],
        ]);
        Faculty::create($this->newFaculty + ['sort' => (int) Faculty::max('sort') + 1]);
        ActivityLogger::admin('settings.faculty_added', null, $this->newFaculty);
        $this->newFaculty = ['code' => '', 'name' => ''];
        $this->load();
    }

    public function addProgram(int $facultyId): void
    {
        $name = trim($this->newProgram[$facultyId] ?? '');
        if ($name === '') {
            return;
        }
        Faculty::findOrFail($facultyId)->programs()->firstOrCreate(['name' => $name]);
        ActivityLogger::admin('settings.program_added', null, ['faculty_id' => $facultyId, 'name' => $name]);
        $this->newProgram[$facultyId] = '';
    }

    public function toggleProgram(int $programId): void
    {
        $program = StudyProgram::findOrFail($programId);
        $program->update(['is_active' => ! $program->is_active]);
        ActivityLogger::admin('settings.program_toggled', null, ['program_id' => $programId, 'active' => $program->is_active]);
    }

    public function render()
    {
        return view('livewire.admin.settings.faculties', [
            'programs' => StudyProgram::orderBy('name')->get()->groupBy('faculty_id'),
        ]);
    }
}
