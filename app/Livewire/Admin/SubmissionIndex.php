<?php

namespace App\Livewire\Admin;

use App\Models\Faculty;
use App\Models\Submission;
use App\Services\SubmissionQuery;
use App\Services\SubmissionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Unggahan')]
class SubmissionIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $faculty = '';

    #[Url(except: '')]
    public string $year = '';

    /** @var int[] */
    public array $selected = [];

    public ?string $flash = null;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'faculty', 'year'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function depositSelected(SubmissionService $service): void
    {
        $queued = 0;
        foreach (Submission::whereIn('id', $this->selected)->get() as $submission) {
            try {
                $service->deposit($submission);
                $queued++;
            } catch (ValidationException) {
                // not in a depositable status: skip
            }
        }
        $this->selected = [];
        $this->flash = "{$queued} unggahan dimasukkan ke antrean setor DSpace.";
    }

    private function filters(): array
    {
        return ['search' => $this->search, 'status' => $this->status, 'faculty' => $this->faculty, 'year' => $this->year];
    }

    public function render()
    {
        return view('livewire.admin.submission-index', [
            'rows' => SubmissionQuery::filtered($this->filters())->with(['faculty', 'studyProgram'])->latest('id')->paginate(25),
            'faculties' => Faculty::orderBy('sort')->get(['id', 'name']),
            'years' => Submission::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'statuses' => ['queue' => '— Antrean verifikasi', 'rejected' => '— Semua ditolak', 'accepted' => '— Semua diterima'] + Submission::ADMIN_LABELS,
            'exportUrl' => route('admin.export', array_filter($this->filters())),
        ]);
    }
}
