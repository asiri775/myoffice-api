<?php
namespace App\Http\Controllers;
use App\Models\AddToFavourite;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\Request;
use App\Models\Space;
use App\Models\SpaceTerm;
use App\Models\Term;
use App\Models\Media;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

use Auth;
class SpaceController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */

     use ApiResponse;


     public function __construct()
     {
         $this->middleware('auth', ['except' => ['index', 'show']]);
         $this->domain_url = "https://myoffice.mybackpocket.co/uploads/";
     }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

     public function addRemoveFavourite(Request $request)
        {

            $user = Auth::user();
            if (!$user) {
                return $this->unauthorized('Unauthorized: user not found or token invalid');
            }


            $v = Validator::make($request->all(), [
                'space_id' => ['required', 'integer', 'min:1'],
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->toArray(), 'Validation error'); // 422
            }

            $spaceId = (int) $v->validated()['space_id'];


            $space = Space::find($spaceId);
            if (!$space) {
                return $this->notFound('Space not found'); // 404
            }

            try {
                return DB::transaction(function () use ($user, $spaceId) {
                    $existing = AddToFavourite::where('user_id', $user->id)
                        ->where('object_id', $spaceId)
                        ->first();

                    if ($existing) {
                        $existing->delete();

                        return $this->ok(
                            ['space_id' => $spaceId, 'favourited' => false],
                            'Removed from favourites successfully'
                        );
                    }

                    AddToFavourite::create([
                        'user_id'   => $user->id,
                        'object_id' => $spaceId,
                    ]);

                    return $this->ok(
                        ['space_id' => $spaceId, 'favourited' => true],
                        'Added to favourites successfully'
                    );
                });
            } catch (\Throwable $e) {

                $err = app()->environment('local') || config('app.debug') ? $e->getMessage() : null;
                return $this->serverError('Unable to update favourites', $err);
            }
        }

     public function index(Request $request)
     {
         // sanitize & defaults
         $limit   = max(0, (int) $request->input('limit', 10)); // 0 => no limit
         $page    = max(0, (int) $request->input('page', 0));
         $perPage = max(1, (int) $request->input('per_page', 10));

         // whitelist sortable columns to avoid SQL injection
         $sortable = ['id','title','price','sale_price','review_score','city','created_at'];
         $sortBy   = in_array($request->input('sortBy', 'id'), $sortable, true) ? $request->input('sortBy', 'id') : 'id';

         $order    = strtolower($request->input('orderBy', 'asc'));
         $orderBy  = $order === 'desc' ? 'desc' : 'asc';

         $searched_address = trim((string) $request->input('searched_address', ''));
         $title            = trim((string) $request->input('title', ''));
         $city             = trim((string) $request->input('city', ''));

         $query = DB::table('bravo_spaces')->where('status', 'publish');

         if ($searched_address !== '') {
             $query->whereRaw(
                 'UPPER(CONCAT(city, ", ", state, ", ", country)) LIKE UPPER(?)',
                 ['%'.$searched_address.'%']
             );
         }

         if ($title !== '') {
             $query->where('title', 'LIKE', '%'.$title.'%');
         }

         if ($city !== '') {
             $query->where(function ($q) use ($city) {
                 $q->where('city', 'LIKE', '%'.$city.'%')
                   ->orWhere('address', 'LIKE', '%'.$city.'%');
             });
         }

         $query->orderBy($sortBy, $orderBy);

         // simple offset paging (your current approach)
         if ($limit > 0) {
             $query->limit($limit)->offset($page * $limit);
         }

         $spaces  = $query->get();
         $result  = $this->getCollection($spaces);

         // include lightweight meta only if client paginates by offset
         $meta = [
             'page'      => $page,
             'per_page'  => $limit > 0 ? $limit : count($result),
             'returned'  => count($result),
             'sort_by'   => $sortBy,
             'order_by'  => $orderBy,
             'filtered'  => [
                 'searched_address' => $searched_address ?: null,
                 'title'            => $title ?: null,
                 'city'             => $city ?: null,
             ],
         ];

         return $this->ok($result, 'Spaces fetched', $meta);
     }


    public function index1(Request $request)
    {
        $limit= $request['limit'];
		$page=$request['page'];
		$per_page=$request['per_page'];
		$sortBy=$request['sortBy'];
		$orderBy=$request['orderBy'];
	    $city=trim($request['city']);
        $title=trim($request['title']);
		$spaces = Space::query();
        $result=[];
		  if (!is_numeric($page)) {
           $page = 10;
         }
		 if (!is_numeric($per_page)) {
           $per_page = 1;
         }
		  // $spaces->DB::table('bravo_spaces')->where('bravo_spaces.status','publish');
		   $spaces->where('bravo_spaces.status','publish');

	   if(!empty($limit) AND !empty($page)){

		    if(!empty($city) OR !empty($title))
			{
				if(!empty($title))
			     {
					 $spaces->Where('bravo_spaces.title', 'like', "%{$title}%");
				 }
				 if(!empty($city))
				 {
				    $spaces->Where(function ($query)use ($city) {
                       $query->Where('bravo_spaces.city', 'like', "%{$city}%")
                      ->orWhere('bravo_spaces.address', 'like', "%{$city}%");
                     });
				 }


                $spaces->limit($limit)->orderBy($sortBy,$orderBy)->paginate($per_page,['*'], 'page', $page);
				// $spaces = Space::where('status','publish')->where('title', 'like', '%'.$title.'%');
                // dd($spaces->toSql());
			}
            else {

              $spaces = Space::where('status','publish')->limit($limit)->orderBy($sortBy,$orderBy)->paginate($per_page,['*'], 'page', $page);
	       }

		$result=$this->getCollection($spaces,$page=10);

        return response()->json(['status' => 'success','result' => $result]);
    }
	}

	private function getCollection($spaces)
	{

		$results=[];
		Foreach($spaces AS $key=>$space)
		{
	//	echo '<pre>';
	//	print_r($space); die;

			$image_url= "";
			$results[$key]['id']=$space->id;
			$results[$key]['title']=$space->title;
			$results[$key]['content']=$space->content;
			$results[$key]['address']=$space->address;
			$image_url=Media::where('id',$space->banner_image_id)->pluck('file_path');
			$str =$this->domain_url.$image_url;
			$order = array('["', '\\','"]');
			$replace = "";
			$image_url= str_replace($order, $replace,$str);
			$results[$key]['image_url']=$image_url;
			$results[$key]['gallery_urls']=$this->getGallery($space->gallery);
			$results[$key]['map_lat']=$space->map_lat;
			$results[$key]['map_lng']=$space->map_lng;
			$results[$key]['map_zoom']=$space->map_zoom;
			$results[$key]['faqs']=$space->faqs;
			$results[$key]['allow_children']=$space->allow_children;
			$results[$key]['allow_infant'] = $space->allow_infant;
			$results[$key]['max_guests'] = $space->max_guests;
			$results[$key]['enable_extra_price']=$space->enable_extra_price;
			$results[$key]['extra_price']=$space->extra_price;
			$results[$key]['price']=$space->price;
			$results[$key]['sale_price']=$space->sale_price;
			$results[$key]['discount_by_days']=$space->discount_by_days;
			$results[$key]['status']=$space->status;
			$results[$key]['review_score']=$space->review_score;
			$results[$key]['available_from']=$space->available_from;
			$results[$key]['available_to']=$space->available_to;
			$results[$key]['first_working_day']=$space->first_working_day;
			$results[$key]['last_working_day']=$space->last_working_day;
			$results[$key]['min_day_before_booking']=$space->min_day_before_booking;
			$results[$key]['min_day_stays']=$space->min_day_stays;
			$results[$key]['min_hour_stays']=$space->min_hour_stays;
			$results[$key]['enable_service_fee']=$space->enable_service_fee;
			$results[$key]['service_fee']=$space->service_fee;
			$results[$key]['discount']=$space->discount;
			$results[$key]['hourly']=$space->hourly;
			$results[$key]['daily']=$space->daily;
			$results[$key]['weekly']=$space->weekly;
			$results[$key]['monthly']=$space->monthly;
			$results[$key]['monthly']=$space->monthly;
            $results[$key]['discounted_hourly']=$space->discounted_hourly;
			$results[$key]['discounted_daily']=$space->discounted_daily;
			$results[$key]['discounted_weekly']=$space->discounted_weekly;
			$results[$key]['discounted_monthly']=$space->discounted_monthly;
			$results[$key]['hours_after_full_day']=$space->hours_after_full_day;
			$results[$key]['house_rules']=$space->house_rules;
			$results[$key]['tos']=$space->tos;
			$results[$key]['total_bookings']=$space->total_bookings;
			$results[$key]['city']=$space->city;
			$results[$key]['state']=$space->state;
			$results[$key]['country']=$space->country;
			$results[$key]['checkin_reminder_time']=$space->checkin_reminder_time;
			$results[$key]['checkout_reminder_time']=$space->checkout_reminder_time;
			$results[$key]['latecheckout_reminder_time']=$space->latecheckout_reminder_time;
			$results[$key]['review_count']=$space->review_count;
			$terms=SpaceTerm::where('target_id',$space->id)->pluck('term_id');
			$results[$key]['amenities']=$this->getTerms($terms);

			//$results['seat']=$space->seat;
			//$results['desk']=$space->desk;
			//$results['square']=$space->square;






		}

		return $results;
	}

	private function getTerms($terms)
	{
        $list=[];
		Foreach($terms AS $term)
		{
			$list['name']=Term::where('id',$term)->pluck('name');
			$list['slug']=Term::where('id',$term)->pluck('slug');
		}

		return $list;
	}

	private function getGallery($galData)
	{
		$galData=$this->array_flatten($galData);
        $gallery=[];
		Foreach($galData AS $gal)
		{
			$image_url=Media::where('id',$gal)->pluck('file_path');
			$str =$this->domain_url.$image_url;
			$order = array('["', '\\','"]');
			$replace = "";
			$image_url= str_replace($order, $replace,$str);
            $gallery[]=$image_url;

		}

		return $gallery;
	}

	private function array_flatten( $arr, $out=array() )
	{
      $out= explode(",", $arr);
	return $out;
  }

  public function show(Request $request, int $id)
  {
      $space = Space::with(['terms.term'])
          ->published()
          ->find($id);

      if (!$space) {
          return response()->json(['status' => 'error', 'message' => 'Space not found'], 404);
      }

      return $this->buildDetailResponse($request, $space);
  }

  private function buildDetailResponse(Request $request, Space $space)
  {
      // increment clicks
      $space->increment('clicks');

      // Get authenticated user (if any) for favorite checking
      $user = Auth::user();
      $userId = $user ? $user->id : null;

      // Check if main space is favorited
      $isFavourited = false;
      if ($userId) {
          $isFavourited = AddToFavourite::where('user_id', $userId)
              ->where('object_id', $space->id)
              ->exists();
      }

      // category & parking (parking by slug)
      $termRows = $space->terms->load('term')->pluck('term');
      $terms    = $termRows->filter(); // drop nulls if any

      $categoryName = 'Uncategorized';
      $parkingName  = '';
      foreach ($terms as $t) {
          if ($t && $t->slug === 'parking') {
              $parkingName = $t->name;
          }
      }

      // related spaces (within ~20km), keep Eloquent + safe binding
      $relatedSpaces = [];
      $relatedSpaceIds = [];
      if (!is_null($space->map_lat) && !is_null($space->map_lng)) {
          $relatedSpacesQuery = Space::select([
                  'id','title','slug','banner_image_id','gallery',
                  'map_lat','map_lng','city','state','country',
                  'price','sale_price','review_score'
              ])
              ->published()
              ->where('id', '!=', $space->id)
              ->whereRaw(
                  '(ST_Distance_Sphere(point(`map_lng`,`map_lat`), point(?,?))) <= (20 / 0.001)',
                  [$space->map_lng, $space->map_lat]
              )
              ->limit(12)
              ->get();

          // Collect related space IDs for batch favorite check
          $relatedSpaceIds = $relatedSpacesQuery->pluck('id')->toArray();

          // Batch check favorites for all related spaces (if user is authenticated)
          $favouritedSpaceIds = [];
          if ($userId && !empty($relatedSpaceIds)) {
              $favouritedSpaceIds = AddToFavourite::where('user_id', $userId)
                  ->whereIn('object_id', $relatedSpaceIds)
                  ->pluck('object_id')
                  ->toArray();
          }

          $relatedSpaces = $relatedSpacesQuery->map(function ($r) use ($favouritedSpaceIds) {
              return [
                  'id'           => $r->id,
                  'title'        => $r->title,
                  'slug'         => $r->slug,
                  'image_url'    => $this->mediaUrl($r->banner_image_id),
                  'gallery_urls' => $this->galleryUrls($r->gallery),
                  'map_lat'      => $r->map_lat,
                  'map_lng'      => $r->map_lng,
                  'city'         => $r->city,
                  'state'        => $r->state,
                  'country'      => $r->country,
                  'price'        => $r->price,
                  'sale_price'   => $r->sale_price,
                  'review_score' => $r->review_score,
                  'favourited'   => in_array($r->id, $favouritedSpaceIds),
              ];
          })
          ->toArray();
      }

      // totals
      $totalRatings  = Review::where('object_id', $space->id)
                          ->where('object_model', 'space')
                          ->count();

      $totalBookings = Booking::where('object_id', $space->id)
                          ->where('object_model', 'space')
                          ->where('status', '!=', 'pending')
                          ->count();

      // review list (approved, newest first)
      $reviewList = Review::approved()
          ->where('object_id', $space->id)
          ->where('object_model', 'space')
          ->orderByDesc('id')
          ->limit(10)
          ->get(['id','title','content','rate_number','created_at','vendor_id','create_user']);

      // booking_data equivalent (lightweight)
      $bookingData = [
          'id'                      => $space->id,
          'max_guests'              => $space->max_guests ?? 1,
          'minDate'                 => date('m/d/Y'),
          'start_date'              => $request->input('start', ''),
          'end_date'                => $request->input('end', ''),
          'booking_type'            => config('space.space_booking_type', 'by_day'),
          'deposit'                 => (bool) config('space.space_deposit_enable', false),
          'deposit_type'            => config('space.space_deposit_type'),
          'deposit_amount'          => config('space.space_deposit_amount'),
          'deposit_fomular'         => config('space.space_deposit_fomular', 'default'),
          'is_form_enquiry_and_book'=> false,
      ];

      // payload (same shape you used in index)
      $data = [
          'id'                     => $space->id,
          'title'                  => $space->title,
          'slug'                   => $space->slug,
          'content'                => $space->content,
          'address'                => $space->address,
          'city'                   => $space->city,
          'state'                  => $space->state,
          'country'                => $space->country,
          'map_lat'                => $space->map_lat,
          'map_lng'                => $space->map_lng,
          'map_zoom'               => $space->map_zoom,
          'faqs'                   => $space->faqs,
          'allow_children'         => $space->allow_children,
          'allow_infant'           => $space->allow_infant,
          'max_guests'             => $space->max_guests,
          'enable_extra_price'     => $space->enable_extra_price,
          'extra_price'            => $space->extra_price,
          'price'                  => $space->price,
          'sale_price'             => $space->sale_price,
          'discount_by_days'       => $space->discount_by_days,
          'status'                 => $space->status,
          'review_score'           => $space->review_score,
          'available_from'         => $space->available_from,
          'available_to'           => $space->available_to,
          'first_working_day'      => $space->first_working_day,
          'last_working_day'       => $space->last_working_day,
          'min_day_before_booking' => $space->min_day_before_booking,
          'min_day_stays'          => $space->min_day_stays,
          'min_hour_stays'         => $space->min_hour_stays,
          'enable_service_fee'     => $space->enable_service_fee,
          'service_fee'            => $space->service_fee,
          'discount'               => $space->discount,
          'hourly'                 => $space->hourly,
          'daily'                  => $space->daily,
          'weekly'                 => $space->weekly,
          'monthly'                => $space->monthly,
          'discounted_hourly'      => $space->discounted_hourly,
          'discounted_daily'       => $space->discounted_daily,
          'discounted_weekly'      => $space->discounted_weekly,
          'discounted_monthly'     => $space->discounted_monthly,
          'hours_after_full_day'   => $space->hours_after_full_day,
          'house_rules'            => $space->house_rules,
          'tos'                    => $space->tos,
          'total_bookings'         => $space->total_bookings,
          'image_url'              => $this->mediaUrl($space->banner_image_id),
          'gallery_urls'           => $this->galleryUrls($space->gallery),
          'amenities'              => $terms->map(fn($t)=>[
                                      'id'   => $t->id,
                                      'name' => $t->name,
                                      'slug' => $t->slug
                                   ])->values(),
          'category_name'          => $categoryName,
          'parking'                => $parkingName,
          'favourited'             => $isFavourited,
          'related_spaces'         => $relatedSpaces,
          'totalRatings'           => $totalRatings,
          'totalBookings'          => $totalBookings,
          'review_list'            => $reviewList,
          'booking_data'           => $bookingData,
      ];

      return $this->ok($data, 'Space detail');
  }

  /* ------- helpers (same as before) ------- */

  private function mediaUrl($mediaId)
  {
      if (!$mediaId) return null;
      $path = Media::where('id', $mediaId)->value('file_path');
      if (!$path) return null;
      $path = trim($path, "[]\"\\");
      return rtrim($this->domain_url, '/').'/'.$path;
  }

  /**
   * POST /api/space/{space_id}/reviews
   * Submit review for a space
   */
  public function submitReview(Request $request): JsonResponse
  {
      try {
          $user = Auth::user();
          if (!$user) {
              return $this->unauthorized('Unauthorized: user not found or token invalid');
          }

          // Get space_id from route parameter
          $spaceId = (int) $request->route('space_id');
          if ($spaceId <= 0) {
              return $this->badRequest('Invalid space ID');
          }

          // Find the space
          $space = Space::find($spaceId);
          if (!$space) {
              return $this->notFound('Space not found');
          }

          // Validate input
          $validator = Validator::make($request->all(), [
              'title' => ['required', 'string', 'max:255'],
              'description' => ['required', 'string', 'min:10'],
              'rating' => ['required', 'numeric', 'min:0', 'max:5'],
          ], [
              'title.required' => 'The title field is required.',
              'title.string' => 'The title must be a string.',
              'title.max' => 'The title may not be greater than 255 characters.',
              'description.required' => 'The description field is required.',
              'description.string' => 'The description must be a string.',
              'description.min' => 'The description must be at least 10 characters.',
              'rating.required' => 'The rating field is required.',
              'rating.numeric' => 'The rating must be a number.',
              'rating.min' => 'The rating must be at least 0.',
              'rating.max' => 'The rating must not be greater than 5.',
          ]);

          if ($validator->fails()) {
              return response()->json([
                  'success' => false,
                  'message' => 'Validation error',
                  'errors' => $validator->errors()->toArray(),
              ], 422);
          }

          $validated = $validator->validated();

          // Check if user already reviewed this space
          $existingReview = Review::where('object_id', $spaceId)
              ->where('object_model', 'space')
              ->where('create_user', $user->id)
              ->first();

          if ($existingReview) {
              return response()->json([
                  'success' => false,
                  'message' => 'You have already submitted a review for this space',
              ], 409);
          }

          // Create the review
          $review = Review::create([
              'object_id' => $spaceId,
              'object_model' => 'space',
              'title' => $validated['title'],
              'content' => $validated['description'],
              'rate_number' => (float) $validated['rating'],
              'author_ip' => $request->ip(),
              'status' => 'approved', // You can change this to 'pending' if you want admin approval
              'vendor_id' => $space->create_user ?? null,
              'create_user' => $user->id,
          ]);

          return response()->json([
              'success' => true,
              'message' => 'Review submitted successfully',
          ], 201);
      } catch (\Throwable $e) {
          return $this->serverError('Failed to submit review', [
              'exception' => class_basename($e),
              'message' => $e->getMessage(),
          ]);
      }
  }

  private function galleryUrls($galleryCsv)
  {
      if (!$galleryCsv) return [];
      $ids = array_filter(array_map('trim', explode(',', $galleryCsv)), fn($x)=>$x!=='');
      if (empty($ids)) return [];
      $rows = Media::whereIn('id', $ids)->pluck('file_path', 'id');
      $out = [];
      foreach ($ids as $id) {
          $p = $rows[$id] ?? null;
          if ($p) {
              $p = trim($p, "[]\"\\");
              $out[] = rtrim($this->domain_url, '/').'/'.$p;
          }
      }
      return $out;
  }



}
