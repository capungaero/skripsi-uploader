<?php

namespace App\Livewire\Admin\Settings;

use App\Models\AiCriterion;
use App\Services\ActivityLogger;
use App\Services\Ai\AiClient;
use App\Services\Settings;
use Livewire\Attributes\Title;

#[Title('AI & Kriteria')]
class AiConfig extends SettingsForm
{
    /** @var array<int, array> criterion id => editable props */
    public array $criteria = [];

    public array $newCriterion = ['key' => '', 'label' => ''];

    public ?string $testResult = null;

    protected function keys(): array
    {
        return ['ai.enabled', 'ai.base_url', 'ai.api_key', 'ai.model', 'ai.json_mode', 'ai.timeout', 'ai.max_images', 'ai.max_text_chars', 'ai.max_tokens'];
    }

    public function mount(Settings $settings): void
    {
        parent::mount($settings);
        $this->loadCriteria();
    }

    private function loadCriteria(): void
    {
        $this->criteria = AiCriterion::orderBy('sort')->get()->mapWithKeys(fn (AiCriterion $c) => [$c->id => [
            'key' => $c->key, 'label' => $c->label, 'instruction' => $c->instruction,
            'page_keywords' => implode(', ', $c->page_keywords ?? []), 'needs_image' => $c->needs_image,
            'min_score' => $c->min_score, 'required' => $c->required, 'active' => $c->active, 'sort' => $c->sort,
        ]])->all();
    }

    public function save(Settings $settings): void
    {
        $this->persist($settings, [
            'form.ai__base_url' => ['nullable', 'url:https,http', 'max:300'],
            'form.ai__model' => ['nullable', 'string', 'max:150'],
            'form.ai__timeout' => ['required', 'integer', 'min:10', 'max:600'],
            'form.ai__max_images' => ['required', 'integer', 'min:0', 'max:20'],
            'form.ai__max_text_chars' => ['required', 'integer', 'min:2000', 'max:200000'],
            'form.ai__max_tokens' => ['required', 'integer', 'min:500', 'max:64000'],
        ]);
    }

    public function testConnection(AiClient $client): void
    {
        try {
            $this->testResult = 'Berhasil: '.mb_substr($client->ping(), 0, 200);
        } catch (\Throwable $e) {
            $this->testResult = 'Gagal: '.mb_substr($e->getMessage(), 0, 400);
        }
        ActivityLogger::admin('ai.connection_tested', null, ['ok' => str_starts_with($this->testResult, 'Berhasil')]);
    }

    public function saveCriteria(): void
    {
        $this->validate([
            'criteria.*.label' => ['required', 'string', 'max:150'],
            'criteria.*.instruction' => ['required', 'string', 'max:3000'],
            'criteria.*.min_score' => ['required', 'integer', 'min:0', 'max:100'],
            'criteria.*.sort' => ['required', 'integer'],
        ]);

        foreach ($this->criteria as $id => $c) {
            AiCriterion::whereKey($id)->update([
                'label' => $c['label'],
                'instruction' => $c['instruction'],
                'page_keywords' => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $c['page_keywords']))))),
                'needs_image' => (bool) $c['needs_image'],
                'min_score' => (int) $c['min_score'],
                'required' => (bool) $c['required'],
                'active' => (bool) $c['active'],
                'sort' => (int) $c['sort'],
            ]);
        }
        ActivityLogger::admin('settings.ai_criteria_updated');
        $this->loadCriteria();
        $this->flash = 'Kriteria AI disimpan.';
    }

    public function addCriterion(): void
    {
        $this->validate([
            'newCriterion.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,48}$/', 'unique:ai_criteria,key'],
            'newCriterion.label' => ['required', 'string', 'max:150'],
        ]);
        AiCriterion::create($this->newCriterion + [
            'instruction' => 'Jelaskan apa yang harus diperiksa AI untuk kriteria ini.',
            'page_keywords' => [],
            'active' => false,
            'sort' => (int) AiCriterion::max('sort') + 10,
        ]);
        ActivityLogger::admin('settings.ai_criterion_added', null, $this->newCriterion);
        $this->newCriterion = ['key' => '', 'label' => ''];
        $this->loadCriteria();
    }

    public function deleteCriterion(int $id): void
    {
        $criterion = AiCriterion::findOrFail($id);
        $criterion->delete();
        ActivityLogger::admin('settings.ai_criterion_deleted', null, ['key' => $criterion->key]);
        $this->loadCriteria();
    }

    public function render()
    {
        return view('livewire.admin.settings.ai-config');
    }
}
