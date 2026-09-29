<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Pengguna Admin')]
class Users extends Component
{
    public ?int $editingId = null;

    public array $user = ['name' => '', 'email' => '', 'role' => 'verifikator', 'is_active' => true, 'password' => ''];

    public ?string $flash = null;

    public function edit(int $id): void
    {
        $u = User::findOrFail($id);
        $this->editingId = $id;
        $this->user = ['name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'is_active' => $u->is_active, 'password' => ''];
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'user']);
    }

    public function save(): void
    {
        $data = $this->validate([
            'user.name' => ['required', 'string', 'max:100'],
            'user.email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->editingId)],
            'user.role' => ['required', Rule::in(array_keys(User::ROLES))],
            'user.is_active' => ['boolean'],
            'user.password' => [$this->editingId ? 'nullable' : 'required', Password::min(12)->letters()->numbers()],
        ])['user'];

        $data['email'] = strtolower($data['email']);
        if ($this->editingId === Auth::id()) { // never lock yourself out
            $data['role'] = 'superadmin';
            $data['is_active'] = true;
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user = $this->editingId ? tap(User::findOrFail($this->editingId))->update($data) : User::create($data);
        ActivityLogger::admin($this->editingId ? 'user.updated' : 'user.created', null, [
            'user_id' => $user->id, 'email' => $user->email, 'role' => $user->role, 'active' => $user->is_active,
            'password_changed' => isset($data['password']),
        ]);
        $this->cancel();
        $this->flash = 'Pengguna disimpan.';
    }

    public function render()
    {
        return view('livewire.admin.users', ['users' => User::orderBy('name')->get()]);
    }
}
