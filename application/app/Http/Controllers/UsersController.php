<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UsersController extends Controller
{
    /**
     * POST /api/login
     */
    public function authenticate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'fail',
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = Users::where('email', strtolower(trim($data['email'])))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'status'  => 'fail',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // rotate api_key
        $apiKey = base64_encode(Str::random(40));
        $user->update(['api_key' => $apiKey]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful',
            'data'    => $this->userPayload($user, $apiKey),
        ]);
    }

    /**
     * POST /api/register
     */
    public function userRegister(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name'    => ['required','string','min:2','max:15','regex:/^[A-Za-z]{2,15}$/'],
            'last_name'     => ['required','string','min:2','max:15','regex:/^[A-Za-z]{2,15}$/'],
            'email'         => ['required','string','email','max:255', Rule::unique('users', 'email')],
            'password'      => ['required','string','min:8','regex:/^\S{8,}$/'],
            'role_id'       => ['required'],
            'country'       => ['nullable','string'],
            'mobile_number' => ['nullable','string'],
        ], [
            'email.required'      => 'Email is required field',
            'email.email'         => 'Email invalid',
            'email.unique'        => 'Email already exists',
            'password.required'   => 'Password is required field',
            'password.min'        => 'Password must be at least 8 characters',
            'password.regex'      => 'Password cannot contain spaces',
            'first_name.required' => 'The first name is required field',
            'last_name.required'  => 'The last name is required field',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data  = $validator->validated();
        $first = trim($data['first_name']);
        $last  = trim($data['last_name']);
        $email = strtolower(trim($data['email']));
        $name  = $first.' '.$last;

        try {
            $user = DB::transaction(function () use ($data, $first, $last, $name, $email) {
                $apiKey = base64_encode(Str::random(40));

                $user = Users::create([
                    'first_name' => $first,
                    'last_name'  => $last,
                    'name'       => $name,
                    'email'      => $email,
                    'password'   => Hash::make($data['password']),
                    'role_id'    => $data['role_id'],
                    'super_host' => 0,
                    'country'    => $data['country'] ?? null,
                    'phone'      => $data['mobile_number'] ?? null,
                    'api_key'    => $apiKey,
                ]);

                $user->setAttribute('fresh_api_key', $apiKey);

                return $user;
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'User registered successfully',
                'data'    => $this->userPayload($user, $user->fresh_api_key),
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => env('APP_DEBUG') ? $e->getMessage() : 'Unable to register user',
            ], 500);
        }
    }

    /* -------------------- helpers -------------------- */

    private function userPayload(Users $user, string $apiKey): array
    {
        return [
            'id'            => $user->id,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'name'          => $user->name,
            'email'         => $user->email,
            'role_id'       => $user->role_id,
            'country'       => $user->country,
            'mobile_number' => $user->phone,
            'api_key'       => $apiKey,
            'created_at'    => $user->created_at,
            'updated_at'    => $user->updated_at,
        ];
    }
}