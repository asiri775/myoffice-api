<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Space;
use App\Models\SpaceTerm;
use App\Models\Term;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

use Auth;
class SpaceController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */

    public function __construct()
    {
        $this->middleware('auth');
		$this->domain_url="https://myoffice.mybackpocket.co/uploads/";
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
	
	 public function index(Request $request)
    {   
        $limit= $request['limit']; 
		$page=$request['page']; 
		$per_page=$request['per_page']; 
		$sortBy=$request['sortBy'];
		$orderBy=$request['orderBy'];
	    $searched_address=trim($request['searched_address']);
		$spaces = Space::query();
        $result=[];
		  if (!is_numeric($page)) {
           $page = 10;
		  }
		 if (!is_numeric($per_page)) {
           $per_page = 1;
         }
		 
		 	  $statement = 'select * from bravo_spaces WHERE status ="publish"';
		 
		       if(!empty($searched_address))
			   {
                 $statement.=' AND UPPER(CONCAT(city, ", ", state, ", ", country)) LIKE UPPER("%'.$searched_address.'%") ';
			   }
		        if(!empty($sortBy) && !empty($orderBy))
				{
					$statement.= ' ORDER BY '.$sortBy.' '.$orderBy;
			     }	   
		    
		  		if(!empty($limit) && !empty($page))
				 {
					 $offset= $page*$limit;
                     $statement.= ' LIMIT '.$limit.' OFFSET '.$offset;
				 }

		  $spaces=DB::select($statement);

		$result=$this->getCollection($spaces);
		
        return response()->json(['status' => 'success','result' => $result]);
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
   
    public function show($id)
    {
        $space = Space::where('id', $id)->get();
        return response()->json($space);
        
    }
   
}