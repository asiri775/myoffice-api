<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticableTrait;

use App\Mail\DbTemplateMailable;
use App\Services\MailTemplateService;

use Illuminate\Support\Facades\Mail;
use App\Models\EmailSubject;
use App\Models\EmailTemplate;
use App\Mail\EmailUserVerifyRegister;
use App\Mail\EmailUserWelcome;
use App\Mail\EmailUserRegistered;
use App\Mail\EmailNewUserAdmin;
use App\Support\TemplateMerge;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;


class Users extends Model implements Authenticatable
{
   //
   use AuthenticableTrait;

   /**
    * Get the name of the unique password field for the user.
    *
    * @return string
    */
   public function getAuthPasswordName()
   {
       return 'password';
   }

   protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        "api_key",
        'password',
        'address',
        'address2',
        'phone',
        'birthday',
        'city',
        'state',
        'country',
        'zip_code',
        'last_login_at',
        'avatar_id',
        'bio',
        'business_name',
        'map_lat',
        'map_lng',
        'map_zoom',
        'instagram_link',
        'facebook_link',
        'site_link',
        'super_host',
        'role_id'
    ];
   protected $hidden = [
    'password',
    'remember_token',
   ];

   protected $casts = [
    'email_verified_at' => 'datetime',
   ];
   /*
   * Get Todo of User
   *
   */
   public function Space()
   {
       return $this->hasMany('App\Space');
   }



   protected function findSubjectAndHtmlByToken(string $token, int $domain = 9): array
   {
       $sub = EmailSubject::where('token', $token)->first();
       if (!$sub) {
           \Log::warning("Mail: EmailSubject token not found", ['token' => $token]);
           return ['['.$token.']', '<p>'.$token.'</p>'];
       }

       $tpl = EmailTemplate::where('domain', $domain)
               ->where('subject_id', $sub->id)
               ->first();

       if (!$tpl) {
           \Log::warning("Mail: EmailTemplate not found for token", [
               'token' => $token,
               'domain' => $domain,
               'subject_id' => $sub->id,
           ]);
           return [$sub->subject, '<p>'.$token.'</p>'];
       }

       // Support either column name
       $html = $tpl->content ?? $tpl->html ?? null;
       if ($html === null) {
           \Log::warning("Mail: EmailTemplate missing html/content column", [
               'token' => $token,
               'domain' => $domain,
               'subject_id' => $sub->id,
               'columns' => array_keys($tpl->getAttributes()),
           ]);
           $html = '<p>'.$token.'</p>';
       }

       return [$sub->subject, $html];
   }


public function sendEmailUserVerificationNotification($register_as)
{
    $actionUrl = $this->verificationUrl($this);

    $token = $register_as === 'host'
        ? 'MYOFFICE___HOST__ACTIVATION_EMAIL'
        : 'MYOFFICE___USER__ACTIVATION_EMAIL';

    [$subject, $html] = $this->findSubjectAndHtmlByToken($token, 9);


    if ($override = setting_item('subject_email_verify_register_user')) {
        $subject = $override;
    }

    $html = TemplateMerge::render($html, [
        'name'        => $this->getDisplayName(true),
        'first_name'  => (string) $this->first_name,
        'last_name'   => (string) $this->last_name,
        'email'       => $this->email,
        'register_as' => $register_as,
        'verify_url'  => $actionUrl,
        'url'         => $actionUrl,
    ]);

    Mail::to($this->email)->send(new EmailUserVerifyRegister($subject, $html));
}

public function sendEmailWelcomeNotification($register_as)
{
    $token = $register_as === 'host'
        ? 'MYOFFICE___HOST__WELCOME_EMAIL'
        : 'MYOFFICE___USER__WELCOME_EMAIL';

    [$subject, $html] = $this->findSubjectAndHtmlByToken($token, 9);


    $html = TemplateMerge::render($html, [
        'name'        => $this->getDisplayName(true),
        'first_name'  => (string) $this->first_name,
        'last_name'   => (string) $this->last_name,
        'email'       => $this->email,
        'register_as' => $register_as,
    ]);

    Mail::to($this->email)->send(new EmailUserWelcome($subject, $html));
}

public function sendEmailRegisteredNotification($register_as)
{
    $actionUrl = $this->verificationUrl($this);

    $token = $register_as === 'host'
        ? 'MYOFFICE__HOST__SIGNUP'
        : 'MYOFFICE__USER__SIGNUP';

    [$subject, $html] = $this->findSubjectAndHtmlByToken($token, 9);

    $html =  TemplateMerge::render($html, [
        'name'        => $this->getDisplayName(true),
        'first_name'  => (string) $this->first_name,
        'last_name'   => (string) $this->last_name,
        'email'       => $this->email,
        'register_as' => $register_as,
        'verify_url'  => $actionUrl,
        'url'         => $actionUrl,
    ]);

    Mail::to($this->email)->send(new EmailUserRegistered($subject, $html));
}

public function sendEmailRegisteredAdminNotification($register_as)
{
    $token = $register_as === 'host'
        ? 'MYOFFICE___ADMIN__HOST_SIGNUP'
        : 'MYOFFICE___ADMIN__USER_SIGNUP';

    [$subject, $html] = $this->findSubjectAndHtmlByToken($token, 9);

    $admins = static::whereHas('roles', fn($q) => $q->whereIn('name', ['administrator']))->get();

    foreach ($admins as $admin) {
        $personalized = TemplateMerge::render($html, [
            'admin_name'  => $admin->getDisplayName(true),
            'user_name'   => $this->getDisplayName(true),
            'user_email'  => $this->email,
            'register_as' => $register_as,
            'user_id'     => (string) $this->id,
        ]);
        Mail::to($admin->email)->send(new EmailNewUserAdmin($subject, $personalized));
    }
}

public function verificationUrl($notifiable)
{
    $expireMinutes = (int) Config::get('auth.verification.expire', 60);

    $base   = url('/api/verify-email');
    $params = [
        'id'      => $notifiable->getKey(),
        'hash'    => sha1($notifiable->getEmailForVerification()),
        'expires' => time() + ($expireMinutes * 60),
    ];


    $payload = parse_url($base, PHP_URL_PATH) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $signature = hash_hmac('sha256', $payload, config('app.key'));

    return $base . '?' . http_build_query($params + ['signature' => $signature], '', '&', PHP_QUERY_RFC3986);
}

public function getEmailForVerification(): string
{
    
    return (string) $this->email;
}

public function getDisplayName(bool $fallbackToEmail = false): string
{
    $name = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
    if ($name === '') {
        $name = (string) ($this->name ?? '');
    }
    if ($name === '' && $fallbackToEmail) {
        $name = (string) $this->email;
    }
    return $name !== '' ? $name : ' ';
}
}
