<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Log Aktivitas')]
class ActivityLogs extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $logs = ActivityLog::with('submission:id,nim,nama')
            ->when($this->actor, fn ($q) => $q->where('actor_type', $this->actor))
            ->when($this->action, fn ($q) => $q->where('action', 'like', $this->action.'%'))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(actor_label) LIKE ?', ['%'.mb_strtolower($this->search).'%'])
                ->orWhereHas('submission', fn ($s) => $s->where('nim', 'like', '%'.$this->search.'%'))))
            ->latest('id')->paginate(50);

        return view('livewire.admin.activity-logs', [
            'logs' => $logs,
            'prefixes' => ['submission', 'ai', 'cloud', 'dspace', 'wa', 'auth', 'settings', 'user', 'attempts'],
        ]);
    }
}
