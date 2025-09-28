<?php
// app/Services/MailTemplateService.php
namespace App\Services;

use App\Models\EmailSubject;
use App\Models\EmailTemplate;
use Illuminate\Support\Str;

class MailTemplateService
{
    public function get(string $code, array $data = []): array
    {
        $subject = optional(EmailSubject::where('code', $code)->first())->subject ?? "[{$code}]";
        $html    = optional(EmailTemplate::where('code', $code)->first())->html ?? "<p>{$code}</p>";
        
        $replacements = [];
        foreach ($data as $k => $v) {
            $replacements['{{'.Str::slug($k, '_').'}}'] = e((string) $v);
        }

        foreach ($data as $k => $v) {
            if (Str::startsWith($k, 'html_')) {
                $replacements['{{'.Str::slug($k, '_').'}}'] = (string) $v;
            }
        }

        $subject = strtr($subject, $replacements);
        $html    = strtr($html, $replacements);

        return [$subject, $html];
    }
}