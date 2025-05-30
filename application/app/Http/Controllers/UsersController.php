<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Users;
use Illuminate\Support\Str;

class UsersController extends Controller
{
  public function __construct()
   {
     //  $this->middleware('auth:api');
   }
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function authenticate(Request $request)
   {
       $this->validate($request, [
       'email' => 'required',
       'password' => 'required'
        ]);
      $user = Users::where('email', $request->input('email'))->first();
     if(Hash::check($request->input('password'), $user->password)){
          $apikey = base64_encode(Str::random(40));
          Users::where('email', $request->input('email'))->update(['api_key' => "$apikey"]);;
          return response()->json(['status' => 'success','api_key' => $apikey]);
      }else{
          return response()->json(['status' => 'fail'],401);
      }
   }

   public function userRegister(Request $request)
   {
        $rules = [
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:15',
                'regex:/^[A-Za-z]{2,15}$/'
            ],
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:15',
                'regex:/^[A-Za-z]{2,15}$/'
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users'
            ],
            'password' => [
                'required',
                'string'
            ],
            'role_id' => [
                'required',
            ],
            'country' => [
                'string',
            ],
            'mobile_number' => [
                'string',
            ],
            // 'referral_code' => [
            //     'string',
            // ]
        ];

        $messages = [
            'email.required' => __('Email is required field'),
            'email.email' => __('Email invalidate'),
            'password.required' => __('Password is required field'),
            'first_name.required' => __('The first name is required field'),
            'last_name.required' => __('The last name is required field'),
            'first_name.regex' => __('The first name should be between 2 and 15 characters long, and must not contain any digits or spaces.'),
            'last_name.regex' => __('The last name should be between 2 and 15 characters long, and must not contain any digits or spaces.'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try{
            $user = Users::create([
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'role_id' => $request->input('role_id'),
                'name' => $request->input('first_name').' '.$request->input('last_name'),
                'super_host' => 0,
                'country' => $request->input('country'),
                'phone' => $request->input('mobile_number'),
                // 'referral_code' => $request->input('referral_code'),
            ]);
            return response()->json([
                'status' => 'success',
                "message"=>"User registered successfully",
                "data"=>[
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'name' => $user->name,
                    'role_id' => $user->role_id,
                    'country' => $user->country,
                    'mobile_number' => $user->phone,
                    // 'referral_code' => $user->referral_code,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => false,
                'message' =>  $exception->getMessage(),
            ], 500);
        }

   }
}
?>
