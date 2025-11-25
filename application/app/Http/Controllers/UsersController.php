<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Support\ApiResponse;
use App\Models\Users;
use App\Models\UserMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

final class UsersController extends Controller
{
    use ApiResponse;
    /**
     * POST /api/login
     */
    public function authenticate(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }

        $data = $v->validated();
        $user = Users::where('email', strtolower(trim($data['email'])))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        $apiKey = base64_encode(Str::random(40));
        $user->update(['api_key' => $apiKey]);

        return $this->ok($this->userPayload($user, $apiKey), 'Login successful');
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
            'role_id'       => ['nullable','integer'],
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
            return $this->fail($validator->errors()->toArray(), 'Validation error');
        }



        try {
            $data  = $validator->validated();
        $first = trim($data['first_name']);
        $last  = trim($data['last_name']);
        $email = strtolower(trim($data['email']));
        $name  = $first.' '.$last;
            $user = DB::transaction(function () use ($data, $first, $last, $name, $email) {
                $apiKey = base64_encode(Str::random(40));

                $user = Users::create([
                    'first_name' => $first,
                    'last_name'  => $last,
                    'name'       => $name,
                    'email'      => $email,
                    'password'   => Hash::make($data['password']),
                    'role_id'    => $data['role_id'] ?? 2,
                    'super_host' => 0,
                    'country'    => $data['country'] ?? null,
                    'phone'      => $data['mobile_number'] ?? null,
                    'api_key'    => $apiKey,
                ]);

                $user->setAttribute('fresh_api_key', $apiKey);

                return $user;
            });


            $register_as ='guest';

            $mustVerify = setting_item('enable_verify_email_register_user');
            if ($mustVerify == 1) {
                try {
                    $user->sendEmailUserVerificationNotification($register_as);
                    $user->sendEmailWelcomeNotification($register_as);
                    $user->sendEmailRegisteredNotification($register_as);
                    // $user->sendEmailRegisteredAdminNotification($register_as);
                } catch (\Throwable $e) {

                    \Log::error('User registration email failed: '.$e->getMessage());
                }
            }
            return $this->created(
                $this->userPayload($user, $user->fresh_api_key),
                'User registered successfully'
            );

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => env('APP_DEBUG') ? $e->getMessage() : 'Unable to register user',
            ], 500);
        }
    }

    /**
     * GET /api/user/notification-settings
     * Get user notification settings
     */
    public function getNotificationSettings(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return $this->unauthorized();
            }

            $user = Users::find($userId);
            if (!$user) {
                return $this->notFound('User not found');
            }

            // Get notification channels
            $channelsJson = UserMeta::getValue($userId, 'notification_channels');
            $notificationChannels = $channelsJson ? json_decode($channelsJson, true) : ['email'];

            // Get email (from user table or meta)
            $email = $user->email ?? UserMeta::getValue($userId, 'notification_email');

            // Get phone number (from user table or meta)
            $phoneNumber = $user->phone ?? UserMeta::getValue($userId, 'notification_phone_number');

            // Get notifications_enabled
            $notificationsEnabledJson = UserMeta::getValue($userId, 'notifications_enabled');
            $notificationsEnabled = $notificationsEnabledJson 
                ? json_decode($notificationsEnabledJson, true) 
                : [
                    'account_changes' => true,
                    'store_offers' => false,
                    'special_events' => true,
                    'budget_warnings' => true,
                ];

            $data = [
                'notification_channels' => $notificationChannels,
                'email' => $email,
                'phone_number' => $phoneNumber,
                'notifications_enabled' => $notificationsEnabled,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Notification settings retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve notification settings', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PUT /api/user/notification-settings
     * Update user notification settings
     */
    public function updateNotificationSettings(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return $this->unauthorized();
            }

            $user = Users::find($userId);
            if (!$user) {
                return $this->notFound('User not found');
            }

            // Validate input
            $validator = Validator::make($request->all(), [
                'notification_channels' => ['nullable', 'array'],
                'notification_channels.*' => ['string', 'in:text,email,whatsapp,push'],
                'email' => ['nullable', 'string', 'email'],
                'phone_number' => ['nullable', 'string'],
                'notifications_enabled' => ['nullable', 'array'],
                'notifications_enabled.account_changes' => ['nullable', 'boolean'],
                'notifications_enabled.store_offers' => ['nullable', 'boolean'],
                'notifications_enabled.special_events' => ['nullable', 'boolean'],
                'notifications_enabled.budget_warnings' => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->fail($validator->errors()->toArray(), 'Validation error');
            }

            $validated = $validator->validated();
            $settingsToUpdate = [];

            // Update notification_channels
            if (isset($validated['notification_channels'])) {
                $settingsToUpdate['notification_channels'] = json_encode($validated['notification_channels']);
            }

            // Update email (in user table and meta)
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
                $settingsToUpdate['notification_email'] = $validated['email'];
            }

            // Update phone_number (in user table and meta)
            if (isset($validated['phone_number'])) {
                $user->phone = $validated['phone_number'];
                $settingsToUpdate['notification_phone_number'] = $validated['phone_number'];
            }

            // Update notifications_enabled
            if (isset($validated['notifications_enabled'])) {
                $settingsToUpdate['notifications_enabled'] = json_encode($validated['notifications_enabled']);
            }

            // Save user model changes
            if (isset($validated['email']) || isset($validated['phone_number'])) {
                $user->save();
            }

            // Update meta
            if (!empty($settingsToUpdate)) {
                UserMeta::upsertPairs($userId, $settingsToUpdate);
            }

            // Get updated settings for response
            $channelsJson = UserMeta::getValue($userId, 'notification_channels');
            $notificationChannels = $channelsJson 
                ? json_decode($channelsJson, true) 
                : ($validated['notification_channels'] ?? ['email']);

            $email = $user->email ?? UserMeta::getValue($userId, 'notification_email');
            $phoneNumber = $user->phone ?? UserMeta::getValue($userId, 'notification_phone_number');

            $notificationsEnabledJson = UserMeta::getValue($userId, 'notifications_enabled');
            $notificationsEnabled = $notificationsEnabledJson 
                ? json_decode($notificationsEnabledJson, true) 
                : ($validated['notifications_enabled'] ?? [
                    'account_changes' => true,
                    'store_offers' => false,
                    'special_events' => true,
                    'budget_warnings' => true,
                ]);

            $data = [
                'notification_channels' => $notificationChannels,
                'email' => $email,
                'phone_number' => $phoneNumber,
                'notifications_enabled' => $notificationsEnabled,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Notification settings updated successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to update notification settings', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
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
