<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Support\ApiResponse;
use App\Models\Users;
use App\Models\UserMeta;
use App\Models\AddToFavourite;
use App\Models\Space;
use App\Models\Media;
use App\Models\Review;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Mail\DbTemplateMailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
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

    /**
     * GET /api/user/profile
     * Get user profile
     */
    public function getProfile(Request $request): JsonResponse
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

            // Get username from meta or use email as fallback
            $username = UserMeta::getValue($userId, 'username') ?? $user->email;

            // Format birthday for response
            $birthdayFormatted = null;
            if ($user->birthday) {
                if ($user->birthday instanceof \Carbon\Carbon) {
                    $birthdayFormatted = $user->birthday->format('Y-m-d');
                } elseif (is_string($user->birthday)) {
                    $birthdayFormatted = date('Y-m-d', strtotime($user->birthday));
                } else {
                    $birthdayFormatted = $user->birthday;
                }
            }

            // Prepare response data
            $data = [
                'username' => $username,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone_number' => $user->phone,
                'birthday' => $birthdayFormatted,
                'about' => $user->bio,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve profile', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/user/reviews
     * Get user reviews
     */
    public function getReviews(Request $request): JsonResponse
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

            // Get reviews written by the user (only for spaces)
            $reviews = Review::where('create_user', $userId)
                ->where('status', 'approved')
                ->where('object_model', 'space')
                ->with(['space' => function($query) {
                    $query->where('status', 'publish')
                        ->select('id', 'title', 'banner_image_id');
                }])
                ->orderByDesc('id')
                ->get();

            // Format the response data
            $data = [];
            $domainUrl = "https://myoffice.mybackpocket.co/uploads/";

            foreach ($reviews as $review) {
                $space = $review->space;
                
                // Skip if space doesn't exist
                if (!$space) {
                    continue;
                }

                // Get image URL
                $imageUrl = null;
                if ($space->banner_image_id) {
                    $mediaPath = Media::where('id', $space->banner_image_id)->value('file_path');
                    if ($mediaPath) {
                        $mediaPath = trim($mediaPath, "[]\"\\");
                        $imageUrl = rtrim($domainUrl, '/') . '/' . $mediaPath;
                    }
                }

                // Format date
                $date = $review->created_at ? $review->created_at->format('Y-m-d') : null;

                $data[] = [
                    'review_title' => $review->title,
                    'description' => $review->content,
                    'rating' => (float) ($review->rate_number ?? 0),
                    'date' => $date,
                    'listing' => [
                        'name' => $space->title,
                        'image_url' => $imageUrl,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'User reviews retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve user reviews', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/user/bookings
     * Get user booking history
     */
    public function getBookings(Request $request): JsonResponse
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

            // Follow the same query logic as BookingSearchController
            $bookingTable = (new Booking)->getTable();
            
            // Get bookings where user is customer (same as BookingSearchController but only customer_id)
            $bookings = Booking::query()
                ->orderByDesc("$bookingTable.id")
                ->where("$bookingTable.customer_id", $userId)
                // Include all valid statuses like BookingSearchController does
                ->whereIn("$bookingTable.status", [
                    'draft','failed','scheduled','booked','checked-in','checked-out','completed','cancelled','no-show',
                ])
                // Exclude archived bookings (same as BookingSearchController)
                ->where(function ($qq) use ($bookingTable) {
                    $qq->whereNull("$bookingTable.is_archive")
                       ->orWhere("$bookingTable.is_archive", '!=', 1);
                })
                ->get();

            // Format the response data
            $data = [];
            $domainUrl = "https://myoffice.mybackpocket.co/uploads/";

            foreach ($bookings as $booking) {
                // Get space directly by object_id (same as BookingSearchController)
                $space = Space::find($booking->object_id);
                
                // Skip if space doesn't exist
                if (!$space) {
                    continue;
                }

                // Get image URL
                $imageUrl = null;
                if ($space->banner_image_id) {
                    $mediaPath = Media::where('id', $space->banner_image_id)->value('file_path');
                    if ($mediaPath) {
                        $mediaPath = trim($mediaPath, "[]\"\\");
                        $imageUrl = rtrim($domainUrl, '/') . '/' . $mediaPath;
                    }
                }

                // Format address
                $addressParts = array_filter([
                    $space->address,
                    $space->city,
                    $space->state,
                    $space->country
                ]);
                $address = !empty($addressParts) ? implode(', ', $addressParts) : ($space->address ?? '');

                // Get rating
                $rating = $space->review_score ?? 0;

                // Format booking date
                $bookingDate = $booking->created_at ? $booking->created_at->format('Y-m-d') : null;

                $data[] = [
                    'booking_id' => $booking->id,
                    'listing' => [
                        'image_url' => $imageUrl,
                        'title' => $space->title,
                        'address' => $address,
                        'rating' => (float) $rating,
                    ],
                    'total_price' => (float) ($booking->total ?? 0),
                    'booking_date' => $bookingDate,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking history retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve booking history', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/user/support
     * Submit help & support request
     */
    public function submitSupport(Request $request): JsonResponse
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
                'subject' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string'],
            ], [
                'subject.required' => 'The subject field is required.',
                'subject.string' => 'The subject must be a string.',
                'subject.max' => 'The subject may not be greater than 255 characters.',
                'description.required' => 'The description field is required.',
                'description.string' => 'The description must be a string.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();

            // Prepare email content
            $userName = $user->getDisplayName();
            $userEmail = $user->email;
            $subject = $validated['subject'];
            $description = $validated['description'];

            // Build HTML email body
            $htmlBody = "
                <html>
                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <h2 style='color: #2c3e50;'>Support Request</h2>
                    <p><strong>From:</strong> {$userName} ({$userEmail})</p>
                    <p><strong>Subject:</strong> {$subject}</p>
                    <hr style='border: 1px solid #ddd; margin: 20px 0;'>
                    <h3 style='color: #34495e;'>Description:</h3>
                    <p style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #3498db;'>
                        " . nl2br(htmlspecialchars($description)) . "
                    </p>
                </body>
                </html>
            ";

            // Send email (you may want to configure the support email address)
            $supportEmail = env('SUPPORT_EMAIL', 'support@myoffice.ca');
            
            try {
                Mail::to($supportEmail)->send(new DbTemplateMailable(
                    'Support Request: ' . $subject,
                    $htmlBody
                ));
            } catch (\Throwable $mailError) {
                // Log the error but don't fail the request
                \Log::error('Support email failed: ' . $mailError->getMessage());
                // Continue - we still return success as the request was received
            }

            return response()->json([
                'success' => true,
                'message' => 'Support request submitted successfully',
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to submit support request', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/user/payment-methods
     * Add or edit payment method
     */
    public function savePaymentMethod(Request $request): JsonResponse
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

            // Validate based on type
            $type = $request->input('type');
            
            if ($type === 'credit_card') {
                // Custom validation for card number (handle spaces)
                $request->merge([
                    'card_number_clean' => preg_replace('/\s+/', '', $request->input('card_number', '')),
                ]);

                $validator = Validator::make($request->all(), [
                    'type' => ['required', 'string', 'in:credit_card'],
                    'cardholder_name' => ['required', 'string', 'max:255'],
                    'card_number' => ['required', 'string'],
                    'card_number_clean' => ['required', 'string', 'regex:/^\d{16}$/'],
                    'expiry_date' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
                    'ccv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
                    'is_default' => ['nullable', 'boolean'],
                ], [
                    'type.required' => 'The type field is required.',
                    'type.in' => 'The type must be credit_card.',
                    'cardholder_name.required' => 'The cardholder name field is required.',
                    'card_number.required' => 'The card number field is required.',
                    'card_number_clean.regex' => 'The card number must be 16 digits.',
                    'expiry_date.required' => 'The expiry date field is required.',
                    'expiry_date.regex' => 'The expiry date format must be MM/YY.',
                    'ccv.required' => 'The CCV field is required.',
                    'ccv.regex' => 'The CCV must be 3 or 4 digits.',
                ]);
            } elseif ($type === 'backpocket_credit') {
                $validator = Validator::make($request->all(), [
                    'type' => ['required', 'string', 'in:backpocket_credit'],
                    'backpocket_email' => ['required', 'string', 'email', 'max:255'],
                    'backpocket_password' => ['required', 'string'],
                    'is_default' => ['nullable', 'boolean'],
                ], [
                    'type.required' => 'The type field is required.',
                    'type.in' => 'The type must be backpocket_credit.',
                    'backpocket_email.required' => 'The backpocket email field is required.',
                    'backpocket_email.email' => 'The backpocket email must be a valid email address.',
                    'backpocket_password.required' => 'The backpocket password field is required.',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => [
                        'type' => ['The type must be either credit_card or backpocket_credit.'],
                    ],
                ], 422);
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();
            $isDefault = $validated['is_default'] ?? false;

            // If setting as default, unset other defaults
            if ($isDefault) {
                PaymentMethod::where('user_id', $userId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            // Check if payment method already exists (for credit card, check by last 4 digits)
            $existing = null;
            if ($type === 'credit_card') {
                // For credit cards, we'll create a new one each time (or you can implement update logic)
                // For now, we'll always create new
            } elseif ($type === 'backpocket_credit') {
                // For backpocket, check if email already exists
                $existing = PaymentMethod::where('user_id', $userId)
                    ->where('type', 'backpocket_credit')
                    ->where('backpocket_email', $validated['backpocket_email'])
                    ->first();
            }

            // Create or update payment method
            if ($existing) {
                $paymentMethod = $existing;
                $paymentMethod->backpocket_password = Crypt::encryptString($validated['backpocket_password']);
                $paymentMethod->is_default = $isDefault;
                $paymentMethod->save();
            } else {
                // Encrypt sensitive data before saving
                $paymentData = [
                    'user_id' => $userId,
                    'type' => $type,
                    'is_default' => $isDefault,
                ];

                if ($type === 'credit_card') {
                    // Clean card number (remove spaces) before encrypting
                    $cardNumber = preg_replace('/\s+/', '', $validated['card_number']);
                    $paymentData['cardholder_name'] = $validated['cardholder_name'];
                    $paymentData['card_number'] = Crypt::encryptString($cardNumber);
                    $paymentData['expiry_date'] = $validated['expiry_date'];
                    $paymentData['ccv'] = Crypt::encryptString($validated['ccv']);
                } elseif ($type === 'backpocket_credit') {
                    $paymentData['backpocket_email'] = $validated['backpocket_email'];
                    $paymentData['backpocket_password'] = Crypt::encryptString($validated['backpocket_password']);
                }

                $paymentMethod = PaymentMethod::create($paymentData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method saved successfully',
                'data' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'is_default' => (bool) $paymentMethod->is_default,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to save payment method', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/user/payment-methods
     * Get all payment methods
     */
    public function getPaymentMethods(Request $request): JsonResponse
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

            $paymentMethods = PaymentMethod::where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get();

            $data = [];
            foreach ($paymentMethods as $pm) {
                if ($pm->type === 'credit_card') {
                    $data[] = [
                        'id' => $pm->id,
                        'type' => $pm->type,
                        'cardholder_name' => $pm->cardholder_name,
                        'masked_card_number' => $pm->getMaskedCardNumber(),
                        'expiry_date' => $pm->expiry_date,
                        'is_default' => (bool) $pm->is_default,
                    ];
                } elseif ($pm->type === 'backpocket_credit') {
                    $data[] = [
                        'id' => $pm->id,
                        'type' => $pm->type,
                        'backpocket_email' => $pm->backpocket_email,
                        'is_default' => (bool) $pm->is_default,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment methods retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve payment methods', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PUT /api/user/payment-methods/{id}/set-default
     * Set default payment method
     */
    public function setDefaultPaymentMethod(Request $request): JsonResponse
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

            $paymentMethodId = (int) $request->route('id');
            
            $validator = Validator::make($request->all(), [
                'is_default' => ['required', 'boolean'],
            ], [
                'is_default.required' => 'The is_default field is required.',
                'is_default.boolean' => 'The is_default must be a boolean.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $isDefault = $validator->validated()['is_default'];

            $paymentMethod = PaymentMethod::where('id', $paymentMethodId)
                ->where('user_id', $userId)
                ->first();

            if (!$paymentMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not found',
                ], 404);
            }

            // If setting as default, unset other defaults
            if ($isDefault) {
                PaymentMethod::where('user_id', $userId)
                    ->where('id', '!=', $paymentMethodId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->is_default = $isDefault;
            $paymentMethod->save();

            return response()->json([
                'success' => true,
                'message' => 'Payment method set as default successfully',
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to set default payment method', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/user/favourites/toggle
     * Toggle favourite listing
     */
    public function toggleFavourite(Request $request): JsonResponse
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
                'listing_id' => ['required', 'integer', 'min:1'],
            ], [
                'listing_id.required' => 'The listing_id field is required.',
                'listing_id.integer' => 'The listing_id must be an integer.',
                'listing_id.min' => 'The listing_id must be at least 1.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $listingId = (int) $validator->validated()['listing_id'];

            // Check if space exists
            $space = Space::find($listingId);
            if (!$space) {
                return response()->json([
                    'success' => false,
                    'message' => 'Listing not found',
                ], 404);
            }

            return DB::transaction(function () use ($userId, $listingId) {
                $existing = AddToFavourite::where('user_id', $userId)
                    ->where('object_id', $listingId)
                    ->first();

                if ($existing) {
                    $existing->delete();
                    return response()->json([
                        'success' => true,
                        'message' => 'Listing removed from favourites',
                    ], 200);
                }

                AddToFavourite::create([
                    'user_id'   => $userId,
                    'object_id' => $listingId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Listing added to favourites',
                ], 200);
            });
        } catch (\Throwable $e) {
            return $this->serverError('Failed to toggle favourite', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/user/favourites
     * Get user favourite listings
     */
    public function getFavourites(Request $request): JsonResponse
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

            // Get all favourites for the user
            $favourites = AddToFavourite::where('user_id', $userId)
                ->with(['space' => function($query) {
                    $query->where('status', 'publish')
                        ->select('id', 'title', 'address', 'city', 'state', 'country', 
                                'banner_image_id', 'hourly', 'discounted_hourly', 'review_score');
                }])
                ->get();

            // Format the response data
            $data = [];
            $domainUrl = "https://myoffice.mybackpocket.co/uploads/";

            foreach ($favourites as $favourite) {
                $space = $favourite->space;
                
                // Skip if space doesn't exist or is not published
                if (!$space) {
                    continue;
                }

                // Get image URL
                $imageUrl = null;
                if ($space->banner_image_id) {
                    $mediaPath = Media::where('id', $space->banner_image_id)->value('file_path');
                    if ($mediaPath) {
                        $mediaPath = trim($mediaPath, "[]\"\\");
                        $imageUrl = rtrim($domainUrl, '/') . '/' . $mediaPath;
                    }
                }

                // Format address
                $addressParts = array_filter([
                    $space->address,
                    $space->city,
                    $space->state,
                    $space->country
                ]);
                $address = !empty($addressParts) ? implode(', ', $addressParts) : ($space->address ?? '');

                // Get price per hour (prefer discounted_hourly, fallback to hourly)
                $pricePerHour = $space->discounted_hourly ?? $space->hourly ?? 0;

                // Get rating
                $rating = $space->review_score ?? 0;

                $data[] = [
                    'id' => $space->id,
                    'image_url' => $imageUrl,
                    'title' => $space->title,
                    'address' => $address,
                    'price_per_hour' => (float) $pricePerHour,
                    'rating' => (float) $rating,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Favourite listings retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve favourite listings', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/user/social-links
     * Get user social media links
     */
    public function getSocialLinks(Request $request): JsonResponse
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

            // Get social media links from UserMeta
            $data = [
                'linkedin' => UserMeta::getValue($userId, 'linkedin') ?? null,
                'facebook' => UserMeta::getValue($userId, 'facebook') ?? null,
                'instagram' => UserMeta::getValue($userId, 'instagram') ?? null,
                'bark' => UserMeta::getValue($userId, 'bark') ?? null,
                'meetup' => UserMeta::getValue($userId, 'meetup') ?? null,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Social media links fetched successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to retrieve social media links', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PUT /api/user/social-links
     * Update user social media links
     */
    public function updateSocialLinks(Request $request): JsonResponse
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
                'linkedin' => ['nullable', 'url', 'max:500'],
                'facebook' => ['nullable', 'url', 'max:500'],
                'instagram' => ['nullable', 'url', 'max:500'],
                'bark' => ['nullable', 'url', 'max:500'],
                'meetup' => ['nullable', 'url', 'max:500'],
            ], [
                'linkedin.url' => 'The linkedin must be a valid URL.',
                'facebook.url' => 'The facebook must be a valid URL.',
                'instagram.url' => 'The instagram must be a valid URL.',
                'bark.url' => 'The bark must be a valid URL.',
                'meetup.url' => 'The meetup must be a valid URL.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();

            // Prepare data for UserMeta
            $socialLinks = [];
            foreach (['linkedin', 'facebook', 'instagram', 'bark', 'meetup'] as $platform) {
                if (array_key_exists($platform, $validated)) {
                    // Store null as empty string or keep null - UserMeta will handle it
                    $socialLinks[$platform] = $validated[$platform] ?? null;
                }
            }

            // Update UserMeta
            if (!empty($socialLinks)) {
                UserMeta::upsertPairs($userId, $socialLinks);
            }

            // Get updated links for response
            $data = [
                'linkedin' => UserMeta::getValue($userId, 'linkedin') ?? null,
                'facebook' => UserMeta::getValue($userId, 'facebook') ?? null,
                'instagram' => UserMeta::getValue($userId, 'instagram') ?? null,
                'bark' => UserMeta::getValue($userId, 'bark') ?? null,
                'meetup' => UserMeta::getValue($userId, 'meetup') ?? null,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Social media links updated successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to update social media links', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PUT /api/user/profile
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
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
                'username' => [
                    'nullable',
                    'string',
                    'max:255',
                    'alpha_dash'
                ],
                'email' => [
                    'nullable',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id)
                ],
                'first_name' => ['nullable', 'string', 'max:255'],
                'last_name' => ['nullable', 'string', 'max:255'],
                'phone_number' => ['nullable', 'string', 'max:30'],
                'birthday' => ['nullable', 'date', 'date_format:Y-m-d'],
                'about' => ['nullable', 'string'],
            ], [
                'username.alpha_dash' => 'The username may only contain letters, numbers, and dashes.',
                'email.unique' => 'The email has already been taken.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();

            // Update user fields
            if (isset($validated['first_name'])) {
                $user->first_name = $validated['first_name'];
            }
            if (isset($validated['last_name'])) {
                $user->last_name = $validated['last_name'];
            }
            if (isset($validated['email'])) {
                $user->email = strtolower(trim($validated['email']));
            }
            if (isset($validated['phone_number'])) {
                $user->phone = $validated['phone_number'];
            }
            if (isset($validated['birthday'])) {
                $user->birthday = $validated['birthday'];
            }
            if (isset($validated['about'])) {
                $user->bio = $validated['about'];
            }

            // Update name if first_name or last_name changed
            if (isset($validated['first_name']) || isset($validated['last_name'])) {
                $firstName = $user->first_name ?? '';
                $lastName = $user->last_name ?? '';
                $user->name = trim($firstName . ' ' . $lastName);
            }

            $user->save();

            // Store username in UserMeta if provided
            if (isset($validated['username'])) {
                UserMeta::upsertPairs($userId, ['username' => $validated['username']]);
            }

            // Reload user to get latest data
            $user->refresh();

            // Get username from meta or use email as fallback
            $username = UserMeta::getValue($userId, 'username') ?? $user->email;

            // Format birthday for response
            $birthdayFormatted = null;
            if ($user->birthday) {
                if ($user->birthday instanceof \Carbon\Carbon) {
                    $birthdayFormatted = $user->birthday->format('Y-m-d');
                } elseif (is_string($user->birthday)) {
                    $birthdayFormatted = date('Y-m-d', strtotime($user->birthday));
                } else {
                    $birthdayFormatted = $user->birthday;
                }
            }

            // Prepare response data
            $data = [
                'username' => $username,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone_number' => $user->phone,
                'birthday' => $birthdayFormatted,
                'about' => $user->bio,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to update profile', [
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
