<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserMeta;
use App\Models\Users;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UserNotificationSettingsController extends Controller
{
    use ApiResponse;

    private const CHANNEL_OPTIONS = ['text', 'email', 'whatsapp'];

    private const DEFAULT_NOTIFICATIONS = [
        'account_changes' => false,
        'store_offers'    => false,
        'special_events'  => false,
        'budget_warnings' => false,
    ];

    /**
     * GET /api/user/notification-settings
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->unauthorized('Unauthorized');
        }

        $payload = $this->buildPayload($user);

        return response()->json([
            'success' => true,
            'message' => 'Notification settings retrieved successfully',
            'data'    => $payload,
        ]);
    }

    /**
     * PUT /api/user/notification-settings
     */
    public function update(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->unauthorized('Unauthorized');
        }

        $validator = Validator::make($request->all(), [
            'notification_channels'   => ['required', 'array', 'min:1'],
            'notification_channels.*' => ['string', Rule::in(self::CHANNEL_OPTIONS)],
            'email'                   => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone_number'            => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'notifications_enabled'                         => ['required', 'array'],
            'notifications_enabled.account_changes'         => ['required', 'boolean'],
            'notifications_enabled.store_offers'            => ['required', 'boolean'],
            'notifications_enabled.special_events'          => ['required', 'boolean'],
            'notifications_enabled.budget_warnings'         => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();
        $channels = array_values(array_unique(
            array_map('strtolower', $data['notification_channels'])
        ));
        $flags = $this->normalizeNotificationFlags($data['notifications_enabled']);

        DB::transaction(function () use ($user, $data, $channels, $flags): void {
            $user->update([
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone_number'] ?? null,
            ]);

            UserMeta::upsertPairs($user->id, [
                'notification_channels'   => json_encode($channels),
                'notification_preferences' => json_encode($flags),
            ]);
        });

        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated successfully',
            'data'    => $this->buildPayload($user),
        ]);
    }

    private function buildPayload(Users $user): array
    {
        $channels = $this->decodeMetaJson($user->id, 'notification_channels', []);
        $channels = array_values(array_intersect(self::CHANNEL_OPTIONS, $channels));

        $flags = $this->decodeMetaJson(
            $user->id,
            'notification_preferences',
            self::DEFAULT_NOTIFICATIONS
        );
        $flags = $this->normalizeNotificationFlags($flags);

        return [
            'notification_channels' => $channels ?: [],
            'email'                 => $user->email ?? '',
            'phone_number'          => $user->phone ?? '',
            'notifications_enabled' => $flags,
        ];
    }

    private function decodeMetaJson(int $userId, string $key, array $default): array
    {
        $raw = UserMeta::getValue($userId, $key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function normalizeNotificationFlags(array $flags): array
    {
        $normalized = self::DEFAULT_NOTIFICATIONS;
        foreach ($flags as $name => $value) {
            if (array_key_exists($name, $normalized)) {
                $normalized[$name] = (bool) $value;
            }
        }

        return $normalized;
    }
}

