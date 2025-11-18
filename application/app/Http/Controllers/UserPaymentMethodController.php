<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UserPaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $methods = UserPaymentMethod::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (UserPaymentMethod $method) => $this->transformMethod($method));

        return response()->json([
            'success' => true,
            'message' => 'Payment methods retrieved successfully',
            'data'    => $methods,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $baseRules = [
            'id'          => ['nullable', 'integer'],
            'type'        => ['required', Rule::in(['credit_card', 'backpocket_credit'])],
            'is_default'  => ['sometimes', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $baseRules);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $type = $request->input('type');

        $rules = $baseRules;
        if ($type === 'credit_card') {
            $rules += [
                'cardholder_name' => ['required', 'string', 'max:255'],
                'card_number'     => ['required', 'digits:16'],
                'expiry_date'     => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
                'ccv'             => ['required', 'digits_between:3,4'],
            ];
        } else {
            $rules += [
                'backpocket_email'    => ['required', 'email'],
                'backpocket_password' => ['required', 'string', 'min:6'],
            ];
        }

        if ($type === 'credit_card') {
            $sanitizedNumber = preg_replace('/\D+/', '', (string) $request->input('card_number'));
            $request->merge(['card_number' => $sanitizedNumber]);
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        $existing = null;
        if (!empty($data['id'])) {
            $existing = UserPaymentMethod::where('id', (int) $data['id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found',
                ], 404);
            }
        }

        $method = DB::transaction(function () use ($user, $type, $data, $existing) {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                UserPaymentMethod::where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            $payload = [
                'user_id'     => $user->id,
                'type'        => $type,
                'is_default'  => $isDefault,
            ];

            if ($type === 'credit_card') {
                $payload += [
                    'cardholder_name' => $data['cardholder_name'],
                    'card_number'     => $data['card_number'],
                    'expiry_date'     => $data['expiry_date'],
                    'ccv'             => $data['ccv'],
                    'backpocket_email'    => null,
                    'backpocket_password' => null,
                ];
            } else {
                $payload += [
                    'cardholder_name'     => null,
                    'card_number'         => null,
                    'expiry_date'         => null,
                    'ccv'                 => null,
                    'backpocket_email'    => $data['backpocket_email'],
                    'backpocket_password' => $data['backpocket_password'],
                ];
            }

            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                return $existing;
            }

            return UserPaymentMethod::create($payload);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment method saved successfully',
            'data'    => [
                'id'         => $method->id,
                'type'       => $method->type,
                'is_default' => (bool) $method->is_default,
            ],
        ]);
    }

    private function transformMethod(UserPaymentMethod $method): array
    {
        if ($method->type === 'credit_card') {
            return [
                'id'                  => $method->id,
                'type'                => $method->type,
                'cardholder_name'     => $method->cardholder_name,
                'masked_card_number'  => $this->maskCardNumber($method->card_number),
                'expiry_date'         => $method->expiry_date,
                'is_default'          => (bool) $method->is_default,
            ];
        }

        return [
            'id'               => $method->id,
            'type'             => $method->type,
            'backpocket_email' => $method->backpocket_email,
            'is_default'       => (bool) $method->is_default,
        ];
    }

    private function maskCardNumber(?string $number): string
    {
        if (!$number) {
            return '**** **** **** ****';
        }

        $digits = preg_replace('/\D+/', '', $number);
        $last4  = substr($digits, -4);

        return sprintf('**** **** **** %s', str_pad($last4, 4, '*', STR_PAD_LEFT));
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $errors,
        ], 422);
    }
}

