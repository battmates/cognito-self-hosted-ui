<?php

namespace App\Http\Controllers;

use App\Services\CognitoDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminUsersController extends Controller
{
    public function index(Request $request, CognitoDirectory $directory)
    {
        $input = $request->validate([
            'field' => ['nullable', Rule::in(['email', 'username', 'given_name', 'family_name', 'sub'])],
            'q' => ['nullable', 'string', 'max:128'], 'cursor' => ['nullable', 'string', 'max:4096'],
            'username' => ['nullable', 'string', 'max:128'],
        ]);
        try {
            $hasSelection = isset($input['username']) && $input['username'] !== '';
            $selected = $hasSelection ? $directory->get($input['username']) : null;
            $page = $hasSelection ? [] : $directory->search($input['field'] ?? 'email', $input['q'] ?? '', $input['cursor'] ?? null);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            return response()->view('portal.error', ['message' => $e->getMessage()], 503);
        }

        if ($hasSelection) {
            abort_unless($selected, 404, 'This user no longer exists.');

            return view('portal.admin-user', [
                'authStatus' => $request->session()->get('auth.status'),
                'selected' => $selected,
                'attributes' => $directory->attributes($selected),
                'listContext' => Arr::only($input, ['field', 'q']),
                'writesEnabled' => config('sso.management_writes_enabled'),
                'isOwnAccount' => strcasecmp($selected['Username'], (string) $request->session()->get('auth.status.user.username')) === 0,
            ]);
        }

        $data = [
            'authStatus' => $request->session()->get('auth.status'), 'users' => $page['Users'] ?? [],
            'cursor' => $page['PaginationToken'] ?? null,
            'writesEnabled' => config('sso.management_writes_enabled'),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'html' => view('portal.partials.admin-results', $data)->render(),
                'rows' => view('portal.partials.admin-user-rows', $data)->render(),
                'cursor' => $data['cursor'],
                'count' => count($data['users']),
                'has_more' => ! empty($data['cursor']),
            ]);
        }

        return view('portal.admin-users', $data);
    }

    public function update(Request $request, CognitoDirectory $directory)
    {
        $input = $request->validate([
            'username' => ['required', 'string', 'max:128'],
            'action' => ['required', Rule::in(['confirm', 'enable', 'disable', 'reset_password', 'set_password', 'sign_out', 'delete'])],
            'password' => ['required_if:action,set_password', 'nullable', 'string', 'confirmed', 'max:256'],
            'temporary' => ['nullable', 'boolean'], 'confirm_action' => ['accepted'],
            'return_to' => ['nullable', Rule::in(['list'])],
            'delete_confirmation' => ['required_if:action,delete', 'nullable', 'string', 'max:128', 'same:username'],
            'user_subject' => ['required_if:action,delete', 'nullable', 'uuid'],
            'field' => ['nullable', Rule::in(['email', 'username', 'given_name', 'family_name', 'sub'])],
            'q' => ['nullable', 'string', 'max:128'], 'cursor' => ['nullable', 'string', 'max:4096'],
        ]);
        $actor = $request->session()->get('auth.status.user.username');
        if ($input['username'] === $actor && in_array($input['action'], ['disable', 'reset_password', 'set_password', 'sign_out', 'delete'], true)) {
            throw ValidationException::withMessages(['action' => 'Use another administrator to change your own account.']);
        }
        try {
            $directory->manage($input['username'], $input['action'], [...$input, 'actor_username' => $actor]);
        } catch (RuntimeException $e) {
            Log::notice('portal.user_management', ['actor' => $actor, 'target' => $input['username'], 'action' => $input['action'], 'outcome' => 'failed']);
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }
        Log::notice('portal.user_management', ['actor' => $actor, 'target' => $input['username'], 'action' => $input['action'], 'outcome' => 'succeeded']);

        $listContext = Arr::only($input, ['field', 'q']);
        if (($input['return_to'] ?? null) === 'list' || $input['action'] === 'delete') {
            return redirect()->route('portal.admin.users', Arr::except($listContext, 'cursor'))->with('portal.notice', $input['action'] === 'delete' ? 'User deleted successfully.' : 'User updated successfully.');
        }

        return redirect()->route('portal.admin.users', [...$listContext, 'username' => $input['username']])->with('portal.notice', 'User updated successfully.');
    }

    public function create(Request $request, CognitoDirectory $directory)
    {
        $input = $request->validate(['username' => ['required', 'string', 'max:128'], 'email' => ['required', 'email', 'max:320'],
            'given_name' => ['nullable', 'string', 'max:128'], 'family_name' => ['nullable', 'string', 'max:128'], 'phone_number' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', 'string', 'max:128'], 'new_role' => ['nullable', 'string', 'max:128'], 'password' => ['required', 'string', 'confirmed', 'max:256'], 'temporary' => ['nullable', 'boolean']]);
        $input['role'] = $this->selectedRole($input);
        try {
            $directory->createUser($input, (string) $request->session()->get('auth.status.user.user_role'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['create' => $e->getMessage()]);
        }

        return redirect()->route('portal.admin.users', ['username' => $input['username']])->with('portal.notice', 'User created successfully.');
    }

    public function attributes(Request $request, CognitoDirectory $directory)
    {
        $input = $request->validate(['username' => ['required', 'string', 'max:128'], 'email' => ['nullable', 'email', 'max:320'],
            'given_name' => ['nullable', 'string', 'max:128'], 'family_name' => ['nullable', 'string', 'max:128'], 'phone_number' => ['nullable', 'string', 'max:32'], 'role' => ['nullable', 'string', 'max:128'], 'new_role' => ['nullable', 'string', 'max:128']]);
        $input['role'] = $this->selectedRole($input);
        try {
            $directory->updateAttributes($input['username'], ['email' => $input['email'] ?? '', 'given_name' => $input['given_name'] ?? '', 'family_name' => $input['family_name'] ?? '', 'phone_number' => $input['phone_number'] ?? '', 'custom:user_role' => $input['role'] ?? ''], (string) $request->session()->get('auth.status.user.user_role'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['attributes' => $e->getMessage()]);
        }

        return back()->with('portal.notice', 'User attributes updated successfully.');
    }

    public function roles(CognitoDirectory $directory)
    {
        try {
            return response()->json(['roles' => $directory->roles()]);
        } catch (RuntimeException) {
            return response()->json(['message' => 'The role list is unavailable. Please try again.'], 503);
        }
    }

    private function selectedRole(array $input): string
    {
        return trim((string) (($input['role'] ?? '') === '__new__' ? ($input['new_role'] ?? '') : ($input['role'] ?? '')));
    }
}
