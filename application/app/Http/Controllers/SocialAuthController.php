<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Users;
use App\Models\UserMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

final class SocialAuthController extends Controller
{
    private array $allowed = ['google','facebook'];

    public function redirect(string $provider): JsonResponse
    {
        if (!in_array($provider, $this->allowed, true)) {
            return response()->json(['status'=>'fail','message'=>'Provider not supported'], 422);
        }
        $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

        return response()->json(['status'=>'success','message'=>'Auth URL','data'=>['url'=>$url]]);
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        if (!in_array($provider, $this->allowed, true)) {
            return response()->json(['status'=>'fail','message'=>'Provider not supported'], 422);
        }

        try {
            $social = Socialite::driver($provider)->stateless()->user();

            $email = strtolower(trim($social->getEmail() ?? ''));
            $name  = trim($social->getName() ?? '');
            $first = trim($social->user['given_name'] ?? ($social->offsetGet('first_name') ?? ''));
            $last  = trim($social->user['family_name'] ?? ($social->offsetGet('last_name') ?? ''));

            if (!$first || !$last) {
                if ($name) { [$first, $last] = array_pad(explode(' ', $name, 2), 2, ''); }
            }
            if (!$email) {
                return response()->json([
                    'status'=>'fail',
                    'message'=>'Email permission is required on your social account',
                ], 400);
            }

            // Map provider -> meta keys
            $idKey     = $provider === 'google'   ? 'social_google_id'    : 'social_facebook_id';
            $emailKey  = $provider === 'google'   ? 'social_google_email' : 'social_facebook_email';
            $nameKey   = $provider === 'google'   ? 'social_google_name'  : 'social_facebook_name';
            $avatarKey = $provider === 'google'   ? 'social_google_avatar': 'social_facebook_avatar';

            // 1) Try match existing user by provider id in user_meta
            $link = UserMeta::where('name', $idKey)
                ->where('val', $social->getId())
                ->first();

            $user = $link?->user;

            // 2) Else try by email on users
            if (!$user) {
                $user = Users::where('email', $email)->first();
            }

            // 3) Create new or rotate api_key for existing
            $newApiKey = base64_encode(Str::random(40));
            if (!$user) {
                // Note: your Users model doesn't have 'api_key' in $fillable by default.
                // If it's not fillable, we use forceFill().
                $user = new Users([
                    'first_name' => $first ?: ($name ?: 'User'),
                    'last_name'  => $last ?: '',
                    'name'       => trim(($first ?: '') . ' ' . ($last ?: '')) ?: ($name ?: 'User'),
                    'email'      => $email,
                    'password'   => Hash::make(Str::random(16)),
                    'role_id'    => 3,
                    'super_host' => 0,
                ]);

                // api_key may not be mass-assignable
                $user->forceFill(['api_key' => $newApiKey])->save();
            } else {
                $user->forceFill(['api_key' => $newApiKey])->save();
            }

            // 4) Upsert meta linkage
            UserMeta::upsertPairs($user->id, [
                $idKey     => (string) $social->getId(),
                $emailKey  => $email,
                $nameKey   => $name ?: trim(($first ?: '') . ' ' . ($last ?: '')),
                $avatarKey => (string) $social->getAvatar(),
                // In your dump there is also 'social_meta_avatar' mirroring the provider avatar:
                'social_meta_avatar' => (string) $social->getAvatar(),
            ]);

            // 5) Respond with your standard payload
            return response()->json([
                'status'  => 'success',
                'message' => 'Login successful',
                'data'    => [
                    'id'            => $user->id,
                    'first_name'    => $user->first_name,
                    'last_name'     => $user->last_name,
                    'name'          => $user->name,
                    'email'         => $user->email,
                    'role_id'       => $user->role_id,
                    'country'       => $user->country,
                    'mobile_number' => $user->phone,
                    'avatar'        => UserMeta::getValue($user->id, $avatarKey),
                    'api_key'       => $user->api_key,
                    'created_at'    => $user->created_at,
                    'updated_at'    => $user->updated_at,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Unable to authenticate with '.$provider,
            ], 500);
        }
    }
}
