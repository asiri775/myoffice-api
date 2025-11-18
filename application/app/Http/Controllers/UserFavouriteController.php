<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AddToFavourite;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserFavouriteController extends Controller
{
    private string $mediaBase;

    public function __construct()
    {
        $this->mediaBase = rtrim(env('MEDIA_BASE_URL', 'https://myoffice.mybackpocket.co/uploads/'), '/');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $favourites = AddToFavourite::with(['space' => function ($query) {
            $query->select([
                'id',
                'title',
                'address',
                'hourly',
                'discounted_hourly',
                'price',
                'sale_price',
                'review_score',
                'banner_image_id',
            ])->where('status', 'publish');
        }])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        $data = $favourites->map(function (AddToFavourite $fav) {
            $space = $fav->space;
            if (!$space) {
                return null;
            }

            return [
                'id'             => $space->id,
                'image_url'      => $this->mediaUrl($space->banner_image_id),
                'title'          => $space->title,
                'address'        => $space->address,
                'price_per_hour' => $this->pricePerHour($space->toArray()),
                'rating'         => $space->review_score !== null
                    ? round((float) $space->review_score, 1)
                    : null,
            ];
        })
        ->filter()
        ->values();

        return response()->json([
            'success' => true,
            'message' => 'Favourite listings retrieved successfully',
            'data'    => $data,
        ]);
    }

    private function pricePerHour(array $space): float
    {
        foreach (['discounted_hourly', 'hourly', 'sale_price', 'price'] as $field) {
            if (!empty($space[$field])) {
                return (float) $space[$field];
            }
        }
        return 0.0;
    }

    private function mediaUrl(?int $mediaId): ?string
    {
        if (!$mediaId) {
            return null;
        }

        $path = Media::where('id', $mediaId)->value('file_path');
        if (!$path) {
            return null;
        }

        return $this->mediaBase . '/' . ltrim($path, '/');
    }
}


