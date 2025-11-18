<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserMeta;
use App\Models\Users;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

final class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data'    => $this->profilePayload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $rules = [
            'username'     => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('users', 'user_name')->ignore($user->id),
            ],
            'email'        => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'phone_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'birthday'     => ['nullable', 'date_format:Y-m-d'],
            'about'        => ['nullable', 'string'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        $user->fill([
            'user_name'    => $this->normalizeUsername($data['username']),
            'email'        => strtolower($data['email']),
            'first_name'   => $data['first_name'],
            'last_name'    => $data['last_name'],
            'phone'        => $data['phone_number'] ?? null,
            'birthday'     => $data['birthday'] ?? null,
            'profile_info' => $data['about'] ?? null,
        ]);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data'    => $this->profilePayload($user->fresh()),
        ]);
    }

    public function updateSocialLinks(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'linkedin'  => ['nullable', 'url', 'max:255'],
            'facebook'  => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'bark'      => ['nullable', 'url', 'max:255'],
            'meetup'    => ['nullable', 'url', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        $user->fill([
            'linkedin_link'  => $data['linkedin'] ?? null,
            'facebook_page'  => $data['facebook'] ?? null,
            'instagram_link' => $data['instagram'] ?? null,
            'meetup_account' => $data['meetup'] ?? null,
        ]);
        $user->save();
        $user->refresh();

        $this->storeBarkLink($user->id, $data['bark'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Social media links updated successfully',
            'data'    => $this->socialLinksPayload($user),
        ]);
    }

    public function showSocialLinks(Request $request): JsonResponse
    {
        /** @var Users|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Social media links fetched successfully',
            'data'    => $this->socialLinksPayload($user),
        ]);
    }

    private function profilePayload(Users $user): array
    {
        $birthday = $user->birthday;
        if ($birthday instanceof CarbonInterface) {
            $birthday = $birthday->format('Y-m-d');
        } elseif (!is_string($birthday) || trim($birthday) === '') {
            $birthday = null;
        }

        return [
            'username'     => $user->user_name ?? '',
            'email'        => $user->email ?? '',
            'first_name'   => $user->first_name ?? '',
            'last_name'    => $user->last_name ?? '',
            'phone_number' => $user->phone ?? '',
            'birthday'     => $birthday,
            'about'        => $user->profile_info ?? '',
        ];
    }

    private function normalizeUsername(string $value): string
    {
        $sanitized = Str::slug($value, '-');
        return $sanitized !== '' ? $sanitized : Str::random(8);
    }

    private function getBarkLink(int $userId): ?string
    {
        return UserMeta::getValue($userId, 'bark_profile') ?: null;
    }

    private function storeBarkLink(int $userId, ?string $link): void
    {
        if ($link === null || $link === '') {
            UserMeta::query()
                ->where('user_id', $userId)
                ->where('name', 'bark_profile')
                ->delete();
            return;
        }

        UserMeta::upsertPairs($userId, ['bark_profile' => $link]);
    }

    private function socialLinksPayload(Users $user): array
    {
        return [
            'linkedin'  => $user->linkedin_link,
            'facebook'  => $user->facebook_page,
            'instagram' => $user->instagram_link,
            'bark'      => $this->getBarkLink($user->id),
            'meetup'    => $user->meetup_account,
        ];
    }
}

